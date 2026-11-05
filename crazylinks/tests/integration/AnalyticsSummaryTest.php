<?php
/**
 * Analytics summary contract, list columns, rollup retention.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Analytics\Chart;
use CrazyLinks\Analytics\ListColumns;
use CrazyLinks\Analytics\Rollup;
use CrazyLinks\Analytics\Summary;
use CrazyLinks\Settings;

/**
 * Numbers come from seeded events and rollups, in the shape the editor expects.
 */
class AnalyticsSummaryTest extends TestCase {

	private const A = '01HZZZZZZZZZZZZZZZZZZZZZZA';
	private const B = '01HZZZZZZZZZZZZZZZZZZZZZZB';

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		delete_option( 'crazylinks_rollup_through' );
		delete_transient( Summary::TOTALS_TRANSIENT );
		parent::tear_down();
	}

	private static function day( int $ago ): string {
		return gmdate( 'Y-m-d', time() - $ago * DAY_IN_SECONDS );
	}

	/**
	 * Inserts events.
	 *
	 * @param int         $page_id Page.
	 * @param string      $type    view|click.
	 * @param int         $ago     Days ago.
	 * @param int         $n       Rows.
	 * @param string|null $block   Block id.
	 * @param array<string, mixed> $extra Column overrides.
	 */
	private function seed( int $page_id, string $type, int $ago, int $n, ?string $block = null, array $extra = [], string $visitor_prefix = 'v' ): void {
		global $wpdb;
		for ( $i = 0; $i < $n; $i++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'crazylinks_events',
				array_merge(
					[
						'page_id'       => $page_id,
						'block_id'      => $block,
						'type'          => $type,
						'ts'            => self::day( $ago ) . ' 12:00:' . str_pad( (string) ( $i % 60 ), 2, '0', STR_PAD_LEFT ),
						'visitor_hash'  => substr( md5( $visitor_prefix . $ago . '-' . $i ), 0, 32 ),
						'device'        => 'mobile',
						'os'            => 'ios',
						'in_app'        => 'instagram',
						'referrer_host' => 'l.instagram.com',
					],
					$extra
				)
			);
		}
	}

	private function request( int $page_id, array $params = [] ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'GET', '/crazylinks/v1/analytics/summary' );
		$req->set_query_params( [ 'page_id' => $page_id ] + $params );
		return rest_get_server()->dispatch( $req );
	}

	private function page_with_data(): \WP_Post {
		$post = $this->make_page( $this->doc() );
		// Today: 3 views (2 distinct visitors), 2 clicks on A.
		$this->seed( $post->ID, 'view', 0, 3 );
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}crazylinks_events SET visitor_hash = 'same' WHERE id IN (SELECT id FROM (SELECT id FROM {$wpdb->prefix}crazylinks_events ORDER BY id LIMIT 2) t)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->seed( $post->ID, 'click', 0, 2, self::A );
		// 2 days ago: 4 views (direct, desktop), 1 click on B and 1 on a removed block.
		$this->seed(
			$post->ID,
			'view',
			2,
			4,
			null,
			[
				'device'        => 'desktop',
				'os'            => 'macos',
				'in_app'        => null,
				'referrer_host' => null,
			],
			'w'
		);
		$this->seed( $post->ID, 'click', 2, 1, self::B );
		$this->seed( $post->ID, 'click', 2, 1, '01HZZZZZZZZZZZZZZZZZZZZZZZ' );
		// 40 days ago: outside the 30 day window.
		$this->seed( $post->ID, 'view', 40, 5 );
		return $post;
	}

	public function test_summary_contract_shape_and_numbers(): void {
		$post = $this->page_with_data();
		$res  = $this->request( $post->ID, [ 'range' => '7d' ] );
		$this->assertSame( 200, $res->get_status() );
		$d = $res->get_data();

		$this->assertSame( [ 'views', 'uniques', 'clicks', 'ctr' ], array_keys( $d['totals'] ) );
		$this->assertSame( 7, $d['totals']['views'] );
		$this->assertSame( 4, $d['totals']['clicks'] );
		$this->assertSame( 2 + 4, $d['totals']['uniques'], 'Today 2 distinct, 2 days ago 4 distinct, summed per day' );
		$this->assertSame( round( 4 / 7, 4 ), $d['totals']['ctr'] );

		$this->assertCount( 7, $d['timeseries'], 'Every day in the range is present' );
		$this->assertSame( [ 'date', 'views', 'clicks' ], array_keys( $d['timeseries'][0] ) );
		$by_date = array_column( $d['timeseries'], null, 'date' );
		$this->assertSame( [ 3, 2 ], [ $by_date[ self::day( 0 ) ]['views'], $by_date[ self::day( 0 ) ]['clicks'] ] );
		$this->assertSame( [ 4, 2 ], [ $by_date[ self::day( 2 ) ]['views'], $by_date[ self::day( 2 ) ]['clicks'] ] );
		$this->assertSame( [ 0, 0 ], [ $by_date[ self::day( 1 ) ]['views'], $by_date[ self::day( 1 ) ]['clicks'] ], 'Empty days are filled with zeros' );
		$this->assertSame( self::day( 6 ), $d['timeseries'][0]['date'] );
		$this->assertSame( self::day( 0 ), $d['timeseries'][6]['date'] );

		$blocks = array_column( $d['blocks'], null, 'block_id' );
		$this->assertSame( 2, $blocks[ self::A ]['clicks'] );
		$this->assertSame( 'One', $blocks[ self::A ]['title'] );
		$this->assertSame( 'link', $blocks[ self::A ]['type'] );
		$this->assertSame( 0.5, $blocks[ self::A ]['share'] );
		$this->assertSame( 1, $blocks[ self::B ]['clicks'] );
		$this->assertSame( 0.25, $blocks[ self::B ]['share'] );
		$this->assertSame( '(removed block)', $blocks['01HZZZZZZZZZZZZZZZZZZZZZZZ']['title'] );
		$this->assertSame( self::A, $d['blocks'][0]['block_id'], 'Sorted by clicks' );

		$this->assertSame( [ 'mobile' => 3, 'tablet' => 0, 'desktop' => 4 ], $d['breakdowns']['device'] );
		$this->assertSame( 3, $d['breakdowns']['os']['ios'] );
		$this->assertSame( 4, $d['breakdowns']['os']['macos'] );
		$this->assertSame( 3, $d['breakdowns']['in_app']['instagram'] );
		$this->assertSame( 4, $d['breakdowns']['in_app']['none'] );
		$this->assertSame(
			[
				[ 'host' => '(direct)', 'count' => 4 ],
				[ 'host' => 'l.instagram.com', 'count' => 3 ],
			],
			$d['breakdowns']['referrer']
		);
		$this->assertFalse( $d['country_available'] );
	}

	public function test_zero_data_page_has_zeros_not_errors(): void {
		$post = $this->make_page( $this->doc() );
		$d    = $this->request( $post->ID, [ 'range' => '30d' ] )->get_data();
		$this->assertSame( [ 0, 0, 0, 0.0 ], array_values( $d['totals'] ) );
		$this->assertCount( 30, $d['timeseries'] );
		$this->assertCount( 2, $d['blocks'], 'Both links are listed with zero clicks' );
		$this->assertSame( 0.0, $d['blocks'][0]['share'] );
		$this->assertSame( [ 'mobile' => 0, 'tablet' => 0, 'desktop' => 0 ], $d['breakdowns']['device'] );
		$this->assertSame( [], $d['breakdowns']['referrer'] );
	}

	public function test_ranges(): void {
		$post = $this->page_with_data();
		$this->assertSame( 7, $this->request( $post->ID, [ 'range' => '30d' ] )->get_data()['totals']['views'] );
		$this->assertSame( 12, $this->request( $post->ID, [ 'range' => '90d' ] )->get_data()['totals']['views'] );
		$this->assertCount( 90, $this->request( $post->ID, [ 'range' => '90d' ] )->get_data()['timeseries'] );

		$custom = $this->request( $post->ID, [ 'from' => self::day( 3 ), 'to' => self::day( 1 ) ] )->get_data();
		$this->assertCount( 3, $custom['timeseries'] );
		$this->assertSame( 4, $custom['totals']['views'] );
		$this->assertSame( 4, $custom['totals']['uniques'] );

		$legacy = $this->request( $post->ID, [ 'days' => 28 ] )->get_data();
		$this->assertSame( 7, $legacy['views'], 'Older top-level fields remain' );
		$this->assertSame( $legacy['totals']['clicks'], $legacy['clicks'] );
		$this->assertCount( 28, $legacy['daily'] );
		$this->assertArrayHasKey( 'day', $legacy['daily'][0] );

		foreach ( [ [ 'from' => '2026-13-45' ], [ 'from' => self::day( 1 ), 'to' => self::day( 5 ) ], [ 'from' => '2000-01-01', 'to' => self::day( 0 ) ], [ 'range' => '1y' ] ] as $bad ) {
			$this->assertSame( 400, $this->request( $post->ID, $bad )->get_status(), wp_json_encode( $bad ) );
		}
	}

	public function test_rolled_up_days_survive_event_pruning(): void {
		$post = $this->page_with_data();
		$live = $this->request( $post->ID, [ 'range' => '90d' ] )->get_data();

		Rollup::roll_range( self::day( 60 ), self::day( 1 ) );
		update_option( 'crazylinks_rollup_through', self::day( 1 ) );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}crazylinks_events WHERE ts < %s", self::day( 0 ) . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$after = $this->request( $post->ID, [ 'range' => '90d' ] )->get_data();
		$this->assertSame( $live['totals']['clicks'], $after['totals']['clicks'] );
		$this->assertSame( $live['totals'], $after['totals'] );
		$this->assertSame( $live['timeseries'], $after['timeseries'] );
		$this->assertSame( 12, $after['totals']['views'], 'Rolled days (including the 40 day old ones) still count' );
		$this->assertSame( [ 'mobile' => 3, 'tablet' => 0, 'desktop' => 0 ], $after['breakdowns']['device'], 'Breakdowns need raw events, so only today is left' );
		$this->assertSame( self::day( 0 ), $after['events_available_from'] );
	}

	public function test_permissions(): void {
		$post = $this->make_page( $this->doc() );
		wp_set_current_user( 0 );
		$this->assertContains( $this->request( $post->ID )->get_status(), [ 401, 403 ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $this->request( $post->ID )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 404, $this->request( 999999 )->get_status() );
	}

	public function test_list_columns_show_30_day_totals_and_cache_until_rollup(): void {
		$post = $this->page_with_data();
		$cols = ( new ListColumns() )->columns( [ 'cb' => '', 'title' => 'Title', 'date' => 'Date' ] );
		$this->assertSame( [ 'cb', 'title', 'cl_views', 'cl_clicks', 'cl_ctr', 'date' ], array_keys( $cols ) );

		$cell = static function ( string $col ) use ( $post ): string {
			ob_start();
			( new ListColumns() )->render( $col, $post->ID );
			return (string) ob_get_clean();
		};
		$this->assertSame( '7', $cell( 'cl_views' ), 'The 40 day old views are outside 30d' );
		$this->assertSame( '4', $cell( 'cl_clicks' ) );
		$this->assertSame( '57.1%', $cell( 'cl_ctr' ) );
		$this->assertIsArray( get_transient( Summary::TOTALS_TRANSIENT ) );

		$this->seed( $post->ID, 'view', 0, 2, null, [], 'late' );
		$this->assertSame( '7', $cell( 'cl_views' ), 'Served from the cache' );
		Rollup::roll_range( self::day( 1 ), self::day( 1 ) );
		$this->assertFalse( get_transient( Summary::TOTALS_TRANSIENT ), 'The rollup invalidates it' );
		$this->assertSame( '9', $cell( 'cl_views' ) );

		$actions = ( new ListColumns() )->row_actions( [], $post );
		$this->assertStringContainsString( 'page=crazylinks-analytics', $actions['analytics'] );
		$this->assertStringContainsString( 'page_id=' . $post->ID, $actions['analytics'] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertArrayNotHasKey( 'analytics', ( new ListColumns() )->row_actions( [], $post ) );
	}

	public function test_prune_respects_retention_and_rollup(): void {
		global $wpdb;
		$post = $this->make_page( $this->doc() );
		$this->seed( $post->ID, 'view', 100, 2 );
		$this->seed( $post->ID, 'view', 50, 2 );
		$count = fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}crazylinks_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 90, Settings::event_retention_days(), 'Default is 90 days' );
		$this->assertSame( 0, ( new Rollup() )->prune(), 'Nothing is deleted before the first rollup' );

		update_option( 'crazylinks_rollup_through', self::day( 120 ) );
		$this->assertSame( 0, ( new Rollup() )->prune(), 'Unrolled days are never pruned' );

		( new Rollup() )->run();
		$this->assertSame( 2, $count(), 'Events older than 90 days are gone, newer kept' );
		$this->assertSame( self::day( 1 ), get_option( 'crazylinks_rollup_through' ) );
		$daily = (int) $wpdb->get_var( "SELECT SUM(count) FROM {$wpdb->prefix}crazylinks_daily WHERE page_id = {$post->ID}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 4, $daily, 'Daily totals keep the pruned days' );

		update_option( Settings::OPTION, [ 'retention_days' => 0 ] );
		$this->seed( $post->ID, 'view', 200, 1 );
		( new Rollup() )->prune();
		$this->assertSame( 3, $count(), '0 keeps everything' );

		update_option( Settings::OPTION, [ 'retention_days' => 30 ] );
		( new Rollup() )->prune();
		$this->assertSame( 0, $count() );
	}

	public function test_legacy_retention_months_is_honoured_until_days_are_saved(): void {
		update_option( Settings::OPTION, [ 'retention_months' => 2 ] );
		$this->assertSame( 60, Settings::event_retention_days() );
		update_option( Settings::OPTION, [ 'retention_months' => 2, 'retention_days' => 10 ] );
		$this->assertSame( 10, Settings::event_retention_days() );
	}

	public function test_chart_svg_is_escaped_and_handles_edge_cases(): void {
		$svg = Chart::svg( [] );
		$this->assertStringStartsWith( '<svg', $svg );
		$one = Chart::svg( [ [ 'date' => '2026-01-01', 'views' => 5, 'clicks' => 1 ] ] );
		$this->assertStringContainsString( '<circle', $one );
		$many = Chart::svg( array_map( fn( $i ) => [ 'date' => self::day( 9 - $i ), 'views' => $i * 10, 'clicks' => $i ], range( 0, 9 ) ) );
		$this->assertStringContainsString( 'polyline', $many );
		$this->assertStringContainsString( 'role="img"', $many );
		$this->assertSame( 4, Chart::nice_max( 0 ) );
		$this->assertSame( 100, Chart::nice_max( 81 ) );
		$this->assertSame( 250, Chart::nice_max( 201 ) );
	}
}
