<?php
/**
 * Weekly schedule windows: evaluator, renderer, /go/, reachable_block and page-cache policy.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Analytics\ClickRedirect;
use CrazyLinks\Analytics\Visitor;
use CrazyLinks\Frontend\TemplateLoader;
use CrazyLinks\PageStore;
use CrazyLinks\Render\RenderContext;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Render\Schedule;
use CrazyLinks\Rest\RestRoutes;
use CrazyLinks\Schema\PageValidator;

/**
 * Schedule::* in America/Chicago (DST ends 2026-11-01, starts 2026-03-08), plus integration.
 */
class WeeklyScheduleTest extends TestCase {

	private const LINK  = '01HZZZZZZZZZZZZZZZZZZZZZWA';
	private const GROUP = '01HZZZZZZZZZZZZZZZZZZZZZWG';
	private const CHILD = '01HZZZZZZZZZZZZZZZZZZZZZWC';
	private const FORM  = '01HZZZZZZZZZZZZZZZZZZZZZWF';

	private \DateTimeZone $tz;

	public function set_up(): void {
		parent::set_up();
		$this->tz = new \DateTimeZone( 'America/Chicago' );
		update_option( 'timezone_string', 'America/Chicago' );
		update_option( 'gmt_offset', '' );
	}

	public function tear_down(): void {
		delete_option( 'timezone_string' );
		parent::tear_down();
	}

	/** Unix time of a Chicago wall-clock time. */
	private function local( string $local ): int {
		return ( new \DateTimeImmutable( $local, $this->tz ) )->getTimestamp();
	}

	/**
	 * @param int[] $days Days.
	 * @return array<string, mixed>
	 */
	private function weekly( array $days, string $start, string $end, ?string $from = null, ?string $to = null ): array {
		return [
			'start'  => $from,
			'end'    => $to,
			'weekly' => [
				'days'  => $days,
				'start' => $start,
				'end'   => $end,
			],
		];
	}

	public function test_minutes_and_normalisation(): void {
		$this->assertSame( 750, Schedule::minutes( '12:30' ) );
		$this->assertNull( Schedule::minutes( '24:00' ) );
		$this->assertNull( Schedule::minutes( '9:00' ) );
		$this->assertNull( Schedule::weekly( [ 'weekly' => null ] ) );
		$this->assertNull( Schedule::weekly( [ 'weekly' => [ 'days' => [], 'start' => '09:00', 'end' => '10:00' ] ] ) );
		$this->assertSame( [ 1, 4 ], Schedule::weekly( $this->weekly( [ 4, 1, 4 ], '09:00', '10:00' ) )['days'] );
		$this->assertFalse( Schedule::is_set( [ 'start' => null, 'end' => null ] ) );
		$this->assertTrue( Schedule::is_set( $this->weekly( [ 1 ], '09:00', '10:00' ) ) );
	}

	public function test_mon_to_thu_lunch_window_across_weekday_boundaries(): void {
		$s = $this->weekly( [ 1, 2, 3, 4 ], '12:30', '14:00' );
		$this->assertTrue( Schedule::is_active( $s, $this->local( '2026-11-05 13:00' ), $this->tz ), 'Thursday 13:00' );
		$this->assertTrue( Schedule::is_active( $s, $this->local( '2026-11-05 12:30' ), $this->tz ), 'start is inclusive' );
		$this->assertFalse( Schedule::is_active( $s, $this->local( '2026-11-05 12:29' ), $this->tz ) );
		$this->assertFalse( Schedule::is_active( $s, $this->local( '2026-11-05 14:00' ), $this->tz ), 'end is exclusive' );
		$this->assertFalse( Schedule::is_active( $s, $this->local( '2026-11-06 13:00' ), $this->tz ), 'Friday' );
		$this->assertFalse( Schedule::is_active( $s, $this->local( '2026-11-08 13:00' ), $this->tz ), 'Sunday' );
		$this->assertTrue( Schedule::is_active( $s, $this->local( '2026-11-09 12:45' ), $this->tz ), 'Monday' );

		$this->assertSame( $this->local( '2026-11-05 14:00' ), Schedule::next_boundary( $s, $this->local( '2026-11-05 13:00' ), $this->tz ) );
		$this->assertSame( $this->local( '2026-11-09 12:30' ), Schedule::next_boundary( $s, $this->local( '2026-11-05 15:00' ), $this->tz ), 'Thursday evening → Monday' );
		$this->assertSame( $this->local( '2026-11-05 12:30' ), Schedule::next_boundary( $s, $this->local( '2026-11-05 08:00' ), $this->tz ) );
		$this->assertSame( $this->local( '2026-11-05 14:00' ), Schedule::closes_at( $s, $this->local( '2026-11-05 13:00' ), $this->tz ) );
	}

	public function test_window_past_midnight_belongs_to_its_start_day(): void {
		$s = $this->weekly( [ 5 ], '22:00', '01:00' ); // Friday night.
		$this->assertTrue( Schedule::is_active( $s, $this->local( '2026-11-06 23:30' ), $this->tz ), 'Friday 23:30' );
		$this->assertTrue( Schedule::is_active( $s, $this->local( '2026-11-07 00:30' ), $this->tz ), 'Saturday 00:30' );
		$this->assertFalse( Schedule::is_active( $s, $this->local( '2026-11-07 01:00' ), $this->tz ) );
		$this->assertFalse( Schedule::is_active( $s, $this->local( '2026-11-06 00:30' ), $this->tz ), 'Friday 00:30 belongs to Thursday' );
		$this->assertFalse( Schedule::is_active( $s, $this->local( '2026-11-05 23:30' ), $this->tz ) );
		$this->assertSame( $this->local( '2026-11-07 01:00' ), Schedule::closes_at( $s, $this->local( '2026-11-06 23:30' ), $this->tz ) );
		$this->assertSame( $this->local( '2026-11-06 22:00' ), Schedule::next_boundary( $s, $this->local( '2026-11-06 12:00' ), $this->tz ) );
	}

	public function test_full_days_and_back_to_back_windows(): void {
		$s = $this->weekly( [ 1, 2 ], '00:00', '00:00' );
		$this->assertTrue( Schedule::is_active( $s, $this->local( '2026-11-09 00:00' ), $this->tz ) );
		$this->assertTrue( Schedule::is_active( $s, $this->local( '2026-11-10 23:59' ), $this->tz ) );
		$this->assertFalse( Schedule::is_active( $s, $this->local( '2026-11-11 00:00' ), $this->tz ) );
		$this->assertSame( $this->local( '2026-11-11 00:00' ), Schedule::closes_at( $s, $this->local( '2026-11-09 10:00' ), $this->tz ), 'Monday and Tuesday close together' );
	}

	public function test_dst_change_days_follow_wall_clock_time(): void {
		// Fall back (2026-11-01, Sunday): 00:30 CDT to 03:00 CST is 3.5 real hours.
		$s   = $this->weekly( [ 7 ], '00:30', '03:00' );
		$occ = Schedule::occurrences( Schedule::weekly( $s ), $this->local( '2026-11-01 12:00' ), $this->local( '2026-11-01 12:00' ), $this->tz );
		$this->assertContains( [ strtotime( '2026-11-01T05:30:00Z' ), strtotime( '2026-11-01T09:00:00Z' ) ], $occ );
		$this->assertTrue( Schedule::is_active( $s, strtotime( '2026-11-01T08:30:00Z' ), $this->tz ), '02:30 CST, after the repeated hour' );

		// The lunch window stays at 12:30 local on both sides of the change.
		$lunch = $this->weekly( [ 1, 2, 3, 4, 5, 6, 7 ], '12:30', '14:00' );
		$this->assertTrue( Schedule::is_active( $lunch, strtotime( '2026-10-31T17:45:00Z' ), $this->tz ), 'Saturday 12:45 CDT' );
		$this->assertTrue( Schedule::is_active( $lunch, strtotime( '2026-11-01T18:45:00Z' ), $this->tz ), 'Sunday 12:45 CST' );
		$this->assertFalse( Schedule::is_active( $lunch, strtotime( '2026-11-01T17:45:00Z' ), $this->tz ), 'Sunday 11:45 CST' );

		// Spring forward (2026-03-08): 02:30 does not exist and starts at 03:30 CDT.
		$gap = $this->weekly( [ 7 ], '02:30', '04:00' );
		$this->assertSame( strtotime( '2026-03-08T08:30:00Z' ), Schedule::next_boundary( $gap, strtotime( '2026-03-08T06:00:00Z' ), $this->tz ) );
		$this->assertFalse( Schedule::is_active( $gap, strtotime( '2026-03-08T08:15:00Z' ), $this->tz ) );
		$this->assertTrue( Schedule::is_active( $gap, strtotime( '2026-03-08T08:45:00Z' ), $this->tz ) );
	}

	public function test_one_off_range_and_weekly_window_must_both_hold(): void {
		$s = $this->weekly( [ 1, 2, 3, 4 ], '12:30', '14:00', '2026-11-09T00:00:00-06:00', '2026-11-20T00:00:00-06:00' );
		$this->assertFalse( Schedule::is_active( $s, $this->local( '2026-11-05 13:00' ), $this->tz ), 'In the window, before the range' );
		$this->assertTrue( Schedule::is_active( $s, $this->local( '2026-11-10 13:00' ), $this->tz ) );
		$this->assertFalse( Schedule::is_active( $s, $this->local( '2026-11-10 15:00' ), $this->tz ), 'In the range, outside the window' );
		$this->assertFalse( Schedule::is_active( $s, $this->local( '2026-11-23 13:00' ), $this->tz ), 'After the range' );
		$this->assertSame( $this->local( '2026-11-09 00:00' ), Schedule::next_boundary( $s, $this->local( '2026-11-05 13:00' ), $this->tz ) );
		$this->assertSame( $this->local( '2026-11-19 12:30' ), Schedule::next_boundary( $s, $this->local( '2026-11-18 15:00' ), $this->tz ) );
		$this->assertNull( Schedule::next_boundary( $s, $this->local( '2026-11-23 13:00' ), $this->tz ), 'Range over: never changes again' );
		// One-off end before the window closes wins.
		$short = $this->weekly( [ 4 ], '12:30', '14:00', null, '2026-11-05T13:15:00-06:00' );
		$this->assertSame( $this->local( '2026-11-05 13:15' ), Schedule::closes_at( $short, $this->local( '2026-11-05 13:00' ), $this->tz ) );
	}

	public function test_page_ttl_is_the_next_boundary_capped_at_24h(): void {
		$now   = $this->local( '2026-11-05 13:00' );
		$block = static fn( array $schedule, bool $visible = true ): array => [
			'type'     => 'link',
			'visible'  => $visible,
			'schedule' => $schedule,
		];
		$this->assertNull( Schedule::page_ttl( [ 'blocks' => [ $block( [ 'start' => null, 'end' => null ] ) ] ], $now, $this->tz ) );
		$this->assertSame( 3600, Schedule::page_ttl( [ 'blocks' => [ $block( $this->weekly( [ 4 ], '12:30', '14:00' ) ) ] ], $now, $this->tz ) );
		$this->assertSame( DAY_IN_SECONDS, Schedule::page_ttl( [ 'blocks' => [ $block( [ 'start' => gmdate( 'c', $now + 3 * DAY_IN_SECONDS ) ] ) ] ], $now, $this->tz ) );
		$this->assertSame( 60, Schedule::page_ttl( [ 'blocks' => [ $block( [ 'end' => gmdate( 'c', $now + 10 ) ] ) ] ], $now, $this->tz ) );
		$this->assertNull( Schedule::page_ttl( [ 'blocks' => [ $block( $this->weekly( [ 4 ], '12:30', '14:00' ), false ) ] ], $now, $this->tz ), 'Hidden blocks do not count' );
		$this->assertNull( Schedule::page_ttl( [ 'blocks' => [ $block( [ 'end' => '2001-01-01T00:00:00Z' ] ) ] ], $now, $this->tz ), 'Expired schedules do not count' );
		$two = [ $block( $this->weekly( [ 4 ], '12:30', '14:00' ) ), $block( [ 'end' => gmdate( 'c', $now + 600 ) ] ) ];
		$this->assertSame( 600, Schedule::page_ttl( [ 'blocks' => $two ], $now, $this->tz ), 'Earliest of several blocks' );
	}

	/**
	 * A window around the real current time (site zone), every day.
	 *
	 * @param int $from Minutes from now.
	 * @param int $to   Minutes from now.
	 */
	private function around_now( int $from, int $to ): array {
		$now = new \DateTimeImmutable( 'now', $this->tz );
		return [
			'start'  => null,
			'end'    => null,
			'weekly' => [
				'days'  => [ 1, 2, 3, 4, 5, 6, 7 ],
				'start' => $now->modify( $from . ' minutes' )->format( 'H:i' ),
				'end'   => $now->modify( $to . ' minutes' )->format( 'H:i' ),
			],
		];
	}

	private function link_doc( array $schedule ): array {
		return $this->doc(
			[
				[
					'id'       => self::LINK,
					'type'     => 'link',
					'schedule' => $schedule,
					'props'    => [
						'title' => 'Show link',
						'url'   => 'https://example.com/show',
					],
				],
			]
		);
	}

	public function test_validator_accepts_canonicalises_and_rejects_weekly_windows(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$ok    = ( new PageValidator() )->validate( $this->link_doc( $this->weekly( [ 4, 1, 3, 2 ], '12:30', '14:00' ) ), $admin );
		$this->assertTrue( $ok->is_valid(), wp_json_encode( $ok->errors() ) );
		$this->assertSame( [ 1, 2, 3, 4 ], $ok->document()['blocks'][0]['schedule']['weekly']['days'] );

		$cleared = ( new PageValidator() )->validate( $this->link_doc( [ 'weekly' => null ] ), $admin );
		$this->assertArrayNotHasKey( 'weekly', $cleared->document()['blocks'][0]['schedule'] );

		$bad = [
			'/blocks/0/schedule/weekly/start'  => $this->weekly( [ 1 ], '25:00', '14:00' ),
			'/blocks/0/schedule/weekly/days'   => $this->weekly( [], '12:00', '14:00' ),
			'/blocks/0/schedule/weekly/days/0' => $this->weekly( [ 8 ], '12:00', '14:00' ),
		];
		foreach ( $bad as $path => $schedule ) {
			$r = ( new PageValidator() )->validate( $this->link_doc( $schedule ), $admin );
			$this->assertFalse( $r->is_valid(), $path );
			$this->assertContains( $path, array_column( $r->errors(), 'path' ), $path );
		}
		$dupes = ( new PageValidator() )->validate( $this->link_doc( $this->weekly( [ 1, 1 ], '12:00', '14:00' ) ), $admin );
		$this->assertFalse( $dupes->is_valid() );
		$extra = $this->weekly( [ 1 ], '12:00', '14:00' );
		$extra['weekly']['tz'] = 'UTC';
		$this->assertFalse( ( new PageValidator() )->validate( $this->link_doc( $extra ), $admin )->is_valid() );
	}

	public function test_renderer_omits_blocks_outside_the_window_and_records_the_boundary(): void {
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$render = function ( array $schedule, int $now ) use ( $admin ): array {
			$doc = ( new PageValidator() )->validate( $this->link_doc( $schedule ), $admin )->document();
			$ctx = new RenderContext( 0, 'p', $admin, 'standalone', false, [], $now, $this->tz );
			return [ ( new Renderer() )->render( $doc, $ctx ), $ctx ];
		};
		$s = $this->weekly( [ 1, 2, 3, 4 ], '12:30', '14:00' );

		[ $html, $ctx ] = $render( $s, $this->local( '2026-11-05 13:00' ) );
		$this->assertStringContainsString( 'Show link', $html );
		$this->assertStringContainsString( 'data-cl-end="' . gmdate( 'c', $this->local( '2026-11-05 14:00' ) ) . '"', $html, 'JS hides it when the window closes' );
		$this->assertSame( $this->local( '2026-11-05 14:00' ), $ctx->next_boundary );

		[ $html, $ctx ] = $render( $s, $this->local( '2026-11-05 15:00' ) );
		$this->assertStringNotContainsString( 'Show link', $html, 'Outside the window: not in the HTML at all' );
		$this->assertStringNotContainsString( self::LINK, $html );
		$this->assertSame( $this->local( '2026-11-09 12:30' ), $ctx->next_boundary, 'Cache expires when Monday opens' );
	}

	public function test_go_returns_404_outside_the_window(): void {
		$visitor = new Visitor( '203.0.113.9', 'Mozilla/5.0', '' );
		$in      = $this->make_page( $this->link_doc( $this->around_now( -60, 60 ) ), 'weekly-in' );
		$this->assertSame( 302, ( new ClickRedirect() )->resolve( $in, self::LINK, $visitor )['status'] );
		$out = $this->make_page( $this->link_doc( $this->around_now( 120, 180 ) ), 'weekly-out' );
		$this->assertSame( 404, ( new ClickRedirect() )->resolve( $out, self::LINK, $visitor )['status'] );
	}

	/**
	 * @param array<string, mixed> $section Section schedule.
	 * @param array<string, mixed> $child   Child schedule.
	 */
	private function section_doc( array $section, array $child ): array {
		return $this->doc(
			[
				[
					'id'       => self::GROUP,
					'type'     => 'group',
					'schedule' => $section,
					'props'    => [ 'heading' => 'Live now' ],
				],
				[
					'id'        => self::FORM,
					'type'      => 'email_capture',
					'parent_id' => self::GROUP,
					'schedule'  => $child,
					'props'     => [
						'heading'     => 'Join',
						'button_text' => 'Go',
					],
				],
				[
					'id'        => self::CHILD,
					'type'      => 'link',
					'parent_id' => self::GROUP,
					'props'     => [
						'title' => 'Inside',
						'url'   => 'https://example.com/inside',
					],
				],
			]
		);
	}

	public function test_reachable_block_honours_weekly_windows_of_block_and_section(): void {
		$open   = $this->around_now( -60, 60 );
		$closed = $this->around_now( 120, 180 );
		$none   = [
			'start' => null,
			'end'   => null,
		];
		$a = $this->make_page( $this->section_doc( $none, $open ), 'r-a' );
		$this->assertNotNull( PageStore::reachable_block( $a, self::FORM, [ 'email_capture' ] ) );
		$b = $this->make_page( $this->section_doc( $none, $closed ), 'r-b' );
		$this->assertNull( PageStore::reachable_block( $b, self::FORM, [ 'email_capture' ] ), 'Child outside its window' );
		$c = $this->make_page( $this->section_doc( $closed, $open ), 'r-c' );
		$this->assertNull( PageStore::reachable_block( $c, self::FORM, [ 'email_capture' ] ), 'Section outside its window hides its children' );
		$this->assertSame( 404, ( new ClickRedirect() )->resolve( $c, self::CHILD, new Visitor( '203.0.113.9', 'UA', '' ) )['status'] );
	}

	public function test_scheduled_pages_opt_out_of_page_caches(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$now   = time();
		$plain = $this->make_page( $this->doc(), 'no-schedule' );
		$this->assertNull( TemplateLoader::schedule_max_age( PageStore::get_document( $plain ), $now ) );

		$sched = $this->make_page( $this->link_doc( $this->around_now( 120, 180 ) ), 'with-schedule' );
		$ttl   = TemplateLoader::schedule_max_age( PageStore::get_document( $sched ), $now );
		$this->assertNotNull( $ttl );
		$this->assertGreaterThanOrEqual( 60, $ttl );
		$this->assertLessThanOrEqual( 2 * HOUR_IN_SECONDS + 60, $ttl, 'Revalidate when the window opens' );

		$this->go_to( get_permalink( $plain ) );
		( new TemplateLoader() )->template_redirect();
		$this->assertNull( TemplateLoader::schedule_cache_max_age(), 'No schedule: default caching' );

		$this->go_to( get_permalink( $sched ) );
		( new TemplateLoader() )->template_redirect();
		$this->assertNotNull( TemplateLoader::schedule_cache_max_age() );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	public function test_rest_routes_send_no_store_and_skip_page_caches(): void {
		$request  = new \WP_REST_Request( 'POST', '/crazylinks/v1/subscribe' );
		$response = ( new RestRoutes() )->no_store( new \WP_REST_Response( [] ), rest_get_server(), $request );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}
}
