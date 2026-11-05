<?php
/**
 * Click redirect and logging.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Analytics\ClickRedirect;
use CrazyLinks\Analytics\EventLogger;
use CrazyLinks\Analytics\Rollup;
use CrazyLinks\Analytics\Summary;
use CrazyLinks\Analytics\Visitor;
use CrazyLinks\Settings;

/**
 * /go/ validates the block, logs one event and redirects to the stored URL only.
 */
class ClickRedirectTest extends TestCase {

	private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Instagram 350.0';

	private function visitor( string $ip = '203.0.113.7', string $ua = self::UA, string $ref = 'https://l.instagram.com/x' ): Visitor {
		return new Visitor( $ip, $ua, $ref );
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_valid_click_logs_and_redirects(): void {
		$post   = $this->make_page( $this->doc() );
		$result = ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor() );
		$this->assertSame( 302, $result['status'] );
		$this->assertSame( 'https://example.com/one', $result['location'] );
		$this->assertSame( 'logged', $result['event'] );

		$events = $this->events();
		$this->assertCount( 1, $events );
		$row = $events[0];
		$this->assertSame( (string) $post->ID, $row['page_id'] );
		$this->assertSame( '01HZZZZZZZZZZZZZZZZZZZZZZA', $row['block_id'] );
		$this->assertSame( 'click', $row['type'] );
		$this->assertSame( 32, strlen( $row['visitor_hash'] ) );
		$this->assertSame( substr( hash( 'sha256', '203.0.113.7|' . self::UA . '|' . Visitor::daily_salt() ), 0, 32 ), $row['visitor_hash'] );
		$this->assertNull( $row['country'] );
		$this->assertSame( 'instagram', $row['in_app'] );
		$this->assertSame( 'mobile', $row['device'] );
		$this->assertSame( 'ios', $row['os'] );
		$this->assertSame( 'l.instagram.com', $row['referrer_host'] );
		$this->assertStringNotContainsString( '203.0.113.7', wp_json_encode( $row ) );
	}

	public function test_bogus_block_ids_are_404_and_not_logged(): void {
		$post = $this->make_page( $this->doc() );
		foreach ( [ '01HZZZZZZZZZZZZZZZZZZZZZZQ', 'bogus', '' ] as $id ) {
			$this->assertSame( 404, ( new ClickRedirect() )->resolve( $post, $id, $this->visitor() )['status'], $id );
		}
		$this->assertCount( 0, $this->events() );
	}

	public function test_draft_page_is_404(): void {
		$post = $this->make_page( $this->doc(), 'draft', 'draft' );
		$this->assertSame( 404, ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor() )['status'] );
	}

	public function test_non_link_blocks_and_mailto_are_404(): void {
		$doc  = $this->doc(
			[
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZA',
					'type'  => 'heading',
					'props' => [ 'text' => 'x' ],
				],
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZB',
					'type'  => 'link',
					'props' => [
						'title' => 'mail',
						'url'   => 'mailto:a@example.com',
					],
				],
			]
		);
		$post = $this->make_page( $doc );
		$this->assertSame( 404, ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor() )['status'] );
		$this->assertSame( 404, ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZB', $this->visitor() )['status'] );
	}

	public function test_hidden_or_expired_block_is_404(): void {
		$doc                             = $this->doc();
		$doc['blocks'][0]['visible']    = false;
		$doc['blocks'][1]['schedule']   = [
			'start' => null,
			'end'   => '2001-01-01T00:00:00Z',
		];
		$post                            = $this->make_page( $doc );
		$this->assertSame( 404, ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor() )['status'] );
		$this->assertSame( 404, ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZB', $this->visitor() )['status'] );
	}

	public function test_repeat_click_is_deduplicated_but_still_redirects(): void {
		$post = $this->make_page( $this->doc() );
		$r    = new ClickRedirect();
		$this->assertSame( 'logged', $r->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor() )['event'] );
		$second = $r->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor() );
		$this->assertSame( 302, $second['status'] );
		$this->assertSame( 'duplicate', $second['event'] );
		$this->assertSame( 'logged', $r->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZB', $this->visitor() )['event'], 'Different block is a new click' );
		$this->assertSame( 'logged', $r->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor( '198.51.100.9' ) )['event'], 'Different visitor is a new click' );
		$this->assertCount( 3, $this->events() );
	}

	public function test_burst_is_rate_limited_per_visitor(): void {
		update_option( Settings::OPTION, [ 'clicks_per_minute' => 3 ] );
		$post   = $this->make_page( $this->doc() );
		$r      = new ClickRedirect();
		$events = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$events[] = $r->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor() )['event'];
		}
		$this->assertSame( [ 'logged', 'duplicate', 'duplicate', 'rate-limited', 'rate-limited' ], $events );
	}

	public function test_bots_are_redirected_but_not_logged(): void {
		$post = $this->make_page( $this->doc() );
		foreach ( [ 'facebookexternalhit/1.1', 'curl/8.7.1', 'Googlebot/2.1 (+http://www.google.com/bot.html)', '' ] as $ua ) {
			$result = ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor( '192.0.2.1', $ua ) );
			$this->assertSame( 302, $result['status'] );
			$this->assertSame( 'filtered', $result['event'], $ua );
		}
		$this->assertCount( 0, $this->events() );
	}

	public function test_analytics_off_and_untracked_blocks_do_not_log(): void {
		$doc                           = $this->doc();
		$doc['blocks'][1]['tracking'] = false;
		$post                          = $this->make_page( $doc );
		$this->assertSame( 'untracked', ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZB', $this->visitor() )['event'] );
		update_option( Settings::OPTION, [ 'analytics' => false ] );
		$this->assertSame( 'disabled', ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor() )['event'] );
		$this->assertCount( 0, $this->events() );
	}

	public function test_utm_is_appended_without_overriding_existing_params(): void {
		$doc                   = $this->doc();
		$doc['blocks'][1]['props']['url'] = 'https://example.com/two?a=1&utm_source=keep';
		$post                  = $this->make_page(
			$this->doc(
				$doc['blocks'],
				[
					'utm' => [
						'enabled'  => true,
						'source'   => 'crazylinks',
						'medium'   => 'bio',
						'campaign' => '{slug}',
					],
				]
			),
			'utm-page'
		);
		$one = ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor() )['location'];
		$this->assertSame( 'https://example.com/one?utm_source=crazylinks&utm_medium=bio&utm_campaign=utm-page', $one );
		$two = ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZB', $this->visitor() )['location'];
		$this->assertStringContainsString( 'utm_source=keep', $two );
		$this->assertStringNotContainsString( 'utm_source=crazylinks', $two );
	}

	public function test_redirect_target_never_comes_from_the_request(): void {
		$post        = $this->make_page( $this->doc() );
		$_GET['url'] = 'https://evil.example/';
		$_GET['to']  = 'https://evil.example/';
		$result      = ( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor() );
		unset( $_GET['url'], $_GET['to'] );
		$this->assertSame( 'https://example.com/one', $result['location'] );
	}

	public function test_view_logging_dedupes_and_rollup_aggregates(): void {
		$post                       = $this->make_page( $this->doc() );
		$_SERVER['REMOTE_ADDR']     = '203.0.113.50';
		$_SERVER['HTTP_USER_AGENT'] = self::UA;
		$_SERVER['REQUEST_METHOD']  = 'GET';
		EventLogger::log_view( $post );
		EventLogger::log_view( $post );
		$this->assertCount( 1, $this->events() );

		$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
		$_SERVER['REMOTE_ADDR']      = '203.0.113.51';
		EventLogger::log_view( $post );
		unset( $_SERVER['HTTP_SEC_PURPOSE'] );
		$this->assertCount( 1, $this->events(), 'Prefetch is not a view' );

		( new ClickRedirect() )->resolve( $post, '01HZZZZZZZZZZZZZZZZZZZZZZA', $this->visitor() );
		$today = gmdate( 'Y-m-d' );
		Rollup::roll_range( $today, $today );
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT block_id, type, count, uniques FROM {$wpdb->prefix}crazylinks_daily WHERE page_id = %d ORDER BY type", $post->ID ), ARRAY_A );
		$this->assertSame(
			[
				[
					'block_id' => '01HZZZZZZZZZZZZZZZZZZZZZZA',
					'type'     => 'click',
					'count'    => '1',
					'uniques'  => '1',
				],
				[
					'block_id' => '',
					'type'     => 'view',
					'count'    => '1',
					'uniques'  => '1',
				],
			],
			$rows
		);
		Rollup::roll_range( $today, $today );
		$this->assertSame( '2', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}crazylinks_daily WHERE page_id = %d", $post->ID ) ), 'Rollup is idempotent' );

		$summary = Summary::for_page( $post->ID, 7 );
		$this->assertSame( 1, $summary['views'] );
		$this->assertSame( 1, $summary['clicks'] );
		$this->assertSame( 'One', $summary['blocks'][0]['title'] );
	}

	public function test_daily_salt_rotates(): void {
		$salt = Visitor::daily_salt();
		$this->assertSame( $salt, Visitor::daily_salt() );
		$this->assertNotSame( $salt, Visitor::salt_for_day( '2000-01-01' ), 'Another day has another salt' );
		$this->assertFalse( get_option( 'crazylinks_salt_' . gmdate( 'Ymd' ) ), 'Minting a new day discards the other days' );
		$this->assertNotSame( $salt, Visitor::daily_salt(), 'The discarded salt is never reused' );
	}
}
