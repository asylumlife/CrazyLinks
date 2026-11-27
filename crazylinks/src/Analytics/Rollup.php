<?php
/**
 * Daily rollups and retention.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

use CrazyLinks\Settings;
use CrazyLinks\Subscribers;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates raw events into {prefix}crazylinks_daily via WP-Cron and prunes old raw events.
 * Days are UTC.
 */
final class Rollup {

	public const HOOK = 'crazylinks_daily_rollup';

	/**
	 * Raw events deleted per query when pruning.
	 */
	private const PRUNE_BATCH = 5000;

	/**
	 * Most delete queries one prune runs, so a large backlog is spread over several nights.
	 */
	private const PRUNE_MAX_BATCHES = 20;

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Schedules the nightly job.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 00:15 UTC' ), 'daily', self::HOOK );
		}
	}

	/**
	 * Unschedules.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron callback.
	 */
	public function run(): void {
		RateLimiter::sweep();
		$through = (string) get_option( 'crazylinks_rollup_through', '' );
		// Re-roll the last rolled day too, in case events arrived late.
		$from      = '' !== $through ? gmdate( 'Y-m-d', (int) strtotime( $through . ' UTC -1 day' ) ) : $this->first_event_day();
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		if ( null !== $from && $from <= $yesterday ) {
			self::roll_range( $from, $yesterday );
			update_option( 'crazylinks_rollup_through', $yesterday, false );
		}
		$this->prune();
		Subscribers\Store::purge( (int) Settings::get( 'subscriber_retention_days' ) );
		Summary::flush_page_totals();
	}

	/**
	 * Aggregates [from, to] (inclusive, UTC dates). Idempotent.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function roll_range( string $from, string $to ): void {
		global $wpdb;
		[ $start, $end ] = Summary::day_bounds( $from, $to );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (page_id, block_id, item_id, day, type, count, uniques)
				SELECT page_id, COALESCE(block_id, ''), COALESCE(item_id, ''), DATE(ts), type, COUNT(*), COUNT(DISTINCT visitor_hash)
				FROM %i
				WHERE ts >= %s AND ts < %s
				GROUP BY page_id, COALESCE(block_id, ''), COALESCE(item_id, ''), DATE(ts), type
				ON DUPLICATE KEY UPDATE count = VALUES(count), uniques = VALUES(uniques)",
				Summary::daily_table(),
				Summary::events_table(),
				$start,
				$end
			)
		);
		Summary::flush_page_totals();
	}

	/**
	 * Earliest event day, or null with no events.
	 */
	private function first_event_day(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, read once per nightly run.
		$ts = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(ts) FROM %i', Summary::events_table() ) );
		return $ts ? substr( (string) $ts, 0, 10 ) : null;
	}

	/**
	 * Deletes raw events past the retention window (Settings: days, 0 = keep), but only days that
	 * have been rolled up. Rollups are kept forever.
	 *
	 * @return int Rows deleted.
	 */
	public function prune(): int {
		global $wpdb;
		$days = Settings::event_retention_days();
		if ( $days < 1 ) {
			return 0;
		}
		$through = (string) get_option( 'crazylinks_rollup_through', '' );
		if ( '' === $through ) {
			return 0; // Nothing is safe to delete before the first rollup.
		}
		$cutoff = min(
			gmdate( 'Y-m-d 00:00:00', time() - $days * DAY_IN_SECONDS ),
			Summary::next_day( $through ) . ' 00:00:00'
		);
		$total  = 0;
		for ( $batch = 0; $batch < self::PRUNE_MAX_BATCHES; $batch++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table housekeeping write.
			$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ts < %s LIMIT %d', Summary::events_table(), $cutoff, self::PRUNE_BATCH ) );
			$total  += $deleted;
			if ( $deleted < self::PRUNE_BATCH ) {
				break;
			}
		}
		return $total;
	}
}
