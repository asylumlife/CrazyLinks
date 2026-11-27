<?php
/**
 * Activity: what happened on a page, in order.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Reads raw events as visits (one visitor's views and clicks on one UTC day, in time order) and
 * as counts per hour of the day. Raw events only, so the retention window applies.
 *
 * A visit is found through the visitor hash, which changes every day and is not a cookie: the
 * same person on two days is two visits, and the hash itself never leaves this class.
 */
final class Activity {

	public const PER_PAGE = 50;

	/**
	 * Most rows an activity CSV will hold.
	 */
	public const CSV_LIMIT = 50000;

	/**
	 * UTC datetime bounds of an inclusive Y-m-d range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array{0:string, 1:string}
	 */
	private static function bounds( string $from, string $to ): array {
		return [ $from . ' 00:00:00', gmdate( 'Y-m-d', (int) strtotime( $to . ' UTC +1 day' ) ) . ' 00:00:00' ];
	}

	/**
	 * One page of visits, the most recently active first.
	 *
	 * @param int                   $page_id  Page id.
	 * @param string                $from     Y-m-d (UTC).
	 * @param string                $to       Y-m-d (UTC).
	 * @param array<string, string> $titles   Link titles from Summary::link_titles().
	 * @param int                   $page     1-based page of visits.
	 * @param int                   $per_page Visits per page.
	 * @return array{total:int, page:int, pages:int, per_page:int, visits:array<int, array{started:int, source:string, label:string, device:string, os:string, views:int, clicks:int, events:array<int, array{ts:int, type:string, title:string}>}>}
	 */
	public static function visits( int $page_id, string $from, string $to, array $titles = [], int $page = 1, int $per_page = self::PER_PAGE ): array {
		global $wpdb;
		[ $start, $end ] = self::bounds( $from, $to );
		$events          = $wpdb->prefix . 'crazylinks_events';
		$per_page        = max( 1, min( 500, $per_page ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT 1 FROM {$events} WHERE page_id = %d AND type IN ('view', 'click') AND ts >= %s AND ts < %s GROUP BY visitor_hash, DATE(ts) ) t",
				$page_id,
				$start,
				$end
			)
		);
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = max( 1, min( $pages, $page ) );
		$out   = [
			'total'    => $total,
			'page'     => $page,
			'pages'    => $pages,
			'per_page' => $per_page,
			'visits'   => [],
		];
		if ( 0 === $total ) {
			return $out;
		}
		$keys = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT visitor_hash AS h, DATE(ts) AS d, MAX(ts) AS last_ts, MAX(id) AS last_id FROM {$events}
				WHERE page_id = %d AND type IN ('view', 'click') AND ts >= %s AND ts < %s
				GROUP BY visitor_hash, DATE(ts) ORDER BY last_ts DESC, last_id DESC LIMIT %d OFFSET %d",
				$page_id,
				$start,
				$end,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		if ( ! $keys ) {
			return $out;
		}
		$order  = [];
		$hashes = [];
		$days   = [];
		foreach ( $keys as $k ) {
			$order[ $k['h'] . '|' . $k['d'] ] = [];
			$hashes[ (string) $k['h'] ]       = true;
			$days[]                           = (string) $k['d'];
		}
		$hashes = array_keys( $hashes );
		$rows   = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, visitor_hash, ts, type, COALESCE(block_id, '') AS block_id, COALESCE(item_id, '') AS item_id, device, COALESCE(os, '') AS os,
					COALESCE(in_app, '') AS in_app, COALESCE(referrer_host, '') AS host, COALESCE(utm_source, '') AS utm
				FROM {$events} WHERE page_id = %d AND type IN ('view', 'click') AND ts >= %s AND ts < %s
					AND visitor_hash IN (" . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ')
				ORDER BY ts ASC, id ASC',
				array_merge(
					[ $page_id, max( $start, min( $days ) . ' 00:00:00' ), min( $end, gmdate( 'Y-m-d', (int) strtotime( max( $days ) . ' UTC +1 day' ) ) . ' 00:00:00' ) ],
					$hashes
				)
			),
			ARRAY_A
		);
		// phpcs:enable
		foreach ( $rows as $r ) {
			$key = $r['visitor_hash'] . '|' . substr( (string) $r['ts'], 0, 10 );
			if ( isset( $order[ $key ] ) ) {
				$order[ $key ][] = $r;
			}
		}
		foreach ( $order as $list ) {
			if ( $list ) {
				$out['visits'][] = self::visit( $list, $titles );
			}
		}
		return $out;
	}

	/**
	 * Shapes one visit from its events (oldest first).
	 *
	 * @param array<int, array<string, mixed>> $rows   Event rows.
	 * @param array<string, string>            $titles Link titles.
	 * @return array{started:int, source:string, label:string, device:string, os:string, views:int, clicks:int, events:array<int, array{ts:int, type:string, title:string}>}
	 */
	private static function visit( array $rows, array $titles ): array {
		$view = null;
		foreach ( $rows as $r ) {
			if ( 'view' === $r['type'] ) {
				$view = $r;
				break;
			}
		}
		$first           = $rows[0];
		[ $key, $label ] = $view
			? Summary::source_name( (string) $view['utm'], (string) $view['in_app'], (string) $view['host'], true )
			: Summary::source_name( '', (string) $first['in_app'], '', false );
		$events          = [];
		$views           = 0;
		$clicks          = 0;
		foreach ( $rows as $r ) {
			$is_view  = 'view' === $r['type'];
			$views   += $is_view ? 1 : 0;
			$clicks  += $is_view ? 0 : 1;
			$events[] = [
				'ts'    => (int) strtotime( $r['ts'] . ' UTC' ),
				'type'  => (string) $r['type'],
				'title' => $is_view ? '' : self::title( (string) $r['block_id'], (string) $r['item_id'], $titles ),
			];
		}
		return [
			'started' => $events[0]['ts'],
			'source'  => $key,
			'label'   => $label,
			'device'  => (string) $first['device'],
			'os'      => (string) $first['os'],
			'views'   => $views,
			'clicks'  => $clicks,
			'events'  => $events,
		];
	}

	/**
	 * The name of what was clicked.
	 *
	 * @param string                $block_id Block id.
	 * @param string                $item_id  Item id, or ''.
	 * @param array<string, string> $titles   Link titles.
	 */
	private static function title( string $block_id, string $item_id, array $titles ): string {
		return $titles[ $block_id . '|' . $item_id ] ?? $titles[ $block_id . '|' ] ?? __( '(removed block)', 'crazylinks' );
	}

	/**
	 * Views and clicks per hour of the day (0-23) in the given timezone, summed over the range.
	 *
	 * @param int           $page_id Page id.
	 * @param string        $from    Y-m-d (UTC).
	 * @param string        $to      Y-m-d (UTC).
	 * @param \DateTimeZone $tz      Timezone the hours are shown in.
	 * @return array<int, array{hour:int, views:int, clicks:int}>
	 */
	public static function hours( int $page_id, string $from, string $to, \DateTimeZone $tz ): array {
		global $wpdb;
		[ $start, $end ] = self::bounds( $from, $to );
		$out             = [];
		for ( $h = 0; $h < 24; $h++ ) {
			$out[ $h ] = [
				'hour'   => $h,
				'views'  => 0,
				'clicks' => 0,
			];
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(ts, '%%Y-%%m-%%d %%H:00:00') AS h, type, COUNT(*) AS n FROM {$wpdb->prefix}crazylinks_events
				WHERE page_id = %d AND type IN ('view', 'click') AND ts >= %s AND ts < %s GROUP BY h, type",
				$page_id,
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable
		$utc = new \DateTimeZone( 'UTC' );
		foreach ( $rows as $r ) {
			$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $r['h'], $utc );
			if ( ! $dt ) {
				continue;
			}
			$hour = (int) $dt->setTimezone( $tz )->format( 'G' );
			$out[ $hour ][ 'view' === $r['type'] ? 'views' : 'clicks' ] += (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Every event of the range as flat rows for the CSV, newest first, at most CSV_LIMIT. Each row
	 * carries its visit's number (1 = the most recently active visit) and source.
	 *
	 * @param int                   $page_id Page id.
	 * @param string                $from    Y-m-d (UTC).
	 * @param string                $to      Y-m-d (UTC).
	 * @param array<string, string> $titles  Link titles.
	 * @return array<int, array{ts:int, visit:int, source:string, device:string, os:string, type:string, title:string}>
	 */
	public static function rows( int $page_id, string $from, string $to, array $titles = [] ): array {
		$out   = [];
		$n     = 0;
		$page  = 1;
		$pages = 1;
		do {
			$batch = self::visits( $page_id, $from, $to, $titles, $page, 500 );
			$pages = $batch['pages'];
			foreach ( $batch['visits'] as $v ) {
				++$n;
				foreach ( array_reverse( $v['events'] ) as $e ) {
					if ( count( $out ) >= self::CSV_LIMIT ) {
						return $out;
					}
					$out[] = [
						'ts'     => $e['ts'],
						'visit'  => $n,
						'source' => $v['label'],
						'device' => $v['device'],
						'os'     => $v['os'],
						'type'   => $e['type'],
						'title'  => $e['title'],
					];
				}
			}
			++$page;
		} while ( $page <= $pages );
		return $out;
	}
}
