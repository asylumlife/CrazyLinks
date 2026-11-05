<?php
/**
 * Analytics summary queries.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

use CrazyLinks\PageStore;

defined( 'ABSPATH' ) || exit;

/**
 * Reads rollups for past days plus raw events for today (not rolled up yet).
 *
 * `report()` is the contract used by the editor and the Analytics screen:
 * - Days up to `crazylinks_rollup_through` come from `crazylinks_daily` (so they survive event
 *   pruning); later days (normally today) come from `crazylinks_events`.
 * - `uniques` is the sum of each day's distinct visitor hashes. The hash salt rotates daily, so a
 *   person who visits on two days is counted twice; this holds with or without raw events.
 * - Device, OS, in-app and referrer breakdowns need raw events and cover only the retention window.
 */
final class Summary {

	public const TOTALS_TRANSIENT = 'crazylinks_page_totals';

	/**
	 * Maximum days in one report.
	 */
	public const MAX_DAYS = 731;

	/**
	 * Resolves request parameters to an inclusive UTC date range. Order of precedence: from/to,
	 * range (7d|30d|90d), days, default 30d.
	 *
	 * @param string|null $range Range token.
	 * @param string|null $from  Y-m-d.
	 * @param string|null $to    Y-m-d.
	 * @param int|null    $days  Legacy day count.
	 * @return array{from:string, to:string, days:int}|\WP_Error
	 */
	public static function resolve_range( ?string $range, ?string $from, ?string $to, ?int $days = null ) {
		$today = gmdate( 'Y-m-d' );
		if ( null !== $from && '' !== $from ) {
			$to = ( null === $to || '' === $to ) ? $today : $to;
			foreach ( [ $from, $to ] as $date ) {
				$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
				if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
					return new \WP_Error( 'crazylinks_bad_range', __( 'Dates must look like 2026-01-31.', 'crazylinks' ), [ 'status' => 400 ] );
				}
			}
			$to = min( $to, $today );
			if ( $from > $to ) {
				return new \WP_Error( 'crazylinks_bad_range', __( 'The start date must not be after the end date.', 'crazylinks' ), [ 'status' => 400 ] );
			}
		} else {
			$n    = [
				'7d'  => 7,
				'30d' => 30,
				'90d' => 90,
			][ (string) $range ] ?? ( null !== $days && $days > 0 ? $days : 30 );
			$n    = max( 1, min( self::MAX_DAYS, $n ) );
			$to   = $today;
			$from = gmdate( 'Y-m-d', time() - ( $n - 1 ) * DAY_IN_SECONDS );
		}
		$span = (int) round( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS ) + 1;
		if ( $span > self::MAX_DAYS ) {
			return new \WP_Error( 'crazylinks_bad_range', __( 'That range is too long.', 'crazylinks' ), [ 'status' => 400 ] );
		}
		return [
			'from' => $from,
			'to'   => $to,
			'days' => $span,
		];
	}

	/**
	 * Per-day, per-type, per-block counts from the rollup (rolled days) plus events (the rest).
	 *
	 * @param int|null $page_id Page id, or null for every page.
	 * @param string   $from    Y-m-d.
	 * @param string   $to      Y-m-d.
	 * @return array<int, array{page_id:int, block_id:string, day:string, type:string, count:int, uniques:int}>
	 */
	private static function rows( ?int $page_id, string $from, string $to ): array {
		global $wpdb;
		$through = (string) get_option( 'crazylinks_rollup_through', '' );
		$out     = [];
		$only    = null === $page_id ? '' : $wpdb->prepare( ' AND page_id = %d', $page_id );
		$normal  = static function ( $r ): array {
			return [
				'page_id'  => (int) $r['page_id'],
				'block_id' => (string) $r['block_id'],
				'day'      => (string) $r['day'],
				'type'     => (string) $r['type'],
				'count'    => (int) $r['count'],
				'uniques'  => (int) $r['uniques'],
			];
		};
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== $through && $from <= $through ) {
			$rolled_to = min( $to, $through );
			foreach ( (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT page_id, block_id, day, type, count, uniques FROM {$wpdb->prefix}crazylinks_daily WHERE day >= %s AND day <= %s{$only}", $from, $rolled_to ),
				ARRAY_A
			) as $r ) {
				$out[] = $normal( $r );
			}
		}
		$live_from = '' !== $through ? max( $from, gmdate( 'Y-m-d', (int) strtotime( $through . ' UTC +1 day' ) ) ) : $from;
		if ( $live_from <= $to ) {
			foreach ( (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT page_id, COALESCE(block_id, '') AS block_id, DATE(ts) AS day, type, COUNT(*) AS count, COUNT(DISTINCT visitor_hash) AS uniques
					FROM {$wpdb->prefix}crazylinks_events WHERE ts >= %s AND ts < %s AND type IN ('view', 'click'){$only}
					GROUP BY page_id, COALESCE(block_id, ''), DATE(ts), type",
					$live_from . ' 00:00:00',
					gmdate( 'Y-m-d', (int) strtotime( $to . ' UTC +1 day' ) ) . ' 00:00:00'
				),
				ARRAY_A
			) as $r ) {
				$out[] = $normal( $r );
			}
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * The analytics report for one page and an inclusive UTC date range (see class docblock).
	 *
	 * @param int    $page_id Page id.
	 * @param string $from    Y-m-d.
	 * @param string $to      Y-m-d.
	 * @return array<string, mixed>
	 */
	public static function report( int $page_id, string $from, string $to ): array {
		$views   = 0;
		$clicks  = 0;
		$uniques = 0;
		$series  = [];
		$last    = (int) strtotime( $to . ' UTC' );
		for ( $t = (int) strtotime( $from . ' UTC' ); $t <= $last; $t += DAY_IN_SECONDS ) {
			$day            = gmdate( 'Y-m-d', $t );
			$series[ $day ] = [
				'date'   => $day,
				'views'  => 0,
				'clicks' => 0,
			];
		}
		$per_block = [];
		foreach ( self::rows( $page_id, $from, $to ) as $r ) {
			if ( ! isset( $series[ $r['day'] ] ) ) {
				continue;
			}
			if ( 'view' === $r['type'] ) {
				$views                        += $r['count'];
				$uniques                      += $r['uniques'];
				$series[ $r['day'] ]['views'] += $r['count'];
			} else {
				$clicks                        += $r['count'];
				$series[ $r['day'] ]['clicks'] += $r['count'];
				$per_block[ $r['block_id'] ]    = ( $per_block[ $r['block_id'] ] ?? 0 ) + $r['count'];
			}
		}

		return [
			'totals'                => [
				'views'   => $views,
				'uniques' => $uniques,
				'clicks'  => $clicks,
				'ctr'     => $views > 0 ? round( $clicks / $views, 4 ) : 0.0,
			],
			'timeseries'            => array_values( $series ),
			'blocks'                => self::block_rows( $page_id, $per_block, $clicks, $views ),
			'breakdowns'            => self::breakdowns( $page_id, $from, $to ),
			/**
			 * Filters whether country data is available (v1.0 never collects it).
			 *
			 * @param bool $available Default false.
			 */
			'country_available'     => (bool) apply_filters( 'crazylinks_country_available', false ),
			'range'                 => [
				'from' => $from,
				'to'   => $to,
				'days' => count( $series ),
			],
			'events_available_from' => self::first_event_day( $page_id ),
		];
	}

	/**
	 * Per-block rows: every clickable block still on the page plus removed blocks that have clicks.
	 *
	 * @param int                $page_id   Page id.
	 * @param array<string, int> $per_block Clicks by block id.
	 * @param int                $clicks    Total clicks.
	 * @param int                $views     Total views.
	 * @return array<int, array<string, mixed>>
	 */
	private static function block_rows( int $page_id, array $per_block, int $clicks, int $views ): array {
		$doc  = PageStore::get_document( $page_id ) ?? [];
		$rows = [];
		$seen = [];
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $block ) {
			if ( ! is_array( $block ) || ! in_array( $block['type'] ?? '', ClickRedirect::TYPES, true ) ) {
				continue;
			}
			$id          = (string) $block['id'];
			$seen[ $id ] = true;
			$n           = $per_block[ $id ] ?? 0;
			$rows[]      = self::block_row( $id, self::block_title( $block ), (string) $block['type'], $n, $clicks, $views );
		}
		foreach ( $per_block as $id => $n ) {
			if ( '' === (string) $id || isset( $seen[ (string) $id ] ) ) {
				continue;
			}
			$rows[] = self::block_row( (string) $id, __( '(removed block)', 'crazylinks' ), 'unknown', $n, $clicks, $views );
		}
		usort(
			$rows,
			static fn( $a, $b ) => $b['clicks'] <=> $a['clicks']
		);
		return $rows;
	}

	/**
	 * One block row. `ctr` is clicks per page view (kept for the older summary shape).
	 *
	 * @param string $id     Block id.
	 * @param string $title  Title.
	 * @param string $type   Type.
	 * @param int    $n      Clicks.
	 * @param int    $clicks Total clicks.
	 * @param int    $views  Total views.
	 * @return array<string, mixed>
	 */
	private static function block_row( string $id, string $title, string $type, int $n, int $clicks, int $views ): array {
		return [
			'block_id' => $id,
			'title'    => $title,
			'type'     => $type,
			'clicks'   => $n,
			'share'    => $clicks > 0 ? round( $n / $clicks, 4 ) : 0.0,
			'ctr'      => $views > 0 ? round( $n / $views, 4 ) : null,
		];
	}

	/**
	 * A human label for a clickable block.
	 *
	 * @param array<string, mixed> $block Block.
	 */
	private static function block_title( array $block ): string {
		$props = (array) ( $block['props'] ?? [] );
		foreach ( [ 'title', 'alt', 'label' ] as $key ) {
			if ( ! empty( $props[ $key ] ) && is_string( $props[ $key ] ) ) {
				return $props[ $key ];
			}
		}
		if ( ! empty( $props['url'] ) && is_string( $props['url'] ) ) {
			return $props['url'];
		}
		return ucfirst( str_replace( '_', ' ', (string) $block['type'] ) );
	}

	/**
	 * Device, OS, in-app and referrer breakdowns of page views (raw events only).
	 *
	 * @param int    $page_id Page id.
	 * @param string $from    Y-m-d.
	 * @param string $to      Y-m-d.
	 * @return array<string, mixed>
	 */
	private static function breakdowns( int $page_id, string $from, string $to ): array {
		global $wpdb;
		$start = $from . ' 00:00:00';
		$end   = gmdate( 'Y-m-d', (int) strtotime( $to . ' UTC +1 day' ) ) . ' 00:00:00';
		$out   = [
			'device'   => [
				'mobile'  => 0,
				'tablet'  => 0,
				'desktop' => 0,
			],
			'os'       => [],
			'in_app'   => [],
			'referrer' => [],
		];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cols = [
			'device' => 'device',
			'os'     => "COALESCE(os, 'other')",
			'in_app' => "COALESCE(in_app, 'none')",
		];
		foreach ( $cols as $key => $expr ) {
			foreach ( (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$expr} AS k, COUNT(*) AS n FROM {$wpdb->prefix}crazylinks_events WHERE page_id = %d AND type = 'view' AND ts >= %s AND ts < %s GROUP BY k ORDER BY n DESC",
					$page_id,
					$start,
					$end
				),
				ARRAY_A
			) as $r ) {
				$out[ $key ][ (string) $r['k'] ] = (int) $r['n'];
			}
		}
		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(referrer_host, '') AS k, COUNT(*) AS n FROM {$wpdb->prefix}crazylinks_events WHERE page_id = %d AND type = 'view' AND ts >= %s AND ts < %s GROUP BY k ORDER BY n DESC LIMIT 10",
				$page_id,
				$start,
				$end
			),
			ARRAY_A
		) as $r ) {
			$out['referrer'][] = [
				'host'  => '' === $r['k'] ? '(direct)' : (string) $r['k'],
				'count' => (int) $r['n'],
			];
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Day of the oldest raw event for the page, or null (breakdowns only cover days from here on).
	 *
	 * @param int $page_id Page id.
	 */
	private static function first_event_day( int $page_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ts = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(ts) FROM {$wpdb->prefix}crazylinks_events WHERE page_id = %d", $page_id ) );
		return $ts ? substr( (string) $ts, 0, 10 ) : null;
	}

	/**
	 * Views and clicks over the last 30 days (UTC, including today) for every page, cached in a
	 * transient that the rollup clears (and that expires after 15 minutes so today's numbers move).
	 *
	 * @return array<int, array{views:int, clicks:int}>
	 */
	public static function page_totals(): array {
		$cached = get_transient( self::TOTALS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$totals = [];
		foreach ( self::rows( null, gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ), gmdate( 'Y-m-d' ) ) as $r ) {
			$totals[ $r['page_id'] ] ??= [
				'views'  => 0,
				'clicks' => 0,
			];
			if ( 'view' === $r['type'] ) {
				$totals[ $r['page_id'] ]['views'] += $r['count'];
			} else {
				$totals[ $r['page_id'] ]['clicks'] += $r['count'];
			}
		}
		set_transient( self::TOTALS_TRANSIENT, $totals, 15 * MINUTE_IN_SECONDS );
		return $totals;
	}

	/**
	 * Clears the cached per-page totals (called by the rollup).
	 */
	public static function flush_page_totals(): void {
		delete_transient( self::TOTALS_TRANSIENT );
	}

	/**
	 * Summary for a page over the last N days (including today), UTC.
	 *
	 * @param int $page_id Page id.
	 * @param int $days    Days.
	 * @return array<string, mixed>
	 */
	public static function for_page( int $page_id, int $days ): array {
		global $wpdb;
		$days  = max( 1, min( 3650, $days ) );
		$today = gmdate( 'Y-m-d' );
		$from  = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rolled = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT block_id, day, type, count, uniques FROM {$wpdb->prefix}crazylinks_daily WHERE page_id = %d AND day >= %s AND day < %s",
				$page_id,
				$from,
				$today
			),
			ARRAY_A
		);
		$live   = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(block_id, '') AS block_id, DATE(ts) AS day, type, COUNT(*) AS count, COUNT(DISTINCT visitor_hash) AS uniques
				FROM {$wpdb->prefix}crazylinks_events WHERE page_id = %d AND ts >= %s GROUP BY COALESCE(block_id, ''), DATE(ts), type",
				$page_id,
				$today . ' 00:00:00'
			),
			ARRAY_A
		);
		// phpcs:enable

		$totals = [
			'view'  => 0,
			'click' => 0,
		];
		$uniq   = 0;
		$daily  = [];
		$blocks = [];
		foreach ( array_merge( $rolled, $live ) as $row ) {
			$type  = (string) $row['type'];
			$count = (int) $row['count'];
			$day   = (string) $row['day'];
			if ( ! isset( $daily[ $day ] ) ) {
				$daily[ $day ] = [
					'day'    => $day,
					'views'  => 0,
					'clicks' => 0,
				];
			}
			if ( 'view' === $type ) {
				$totals['view']         += $count;
				$uniq                   += (int) $row['uniques'];
				$daily[ $day ]['views'] += $count;
			} elseif ( 'click' === $type ) {
				$totals['click']         += $count;
				$daily[ $day ]['clicks'] += $count;
				$bid                      = (string) $row['block_id'];
				$blocks[ $bid ]           = ( $blocks[ $bid ] ?? 0 ) + $count;
			}
		}
		ksort( $daily );
		arsort( $blocks );

		$doc        = PageStore::get_document( $page_id ) ?? [];
		$block_rows = [];
		foreach ( $blocks as $bid => $count ) {
			$block        = PageStore::find_block( $doc, (string) $bid );
			$block_rows[] = [
				'block_id' => (string) $bid,
				'title'    => (string) ( $block['props']['title'] ?? $block['props']['alt'] ?? '' ),
				'clicks'   => $count,
				'ctr'      => $totals['view'] > 0 ? round( $count / $totals['view'], 4 ) : null,
			];
		}

		return [
			'page_id' => $page_id,
			'range'   => [
				'from' => $from,
				'to'   => $today,
				'days' => $days,
			],
			'views'   => $totals['view'],
			'uniques' => $uniq,
			'clicks'  => $totals['click'],
			'ctr'     => $totals['view'] > 0 ? round( $totals['click'] / $totals['view'], 4 ) : null,
			'blocks'  => $block_rows,
			'daily'   => array_values( $daily ),
		];
	}
}
