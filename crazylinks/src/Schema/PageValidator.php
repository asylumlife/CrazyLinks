<?php
/**
 * Page document validator and sanitizer.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Schema;

use CrazyLinks\Render\Contrast;
use CrazyLinks\Render\Payments;
use CrazyLinks\Render\Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a page document against schemas/page.v1.json, then applies business rules
 * (URL policy, structure, capability gates) and sanitizes it into canonical form.
 */
final class PageValidator {

	public const MAX_JSON_BYTES = 512000;

	/**
	 * Cached decoded schema.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $schema = null;

	/**
	 * Allowed HTML in `text` blocks.
	 *
	 * @var array<string, array<string, bool>>
	 */
	public const TEXT_HTML = [
		'p'      => [],
		'br'     => [],
		'strong' => [],
		'em'     => [],
		'a'      => [
			'href'  => true,
			'title' => true,
		],
	];

	/**
	 * The decoded schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		if ( null === self::$schema ) {
			$raw          = (string) file_get_contents( CRAZYLINKS_DIR . 'schemas/page.v1.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$decoded      = json_decode( $raw, true );
			self::$schema = is_array( $decoded ) ? $decoded : [];
		}
		return self::$schema;
	}

	/**
	 * A minimal valid document.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_document(): array {
		return [
			'schema'   => 1,
			'profile'  => [],
			'design'   => [],
			'blocks'   => [],
			'settings' => [],
		];
	}

	/**
	 * Keys whose values are JSON arrays; every other empty PHP array is a JSON object.
	 */
	public const LIST_KEYS = [ 'blocks', 'items', 'rel', 'tags', 'product_ids' ];

	/**
	 * Converts a document to its JSON shape (empty objects as stdClass).
	 *
	 * @param array<mixed> $data Data.
	 * @param string|int   $key  Key of $data in its parent.
	 * @return array<mixed>|\stdClass
	 */
	public static function to_json_shape( array $data, $key = '' ) {
		if ( [] === $data ) {
			return in_array( $key, self::LIST_KEYS, true ) ? [] : new \stdClass();
		}
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = self::to_json_shape( $v, is_int( $k ) ? $key : $k );
			}
		}
		return $data;
	}

	/**
	 * Validates and normalizes.
	 *
	 * @param string|array<string, mixed> $input    JSON string or decoded array.
	 * @param int                         $user_id  Acting user (capability gates).
	 * @param array<string, mixed>|null   $previous Previously stored document, if any.
	 */
	public function validate( $input, int $user_id, ?array $previous = null ): ValidationResult {
		if ( is_string( $input ) ) {
			if ( strlen( $input ) > self::MAX_JSON_BYTES ) {
				return $this->fail( '/', __( 'The page document is too large.', 'crazylinks' ) );
			}
			try {
				$input = json_decode( $input, true, 32, JSON_THROW_ON_ERROR );
			} catch ( \JsonException $e ) {
				/* translators: %s: JSON parser message. */
				return $this->fail( '/', sprintf( __( 'Invalid JSON: %s', 'crazylinks' ), $e->getMessage() ) );
			}
		}
		if ( ! is_array( $input ) || ( [] !== $input && array_is_list( $input ) ) ) {
			return $this->fail( '/', __( 'The page document must be a JSON object.', 'crazylinks' ) );
		}

		$doc    = $this->normalize( $input, $previous );
		$errors = ( new SchemaValidator( self::schema() ) )->validate( $doc );
		if ( $errors ) {
			return new ValidationResult( null, $errors );
		}

		$errors = $this->business_rules( $doc, $user_id, $previous );
		if ( $errors ) {
			return new ValidationResult( null, $errors );
		}

		$clean = $this->sanitize( $doc );
		return new ValidationResult( $clean, [], self::warnings_for( $clean ) );
	}

	/**
	 * Fills defaults so authors can omit boilerplate (ids, flags, empty sections).
	 *
	 * @param array<string, mixed>      $doc      Input.
	 * @param array<string, mixed>|null $previous Previously stored document, if any.
	 * @return array<string, mixed>
	 */
	private function normalize( array $doc, ?array $previous = null ): array {
		$doc += [
			'schema'   => 1,
			'profile'  => [],
			'design'   => [],
			'blocks'   => [],
			'settings' => [],
		];
		if ( is_array( $doc['blocks'] ) && array_is_list( $doc['blocks'] ) ) {
			foreach ( $doc['blocks'] as $i => $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$block += [
					'id'        => Ulid::generate(),
					'parent_id' => null,
					'order'     => $i,
					'visible'   => true,
					'schedule'  => [
						'start' => null,
						'end'   => null,
					],
					'pinned'    => false,
					'animation' => 'none',
					'tracking'  => true,
					'props'     => [],
				];
				if ( is_array( $block['schedule'] ) ) {
					$block['schedule'] += [
						'start' => null,
						'end'   => null,
					];
				}
				if ( 'socials' === ( $block['type'] ?? '' ) && is_array( $block['props'] ) ) {
					$block['props'] = $this->with_social_ids( $block, $previous );
				}
				$doc['blocks'][ $i ] = $block;
			}
		}
		return $doc;
	}

	/**
	 * Gives every social item a stable ULID. An item without one reuses the id of the same
	 * network and URL in the previous document (so API clients that omit ids keep their click
	 * URLs), otherwise it gets a new id. Duplicate ids within the block are replaced.
	 *
	 * @param array<string, mixed>      $block    Socials block.
	 * @param array<string, mixed>|null $previous Previous document.
	 * @return array<string, mixed> Props.
	 */
	private function with_social_ids( array $block, ?array $previous ): array {
		$props = (array) $block['props'];
		if ( ! isset( $props['items'] ) || ! is_array( $props['items'] ) || ! array_is_list( $props['items'] ) ) {
			return $props;
		}
		$known = [];
		foreach ( (array) ( $previous['blocks'] ?? [] ) as $old ) {
			if ( ! is_array( $old ) || ( $old['id'] ?? null ) !== $block['id'] ) {
				continue;
			}
			foreach ( (array) ( $old['props']['items'] ?? [] ) as $item ) {
				if ( is_array( $item ) && ! empty( $item['id'] ) ) {
					$known[ ( $item['network'] ?? '' ) . '|' . ( $item['url'] ?? '' ) ] = (string) $item['id'];
				}
			}
		}
		$used = [];
		foreach ( $props['items'] as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = isset( $item['id'] ) && is_string( $item['id'] ) ? $item['id'] : '';
			if ( '' === $id ) {
				$id = $known[ ( $item['network'] ?? '' ) . '|' . ( $item['url'] ?? '' ) ] ?? '';
			}
			if ( '' === $id || isset( $used[ $id ] ) ) {
				$id = Ulid::generate();
			}
			$used[ $id ]          = true;
			$item['id']           = $id;
			$props['items'][ $i ] = $item;
		}
		return $props;
	}

	/**
	 * Non-blocking problems in a valid document (SPEC §5A.6): images without alternative text.
	 * Mark an image `decorative` to silence its warning. The effective alt text is the prop, else the
	 * Media Library alt text, which the renderer falls back to as well.
	 *
	 * @param array<string, mixed> $doc Valid document.
	 * @return array<int, array{path:string, message:string, code:string, block_id?:string}>
	 */
	public static function warnings_for( array $doc ): array {
		$out   = [];
		$warn  = static function ( string $path, string $message, ?string $block_id = null, string $code = 'missing_alt' ) use ( &$out ): void {
			$row = [
				'path'    => $path,
				'message' => $message,
				'code'    => $code,
			];
			if ( null !== $block_id ) {
				$row['block_id'] = $block_id;
			}
			$out[] = $row;
		};
		$lacks = static function ( int $attachment, $alt, $decorative ): bool {
			if ( $attachment <= 0 || ! empty( $decorative ) || '' !== trim( (string) $alt ) ) {
				return false;
			}
			return '' === trim( (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ) );
		};

		$tokens  = null;
		$profile = (array) ( $doc['profile'] ?? [] );
		if ( (int) ( $profile['avatar'] ?? 0 ) > 0 && '' === trim( (string) ( $profile['name'] ?? '' ) ) ) {
			$warn( '/profile/name', __( 'The profile picture uses your name as its description. Add a name.', 'crazylinks' ) );
		}
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$props = (array) ( $block['props'] ?? [] );
			$base  = '/blocks/' . $i . '/props';
			$id    = (string) ( $block['id'] ?? '' );
			switch ( $block['type'] ?? '' ) {
				case 'image':
					if ( $lacks( (int) ( $props['image'] ?? 0 ), $props['alt'] ?? '', $props['decorative'] ?? false ) ) {
						$warn( $base . '/alt', __( 'This image has no alternative text. Describe it, or mark it decorative.', 'crazylinks' ), $id );
					}
					break;
				case 'gallery':
					foreach ( (array) ( $props['items'] ?? [] ) as $n => $item ) {
						if ( is_array( $item ) && $lacks( (int) ( $item['image'] ?? 0 ), $item['alt'] ?? '', $item['decorative'] ?? false ) ) {
							$warn( $base . '/items/' . $n . '/alt', __( 'This gallery image has no alternative text. Describe it, or mark it decorative.', 'crazylinks' ), $id );
						}
					}
					break;
				case 'link':
					if ( $lacks( (int) ( $props['thumbnail'] ?? 0 ), $props['thumbnail_alt'] ?? '', $props['decorative'] ?? false ) ) {
						$warn( $base . '/thumbnail_alt', __( 'This link image has no alternative text. Describe it, or mark it decorative.', 'crazylinks' ), $id );
					}
					break;
				case 'group':
					$tokens = $tokens ?? new Tokens( (array) ( $doc['design'] ?? [] ) );
					$worst  = null;
					foreach ( Contrast::section_pairs( $props, $tokens->text_color(), $tokens->background_samples() ) as $pair ) {
						$ratio = Contrast::ratio( $pair['fg'], $pair['bg'] );
						$worst = null === $worst ? $ratio : min( $worst, $ratio );
					}
					if ( null !== $worst && $worst < Contrast::AA_BODY ) {
						$warn(
							$base . '/text_color',
							/* translators: %s: contrast ratio such as 3.2. */
							sprintf( __( 'Text in this section is hard to read on its background (%s:1, needs 4.5:1). Pick another text or background colour.', 'crazylinks' ), number_format_i18n( $worst, 1 ) ),
							$id,
							'low_contrast'
						);
					}
					break;
			}
		}
		return $out;
	}

	/**
	 * Rules the schema cannot express.
	 *
	 * @param array<string, mixed>      $doc      Schema-valid document.
	 * @param int                       $user_id  User.
	 * @param array<string, mixed>|null $previous Previous document.
	 * @return array<int, array{path:string, message:string}>
	 */
	private function business_rules( array $doc, int $user_id, ?array $previous ): array {
		$errors   = [];
		$ids      = [];
		$groups   = [];
		$can_html = user_can( $user_id, 'unfiltered_html' );

		foreach ( $doc['blocks'] as $block ) {
			if ( 'group' === $block['type'] ) {
				$groups[ $block['id'] ] = true;
			}
		}

		$prev_html = [];
		foreach ( (array) ( $previous['blocks'] ?? [] ) as $old ) {
			if ( is_array( $old ) && 'html' === ( $old['type'] ?? '' ) ) {
				$prev_html[ (string) ( $old['id'] ?? '' ) ] = (string) ( $old['props']['html'] ?? '' );
			}
		}

		foreach ( $doc['blocks'] as $i => $block ) {
			$path = '/blocks/' . $i;
			if ( isset( $ids[ $block['id'] ] ) ) {
				$errors[] = $this->err( $path . '/id', __( 'Duplicate block id.', 'crazylinks' ) );
			}
			$ids[ $block['id'] ] = true;

			if ( null !== $block['parent_id'] ) {
				if ( 'group' === $block['type'] ) {
					$errors[] = $this->err( $path . '/parent_id', __( 'Groups cannot be nested.', 'crazylinks' ) );
				} elseif ( ! isset( $groups[ $block['parent_id'] ] ) ) {
					$errors[] = $this->err( $path . '/parent_id', __( 'parent_id must reference a group block on this page.', 'crazylinks' ) );
				}
			}

			$start = $block['schedule']['start'];
			$end   = $block['schedule']['end'];
			if ( is_string( $start ) && is_string( $end ) && strtotime( $start ) >= strtotime( $end ) ) {
				$errors[] = $this->err( $path . '/schedule', __( 'Schedule end must be after start.', 'crazylinks' ) );
			}

			if ( 'html' === $block['type'] && ! $can_html ) {
				$unchanged = isset( $prev_html[ $block['id'] ] ) && $prev_html[ $block['id'] ] === $block['props']['html'];
				if ( ! $unchanged ) {
					$errors[] = $this->err( $path, __( 'Only users allowed to post unfiltered HTML can add or change custom HTML blocks.', 'crazylinks' ) );
				}
			}

			foreach ( $this->url_fields( $block ) as $field => [ $url, $schemes ] ) {
				if ( '' !== $url && ! UrlPolicy::is_allowed( $url, $schemes ) ) {
					$errors[] = $this->err(
						$path . '/props/' . $field,
						/* translators: %s: allowed URL schemes. */
						sprintf( __( 'URL is not allowed. Allowed schemes: %s.', 'crazylinks' ), implode( ', ', $schemes ) )
					);
				}
			}

			if ( 'link' === $block['type'] && isset( $block['props']['deep_link'] ) && preg_match( '/^\s*(javascript|data|vbscript|file|blob|about):/i', (string) $block['props']['deep_link'] ) ) {
				$errors[] = $this->err( $path . '/props/deep_link', __( 'This deep link scheme is not allowed.', 'crazylinks' ) );
			}
			if ( 'payment' === $block['type'] ) {
				$errors = array_merge( $errors, $this->payment_errors( $path, (array) $block['props'] ) );
			}
			if ( 'video' === $block['type'] && empty( $block['props']['video'] ) && empty( $block['props']['url'] ) ) {
				$errors[] = $this->err( $path, __( 'A video block needs an attachment or a URL.', 'crazylinks' ) );
			}
			if ( 'contact' === $block['type'] && ! empty( $block['props']['email'] ) && ! is_email( (string) $block['props']['email'] ) ) {
				$errors[] = $this->err( $path . '/props/email', __( 'Invalid email address.', 'crazylinks' ) );
			}
		}

		$css      = (string) ( $doc['design']['custom_css'] ?? '' );
		$prev_css = (string) ( $previous['design']['custom_css'] ?? '' );
		if ( '' !== $css && $css !== $prev_css && ! $can_html ) {
			$errors[] = $this->err( '/design/custom_css', __( 'Only users allowed to post unfiltered HTML can change custom CSS.', 'crazylinks' ) );
		}

		return $errors;
	}

	/**
	 * Payment preset rules: the destination must be a handle or URL that fits the chosen service.
	 *
	 * @param string               $path  Block path.
	 * @param array<string, mixed> $props Payment props.
	 * @return array<int, array{path:string, message:string}>
	 */
	private function payment_errors( string $path, array $props ): array {
		$service = (string) ( $props['service'] ?? 'custom' );
		$url     = Payments::resolve( $props );
		if ( '' === $url ) {
			return [ $this->err( $path . '/props/url', __( 'Enter a valid handle or a URL for this payment service.', 'crazylinks' ) ) ];
		}
		if ( UrlPolicy::is_allowed( $url, UrlPolicy::WEB_SCHEMES ) && ! Payments::url_matches( $service, $url ) ) {
			return [ $this->err( $path . '/props/url', __( 'This URL does not belong to the selected payment service.', 'crazylinks' ) ) ];
		}
		return [];
	}

	/**
	 * URL-bearing props of a block, keyed by JSON path suffix.
	 *
	 * @param array<string, mixed> $block Block.
	 * @return array<string, array{0:string, 1:string[]}>
	 */
	private function url_fields( array $block ): array {
		$p   = (array) $block['props'];
		$out = [];
		switch ( $block['type'] ) {
			case 'link':
			case 'image':
			case 'countdown':
				$out['url'] = [ (string) ( $p['url'] ?? '' ), UrlPolicy::LINK_SCHEMES ];
				break;
			case 'embed':
			case 'video':
			case 'payment':
				$out['url'] = [ (string) ( $p['url'] ?? '' ), UrlPolicy::WEB_SCHEMES ];
				break;
			case 'socials':
			case 'gallery':
				foreach ( (array) ( $p['items'] ?? [] ) as $i => $item ) {
					$out[ 'items/' . $i . '/url' ] = [ (string) ( $item['url'] ?? '' ), UrlPolicy::LINK_SCHEMES ];
				}
				break;
		}
		return $out;
	}

	/**
	 * Canonicalizes: cleans strings and URLs, kses rich text, re-numbers order.
	 *
	 * @param array<string, mixed> $doc Valid document.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $doc ): array {
		foreach ( $doc['blocks'] as $i => $block ) {
			$props = $this->clean_strings( (array) $block['props'], 'html' === $block['type'] );
			foreach ( $this->url_fields( $block ) as $field => [ $url, $schemes ] ) {
				if ( '' === $url ) {
					continue;
				}
				$segments = explode( '/', $field );
				if ( 1 === count( $segments ) ) {
					$props[ $field ] = UrlPolicy::clean( $url, $schemes );
				} else {
					$props[ $segments[0] ][ (int) $segments[1] ][ $segments[2] ] = UrlPolicy::clean( $url, $schemes );
				}
			}
			if ( 'payment' === $block['type'] && empty( $props['url'] ) ) {
				// Store the resolved URL so every consumer sees one canonical destination.
				$props['url'] = UrlPolicy::clean( Payments::resolve( $props ), UrlPolicy::WEB_SCHEMES );
			}
			if ( 'text' === $block['type'] ) {
				$props['content'] = self::police_links( wp_kses( (string) $props['content'], self::TEXT_HTML, UrlPolicy::LINK_SCHEMES ) );
			}
			$block['props'] = $props;
			if ( array_key_exists( 'weekly', $block['schedule'] ) ) {
				if ( is_array( $block['schedule']['weekly'] ) ) {
					// Canonical form: weekdays ascending.
					$days = array_map( 'intval', (array) $block['schedule']['weekly']['days'] );
					sort( $days );
					$block['schedule']['weekly']['days'] = $days;
				} else {
					unset( $block['schedule']['weekly'] ); // null clears the window.
				}
			}
			$doc['blocks'][ $i ] = $block;
		}

		$doc['blocks']   = $this->reorder( $doc['blocks'] );
		$doc['profile']  = $this->clean_strings( (array) $doc['profile'], false );
		$css             = (string) ( $doc['design']['custom_css'] ?? '' );
		$doc['design']   = $this->clean_strings( (array) $doc['design'], false );
		$doc['settings'] = $this->clean_strings( (array) $doc['settings'], false );
		if ( '' !== $css ) {
			$doc['design']['custom_css'] = str_replace( '</', '<\/', wp_check_invalid_utf8( $css ) );
		}

		return [
			'schema'   => 1,
			'profile'  => $doc['profile'],
			'design'   => $doc['design'],
			'blocks'   => $doc['blocks'],
			'settings' => $doc['settings'],
		];
	}

	/**
	 * Holds rich-text links to the link-block URL policy (SPEC §12). wp_kses() turns a disallowed
	 * `javascript:` href into a harmless but broken relative `href="alert(1)"` and lets
	 * protocol-relative `//host` through; here any href that fails UrlPolicy is removed entirely,
	 * and allowed ones are stored in their cleaned form.
	 *
	 * @param string $html kses-filtered HTML.
	 */
	public static function police_links( string $html ): string {
		if ( ! str_contains( $html, '<' ) ) {
			return $html;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( [ 'tag_name' => 'A' ] ) ) {
			$href = $tags->get_attribute( 'href' );
			if ( null === $href ) {
				continue;
			}
			$clean = is_string( $href ) ? UrlPolicy::clean( $href, UrlPolicy::LINK_SCHEMES ) : '';
			if ( '' === $clean ) {
				$tags->remove_attribute( 'href' );
			} elseif ( $clean !== $href ) {
				$tags->set_attribute( 'href', $clean );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * Removes invalid UTF-8 and control characters from every string (escaping happens on output).
	 *
	 * @param array<mixed> $data Data.
	 * @param bool         $raw  Leave strings untouched (custom HTML).
	 * @return array<mixed>
	 */
	private function clean_strings( array $data, bool $raw ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->clean_strings( $value, $raw );
			} elseif ( is_string( $value ) && ! $raw ) {
				$value        = wp_check_invalid_utf8( $value );
				$data[ $key ] = trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value ) );
			}
		}
		return $data;
	}

	/**
	 * Sorts blocks: top level by order, each group followed by its children, order re-numbered.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function reorder( array $blocks ): array {
		$by_parent = [];
		foreach ( $blocks as $i => $block ) {
			$by_parent[ (string) $block['parent_id'] ][] = [ $block['order'], $i, $block ];
		}
		$sorted = static function ( array $items ): array {
			usort( $items, static fn( $a, $b ) => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );
			return array_column( $items, 2 );
		};
		$out    = [];
		foreach ( $sorted( $by_parent[''] ?? [] ) as $n => $block ) {
			$block['order'] = $n;
			$out[]          = $block;
			if ( 'group' === $block['type'] ) {
				foreach ( $sorted( $by_parent[ $block['id'] ] ?? [] ) as $m => $child ) {
					$child['order'] = $m;
					$out[]          = $child;
				}
			}
		}
		return $out;
	}

	/**
	 * Single-error result.
	 *
	 * @param string $path    Path.
	 * @param string $message Message.
	 */
	private function fail( string $path, string $message ): ValidationResult {
		return new ValidationResult( null, [ $this->err( $path, $message ) ] );
	}

	/**
	 * Error tuple.
	 *
	 * @param string $path    Path.
	 * @param string $message Message.
	 * @return array{path:string, message:string}
	 */
	private function err( string $path, string $message ): array {
		return [
			'path'    => $path,
			'message' => $message,
		];
	}
}
