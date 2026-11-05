<?php
/**
 * Rate limits, de-duplication, the daily salt and the subscribe endpoint (security review F2, F8).
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Analytics\ClickRedirect;
use CrazyLinks\Analytics\EventLogger;
use CrazyLinks\Analytics\RateLimiter;
use CrazyLinks\Analytics\Visitor;
use CrazyLinks\Settings;

/**
 * Abuse limits are keyed on an HMAC of the IP alone and live in a bounded store.
 */
class AbuseLimitsTest extends TestCase {

	private const A  = '01HZZZZZZZZZZZZZZZZZZZZZZA';
	private const IP = '198.51.100.23';

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR']    = self::IP;
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		parent::tear_down();
	}

	private static function ua( int $i ): string {
		return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/' . ( 100 + $i ) . '.0.0.0 Safari/537.36';
	}

	private function option_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%crazylinks%'" );
	}

	private function limiter_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . RateLimiter::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * F2: rotating the UA from one IP is one view, one unique, and writes no option rows.
	 */
	public function test_rotating_user_agents_are_one_visitor_for_views(): void {
		$post = $this->make_page( $this->doc() );
		$_SERVER['HTTP_USER_AGENT'] = self::ua( 0 );
		$this->assertSame( 'logged', EventLogger::log_view( $post ) );
		$options = $this->option_rows();

		$statuses = [];
		for ( $i = 1; $i < 25; $i++ ) {
			$_SERVER['HTTP_USER_AGENT'] = self::ua( $i );
			$statuses[]                 = EventLogger::log_view( $post );
		}
		$this->assertSame( array_fill( 0, 24, 'duplicate' ), $statuses );
		$this->assertCount( 1, $this->events() );
		$this->assertCount( 1, array_unique( array_column( $this->events(), 'visitor_hash' ) ), '1 unique' );
		$this->assertSame( $options, $this->option_rows(), 'No option or transient rows per visitor' );
		$this->assertLessThanOrEqual( 2, $this->limiter_rows(), 'One counter row and one dedupe row' );
	}

	/**
	 * F2: rotating the UA does not escape the click rate limit.
	 */
	public function test_rotating_user_agents_hit_the_click_limit(): void {
		update_option( Settings::OPTION, [ 'clicks_per_minute' => 20 ] );
		$post = $this->make_page( $this->doc() );
		Visitor::daily_salt();
		$options = $this->option_rows();
		$events  = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$result = ( new ClickRedirect() )->resolve( $post, self::A, new Visitor( self::IP, self::ua( $i ), '' ) );
			$this->assertSame( 302, $result['status'], 'Every valid click still redirects' );
			$events[] = $result['event'];
		}
		$counts = array_count_values( $events );
		$this->assertSame( 1, $counts['logged'] );
		$this->assertSame( 19, $counts['duplicate'] );
		$this->assertSame( 5, $counts['rate-limited'] );
		$this->assertCount( 1, $this->events() );
		$this->assertSame( $options, $this->option_rows() );
		$this->assertSame( 'logged', ( new ClickRedirect() )->resolve( $post, self::A, new Visitor( '198.51.100.99', self::ua( 0 ), '' ) )['event'], 'Another IP is unaffected' );
	}

	/**
	 * F2: views are limited per IP across all pages (filterable).
	 */
	public function test_views_are_rate_limited_per_ip_across_pages(): void {
		$this->assertSame( 120, EventLogger::view_limit() );
		add_filter( 'crazylinks_view_rate_limit', static fn() => 3 );
		$statuses = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$_SERVER['HTTP_USER_AGENT'] = self::ua( $i );
			$statuses[]                 = EventLogger::log_view( $this->make_page( $this->doc(), 'page-' . $i ) );
		}
		$this->assertSame( [ 'logged', 'logged', 'logged', 'rate-limited', 'rate-limited' ], $statuses );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.24';
		$this->assertSame( 'logged', EventLogger::log_view( $this->make_page( $this->doc(), 'page-x' ) ) );
	}

	/**
	 * F2: with a persistent object cache the limiter uses it and leaves the table alone.
	 */
	public function test_object_cache_backend_is_used_when_available(): void {
		$was    = (bool) wp_using_ext_object_cache( true );
		$before = $this->limiter_rows();
		$hits   = [];
		for ( $i = 0; $i < 4; $i++ ) {
			$hits[] = RateLimiter::hit( 'test', 'k', 2, 60 );
		}
		$first  = RateLimiter::first_in_window( 'dd-test', 60 );
		$second = RateLimiter::first_in_window( 'dd-test', 60 );
		wp_using_ext_object_cache( $was );
		$this->assertSame( [ true, true, false, false ], $hits );
		$this->assertTrue( $first );
		$this->assertFalse( $second );
		$this->assertSame( $before, $this->limiter_rows() );
	}

	/**
	 * F2: expired limiter rows are swept.
	 */
	public function test_expired_limiter_rows_are_swept(): void {
		RateLimiter::first_in_window( 'short', 1 );
		$this->assertSame( 1, $this->limiter_rows() );
		RateLimiter::sweep( time() + 5 );
		$this->assertSame( 0, $this->limiter_rows() );
		$this->assertTrue( RateLimiter::first_in_window( 'short', 1 ) );
	}

	/**
	 * F2: the salt is not autoloaded, is mixed with wp_salt(), and is the same for racing requests.
	 */
	public function test_salt_is_private_and_race_safe(): void {
		global $wpdb;
		$name = 'crazylinks_salt_' . gmdate( 'Ymd' );
		$salt = Visitor::daily_salt();
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
		$this->assertIsArray( $row );
		$this->assertContains( $row['autoload'], [ 'off', 'no' ] );
		$this->assertNotSame( $salt, $row['option_value'], 'The stored value alone is not the salt' );
		$this->assertSame( hash_hmac( 'sha256', gmdate( 'Y-m-d' ) . '|' . $row['option_value'], wp_salt() ), $salt );

		// Simulate a request that saw "no salt yet" while another request created one.
		delete_option( $name );
		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => $name,
				'option_value' => 'value-from-the-other-request',
				'autoload'     => 'off',
			]
		);
		$notoptions          = (array) wp_cache_get( 'notoptions', 'options' );
		$notoptions[ $name ] = true;
		wp_cache_set( 'notoptions', $notoptions, 'options' );
		wp_cache_delete( $name, 'options' );

		$this->assertSame( hash_hmac( 'sha256', gmdate( 'Y-m-d' ) . '|value-from-the-other-request', wp_salt() ), Visitor::daily_salt(), 'The loser adopts the winner\'s salt' );
		$this->assertSame( 'value-from-the-other-request', get_option( $name ) );
	}

	/**
	 * F2: the abuse key ignores the user agent; the uniques hash does not.
	 */
	public function test_abuse_key_ignores_user_agent(): void {
		$a = new Visitor( self::IP, self::ua( 1 ), '' );
		$b = new Visitor( self::IP, self::ua( 2 ), '' );
		$this->assertSame( $a->abuse_key(), $b->abuse_key() );
		$this->assertNotSame( $a->hash(), $b->hash() );
		$this->assertSame( hash_hmac( 'sha256', self::IP, Visitor::daily_salt() ), $a->abuse_key() );
	}

	/**
	 * Dispatches a subscribe request.
	 *
	 * @param array<string, mixed> $body Body.
	 */
	private function subscribe( array $body ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/crazylinks/v1/subscribe' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body + [ 'email' => 'fan@example.com' ] ) );
		return rest_get_server()->dispatch( $req );
	}

	private function capture_doc( array $extra = [] ): array {
		return $this->doc(
			[
				array_merge(
					[
						'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZE',
						'type'  => 'email_capture',
						'props' => [ 'consent_text' => 'Yes, email me.' ],
					],
					$extra
				),
				[
					'id'    => self::A,
					'type'  => 'link',
					'props' => [
						'title' => 'One',
						'url'   => 'https://example.com/one',
					],
				],
			]
		);
	}

	/**
	 * F8: every unavailable case gives the same answer; a valid one is accepted.
	 */
	public function test_subscribe_has_one_generic_not_found_and_same_checks_as_go(): void {
		add_filter( 'crazylinks_subscribe_rate_limit', static fn() => 100 );
		$ok        = $this->make_page( $this->capture_doc(), 'sub-ok' );
		$draft     = $this->make_page( $this->capture_doc(), 'sub-draft', 'draft' );
		$hidden    = $this->make_page( $this->capture_doc( [ 'visible' => false ] ), 'sub-hidden' );
		$later     = $this->make_page(
			$this->capture_doc(
				[
					'schedule' => [
						'start' => '2999-01-01T00:00:00Z',
						'end'   => null,
					],
				]
			),
			'sub-later'
		);
		$protected = $this->make_page( $this->capture_doc(), 'sub-locked' );
		wp_update_post(
			[
				'ID'            => $protected->ID,
				'post_password' => 'pw',
			]
		);
		wp_set_current_user( 0 );

		$cases = [
			'missing page'     => [ 999999, '01HZZZZZZZZZZZZZZZZZZZZZZE' ],
			'draft'            => [ $draft->ID, '01HZZZZZZZZZZZZZZZZZZZZZZE' ],
			'hidden block'     => [ $hidden->ID, '01HZZZZZZZZZZZZZZZZZZZZZZE' ],
			'not started'      => [ $later->ID, '01HZZZZZZZZZZZZZZZZZZZZZZE' ],
			'password'         => [ $protected->ID, '01HZZZZZZZZZZZZZZZZZZZZZZE' ],
			'unknown block'    => [ $ok->ID, '01HZZZZZZZZZZZZZZZZZZZZZZQ' ],
			'not a form block' => [ $ok->ID, self::A ],
		];
		$answers = [];
		foreach ( $cases as $label => [ $page_id, $block_id ] ) {
			$res = $this->subscribe(
				[
					'page_id'  => $page_id,
					'block_id' => $block_id,
					'consent'  => true,
				]
			);
			$this->assertSame( 404, $res->get_status(), $label );
			$answers[] = wp_json_encode( $res->get_data() );
		}
		$this->assertCount( 1, array_unique( $answers ), 'One generic error for every not-found case' );

		$this->assertSame(
			400,
			$this->subscribe(
				[
					'page_id'  => $ok->ID,
					'block_id' => '01HZZZZZZZZZZZZZZZZZZZZZZE',
				]
			)->get_status(),
			'Consent required'
		);
		$this->assertSame(
			200,
			$this->subscribe(
				[
					'page_id'  => $ok->ID,
					'block_id' => '01HZZZZZZZZZZZZZZZZZZZZZZE',
					'consent'  => true,
				]
			)->get_status()
		);
	}

	/**
	 * F8: 5 attempts per IP per hour, and rotating the UA does not reset it.
	 */
	public function test_subscribe_rate_limit_is_per_ip(): void {
		$ok    = $this->make_page( $this->capture_doc(), 'sub-rl' );
		$codes = [];
		for ( $i = 0; $i < 7; $i++ ) {
			$_SERVER['HTTP_USER_AGENT'] = self::ua( $i );
			$codes[]                    = $this->subscribe(
				[
					'page_id'  => $ok->ID,
					'block_id' => '01HZZZZZZZZZZZZZZZZZZZZZZE',
					'consent'  => true,
				]
			)->get_status();
		}
		$this->assertSame( [ 200, 200, 200, 200, 200, 429, 429 ], $codes );
	}
}
