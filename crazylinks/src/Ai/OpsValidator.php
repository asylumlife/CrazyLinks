<?php
/**
 * Validates AI operations against the ops schema and the plugin's business rules.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\Schema\SchemaValidator;
use CrazyLinks\Schema\UrlPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Two passes, then the page validator:
 *
 * 1. Schema: schemas/ai-ops.v1.json. SchemaValidator has no `anyOf`, so the outer shape is checked with
 *    unions collapsed to objects and each union member is dispatched here (operation by `op`, new block
 *    by `type`, update props by the target block's type).
 * 2. Business rules, walking the operations in order on a working copy: ids and refs exist, nothing
 *    touches custom HTML, groups do not nest, URLs pass UrlPolicy, schedules make sense, media ids are
 *    attachments on this site, per-mode limits (restyle = design only; analytics = pin/visibility only).
 * 3. The resulting document goes through PageValidator with the acting user's capabilities, exactly
 *    like a human save.
 */
final class OpsValidator {

	public const MODES = [ 'edit', 'build_from_site', 'build_from_description', 'restyle', 'analytics_qa' ];

	/**
	 * Applier.
	 *
	 * @var OpsApplier
	 */
	private OpsApplier $applier;

	/**
	 * Constructor.
	 *
	 * @param OpsApplier|null $applier Applier (inject one with a fixed id factory for deterministic tests).
	 */
	public function __construct( ?OpsApplier $applier = null ) {
		$this->applier = $applier ?? new OpsApplier();
	}

	/**
	 * Validates a model response (or edited operations) against a document.
	 *
	 * @param mixed                     $payload  Decoded response: {summary, operations, questions}.
	 * @param array<string, mixed>      $doc      Base document (what the operations apply to).
	 * @param int                       $user_id  Acting user.
	 * @param string                    $mode     Mode.
	 * @param array<string, mixed>|null $previous Stored document for the capability gates (defaults to $doc).
	 */
	public function validate( $payload, array $doc, int $user_id, string $mode = 'edit', ?array $previous = null ): OpsResult {
		$errors = $this->schema_errors( $payload );
		if ( $errors ) {
			return new OpsResult( null, $errors, [] );
		}
		// The schema pass guaranteed the summary, operations and questions shape.
		$ops = array_values( (array) $payload['operations'] );

		$errors = $this->mode_errors( $ops, $mode );
		$work   = $doc;
		$refs   = [];
		$added  = [];
		foreach ( $ops as $i => $op ) {
			$path = '/operations/' . $i;
			$rule = $this->rule_errors( $op, $work, $refs, $path );
			try {
				$next = $this->applier->step( $work, $op, $refs, $added );
			} catch ( OpsException $e ) {
				$errors[] = self::err( $path . $e->field(), $e->getMessage() );
				continue;
			}
			if ( $rule ) {
				$errors = array_merge( $errors, $rule );
				continue;
			}
			$work = $next;
		}
		if ( $errors ) {
			return new OpsResult( null, $errors, [] );
		}

		$result = $this->applier->finalize( $work, $user_id, $previous ?? $doc );
		if ( ! $result->is_valid() ) {
			return new OpsResult( null, $this->page_errors( $result->errors(), $work ), [] );
		}
		return new OpsResult( $result->document(), [], $added );
	}

	/**
	 * Schema pass.
	 *
	 * @param mixed $payload Payload.
	 * @return array<int, array{path:string, message:string}>
	 */
	public function schema_errors( $payload ): array {
		$root      = OpsSchema::get();
		$validator = new SchemaValidator( $root );
		if ( ! is_array( $payload ) || ( [] !== $payload && array_is_list( $payload ) ) ) {
			return [ self::err( '/', __( 'The response must be a JSON object with summary, operations and questions.', 'crazylinks' ) ) ];
		}
		$errors = $validator->validate_with( $payload, OpsSchema::without_unions( $root ) );
		if ( $errors ) {
			return $errors;
		}
		foreach ( array_values( (array) $payload['operations'] ) as $i => $op ) {
			$path = '/operations/' . $i;
			$name = is_array( $op ) && is_string( $op['op'] ?? null ) ? 'op_' . $op['op'] : '';
			$def  = '' !== $name ? OpsSchema::def( $name ) : null;
			if ( null === $def ) {
				$errors[] = self::err( $path . '/op', __( 'op must be one of add_block, update_block, move_block, remove_block, set_design, set_profile.', 'crazylinks' ) );
				continue;
			}
			$errors = array_merge( $errors, self::prefix( $validator->validate_with( $op, OpsSchema::without_unions( $def ) ), $path ) );
			if ( 'op_add_block' === $name && is_array( $op['block'] ?? null ) ) {
				$type = is_string( $op['block']['type'] ?? null ) ? $op['block']['type'] : '';
				$new  = OpsSchema::def( 'new_' . $type );
				if ( null === $new ) {
					$errors[] = self::err( $path . '/block/type', __( 'This block type cannot be added by AI.', 'crazylinks' ) );
				} else {
					$errors = array_merge( $errors, self::prefix( $validator->validate_with( $op['block'], $new ), $path . '/block' ) );
				}
			}
		}
		return $errors;
	}

	/**
	 * Per-mode limits.
	 *
	 * @param array<int, array<string, mixed>> $ops  Operations.
	 * @param string                           $mode Mode.
	 * @return array<int, array{path:string, message:string}>
	 */
	private function mode_errors( array $ops, string $mode ): array {
		$errors = [];
		foreach ( $ops as $i => $op ) {
			$path = '/operations/' . $i;
			if ( 'restyle' === $mode && 'set_design' !== $op['op'] ) {
				$errors[] = self::err( $path . '/op', __( 'Restyle requests may only use set_design.', 'crazylinks' ) );
			}
			if ( 'analytics_qa' === $mode && ( 'update_block' !== $op['op'] || [] !== array_diff( array_keys( $op ), [ 'op', 'id', 'pinned', 'visible' ] ) ) ) {
				$errors[] = self::err( $path . '/op', __( 'Analytics answers may only pin, unpin, show or hide blocks.', 'crazylinks' ) );
			}
		}
		return $errors;
	}

	/**
	 * Business rules for one operation, checked against the working copy before it is applied.
	 *
	 * @param array<string, mixed>  $op   Operation.
	 * @param array<string, mixed>  $doc  Working copy.
	 * @param array<string, string> $refs Refs so far.
	 * @param string                $path Operation path.
	 * @return array<int, array{path:string, message:string}>
	 */
	private function rule_errors( array $op, array $doc, array $refs, string $path ): array {
		$errors = [];
		switch ( $op['op'] ) {
			case 'add_block':
				$block  = (array) $op['block'];
				$errors = array_merge(
					$this->props_errors( (string) $block['type'], (array) ( $block['props'] ?? [] ), $path . '/block/props' ),
					$this->schedule_errors( (array) ( $block['schedule'] ?? [] ), $path . '/block/schedule' )
				);
				break;
			case 'update_block':
				$ref = (string) $op['id'];
				$i   = OpsApplier::index_of( $doc, $refs[ $ref ] ?? $ref );
				if ( $i < 0 ) {
					break; // The applier reports the missing id.
				}
				$block = (array) $doc['blocks'][ $i ];
				$type  = (string) ( $block['type'] ?? '' );
				if ( isset( $op['props'] ) ) {
					$patch = OpsSchema::def( 'patch_' . $type );
					if ( null === $patch ) {
						break; // Custom HTML: the applier reports it.
					}
					$found  = ( new SchemaValidator( OpsSchema::get() ) )->validate_with( $op['props'], $patch );
					$errors = array_merge(
						self::prefix( $found, $path . '/props' ),
						$found ? [] : $this->props_errors( $type, (array) $op['props'], $path . '/props' )
					);
				}
				if ( isset( $op['schedule'] ) ) {
					$merged = array_merge( (array) ( $block['schedule'] ?? [] ), (array) $op['schedule'] );
					$errors = array_merge( $errors, $this->schedule_errors( $merged, $path . '/schedule' ) );
				}
				break;
			case 'set_design':
				$bg = (array) ( $op['design']['background'] ?? [] );
				if ( ! empty( $bg['image'] ) && ! self::is_attachment( (int) $bg['image'], 'image' ) ) {
					$errors[] = self::err( $path . '/design/background/image', __( 'Image ids must be images in this site\'s media library.', 'crazylinks' ) );
				}
				if ( isset( $op['design']['theme'] ) && '' !== $op['design']['theme'] && ! array_key_exists( (string) $op['design']['theme'], \CrazyLinks\Render\Themes::all() ) ) {
					$errors[] = self::err( $path . '/design/theme', __( 'Unknown theme preset.', 'crazylinks' ) );
				}
				break;
			case 'set_profile':
				foreach ( [ 'avatar', 'banner' ] as $key ) {
					if ( ! empty( $op['profile'][ $key ] ) && ! self::is_attachment( (int) $op['profile'][ $key ], 'image' ) ) {
						$errors[] = self::err( $path . '/profile/' . $key, __( 'Image ids must be images in this site\'s media library.', 'crazylinks' ) );
					}
				}
				break;
		}
		return $errors;
	}

	/**
	 * URL policy and media ownership for new or changed props.
	 *
	 * @param string               $type  Block type.
	 * @param array<string, mixed> $props Props (complete for add, partial for update).
	 * @param string               $path  Path to the props.
	 * @return array<int, array{path:string, message:string}>
	 */
	public function props_errors( string $type, array $props, string $path ): array {
		$errors  = [];
		$schemes = in_array( $type, [ 'embed', 'video', 'payment' ], true ) ? UrlPolicy::WEB_SCHEMES : UrlPolicy::LINK_SCHEMES;
		if ( isset( $props['url'] ) && '' !== $props['url'] && ! UrlPolicy::is_allowed( (string) $props['url'], $schemes ) ) {
			/* translators: %s: allowed URL schemes. */
			$errors[] = self::err( $path . '/url', sprintf( __( 'URL is not allowed. Use a full address starting with %s.', 'crazylinks' ), implode( ', ', $schemes ) ) );
		}
		foreach ( array_values( (array) ( $props['items'] ?? [] ) ) as $n => $item ) {
			if ( is_array( $item ) && isset( $item['url'] ) && '' !== $item['url'] && ! UrlPolicy::is_allowed( (string) $item['url'] ) ) {
				$errors[] = self::err( $path . '/items/' . $n . '/url', __( 'URL is not allowed. Use http, https, mailto, tel or sms.', 'crazylinks' ) );
			}
			if ( 'gallery' === $type && is_array( $item ) && ! self::is_attachment( (int) ( $item['image'] ?? 0 ), 'image' ) ) {
				$errors[] = self::err( $path . '/items/' . $n . '/image', __( 'Image ids must be images in this site\'s media library.', 'crazylinks' ) );
			}
		}
		if ( isset( $props['deep_link'] ) && preg_match( '/^\s*(javascript|data|vbscript|file|blob|about):/i', (string) $props['deep_link'] ) ) {
			$errors[] = self::err( $path . '/deep_link', __( 'This deep link scheme is not allowed.', 'crazylinks' ) );
		}
		$media = [
			'thumbnail' => 'image',
			'image'     => 'image',
			'poster'    => 'image',
			'video'     => 'video',
		];
		foreach ( $media as $key => $kind ) {
			if ( ! empty( $props[ $key ] ) && is_int( $props[ $key ] ) && ! self::is_attachment( $props[ $key ], $kind ) ) {
				$errors[] = self::err( $path . '/' . $key, __( 'Media ids must be items in this site\'s media library.', 'crazylinks' ) );
			}
		}
		if ( 'woo_products' === $type ) {
			foreach ( (array) ( $props['product_ids'] ?? [] ) as $n => $pid ) {
				if ( 'product' !== get_post_type( (int) $pid ) ) {
					$errors[] = self::err( $path . '/product_ids/' . $n, __( 'Unknown product.', 'crazylinks' ) );
				}
			}
		}
		if ( 'product' === $type && ! empty( $props['product_id'] ) && \CrazyLinks\Woo\Store::is_available() && null === \CrazyLinks\Woo\Store::product( (int) $props['product_id'] ) ) {
			$errors[] = self::err( $path . '/product_id', __( 'Unknown product, or the store did not answer. Use the search-products ability to find a product id.', 'crazylinks' ) );
		}
		if ( 'email_capture' === $type && ! empty( $props['audience_id'] ) ) {
			$known = array_column( \CrazyLinks\Subscribers\Integrations::cached_audiences(), 'id' );
			$known = array_filter( array_merge( $known, [ \CrazyLinks\Subscribers\Integrations::default_audience() ] ) );
			if ( ! in_array( (string) $props['audience_id'], $known, true ) ) {
				$errors[] = self::err( $path . '/audience_id', __( 'Unknown Mailchimp audience. Use one of the audiences configured on this site, or leave audience_id out for the site default.', 'crazylinks' ) );
			}
		}
		if ( in_array( $type, [ 'latest_posts', 'woo_products' ], true ) && ! empty( $props['category'] ) && ! term_exists( (int) $props['category'] ) ) {
			$errors[] = self::err( $path . '/category', __( 'Unknown category.', 'crazylinks' ) );
		}
		if ( 'text' === $type && isset( $props['content'] ) && preg_match( '/<\s*(script|style|iframe|object|embed|form|input)\b/i', (string) $props['content'] ) ) {
			$errors[] = self::err( $path . '/content', __( 'Text may only use p, strong, em, a and br.', 'crazylinks' ) );
		}
		return $errors;
	}

	/**
	 * Schedule sanity: start before end, and a new end not already in the past.
	 *
	 * @param array<string, mixed> $schedule Schedule.
	 * @param string               $path     Path.
	 * @return array<int, array{path:string, message:string}>
	 */
	private function schedule_errors( array $schedule, string $path ): array {
		$start = is_string( $schedule['start'] ?? null ) ? strtotime( $schedule['start'] ) : null;
		$end   = is_string( $schedule['end'] ?? null ) ? strtotime( $schedule['end'] ) : null;
		if ( $start && $end && $start >= $end ) {
			return [ self::err( $path, __( 'Schedule end must be after start.', 'crazylinks' ) ) ];
		}
		if ( $end && $end < time() ) {
			return [ self::err( $path . '/end', __( 'Schedule end is in the past, so the block would never show.', 'crazylinks' ) ) ];
		}
		return [];
	}

	/**
	 * Rewrites PageValidator paths (/blocks/3/props/url) into block ids the model can act on.
	 *
	 * @param array<int, array{path:string, message:string}> $errors Errors.
	 * @param array<string, mixed>                           $doc    Document the paths refer to.
	 * @return array<int, array{path:string, message:string}>
	 */
	private function page_errors( array $errors, array $doc ): array {
		foreach ( $errors as $n => $e ) {
			if ( preg_match( '#^/blocks/(\d+)(.*)$#', $e['path'], $m ) ) {
				$block = (array) ( $doc['blocks'][ (int) $m[1] ] ?? [] );
				$id    = (string) ( $block['id'] ?? '' );
				if ( '' !== $id ) {
					$errors[ $n ]['path'] = '/page/blocks/' . $id . $m[2];
				}
			} else {
				$errors[ $n ]['path'] = '/page' . ( '/' === $e['path'] ? '' : $e['path'] );
			}
		}
		return $errors;
	}

	/**
	 * Whether an id is an attachment of the given kind on this site.
	 *
	 * @param int    $id   Attachment id.
	 * @param string $kind image | video.
	 */
	public static function is_attachment( int $id, string $kind ): bool {
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return false;
		}
		if ( 'image' === $kind ) {
			return (bool) wp_attachment_is_image( $id );
		}
		return str_starts_with( (string) get_post_mime_type( $id ), $kind . '/' );
	}

	/**
	 * Prefixes paths.
	 *
	 * @param array<int, array{path:string, message:string}> $errors Errors.
	 * @param string                                         $prefix Prefix.
	 * @return array<int, array{path:string, message:string}>
	 */
	private static function prefix( array $errors, string $prefix ): array {
		foreach ( $errors as $n => $e ) {
			$errors[ $n ]['path'] = $prefix . ( '/' === $e['path'] ? '' : $e['path'] );
		}
		return $errors;
	}

	/**
	 * Error tuple.
	 *
	 * @param string $path    Path.
	 * @param string $message Message.
	 * @return array{path:string, message:string}
	 */
	private static function err( string $path, string $message ): array {
		return [
			'path'    => $path,
			'message' => $message,
		];
	}
}
