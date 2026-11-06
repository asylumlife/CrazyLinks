<?php
/**
 * Mailchimp adapter, sync queue, webhook, settings and privacy.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Activator;
use CrazyLinks\Schema\PageValidator;
use CrazyLinks\Settings;
use CrazyLinks\Subscribers\Adapters;
use CrazyLinks\Subscribers\Adapters\Mailchimp;
use CrazyLinks\Subscribers\Integrations;
use CrazyLinks\Subscribers\PersonalData;
use CrazyLinks\Subscribers\Store;
use CrazyLinks\Subscribers\Sync;

/**
 * A fake HTTP layer (pre_http_request) stands in for Mailchimp; no test touches the network.
 */
class MailchimpTest extends TestCase {

	private const KEY      = 'crazylinksFakeTestKeyNotRealXYZ0-us6';
	private const AUDIENCE = 'a1b2c3d4e5';
	private const FORM     = '01HZZZZZZZZZZZZZZZZZZZZZZM';
	private const API      = 'https://us6.api.mailchimp.com/3.0';

	/**
	 * Requests seen: method, url, headers, body (decoded), timeout.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $requests = [];

	/**
	 * Answers a request: callable( string $method, string $url, array $body ) returning a response or WP_Error.
	 *
	 * @var callable
	 */
	private $answer;

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.88';
		add_filter( 'crazylinks_subscribe_rate_limit', static fn() => 1000 );
		reset_phpmailer_instance();
		$this->requests = [];
		$this->answer   = fn( string $method, string $url, array $body ) => $this->default_answer( $method, $url, $body );
		add_filter( 'pre_http_request', [ $this, 'fake_http' ], 10, 3 );
		$this->connect();
		Sync::unschedule();
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'fake_http' ], 10 );
		delete_option( Settings::OPTION );
		delete_option( Integrations::SECRET_OPTION );
		delete_option( Sync::BACKFILL_OPTION );
		delete_transient( Integrations::TEST_TRANSIENT );
		Sync::unschedule();
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function connect( array $extra = [] ): void {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				[
					'subscriber_adapter' => 'mailchimp',
					'mailchimp_api_key'  => self::KEY,
					'mailchimp_audience' => self::AUDIENCE,
				],
				$extra
			)
		);
	}

	/**
	 * The fake transport.
	 *
	 * @param mixed                $pre  Preempt.
	 * @param array<string, mixed> $args Args.
	 * @param string               $url  URL.
	 * @return mixed
	 */
	public function fake_http( $pre, $args, $url ) {
		$body             = isset( $args['body'] ) && is_string( $args['body'] ) ? (array) json_decode( $args['body'], true ) : [];
		$this->requests[] = [
			'method'  => (string) ( $args['method'] ?? 'GET' ),
			'url'     => $url,
			'headers' => (array) ( $args['headers'] ?? [] ),
			'body'    => $body,
			'timeout' => $args['timeout'] ?? null,
			'redir'   => $args['redirection'] ?? null,
		];
		$answer = $this->answer;
		return $answer( (string) ( $args['method'] ?? 'GET' ), $url, $body );
	}

	/**
	 * A response.
	 *
	 * @param int   $code HTTP status.
	 * @param mixed $body Body (encoded unless a string).
	 * @return array<string, mixed>
	 */
	private function res( int $code, $body = '' ): array {
		return [
			'headers'  => [],
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * A member as Mailchimp returns it.
	 *
	 * @param string $email  Email.
	 * @param string $status Status.
	 * @return array<string, mixed>
	 */
	private function member( string $email, string $status = 'pending' ): array {
		return [
			'id'            => md5( strtolower( $email ) ),
			'email_address' => $email,
			'status'        => $status,
			'list_id'       => self::AUDIENCE,
		];
	}

	/**
	 * A working Mailchimp: members come back pending (or as sent), tags 204, ping, lists.
	 *
	 * @param string               $method Method.
	 * @param string               $url    URL.
	 * @param array<string, mixed> $body   Body.
	 * @return array<string, mixed>
	 */
	private function default_answer( string $method, string $url, array $body ): array {
		if ( str_contains( $url, '/ping' ) ) {
			return $this->res( 200, [ 'health_status' => "Everything's Chimpy!" ] );
		}
		if ( str_contains( $url, '/lists?' ) ) {
			return $this->res(
				200,
				[
					'lists'       => [
						[
							'id'    => self::AUDIENCE,
							'name'  => 'Fans',
							'stats' => [ 'member_count' => 42 ],
						],
						[
							'id'    => 'f0f0f0f0f0',
							'name'  => 'Wholesale <b>list</b>',
							'stats' => [ 'member_count' => 3 ],
						],
					],
					'total_items' => 2,
				]
			);
		}
		if ( str_ends_with( $url, '/tags' ) || str_contains( $url, '/actions/delete-permanent' ) ) {
			return $this->res( 204 );
		}
		if ( 'PUT' === $method && preg_match( '#/members/([a-f0-9]{32})#', $url ) ) {
			return $this->res( 200, $this->member( (string) ( $body['email_address'] ?? 'x@example.com' ), (string) ( $body['status_if_new'] ?? $body['status'] ?? 'pending' ) ) );
		}
		return $this->res( 404, [ 'title' => 'Resource Not Found' ] );
	}

	/**
	 * A page with one email capture block.
	 *
	 * @param array<string, mixed> $props Props.
	 */
	private function page( array $props = [], string $slug = 'mc-page' ): \WP_Post {
		$post = $this->make_page(
			$this->doc(
				[
					[
						'id'    => self::FORM,
						'type'  => 'email_capture',
						'props' => $props + [
							'destination'  => 'mailchimp',
							'consent_text' => 'Yes, email me.',
						],
					],
				]
			),
			$slug
		);
		wp_set_current_user( 0 );
		return $post;
	}

	private function subscribe( \WP_Post $post, string $email = 'Fan@Example.com' ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/crazylinks/v1/subscribe' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body(
			(string) wp_json_encode(
				[
					'page_id'  => $post->ID,
					'block_id' => self::FORM,
					'email'    => $email,
					'consent'  => true,
				]
			)
		);
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Requests whose URL contains a fragment.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sent_to( string $fragment, string $method = '' ): array {
		return array_values( array_filter( $this->requests, static fn( $r ) => str_contains( $r['url'], $fragment ) && ( '' === $method || $method === $r['method'] ) ) );
	}

	private function webhook( array $body, ?string $key = null, string $method = 'POST' ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/crazylinks/v1/integrations/mailchimp/webhook' );
		$req->set_query_params( [ 'key' => $key ?? Integrations::webhook_secret() ] );
		if ( 'POST' === $method ) {
			$req->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
			$req->set_body_params( $body );
		}
		return rest_get_server()->dispatch( $req );
	}

	// ---------------------------------------------------------------------------------------------
	// Adapter.

	public function test_registry_has_mailchimp_and_accepts_more_adapters(): void {
		$this->assertInstanceOf( Mailchimp::class, Adapters::get( 'mailchimp' ) );
		$this->assertNull( Adapters::get( 'local' ) );
		$this->assertNull( Adapters::get( 'nope' ) );
		$this->assertInstanceOf( Mailchimp::class, Adapters::active() );
		add_filter( 'crazylinks_subscriber_adapters', static fn( $list ) => $list + [ 'broken' => new \stdClass() ] );
		$this->assertArrayNotHasKey( 'broken', Adapters::all(), 'Only Adapter implementations under their own id' );
		remove_all_filters( 'crazylinks_subscriber_adapters' );
	}

	public function test_base_url_comes_from_the_key_data_center(): void {
		$this->assertSame( 'https://us6.api.mailchimp.com/3.0', ( new Mailchimp( self::KEY ) )->base_url() );
		$this->assertSame( 'https://us21.api.mailchimp.com/3.0', ( new Mailchimp( 'abcdefabcdef-us21' ) )->base_url() );
		$this->assertSame( '', ( new Mailchimp( 'no-dash-here.evil.com' ) )->base_url() );
		$this->assertSame( '', ( new Mailchimp( 'abcdefabcdef-us6.evil.com/x' ) )->base_url() );
		$bad = ( new Mailchimp( 'not a key' ) )->test();
		$this->assertWPError( $bad );
		$this->assertSame( 'crazylinks_mc_no_key', $bad->get_error_code() );
		$this->assertSame( [], $this->requests, 'No request without a valid key' );
	}

	public function test_push_sends_an_upsert_with_status_if_new_and_basic_auth(): void {
		$mc     = new Mailchimp( self::KEY );
		$result = $mc->push(
			[
				'email'         => 'Fan@Example.com',
				'status_if_new' => 'pending',
				'consent_at'    => '2026-11-06 10:00:00',
			],
			self::AUDIENCE,
			[ 'vip', ' ', 'vip', 'spring <b>drop</b>' ]
		);
		$this->assertTrue( $result );
		$this->assertCount( 2, $this->requests );
		[ $put, $tags ] = $this->requests;
		$hash           = md5( 'fan@example.com' );
		$this->assertSame( 'PUT', $put['method'] );
		$this->assertSame( self::API . '/lists/' . self::AUDIENCE . '/members/' . $hash . '?skip_merge_validation=true', $put['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'crazylinks:' . self::KEY ), $put['headers']['Authorization'] );
		$this->assertSame( 10, $put['timeout'] );
		$this->assertSame( 0, $put['redir'] );
		$this->assertSame( 'fan@example.com', $put['body']['email_address'] );
		$this->assertSame( 'pending', $put['body']['status_if_new'] );
		$this->assertArrayNotHasKey( 'status', $put['body'], 'Never sets the status of an existing member' );
		$this->assertSame( '2026-11-06T10:00:00+00:00', $put['body']['timestamp_signup'] );
		$this->assertSame( [ 'vip', 'spring drop' ], $put['body']['tags'] );
		$this->assertSame( 'POST', $tags['method'] );
		$this->assertSame( self::API . '/lists/' . self::AUDIENCE . '/members/' . $hash . '/tags', $tags['url'] );
		$this->assertSame(
			[
				[
					'name'   => 'vip',
					'status' => 'active',
				],
				[
					'name'   => 'spring drop',
					'status' => 'active',
				],
			],
			$tags['body']['tags']
		);
		$this->assertSame(
			[
				'id'     => $hash,
				'status' => 'pending',
			],
			$mc->last_result()
		);
	}

	public function test_push_rejects_bad_audience_ids_without_a_request(): void {
		$result = ( new Mailchimp( self::KEY ) )->push( [ 'email' => 'a@example.com' ], '../../x', [] );
		$this->assertWPError( $result );
		$this->assertSame( 'crazylinks_mc_audience_not_found', $result->get_error_code() );
		$this->assertSame( [], $this->requests );
	}

	public function test_resubscribe_asks_an_unsubscribed_member_to_confirm_again(): void {
		$this->answer = function ( string $method, string $url, array $body ) {
			if ( 'PUT' === $method ) {
				return $this->res( 200, $this->member( 'a@example.com', isset( $body['status'] ) ? 'pending' : 'unsubscribed' ) );
			}
			return $this->res( 204 );
		};
		$mc = new Mailchimp( self::KEY );
		$this->assertTrue(
			$mc->push(
				[
					'email'       => 'a@example.com',
					'resubscribe' => true,
				],
				self::AUDIENCE,
				[]
			)
		);
		$this->assertCount( 2, $this->requests );
		$this->assertSame( [ 'status' => 'pending' ], $this->requests[1]['body'], 'Pending, never subscribed' );
		$this->assertSame( 'pending', $mc->last_result()['status'] );

		$this->requests = [];
		$this->assertTrue( $mc->push( [ 'email' => 'a@example.com' ], self::AUDIENCE, [] ) );
		$this->assertCount( 1, $this->requests, 'Without resubscribe an unsubscribed member is left alone' );
	}

	public function test_audiences_and_ping(): void {
		$mc = new Mailchimp( self::KEY );
		$this->assertTrue( $mc->test() );
		$this->assertSame( self::API . '/ping', $this->requests[0]['url'] );
		$list = $mc->audiences();
		$this->assertSame(
			[
				[
					'id'           => self::AUDIENCE,
					'name'         => 'Fans',
					'member_count' => 42,
				],
				[
					'id'           => 'f0f0f0f0f0',
					'name'         => 'Wholesale list',
					'member_count' => 3,
				],
			],
			$list
		);
		$this->assertStringStartsWith( self::API . '/lists?count=1000&fields=', $this->requests[1]['url'] );
	}

	/**
	 * @return array<string, array{0:int, 1:mixed, 2:string, 3:bool}>
	 */
	public static function errors(): array {
		return [
			'bad key'          => [ 401, [ 'title' => 'API Key Invalid', 'status' => 401, 'detail' => 'Your API key may be invalid' ], 'crazylinks_mc_bad_key', false ],
			'audience missing' => [ 404, [ 'title' => 'Resource Not Found', 'status' => 404 ], 'crazylinks_mc_audience_not_found', false ],
			'member exists'    => [ 400, [ 'title' => 'Member Exists', 'status' => 400, 'detail' => 'fan@example.com is already a list member' ], 'crazylinks_mc_member_exists', false ],
			'compliance'       => [ 400, [ 'title' => 'Member In Compliance State', 'status' => 400 ], 'crazylinks_mc_member_state', false ],
			'rate limited'     => [ 429, [ 'title' => 'Too Many Requests', 'status' => 429 ], 'crazylinks_mc_rate_limited', true ],
			'server'           => [ 503, 'Service Unavailable', 'crazylinks_mc_server', true ],
			'other 400'        => [ 400, [ 'title' => 'Invalid Resource', 'detail' => 'Please provide a valid email: x@y.z' ], 'crazylinks_mc_rejected', false ],
			'garbage 200'      => [ 200, '<html>nope</html>', 'crazylinks_mc_bad_response', true ],
		];
	}

	/**
	 * @dataProvider errors
	 *
	 * @param int    $code  HTTP status.
	 * @param mixed  $body  Body.
	 * @param string $error Expected code.
	 * @param bool   $retry Expected retry flag.
	 */
	public function test_errors_are_mapped_to_plain_language( int $code, $body, string $error, bool $retry ): void {
		$this->answer = fn() => $this->res( $code, $body );
		$result       = ( new Mailchimp( self::KEY ) )->push( [ 'email' => 'fan@example.com' ], self::AUDIENCE, [] );
		$this->assertWPError( $result );
		$this->assertSame( $error, $result->get_error_code() );
		$this->assertSame( $retry, $result->get_error_data()['retry'] );
		$this->assertStringNotContainsString( self::KEY, $result->get_error_message() );
		$this->assertStringNotContainsString( '@', $result->get_error_message(), 'Addresses from Mailchimp details are redacted' );
		$this->assertNotSame( '', $result->get_error_message() );
	}

	public function test_transport_failure_is_retryable(): void {
		$this->answer = fn() => new \WP_Error( 'http_request_failed', 'cURL error 28' );
		$result       = ( new Mailchimp( self::KEY ) )->test();
		$this->assertSame( 'crazylinks_mc_unreachable', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retry'] );
	}

	public function test_remove_uses_delete_permanent_and_treats_404_as_done(): void {
		$mc = new Mailchimp( self::KEY );
		$this->assertTrue( $mc->remove( 'Fan@Example.com', self::AUDIENCE ) );
		$this->assertSame( 'POST', $this->requests[0]['method'] );
		$this->assertSame( self::API . '/lists/' . self::AUDIENCE . '/members/' . md5( 'fan@example.com' ) . '/actions/delete-permanent', $this->requests[0]['url'] );
		$this->answer = fn() => $this->res( 404, [ 'title' => 'Resource Not Found' ] );
		$this->assertTrue( $mc->remove( 'gone@example.com', self::AUDIENCE ) );
	}

	public function test_register_webhook_creates_it_once(): void {
		$hooks        = [];
		$this->answer = function ( string $method, string $url, array $body ) use ( &$hooks ) {
			if ( 'GET' === $method ) {
				return $this->res( 200, [ 'webhooks' => $hooks ] );
			}
			$hooks[] = [ 'url' => $body['url'] ];
			return $this->res( 200, [ 'id' => 'wh1' ] + $body );
		};
		$mc  = new Mailchimp( self::KEY );
		$url = Integrations::webhook_url();
		$this->assertSame( 'created', $mc->register_webhook( self::AUDIENCE, $url ) );
		$post = $this->sent_to( '/webhooks', 'POST' )[0];
		$this->assertSame( self::API . '/lists/' . self::AUDIENCE . '/webhooks', $post['url'] );
		$this->assertSame( $url, $post['body']['url'] );
		$this->assertTrue( $post['body']['events']['subscribe'] && $post['body']['events']['unsubscribe'] && $post['body']['events']['cleaned'] );
		$this->assertSame(
			[
				'user'  => true,
				'admin' => true,
				'api'   => true,
			],
			$post['body']['sources']
		);
		$this->assertSame( 'exists', $mc->register_webhook( self::AUDIENCE, $url ) );
		$this->assertCount( 1, $this->sent_to( '/webhooks', 'POST' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Settings.

	public function test_key_is_masked_and_a_resubmitted_mask_keeps_it(): void {
		$this->assertSame( str_repeat( '•', 12 ) . substr( self::KEY, -4 ), Integrations::mask( self::KEY ) );
		$mask = Integrations::mask( self::KEY );
		$this->assertStringEndsWith( '-us6', $mask );
		$this->assertStringNotContainsString( '0123456789', $mask );

		$out = Settings::sanitize(
			[
				'subscriber_adapter' => 'mailchimp',
				'mailchimp_api_key'  => $mask,
				'mailchimp_audience' => self::AUDIENCE,
			]
		);
		$this->assertSame( self::KEY, $out['mailchimp_api_key'], 'The mask keeps the stored key' );

		$out = Settings::sanitize(
			[
				'subscriber_adapter' => 'mailchimp',
				'mailchimp_api_key'  => 'definitely not a key',
			]
		);
		$this->assertSame( self::KEY, $out['mailchimp_api_key'], 'An invalid key keeps the previous one' );

		$out = Settings::sanitize(
			[
				'subscriber_adapter' => 'nope',
				'mailchimp_api_key'  => '',
				'mailchimp_audience' => 'Bad Id!',
			]
		);
		$this->assertSame( '', $out['mailchimp_api_key'], 'Clearing the field removes the key' );
		$this->assertSame( '', $out['subscriber_adapter'] );
		$this->assertSame( self::AUDIENCE, $out['mailchimp_audience'], 'A malformed audience keeps the previous one' );

		$out = Settings::sanitize( [ 'base' => 'l' ] );
		$this->assertSame( self::KEY, $out['mailchimp_api_key'], 'A partial update without the section leaves it alone' );
	}

	public function test_first_ever_save_is_idempotent(): void {
		delete_option( Settings::OPTION );
		$this->assertFalse( get_option( Settings::OPTION ) );
		$passes = 0;
		add_filter(
			'sanitize_option_' . Settings::OPTION,
			static function ( $value ) use ( &$passes ) {
				++$passes;
				return Settings::sanitize( $value );
			}
		);
		// update_option() on a missing option runs the sanitizer, then add_option() runs it again on its own output.
		update_option(
			Settings::OPTION,
			[
				'base'               => 'l',
				'subscriber_adapter' => 'mailchimp',
				'mailchimp_api_key'  => self::KEY,
				'mailchimp_audience' => self::AUDIENCE,
			]
		);
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		$this->assertSame( 2, $passes, 'WordPress sanitizes a first save twice' );
		$saved = get_option( Settings::OPTION );
		$this->assertSame( self::KEY, $saved['mailchimp_api_key'] );
		$this->assertSame( 'mailchimp', $saved['subscriber_adapter'] );
		$this->assertSame( self::AUDIENCE, $saved['mailchimp_audience'] );
		$this->assertSame( $saved, Settings::sanitize( $saved ), 'Sanitizing the output again changes nothing' );
	}

	public function test_key_is_never_in_rest_output_or_page_json(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$post = $this->page();
		wp_set_current_user( $admin );
		$outputs = [];
		foreach ( [
			[ 'GET', '/crazylinks/v1/integrations/audiences' ],
			[ 'GET', '/wp/v2/settings' ],
			[ 'GET', '/crazylinks/v1/pages/' . $post->ID ],
			[ 'GET', '/crazylinks/v1/subscribers' ],
		] as [ $method, $route ] ) {
			$outputs[] = (string) wp_json_encode( rest_get_server()->response_to_data( rest_do_request( new \WP_REST_Request( $method, $route ) ), false ) );
		}
		$outputs[] = (string) $post->post_content;
		foreach ( $outputs as $json ) {
			$this->assertStringNotContainsString( self::KEY, $json );
			$this->assertStringNotContainsString( '0123456789abcdef', $json );
		}
	}

	public function test_audiences_route_permissions_and_cache(): void {
		$req = fn() => rest_do_request( new \WP_REST_Request( 'GET', '/crazylinks/v1/integrations/audiences' ) );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $req()->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $req()->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 403, $req()->get_status(), 'Admin only (editors get a text field in the editor)' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$res = $req();
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertTrue( $data['available'] );
		$this->assertSame( self::AUDIENCE, $data['default'] );
		$this->assertSame( 'Fans', $data['audiences'][0]['name'] );
		$req();
		$this->assertCount( 1, $this->sent_to( '/lists?' ), 'Cached for 10 minutes' );
	}

	public function test_audiences_route_reports_a_bad_key_plainly(): void {
		$this->answer = fn() => $this->res( 401, [ 'title' => 'API Key Invalid' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$data = rest_do_request( new \WP_REST_Request( 'GET', '/crazylinks/v1/integrations/audiences' ) )->get_data();
		$this->assertFalse( $data['available'] );
		$this->assertStringContainsString( 'API key', $data['error'] );
	}

	public function test_connection_test_is_remembered(): void {
		$this->assertTrue( Integrations::test() );
		$this->assertTrue( Integrations::last_test()['ok'] );
		$this->answer = fn() => $this->res( 401, [ 'title' => 'API Key Invalid' ] );
		$this->assertWPError( Integrations::test() );
		$this->assertFalse( Integrations::last_test()['ok'] );
		$this->assertStringContainsString( 'API key', Integrations::last_test()['message'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Sign-up flow.

	public function test_signup_on_a_mailchimp_block_pushes_once_and_sends_no_email(): void {
		$post  = $this->page( [ 'tags' => [ 'bio-link' ] ] );
		$fired = [];
		add_action( 'crazylinks_subscriber_pending', static function () use ( &$fired ) {
			$fired[] = 'pending';
		} );
		$res = $this->subscribe( $post );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'pending', $res->get_data()['status'] );
		$this->assertSame( 'Check your email to confirm your subscription.', $res->get_data()['message'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'Mailchimp sends the confirmation, not wp_mail' );
		$puts = $this->sent_to( '/members/', 'PUT' );
		$this->assertCount( 1, $puts, 'Pushed once' );
		$this->assertSame( 'pending', $puts[0]['body']['status_if_new'] );
		$this->assertSame( 5, $puts[0]['timeout'], 'Inline push uses the short timeout' );
		$this->assertCount( 1, $this->sent_to( '/tags', 'POST' ) );

		$row = Store::find( $post->ID, 'fan@example.com' );
		$this->assertSame( 'mailchimp', $row['destination'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( self::AUDIENCE, $row['audience_id'] );
		$this->assertSame( 'sent', $row['sync_status'] );
		$this->assertSame( '1', (string) $row['sync_attempts'] );
		$this->assertSame( md5( 'fan@example.com' ), $row['external_id'] );
		$this->assertNotEmpty( $row['synced_at'] );
		$this->assertSame( 'Yes, email me.', $row['consent_text'] );
		$this->assertSame( [ 'pending' ], $fired );

		// Signing up again moments later does not push again.
		$this->subscribe( $post );
		$this->assertCount( 1, $this->sent_to( '/members/', 'PUT' ) );
	}

	public function test_block_audience_overrides_the_default(): void {
		$post = $this->page( [ 'audience_id' => 'f0f0f0f0f0' ] );
		$this->subscribe( $post );
		$this->assertStringContainsString( '/lists/f0f0f0f0f0/members/', $this->sent_to( '/members/', 'PUT' )[0]['url'] );
		$this->assertSame( 'f0f0f0f0f0', Store::find( $post->ID, 'fan@example.com' )['audience_id'] );
	}

	public function test_an_already_subscribed_member_is_confirmed_locally(): void {
		$this->answer = fn( $m, $u, $b ) => str_contains( $u, '/members/' ) ? $this->res( 200, $this->member( 'fan@example.com', 'subscribed' ) ) : $this->res( 204 );
		$post         = $this->page();
		$this->subscribe( $post );
		$row = Store::find( $post->ID, 'fan@example.com' );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertNotEmpty( $row['confirmed_at'] );
	}

	public function test_failed_push_is_retried_with_backoff_up_to_five_attempts(): void {
		$this->answer = fn() => $this->res( 503, 'down' );
		$post         = $this->page();
		$res          = $this->subscribe( $post );
		$this->assertSame( 200, $res->get_status(), 'The visitor still gets the pending answer' );
		$this->assertSame( 'pending', $res->get_data()['status'] );
		$row = Store::find( $post->ID, 'fan@example.com' );
		$id  = (int) $row['id'];
		$this->assertSame( 'failed', $row['sync_status'] );
		$this->assertSame( '1', (string) $row['sync_attempts'] );
		$this->assertStringContainsString( 'temporary problem', $row['sync_error'] );
		$next = wp_next_scheduled( Sync::HOOK_PUSH, [ $id ] );
		$this->assertNotFalse( $next );
		$this->assertEqualsWithDelta( time() + 60, $next, 5 );

		$expected = [ 2 => 240, 3 => 960, 4 => 3840 ];
		foreach ( $expected as $attempt => $delay ) {
			wp_unschedule_event( (int) wp_next_scheduled( Sync::HOOK_PUSH, [ $id ] ), Sync::HOOK_PUSH, [ $id ] );
			do_action( Sync::HOOK_PUSH, $id );
			$this->assertSame( (string) $attempt, (string) Store::get( $id )['sync_attempts'] );
			$this->assertEqualsWithDelta( time() + $delay, wp_next_scheduled( Sync::HOOK_PUSH, [ $id ] ), 5 );
		}
		wp_unschedule_event( (int) wp_next_scheduled( Sync::HOOK_PUSH, [ $id ] ), Sync::HOOK_PUSH, [ $id ] );
		do_action( Sync::HOOK_PUSH, $id );
		$this->assertSame( '5', (string) Store::get( $id )['sync_attempts'] );
		$this->assertFalse( wp_next_scheduled( Sync::HOOK_PUSH, [ $id ] ), 'No sixth attempt' );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );

		// Mailchimp is back: the admin's Retry sync starts again and succeeds.
		$this->answer = fn( string $m, string $u, array $b ) => $this->default_answer( $m, $u, $b );
		$this->assertSame( 1, Sync::retry( [ $id ] ) );
		$row = Store::get( $id );
		$this->assertSame( 'sent', $row['sync_status'] );
		$this->assertSame( '', $row['sync_error'] );
		$this->assertSame( '1', (string) $row['sync_attempts'] );
	}

	public function test_permanent_errors_are_not_retried(): void {
		$this->answer = fn() => $this->res( 400, [ 'title' => 'Member In Compliance State' ] );
		$post         = $this->page();
		$this->subscribe( $post );
		$row = Store::find( $post->ID, 'fan@example.com' );
		$this->assertSame( 'failed', $row['sync_status'] );
		$this->assertFalse( wp_next_scheduled( Sync::HOOK_PUSH, [ (int) $row['id'] ] ) );
	}

	public function test_without_a_connection_the_local_double_opt_in_takes_over(): void {
		$this->connect( [ 'mailchimp_api_key' => '' ] );
		$post = $this->page();
		$res  = $this->subscribe( $post );
		$this->assertSame( 'pending', $res->get_data()['status'] );
		$this->assertSame( [], $this->requests );
		$this->assertNotFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'The local confirmation email goes out' );
		$row = Store::find( $post->ID, 'fan@example.com' );
		$this->assertSame( 'local', $row['destination'] );
		$this->assertSame( 'skipped', $row['sync_status'] );
		$this->assertStringContainsString( 'not connected', $row['sync_error'] );
	}

	public function test_local_blocks_are_unchanged(): void {
		$post = $this->page( [ 'destination' => 'local' ] );
		$this->subscribe( $post );
		$this->assertSame( [], $this->requests );
		$this->assertNotFalse( tests_retrieve_phpmailer_instance()->get_sent() );
		$row = Store::find( $post->ID, 'fan@example.com' );
		$this->assertSame( 'local', $row['destination'] );
		$this->assertSame( '', $row['sync_status'] );
	}

	public function test_template_shows_the_mailchimp_pending_text(): void {
		$post = $this->page();
		$html = ( new \CrazyLinks\Render\Renderer() )->render_post( $post, 'standalone' );
		$this->assertStringContainsString( 'Check your email to confirm your subscription.', $html );
	}

	// ---------------------------------------------------------------------------------------------
	// Webhook.

	public function test_webhook_get_answers_200(): void {
		$this->assertSame( 200, $this->webhook( [], 'anything', 'GET' )->get_status() );
	}

	public function test_webhook_needs_the_secret(): void {
		Integrations::webhook_secret();
		$res = $this->webhook(
			[
				'type' => 'subscribe',
				'data' => [
					'email'   => 'fan@example.com',
					'list_id' => self::AUDIENCE,
				],
			],
			'wrong'
		);
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 403, $this->webhook( [ 'type' => 'subscribe' ], '' )->get_status() );
	}

	public function test_webhook_subscribe_confirms_and_unsubscribe_or_cleaned_unsubscribes(): void {
		$post = $this->page();
		$this->subscribe( $post );
		$this->subscribe( $this->page( [], 'mc-page-2' ), 'fan@example.com' );
		$event = fn( string $type, string $email = 'fan@example.com' ) => $this->webhook(
			[
				'type'     => $type,
				'fired_at' => '2026-11-06 12:00:00',
				'data'     => [
					'id'      => 'abc',
					'email'   => $email,
					'list_id' => self::AUDIENCE,
				],
			]
		);
		$this->assertSame( 200, $event( 'subscribe' )->get_status() );
		foreach ( Store::find_in_audience( 'fan@example.com', self::AUDIENCE ) as $row ) {
			$this->assertSame( 'confirmed', $row['status'] );
			$this->assertNotEmpty( $row['confirmed_at'] );
		}
		$this->assertCount( 2, Store::find_in_audience( 'fan@example.com', self::AUDIENCE ), 'Every matching row' );

		$this->assertSame( 200, $event( 'unsubscribe' )->get_status() );
		$this->assertSame( 'unsubscribed', Store::find( $post->ID, 'fan@example.com' )['status'] );

		$event( 'subscribe' );
		$event( 'cleaned' );
		$this->assertSame( 'unsubscribed', Store::find( $post->ID, 'fan@example.com' )['status'] );

		$before = Store::query( [] )['items'];
		$res    = $event( 'subscribe', 'stranger@example.com' );
		$this->assertSame( 200, $res->get_status(), 'Unknown addresses are ignored quietly' );
		$this->assertSame( [ 'ok' => true ], $res->get_data() );
		$this->assertEquals( $before, Store::query( [] )['items'] );
		$this->assertSame( 200, $event( 'profile' )->get_status(), 'Other events are acknowledged and ignored' );
	}

	public function test_webhook_is_rate_limited_per_ip(): void {
		add_filter( 'crazylinks_webhook_rate_limit', static fn() => 2 );
		$this->webhook( [] );
		$this->webhook( [] );
		$this->assertSame( 429, $this->webhook( [] )->get_status() );
	}

	// ---------------------------------------------------------------------------------------------
	// Backfill, privacy, CSV.

	public function test_backfill_pushes_only_locally_confirmed_rows_as_subscribed(): void {
		$post   = $this->page( [ 'destination' => 'local', 'tags' => [ 'old-list' ] ] );
		$common = [
			'page_id'      => $post->ID,
			'block_id'     => self::FORM,
			'consent_text' => '',
			'token'        => Store::new_token(),
		];
		Store::insert( $common + [ 'email' => 'confirmed@example.com', 'status' => 'confirmed' ] );
		Store::insert( $common + [ 'email' => 'pending@example.com', 'status' => 'pending' ] );
		Store::insert( $common + [ 'email' => 'gone@example.com', 'status' => 'unsubscribed' ] );
		$sent = Store::insert( $common + [ 'email' => 'sent@example.com', 'status' => 'confirmed', 'sync_status' => 'sent' ] );
		$this->assertGreaterThan( 0, $sent );

		$this->assertTrue( Sync::backfill_start() );
		$this->assertNotFalse( wp_next_scheduled( Sync::HOOK_BACKFILL ), 'Runs through cron' );
		while ( Sync::backfill_batch() ) {
			// Foreground loop, as WP-CLI does.
		}
		$puts = $this->sent_to( '/members/', 'PUT' );
		$this->assertCount( 1, $puts );
		$this->assertSame( 'confirmed@example.com', $puts[0]['body']['email_address'] );
		$this->assertSame( 'subscribed', $puts[0]['body']['status_if_new'] );
		$this->assertSame( [ 'old-list' ], $puts[0]['body']['tags'] );
		$this->assertSame( 'sent', Store::find( $post->ID, 'confirmed@example.com' )['sync_status'] );
		$state = Sync::backfill_state();
		$this->assertFalse( $state['running'] );
		$this->assertSame( 1, $state['sent'] );
		$this->assertSame( 0, $state['failed'] );
	}

	public function test_eraser_removes_from_mailchimp_and_exporter_shows_sync(): void {
		$post = $this->page();
		$this->subscribe( $post );
		$export = ( new PersonalData() )->export( 'fan@example.com' );
		$names  = wp_list_pluck( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'Mailchimp', $names['Destination'] );
		$this->assertStringStartsWith( 'sent', $names['Sent to email service'] );

		$this->requests = [];
		$out            = ( new PersonalData() )->erase( 'fan@example.com' );
		$this->assertTrue( $out['items_removed'] );
		$this->assertFalse( $out['items_retained'] );
		$this->assertCount( 1, $this->sent_to( '/actions/delete-permanent', 'POST' ) );
		$this->assertNull( Store::find( $post->ID, 'fan@example.com' ) );
	}

	public function test_eraser_reports_a_mailchimp_failure(): void {
		$post = $this->page();
		$this->subscribe( $post );
		$this->answer = fn() => $this->res( 401, [ 'title' => 'API Key Invalid' ] );
		$out          = ( new PersonalData() )->erase( 'fan@example.com' );
		$this->assertTrue( $out['items_removed'] );
		$this->assertTrue( $out['items_retained'] );
		$this->assertStringContainsString( self::AUDIENCE, $out['messages'][0] );
	}

	public function test_csv_has_sync_columns(): void {
		$post = $this->page();
		$this->subscribe( $post );
		$row  = Store::find( $post->ID, 'fan@example.com' );
		$cols = array_combine( \CrazyLinks\Analytics\Csv::SUBSCRIBER_HEADER, \CrazyLinks\Analytics\Csv::subscriber_row( $row ) );
		$this->assertSame( 'mailchimp', $cols['destination'] );
		$this->assertSame( 'sent', $cols['sync_status'] );
		$this->assertSame( md5( 'fan@example.com' ), $cols['external_id'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Schema and upgrade.

	public function test_block_schema_accepts_the_new_props_and_rejects_a_bad_audience(): void {
		$block = fn( array $props ) => $this->doc(
			[
				[
					'id'    => self::FORM,
					'type'  => 'email_capture',
					'props' => $props,
				],
			]
		);
		$user  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$ok    = ( new PageValidator() )->validate(
			$block(
				[
					'destination' => 'mailchimp',
					'audience_id' => self::AUDIENCE,
					'tags'        => [ 'fans', 'bio' ],
				]
			),
			$user
		);
		$this->assertTrue( $ok->is_valid(), (string) wp_json_encode( $ok->errors() ) );
		foreach ( [
			[ 'audience_id' => 'Bad-ID!' ],
			[ 'audience_id' => '../x' ],
			[ 'destination' => 'kit' ],
			[ 'tags' => array_fill( 0, 11, 'x' ) ],
		] as $bad ) {
			$this->assertFalse( ( new PageValidator() )->validate( $block( $bad ), $user )->is_valid(), (string) wp_json_encode( $bad ) );
		}
	}

	public function test_db_upgrade_adds_the_sync_columns(): void {
		global $wpdb;
		$table = Store::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX sync_status, DROP INDEX audience_email, DROP COLUMN destination, DROP COLUMN audience_id, DROP COLUMN sync_status, DROP COLUMN sync_attempts, DROP COLUMN sync_error, DROP COLUMN synced_at, DROP COLUMN external_id" );
		// phpcs:enable
		$this->assertNotContains( 'sync_status', $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( 'crazylinks_db_version', '5' );
		Activator::maybe_upgrade();
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( [ 'destination', 'audience_id', 'sync_status', 'sync_attempts', 'sync_error', 'synced_at', 'external_id' ] as $col ) {
			$this->assertContains( $col, $cols );
		}
		$this->assertSame( '6', get_option( 'crazylinks_db_version' ) );
	}

	public function test_editing_guide_and_validator_know_the_audiences(): void {
		Integrations::audiences();
		$guide = \CrazyLinks\Ai\EditingGuide::build()['guide'];
		$this->assertStringContainsString( '`' . self::AUDIENCE . '` (Fans)', $guide );
		$this->assertStringContainsString( 'must be one of the Mailchimp audiences', $guide );
		$this->assertStringNotContainsString( self::KEY, $guide );
	}
}
