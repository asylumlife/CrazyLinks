<?php
/**
 * The /{base}/{slug}/go/{block_id} click redirect.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

use CrazyLinks\PageStore;
use CrazyLinks\Render\Payments;
use CrazyLinks\Schema\Ulid;
use CrazyLinks\Schema\UrlPolicy;
use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the block, logs a click and 302s to the stored URL. Never redirects to a URL taken
 * from the request (no open redirect). The redirect always happens for valid blocks; rate limits
 * and de-duplication only decide whether a click is logged.
 */
final class ClickRedirect {

	public const DEDUPE_SECONDS = 10;

	/**
	 * Block types whose URL can be followed through /go/.
	 */
	public const TYPES = [ 'link', 'image', 'payment', 'socials' ];

	/**
	 * Sends the response and exits.
	 *
	 * @param mixed  $post     Queried object.
	 * @param string $block_id Block id from the URL.
	 * @param string $item_id  Item id from the URL (social icons), or ''.
	 */
	public function handle( $post, string $block_id, string $item_id = '' ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache constant.
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$result = $this->resolve( $post instanceof \WP_Post ? $post : null, $block_id, Visitor::from_request(), $item_id );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			header( 'X-CrazyLinks-Event: ' . $result['event'] );
		}

		if ( 302 !== $result['status'] ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return; // WordPress renders the theme's 404 template.
		}
		header( 'Referrer-Policy: no-referrer-when-downgrade' );
		if ( wp_redirect( $result['location'], 302, 'CrazyLinks' ) ) { // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Target is a stored, policy-checked URL.
			exit;
		}
	}

	/**
	 * Decides the response and logs the click.
	 *
	 * @param \WP_Post|null $post     Page.
	 * @param string        $block_id Block id.
	 * @param Visitor       $visitor  Visitor.
	 * @param string        $item_id  Item id inside the block (social icons), or ''.
	 * @return array{status:int, location:string, event:string}
	 */
	public function resolve( ?\WP_Post $post, string $block_id, Visitor $visitor, string $item_id = '' ): array {
		$not_found = [
			'status'   => 404,
			'location' => '',
			'event'    => 'not-found',
		];
		if ( ! Ulid::is_valid( $block_id ) || ( '' !== $item_id && ! Ulid::is_valid( $item_id ) ) ) {
			return $not_found;
		}
		$block = PageStore::reachable_block( $post, $block_id, self::TYPES );
		if ( ! $block || ! $post instanceof \WP_Post ) {
			return $not_found;
		}
		$doc = PageStore::get_document( $post ) ?? [];
		if ( 'socials' === $block['type'] ) {
			// Icon clicks need the item id; the item's own URL is the only destination.
			$url = '' === $item_id ? '' : self::social_url( $block, $item_id );
		} elseif ( '' !== $item_id ) {
			return $not_found;
		} else {
			$url = 'payment' === $block['type'] ? Payments::resolve( (array) ( $block['props'] ?? [] ) ) : (string) ( $block['props']['url'] ?? '' );
		}
		if ( ! UrlPolicy::is_allowed( $url, UrlPolicy::WEB_SCHEMES ) ) {
			return $not_found;
		}
		if ( 'payment' === $block['type'] && ! Payments::url_matches( (string) ( $block['props']['service'] ?? 'custom' ), $url ) ) {
			return $not_found;
		}
		$location = Utm::apply( $url, (array) ( $doc['settings'] ?? [] ), (string) $post->post_name );

		return [
			'status'   => 302,
			'location' => $location,
			'event'    => $this->maybe_log( $post, $block, $visitor, $item_id ),
		];
	}

	/**
	 * URL of one social icon, or '' when the item does not exist.
	 *
	 * @param array<string, mixed> $block   Socials block.
	 * @param string               $item_id Item id.
	 */
	private static function social_url( array $block, string $item_id ): string {
		foreach ( (array) ( $block['props']['items'] ?? [] ) as $item ) {
			if ( is_array( $item ) && ( $item['id'] ?? '' ) === $item_id ) {
				return (string) ( $item['url'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Logs the click unless filtered out. Returns what happened.
	 *
	 * @param \WP_Post             $post    Page.
	 * @param array<string, mixed> $block   Block.
	 * @param Visitor              $visitor Visitor.
	 * @param string               $item_id Item id (social icons), '' otherwise.
	 */
	private function maybe_log( \WP_Post $post, array $block, Visitor $visitor, string $item_id = '' ): string {
		if ( empty( $block['tracking'] ) ) {
			return 'untracked';
		}
		if ( ! Settings::analytics_enabled() ) {
			return 'disabled';
		}
		if ( ! EventLogger::should_count( $visitor ) ) {
			return 'filtered';
		}
		$key = $visitor->abuse_key();
		if ( ! RateLimiter::hit( 'go', $key, max( 1, (int) Settings::get( 'clicks_per_minute' ) ), MINUTE_IN_SECONDS ) ) {
			return 'rate-limited';
		}
		if ( ! RateLimiter::first_in_window( 'click|' . $key . '|' . $post->ID . '|' . $block['id'] . '|' . $item_id, self::DEDUPE_SECONDS ) ) {
			return 'duplicate';
		}
		return EventLogger::insert( (int) $post->ID, 'click', $visitor, (string) $block['id'] ) ? 'logged' : 'error';
	}
}
