<?php
/**
 * Activity: visits in order and hour-of-day counts.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Admin\AnalyticsPage;
use CrazyLinks\Analytics\Activity;
use CrazyLinks\Analytics\Summary;

/**
 * Raw events read back as visits.
 */
class ActivityTest extends TestCase {

	private const A = '01HZZZZZZZZZZZZZZZZZZZZZZA';
	private const B = '01HZZZZZZZZZZZZZZZZZZZZZZB';

	private static function day( int $ago ): string {
		return gmdate( 'Y-m-d', time() - $ago * DAY_IN_SECONDS );
	}

	private function event( int $page_id, string $type, string $visitor, string $ts, array $extra = [] ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'crazylinks_events',
			array_merge(
				[
					'page_id'      => $page_id,
					'type'         => $type,
					'ts'           => $ts,
					'visitor_hash' => $visitor,
					'device'       => 'mobile',
					'os'           => 'iOS',
				],
				$extra
			)
		);
	}

	private function seeded(): int {
		$id = (int) $this->make_page( $this->doc() )->ID;
		$d1 = self::day( 1 );
		$d2 = self::day( 2 );
		// Two days ago: one Instagram visit with two clicks.
		$this->event( $id, 'view', 'ig', "$d2 15:00:00", [ 'in_app' => 'instagram' ] );
		$this->event( $id, 'click', 'ig', "$d2 15:00:20", [ 'block_id' => self::B, 'in_app' => 'instagram' ] );
		$this->event( $id, 'click', 'ig', "$d2 15:00:40", [ 'block_id' => self::A, 'in_app' => 'instagram' ] );
		// Yesterday: the same hash is a new visit; a look-only visit; a click with no view.
		$this->event( $id, 'view', 'ig', "$d1 09:00:00", [ 'utm_source' => 'newsletter' ] );
		$this->event( $id, 'view', 'look', "$d1 10:00:00", [ 'referrer_host' => 'www.google.com', 'device' => 'desktop', 'os' => 'macOS' ] );
		$this->event( $id, 'click', 'ghost', "$d1 11:00:00", [ 'block_id' => self::A ] );
		// Another page with the same visitor must stay out.
		$other = (int) $this->make_page( $this->doc(), 'other' )->ID;
		$this->event( $other, 'click', 'look', "$d1 10:00:05", [ 'block_id' => self::A ] );
		return $id;
	}

	public function test_visits_are_listed_newest_first_with_their_steps_in_order(): void {
		$id     = $this->seeded();
		$titles = Summary::link_titles( Summary::report( $id, self::day( 3 ), self::day( 0 ) )['blocks'] );
		$data   = Activity::visits( $id, self::day( 3 ), self::day( 0 ), $titles );

		$this->assertSame( [ 4, 1, 1 ], [ $data['total'], $data['page'], $data['pages'] ] );
		$this->assertSame( [ 'unknown', 'google', 'utm:newsletter', 'instagram' ], array_column( $data['visits'], 'source' ) );
		$ig = $data['visits'][3];
		$this->assertSame( [ 'Instagram', 'mobile', 'iOS', 1, 2 ], [ $ig['label'], $ig['device'], $ig['os'], $ig['views'], $ig['clicks'] ] );
		$this->assertSame(
			[ [ 'view', '' ], [ 'click', 'Two' ], [ 'click', 'One' ] ],
			array_map( static fn( $e ) => [ $e['type'], $e['title'] ], $ig['events'] )
		);
		$this->assertSame( strtotime( self::day( 2 ) . ' 15:00:00 UTC' ), $ig['started'] );
		$this->assertSame( [ 'desktop', 'macOS', 0 ], [ $data['visits'][1]['device'], $data['visits'][1]['os'], $data['visits'][1]['clicks'] ] );
		$this->assertSame( 'One', $data['visits'][0]['events'][0]['title'] );
		foreach ( $data['visits'] as $v ) {
			$this->assertArrayNotHasKey( 'visitor_hash', $v );
		}
	}

	public function test_paging_range_and_empty_page(): void {
		$id   = $this->seeded();
		$data = Activity::visits( $id, self::day( 3 ), self::day( 0 ), [], 2, 3 );
		$this->assertSame( [ 4, 2, 2 ], [ $data['total'], $data['page'], $data['pages'] ] );
		$this->assertSame( [ 'instagram' ], array_column( $data['visits'], 'source' ) );
		$this->assertSame( '(removed block)', $data['visits'][0]['events'][1]['title'], 'No titles given' );
		// Past the last page clamps to it; a range with nothing is empty, not an error.
		$this->assertSame( 2, Activity::visits( $id, self::day( 3 ), self::day( 0 ), [], 9, 3 )['page'] );
		$this->assertSame( 3, Activity::visits( $id, self::day( 1 ), self::day( 0 ) )['total'] );
		$empty = Activity::visits( $id, self::day( 0 ), self::day( 0 ) );
		$this->assertSame( [ 0, [] ], [ $empty['total'], $empty['visits'] ] );
	}

	public function test_hours_are_counted_in_the_given_timezone(): void {
		$id    = $this->seeded();
		$utc   = Activity::hours( $id, self::day( 3 ), self::day( 0 ), new \DateTimeZone( 'UTC' ) );
		$this->assertCount( 24, $utc );
		$this->assertSame( [ 'hour' => 15, 'views' => 1, 'clicks' => 2 ], $utc[15] );
		$this->assertSame( [ 1, 1, 0 ], [ $utc[9]['views'], $utc[10]['views'], $utc[11]['views'] ] );
		$this->assertSame( 1, $utc[11]['clicks'] );
		$this->assertSame( 3, array_sum( array_column( $utc, 'views' ) ) );
		// Kolkata is UTC+5:30 all year: 15:00 UTC is 20:30 there.
		$ist = Activity::hours( $id, self::day( 3 ), self::day( 0 ), new \DateTimeZone( 'Asia/Kolkata' ) );
		$this->assertSame( [ 1, 2 ], [ $ist[20]['views'], $ist[20]['clicks'] ] );
		$this->assertSame( [ 1, 1, 0 ], [ $ist[14]['views'], $ist[15]['views'], $ist[9]['views'] ] );
	}

	public function test_hours_follow_half_hour_timezones_within_the_hour(): void {
		$id = (int) $this->make_page( $this->doc() )->ID;
		$d1 = self::day( 1 );
		// 15:10 UTC is 20:40 in Kolkata (UTC+5:30); 15:40 UTC is already 21:10 there.
		$this->event( $id, 'view', 'early', "$d1 15:10:00" );
		$this->event( $id, 'view', 'late', "$d1 15:40:00" );
		$hours = Activity::hours( $id, self::day( 2 ), self::day( 0 ), new \DateTimeZone( 'Asia/Kolkata' ) );
		$this->assertSame( [ 1, 1 ], [ $hours[20]['views'], $hours[21]['views'] ] );
	}

	public function test_payment_buttons_are_named_by_their_label_not_their_url(): void {
		$block = static fn( string $id, array $props ): array => [
			'id'    => $id,
			'type'  => 'payment',
			'props' => $props,
		];
		$id    = (int) $this->make_page(
			$this->doc(
				[
					$block( self::A, [ 'service' => 'kofi', 'url' => 'https://ko-fi.com/example' ] ),
					$block( self::B, [ 'service' => 'kofi', 'url' => 'https://ko-fi.com/example', 'label' => 'Tip jar' ] ),
				]
			)
		)->ID;
		$titles = Summary::link_titles( Summary::report( $id, self::day( 1 ), self::day( 0 ) )['blocks'] );
		$this->assertSame( \CrazyLinks\Render\Payments::get( 'kofi' )['label'], $titles[ self::A . '|' ] );
		$this->assertStringNotContainsString( 'http', $titles[ self::A . '|' ] );
		$this->assertSame( 'Tip jar', $titles[ self::B . '|' ] );
	}

	public function test_csv_rows_number_the_visits(): void {
		$id   = $this->seeded();
		$rows = Activity::rows( $id, self::day( 3 ), self::day( 0 ), Summary::link_titles( Summary::report( $id, self::day( 3 ), self::day( 0 ) )['blocks'] ) );
		$this->assertCount( 6, $rows );
		$this->assertSame( [ 1, 2, 3, 4, 4, 4 ], array_column( $rows, 'visit' ) );
		$this->assertSame( [ 'click', 'One', 'Instagram' ], [ $rows[3]['type'], $rows[3]['title'], $rows[3]['source'] ] );
		$this->assertSame( 'view', $rows[5]['type'] );
	}

	public function test_screen_shows_the_activity_and_time_of_day_panels(): void {
		$id = $this->seeded();
		update_option( 'timezone_string', 'UTC' );
		$_GET = [ 'page_id' => $id, 'range' => '7d' ];
		ob_start();
		( new AnalyticsPage() )->render();
		$html = (string) ob_get_clean();
		$_GET = [];
		$this->assertStringContainsString( 'id="cl-activity"', $html );
		$this->assertStringContainsString( 'Time of day', $html );
		$this->assertStringContainsString( 'Clicked <strong>Two</strong>', $html );
		$this->assertStringContainsString( 'Opened the page', $html );
		$this->assertStringContainsString( '3:00:20 pm', $html );
		$this->assertStringContainsString( 'Visits 1 to 4 of 4', $html );
		$this->assertStringContainsString( 'which=activity', $html );
		$this->assertStringNotContainsString( 'ghost', $html, 'Visitor hashes are never printed' );
	}
}
