<?php
/**
 * Page JSON storage helpers.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks;

use CrazyLinks\Render\RenderContext;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Schema\PageValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, encodes and looks up the canonical page document stored in post_content.
 */
final class PageStore {

	/**
	 * Encodes a document. HTML-significant characters are hex-escaped so content filters
	 * such as kses never see markup inside the JSON.
	 *
	 * @param array<string, mixed> $doc Document.
	 */
	public static function encode( array $doc ): string {
		return (string) wp_json_encode( PageValidator::to_json_shape( $doc ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The decoded document for a post.
	 *
	 * @param int|\WP_Post $post     Post or id.
	 * @param bool         $fallback Return an empty document instead of null when unreadable.
	 * @return array<string, mixed>|null
	 */
	public static function get_document( $post, bool $fallback = true ): ?array {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return $fallback ? PageValidator::empty_document() : null;
		}
		$doc = json_decode( $post->post_content, true, 64 );
		if ( ! is_array( $doc ) ) {
			return $fallback ? PageValidator::empty_document() : null;
		}
		return $doc;
	}

	/**
	 * Finds a block by id in a document.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @param string               $id  Block id.
	 * @return array<string, mixed>|null
	 */
	public static function find_block( array $doc, string $id ): ?array {
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $block ) {
			if ( is_array( $block ) && ( $block['id'] ?? '' ) === $id ) {
				return $block;
			}
		}
		return null;
	}

	/**
	 * Whether anonymous visitors may reach a page's public endpoints (/go/, vCard, subscribe):
	 * a published link page whose password, if any, the visitor has entered.
	 *
	 * @param mixed $post Post or anything else.
	 * @phpstan-assert-if-true \WP_Post $post
	 */
	public static function is_public_page( $post ): bool {
		return $post instanceof \WP_Post
			&& PostType::NAME === $post->post_type
			&& 'publish' === $post->post_status
			&& ! post_password_required( $post );
	}

	/**
	 * A block that is publicly reachable right now: on a public page, of an allowed type, visible,
	 * inside its schedule, and (for children) inside a visible, scheduled group. Null otherwise.
	 *
	 * @param mixed         $post     Page.
	 * @param string        $block_id Block id ('' = first matching block of the given types).
	 * @param string[]      $types    Allowed block types.
	 * @param callable|null $accept Extra per-block test (e.g. props.vcard), receives the block.
	 * @return array<string, mixed>|null
	 */
	public static function reachable_block( $post, string $block_id, array $types, ?callable $accept = null ): ?array {
		if ( ! self::is_public_page( $post ) ) {
			return null;
		}
		$doc = self::get_document( $post ) ?? [];
		$ctx = RenderContext::for_post( $post, 'standalone', $doc );
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $block ) {
			if ( ! is_array( $block ) || ( '' !== $block_id && ( $block['id'] ?? '' ) !== $block_id ) ) {
				continue;
			}
			if ( ! in_array( $block['type'] ?? '', $types, true ) || empty( $block['visible'] ) || ! Renderer::in_schedule( $block, $ctx ) ) {
				continue;
			}
			if ( ! empty( $block['parent_id'] ) ) {
				$parent = self::find_block( $doc, (string) $block['parent_id'] );
				if ( ! $parent || empty( $parent['visible'] ) || ! Renderer::in_schedule( $parent, $ctx ) ) {
					continue;
				}
			}
			if ( null !== $accept && ! $accept( $block ) ) {
				continue;
			}
			return $block;
		}
		return null;
	}

	/**
	 * Stores validation errors for display on the next admin screen load.
	 *
	 * @param int                                            $post_id Post id.
	 * @param array<int, array{path:string, message:string}> $errors Errors.
	 */
	public static function remember_errors( int $post_id, array $errors ): void {
		set_transient( 'crazylinks_errors_' . get_current_user_id() . '_' . $post_id, $errors, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Pops stored validation errors.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, array{path:string, message:string}>
	 */
	public static function pop_errors( int $post_id ): array {
		$key    = 'crazylinks_errors_' . get_current_user_id() . '_' . $post_id;
		$errors = get_transient( $key );
		delete_transient( $key );
		return is_array( $errors ) ? $errors : [];
	}
}
