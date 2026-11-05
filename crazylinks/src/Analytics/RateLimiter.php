<?php
/**
 * Bounded rate limiting and de-duplication.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window counters and short de-duplication windows (SPEC §7.3).
 *
 * Keys are expected to be Visitor::abuse_key() values (HMAC of the IP, no user agent). State lives
 * in the persistent object cache when there is one, otherwise in the small
 * `{prefix}crazylinks_limits` table (one row per key and window, expired rows swept
 * opportunistically and by the daily cron), never in one transient per visitor.
 *
 * Every failure mode is fail-open: if the store is unavailable the request is allowed.
 */
final class RateLimiter {

	public const GROUP = 'crazylinks_limits';

	/**
	 * One in this many writes sweeps expired rows.
	 */
	private const SWEEP_ODDS = 50;

	/**
	 * Limiter table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'crazylinks_limits';
	}

	/**
	 * Increments a fixed-window counter. Returns false when the limit is exceeded.
	 *
	 * @param string $bucket Bucket name.
	 * @param string $key    Abuse key (Visitor::abuse_key()).
	 * @param int    $limit  Max hits per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function hit( string $bucket, string $key, int $limit, int $window ): bool {
		global $wpdb;
		$window  = max( 1, $window );
		$now     = time();
		$slot    = intdiv( $now, $window );
		$expires = ( $slot + 1 ) * $window;
		$k       = md5( 'rl|' . $bucket . '|' . $key . '|' . $slot );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $k, 0, self::GROUP, $expires - $now + 1 );
			$count = wp_cache_incr( $k, 1, self::GROUP );
			return false === $count || $count <= $limit;
		}

		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table, prepared values.
		$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (k, hits, expires) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE hits = hits + 1", $k, $expires ) );
		if ( false === $ok ) {
			return true;
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$table} WHERE k = %s", $k ) );
		// phpcs:enable
		self::maybe_sweep( $now );
		return $count <= $limit;
	}

	/**
	 * Returns true the first time a key is seen within the window, false on repeats.
	 *
	 * @param string $key    Composite key (abuse key + target).
	 * @param int    $window Seconds.
	 */
	public static function first_in_window( string $key, int $window ): bool {
		global $wpdb;
		$now     = time();
		$expires = $now + max( 1, $window );
		$k       = md5( 'dd|' . $key );

		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( $k, 1, self::GROUP, max( 1, $window ) );
		}

		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table, prepared values.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (k, hits, expires) VALUES (%s, 1, %d)", $k, $expires ) );
		if ( false === $inserted ) {
			return true;
		}
		if ( 1 === (int) $inserted ) {
			self::maybe_sweep( $now );
			return true;
		}
		// The key exists: it counts as new only if its window has passed.
		$renewed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET expires = %d, hits = 1 WHERE k = %s AND expires <= %d", $expires, $k, $now ) );
		// phpcs:enable
		return 1 === (int) $renewed;
	}

	/**
	 * Deletes expired rows now and then.
	 *
	 * @param int $now Unix time.
	 */
	private static function maybe_sweep( int $now ): void {
		if ( 1 === wp_rand( 1, self::SWEEP_ODDS ) ) {
			self::sweep( $now, 500 );
		}
	}

	/**
	 * Deletes expired limiter rows.
	 *
	 * @param int|null $now   Unix time (default: now).
	 * @param int      $limit Max rows to delete (0 = all).
	 */
	public static function sweep( ?int $now = null, int $limit = 0 ): void {
		global $wpdb;
		$table = self::table();
		$now   = $now ?? time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table, prepared values.
		if ( $limit > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires < %d LIMIT %d", $now, $limit ) );
		} else {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires < %d", $now ) );
		}
		// phpcs:enable
	}
}
