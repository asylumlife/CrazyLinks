<?php
/**
 * Cached oEmbed data for embed blocks.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Frontend;

use CrazyLinks\PageStore;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches oEmbed data for allow-listed providers when an author saves a page, and caches it in
 * post meta. Rendering never makes a remote request: visitors get the cached data or a plain link.
 */
final class Embeds {

	public const META = '_cl_oembed';

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {}

	/**
	 * Allow-listed provider hosts.
	 *
	 * @return string[]
	 */
	public static function hosts(): array {
		/**
		 * Filters the hosts an embed block may fetch oEmbed data for.
		 *
		 * @param string[] $hosts Host names (subdomains match).
		 */
		return (array) apply_filters( 'crazylinks_embed_hosts', [ 'youtube.com', 'youtu.be', 'vimeo.com', 'spotify.com', 'soundcloud.com', 'tiktok.com', 'x.com', 'twitter.com', 'instagram.com' ] );
	}

	/**
	 * Whether a URL's host is allow-listed.
	 *
	 * @param string $url URL.
	 */
	public static function is_allowed( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		foreach ( self::hosts() as $allowed ) {
			if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fetches missing oEmbed data after a save (author-initiated, documented in readme.txt).
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $doc     Document.
	 */
	public static function prime( int $post_id, array $doc ): void {
		/**
		 * Filters whether CrazyLinks may fetch oEmbed data on save.
		 *
		 * @param bool $enabled The "Embed previews" setting (default true).
		 */
		if ( ! apply_filters( 'crazylinks_fetch_oembed', (bool) \CrazyLinks\Settings::get( 'fetch_oembed' ) ) ) {
			return;
		}
		$cache   = (array) get_post_meta( $post_id, self::META, true );
		$changed = false;
		$keep    = [];
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $block ) {
			if ( ! in_array( $block['type'] ?? '', [ 'embed', 'video' ], true ) ) {
				continue;
			}
			$url = (string) ( $block['props']['url'] ?? '' );
			$key = md5( $url );
			if ( '' === $url || ! self::is_allowed( $url ) ) {
				continue;
			}
			$keep[ $key ] = true;
			if ( isset( $cache[ $key ] ) ) {
				continue;
			}
			$oembed = _wp_oembed_get_object();
			$data   = $oembed->get_data( $url, [ 'discover' => false ] );
			if ( ! is_object( $data ) ) {
				continue;
			}
			$cache[ $key ] = [
				'html'          => (string) $oembed->data2html( $data, $url ),
				'title'         => isset( $data->title ) ? (string) $data->title : '',
				'thumbnail_url' => isset( $data->thumbnail_url ) ? esc_url_raw( (string) $data->thumbnail_url ) : '',
				'fetched'       => time(),
			];
			$changed       = true;
		}
		$pruned = array_intersect_key( $cache, $keep );
		if ( $changed || count( $pruned ) !== count( $cache ) ) {
			update_post_meta( $post_id, self::META, $pruned );
			\CrazyLinks\Render\Cache::forget( $post_id );
		}
	}

	/**
	 * Cached data for a URL on a page.
	 *
	 * @param int    $post_id Post id.
	 * @param string $url     URL.
	 * @return array<string, mixed>
	 */
	public static function cached( int $post_id, string $url ): array {
		if ( $post_id <= 0 ) {
			return [];
		}
		$cache = get_post_meta( $post_id, self::META, true );
		return is_array( $cache ) && isset( $cache[ md5( $url ) ] ) ? (array) $cache[ md5( $url ) ] : [];
	}

	/**
	 * Restricts provider HTML to iframes and fallback markup (no scripts).
	 *
	 * @param string $html Provider HTML.
	 */
	public static function kses( string $html ): string {
		return wp_kses(
			$html,
			[
				'iframe'     => [
					'src'             => true,
					'width'           => true,
					'height'          => true,
					'title'           => true,
					'frameborder'     => true,
					'allow'           => true,
					'allowfullscreen' => true,
					'loading'         => true,
					'referrerpolicy'  => true,
					'style'           => true,
				],
				'blockquote' => [
					'class'     => true,
					'cite'      => true,
					'data-*'    => true,
					'lang'      => true,
					'data-lang' => true,
				],
				'a'          => [ 'href' => true ],
				'p'          => [],
				'br'         => [],
			],
			[ 'https' ]
		);
	}
}
