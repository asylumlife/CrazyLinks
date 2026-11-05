<?php
/**
 * Rendered HTML cache.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Caches rendered page HTML in a transient per page and mode (SPEC §6.2).
 */
final class Cache {

	public const MAX_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Transient key.
	 *
	 * @param int    $post_id Post id.
	 * @param string $mode    Render mode.
	 */
	private static function key( int $post_id, string $mode ): string {
		return 'crazylinks_html_' . $post_id . '_' . $mode;
	}

	/**
	 * Cached HTML or null.
	 *
	 * @param int    $post_id Post id.
	 * @param string $mode    Mode.
	 * @param string $sig     Signature of inputs.
	 */
	public static function get( int $post_id, string $mode, string $sig ): ?string {
		$hit = get_transient( self::key( $post_id, $mode ) );
		return ( is_array( $hit ) && ( $hit['sig'] ?? '' ) === $sig ) ? (string) $hit['html'] : null;
	}

	/**
	 * Stores HTML.
	 *
	 * @param int    $post_id Post id.
	 * @param string $mode    Mode.
	 * @param string $sig     Signature.
	 * @param string $html    HTML.
	 * @param int    $ttl     Seconds.
	 */
	public static function set( int $post_id, string $mode, string $sig, string $html, int $ttl ): void {
		set_transient(
			self::key( $post_id, $mode ),
			[
				'sig'  => $sig,
				'html' => $html,
			],
			max( 60, min( self::MAX_TTL, $ttl ) )
		);
	}

	/**
	 * Drops every mode for a page.
	 *
	 * @param int $post_id Post id.
	 */
	public static function forget( int $post_id ): void {
		foreach ( [ 'standalone', 'theme', 'embed' ] as $mode ) {
			delete_transient( self::key( $post_id, $mode ) );
		}
	}

	/**
	 * Invalidates all pages (global setting changes).
	 */
	public static function bump(): void {
		update_option( 'crazylinks_cache_salt', wp_generate_password( 8, false ), false );
	}

	/**
	 * Invalidates every page when a public post is published or unpublished, so latest_posts
	 * blocks refresh at once (the 15-minute cap on those pages remains as a fallback).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( $new_status === $old_status || ! $post instanceof \WP_Post || ! in_array( 'publish', [ $new_status, $old_status ], true ) ) {
			return;
		}
		if ( \CrazyLinks\PostType::NAME === $post->post_type || ! is_post_type_viewable( $post->post_type ) ) {
			return; // Link pages forget their own cache on save.
		}
		self::bump();
	}

	/**
	 * Global salt mixed into signatures.
	 */
	public static function salt(): string {
		return (string) get_option( 'crazylinks_cache_salt', '' );
	}
}
