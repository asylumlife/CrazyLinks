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
	 * Most visits one call to visits() returns.
	 */
	private const MAX_PER_PAGE = 500;

	/**
	 * Hour-of-day counts are read in buckets this many minutes long: every timezone offset is a
	 * multiple of it, so a bucket never straddles two local hours.
	 */
	private const HOUR_BUCKET_MINUTES = 15;

	/**
	 * Most rows an activity CSV will hold.
	 */
	public const CSV_LIMIT = 50000;

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
		[ $start, $end ] = Summary::day_bounds( $from, $to );
		$events          = Summary::events_table();
		$per_page        = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the list is built per request.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT 1 FROM %i WHERE page_id = %d AND type IN ('view', 'click') AND ts >= %s AND ts < %s GROUP BY visitor_hash, DATE(ts) ) t",
				$events,
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
		$visit_keys = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT visitor_hash, DATE(ts) AS day, MAX(ts) AS last_ts, MAX(id) AS last_id FROM %i
				WHERE page_id = %d AND type IN ('view', 'click') AND ts >= %s AND ts < %s
				GROUP BY visitor_hash, DATE(ts) ORDER BY last_ts DESC, last_id DESC LIMIT %d OFFSET %d",
				$events,
				$page_id,
				$start,
				$end,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		if ( ! $visit_keys ) {
			return $out;
		}
		// Events per visit, keyed "hash|day" in the order the visits are listed.
		$visits = [];
		$hashes = [];
		$days   = [];
		foreach ( $visit_keys as $visit_key ) {
			$visits[ $visit_key['visitor_hash'] . '|' . $visit_key['day'] ] = [];
			$hashes[ (string) $visit_key['visitor_hash'] ]                  = true;
			$days[] = (string) $visit_key['day'];
		}
		$hashes = array_keys( $hashes );
		// Only the days on this page of visits need reading, within the requested range.
		[ $first, $last ] = Summary::day_bounds( min( $days ), max( $days ) );
		$rows             = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, visitor_hash, ts, type, COALESCE(block_id, '') AS block_id, COALESCE(item_id, '') AS item_id, device, COALESCE(os, '') AS os,
					COALESCE(in_app, '') AS in_app, COALESCE(referrer_host, '') AS host, COALESCE(utm_source, '') AS utm
				FROM %i WHERE page_id = %d AND type IN ('view', 'click') AND ts >= %s AND ts < %s
					AND visitor_hash IN (" . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ')
				ORDER BY ts ASC, id ASC',
				array_merge( [ $events, $page_id, max( $start, $first ), min( $end, $last ) ], $hashes )
			),
			ARRAY_A
		);
		// phpcs:enable
		foreach ( $rows as $row ) {
			$key = $row['visitor_hash'] . '|' . substr( (string) $row['ts'], 0, 10 );
			if ( isset( $visits[ $key ] ) ) {
				$visits[ $key ][] = $row;
			}
		}
		foreach ( $visits as $events_of_visit ) {
			if ( $events_of_visit ) {
				$out['visits'][] = self::visit( $events_of_visit, $titles );
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
		foreach ( $rows as $row ) {
			if ( 'view' === $row['type'] ) {
				$view = $row;
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
		foreach ( $rows as $row ) {
			$is_view  = 'view' === $row['type'];
			$views   += $is_view ? 1 : 0;
			$clicks  += $is_view ? 0 : 1;
			$events[] = [
				'ts'    => (int) strtotime( $row['ts'] . ' UTC' ),
				'type'  => (string) $row['type'],
				'title' => $is_view ? '' : Summary::link_title( $titles, (string) $row['block_id'], (string) $row['item_id'] ),
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
		[ $start, $end ] = Summary::day_bounds( $from, $to );
		$hours           = [];
		for ( $hour = 0; $hour < 24; $hour++ ) {
			$hours[ $hour ] = [
				'hour'   => $hour,
				'views'  => 0,
				'clicks' => 0,
			];
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the chart is built per request.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(ts) AS event_day, HOUR(ts) AS event_hour, FLOOR(MINUTE(ts) / %d) AS bucket, type, COUNT(*) AS n FROM %i
				WHERE page_id = %d AND type IN ('view', 'click') AND ts >= %s AND ts < %s GROUP BY event_day, event_hour, bucket, type",
				self::HOUR_BUCKET_MINUTES,
				Summary::events_table(),
				$page_id,
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable
		$utc = new \DateTimeZone( 'UTC' );
		foreach ( $rows as $row ) {
			$bucket_start = sprintf( '%s %02d:%02d:00', $row['event_day'], (int) $row['event_hour'], (int) $row['bucket'] * self::HOUR_BUCKET_MINUTES );
			$dt           = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $bucket_start, $utc );
			if ( ! $dt ) {
				continue;
			}
			$local_hour = (int) $dt->setTimezone( $tz )->format( 'G' );
			$hours[ $local_hour ][ 'view' === $row['type'] ? 'views' : 'clicks' ] += (int) $row['n'];
		}
		return $hours;
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
		$visit = 0;
		$page  = 1;
		do {
			$batch = self::visits( $page_id, $from, $to, $titles, $page, self::MAX_PER_PAGE );
			foreach ( $batch['visits'] as $visit_data ) {
				++$visit;
				foreach ( array_reverse( $visit_data['events'] ) as $event ) {
					if ( count( $out ) >= self::CSV_LIMIT ) {
						return $out;
					}
					$out[] = [
						'ts'     => $event['ts'],
						'visit'  => $visit,
						'source' => $visit_data['label'],
						'device' => $visit_data['device'],
						'os'     => $visit_data['os'],
						'type'   => $event['type'],
						'title'  => $event['title'],
					];
				}
			}
			++$page;
		} while ( $page <= $batch['pages'] );
		return $out;
	}
}
