<?php
/**
 * Writes analytics events.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Inserts rows into {prefix}crazylinks_events. No cookies, no raw IPs.
 */
final class EventLogger {

	public const TYPES = [ 'view', 'click', 'qr_scan', 'subscribe' ];

	/**
	 * Inserts an event row.
	 *
	 * @param int                        $page_id  Page id.
	 * @param string                     $type     Event type.
	 * @param Visitor                    $visitor  Visitor.
	 * @param string|null                $block_id Block id (clicks).
	 * @param array<string, string|null> $utm Incoming UTM values (views).
	 * @return int|false Insert id.
	 */
	public static function insert( int $page_id, string $type, Visitor $visitor, ?string $block_id = null, array $utm = [] ) {
		global $wpdb;
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}
		$row = [
			'page_id'       => $page_id,
			'block_id'      => $block_id,
			'type'          => $type,
			'ts'            => gmdate( 'Y-m-d H:i:s' ),
			'visitor_hash'  => $visitor->hash(),
			'country'       => null,
			'device'        => $visitor->device(),
			'os'            => $visitor->os(),
			'in_app'        => $visitor->in_app(),
			'referrer_host' => $visitor->referrer_host(),
			'utm_source'    => $utm['source'] ?? null,
			'utm_medium'    => $utm['medium'] ?? null,
			'utm_campaign'  => $utm['campaign'] ?? null,
		];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
		$ok = $wpdb->insert( $wpdb->prefix . 'crazylinks_events', $row, [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
		if ( ! $ok ) {
			return false;
		}
		$id = (int) $wpdb->insert_id;
		/**
		 * Fires after an analytics event is stored.
		 *
		 * @param int                  $id  Event id.
		 * @param array<string, mixed> $row Stored row.
		 */
		do_action( 'crazylinks_event_logged', $id, $row );
		return $id;
	}

	/**
	 * Whether the current request should be counted at all.
	 *
	 * @param Visitor $visitor Visitor.
	 */
	public static function should_count( Visitor $visitor ): bool {
		if ( ! Settings::analytics_enabled() || $visitor->is_bot() ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return false;
		}
		// Browser prefetch / prerender requests are not visits.
		foreach ( [ 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ' ] as $header ) {
			if ( isset( $_SERVER[ $header ] ) && preg_match( '/prefetch|prerender|preview/i', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Max page views logged per IP per minute, across all pages.
	 */
	public static function view_limit(): int {
		/**
		 * Filters how many page views one IP may log per minute across all link pages.
		 * Requests over the limit still render; they are just not counted.
		 *
		 * @param int $limit Default 120.
		 */
		return max( 1, (int) apply_filters( 'crazylinks_view_rate_limit', 120 ) );
	}

	/**
	 * Logs a page view (server-side, standalone and theme modes). Rate-limited per IP and
	 * de-duplicated per IP and page for 30 minutes; both keyed without the user agent.
	 *
	 * @param \WP_Post $post Page.
	 * @return string logged | filtered | rate-limited | duplicate | error
	 */
	public static function log_view( \WP_Post $post ): string {
		$visitor = Visitor::from_request();
		if ( ! self::should_count( $visitor ) ) {
			return 'filtered';
		}
		$key = $visitor->abuse_key();
		if ( ! RateLimiter::hit( 'view', $key, self::view_limit(), MINUTE_IN_SECONDS ) ) {
			return 'rate-limited';
		}
		if ( ! RateLimiter::first_in_window( 'view|' . $key . '|' . $post->ID, 30 * MINUTE_IN_SECONDS ) ) {
			return 'duplicate';
		}
		$utm = [];
		foreach ( [ 'source', 'medium', 'campaign' ] as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only tracking parameter.
			$value        = isset( $_GET[ 'utm_' . $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ 'utm_' . $name ] ) ) : '';
			$utm[ $name ] = '' !== $value ? substr( $value, 0, 64 ) : null;
		}
		return self::insert( (int) $post->ID, 'view', $visitor, null, $utm ) ? 'logged' : 'error';
	}
}
