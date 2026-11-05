<?php
/**
 * Subscriber flow, privacy tools, REST admin routes and CSV.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Analytics\Csv;
use CrazyLinks\Settings;
use CrazyLinks\Subscribers\PersonalData;
use CrazyLinks\Subscribers\Service;
use CrazyLinks\Subscribers\Store;

/**
 * Double opt-in sign-up, confirmation, unsubscribe and the surrounding guarantees (SPEC §12).
 */
class SubscribersTest extends TestCase {

	private const FORM = '01HZZZZZZZZZZZZZZZZZZZZZZE';

	/**
	 * Fired hooks, in order.
	 *
	 * @var string[]
	 */
	private array $fired = [];

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR']     = '198.51.100.77';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
		add_filter( 'crazylinks_subscribe_rate_limit', static fn() => 1000 );
		reset_phpmailer_instance();
		$this->fired = [];
		foreach ( [ 'added', 'pending', 'confirmed', 'unsubscribed' ] as $name ) {
			add_action(
				'crazylinks_subscriber_' . $name,
				function ( $row, $page_id, $block_id ) use ( $name ): void {
					$this->assertIsArray( $row );
					$this->assertArrayNotHasKey( 'token_hash', $row );
					$this->assertSame( (int) $row['page_id'], $page_id );
					$this->assertSame( self::FORM, $block_id );
					$this->fired[] = $name;
				},
				10,
				3
			);
		}
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function page( string $slug = 'sub-page', array $props = [ 'consent_text' => 'Yes, email me.' ] ): \WP_Post {
		$post = $this->make_page(
			$this->doc(
				[
					[
						'id'    => self::FORM,
						'type'  => 'email_capture',
						'props' => $props,
					],
				]
			),
			$slug
		);
		wp_set_current_user( 0 );
		return $post;
	}

	/**
	 * @param array<string, mixed> $body Body.
	 */
	private function subscribe( \WP_Post $post, array $body = [], bool $form = false ): \WP_REST_Response {
		$body += [
			'page_id'  => $post->ID,
			'block_id' => self::FORM,
			'email'    => 'Fan@Example.com',
			'consent'  => true,
		];
		$req = new \WP_REST_Request( 'POST', '/crazylinks/v1/subscribe' );
		$body = array_filter( $body, static fn( $v ) => null !== $v );
		if ( $form ) {
			$req->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
			$req->set_body_params( array_map( 'strval', array_map( static fn( $v ) => true === $v ? '1' : $v, $body ) ) );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $req );
	}

	private function get_link( string $action, string $token ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'GET', '/crazylinks/v1/subscribe/' . $action );
		$req->set_query_params( [ 'token' => $token ] );
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Token from the Nth sent email.
	 */
	private function token_from_mail( string $action, int $index = 0 ): string {
		$mail = tests_retrieve_phpmailer_instance()->get_sent( $index );
		$this->assertNotFalse( $mail );
		$this->assertSame( 1, preg_match( '#subscribe(?:/|%2F)' . $action . '[^\s]*?token=([a-f0-9]{48})#', $mail->body, $m ), $mail->body );
		return $m[1];
	}

	private function row( int $page_id, string $email = 'fan@example.com' ): array {
		$row = Store::find( $page_id, $email );
		$this->assertNotNull( $row );
		return $row;
	}

	public function test_table_has_no_ip_or_user_agent_columns(): void {
		global $wpdb;
		$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( [ 'id', 'page_id', 'block_id', 'email', 'status', 'consent_text', 'consent_at', 'confirmed_at', 'token_hash', 'created_at', 'updated_at' ], $cols );
	}

	public function test_full_double_opt_in_flow(): void {
		$post = $this->page();
		$res  = $this->subscribe( $post );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'pending', $res->get_data()['status'] );

		$row = $this->row( $post->ID );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 'fan@example.com', $row['email'] );
		$this->assertSame( 'Yes, email me.', $row['consent_text'], 'Consent wording is snapshotted from the block' );
		$this->assertNotEmpty( $row['consent_at'] );
		$this->assertNull( $row['confirmed_at'] );
		$this->assertSame( self::FORM, $row['block_id'] );
		$this->assertStringNotContainsString( '198.51.100.77', wp_json_encode( $row ) );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'fan@example.com', tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );
		$this->assertStringContainsString( 'confirm', $mail->body );
		$this->assertStringContainsString( 'unsubscribe', $mail->body, 'Unsubscribe link in every email' );
		$this->assertStringContainsString( 'List-Unsubscribe', $mail->header );
		$token = $this->token_from_mail( 'confirm' );
		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT token_hash FROM ' . Store::table() . ' WHERE id = %d', $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( hash( 'sha256', $token ), $stored );
		$this->assertNotSame( $token, $stored );

		$res = $this->get_link( 'confirm', $token );
		$this->assertSame( 303, $res->get_status() );
		$loc = $res->get_headers()['Location'];
		$this->assertStringContainsString( 'subscribed=1', $loc );
		$this->assertStringContainsString( get_permalink( $post ), $loc );
		$this->assertStringEndsWith( '#cl-ec-' . self::FORM . '-m-done', $loc );
		$row = $this->row( $post->ID );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertNotEmpty( $row['confirmed_at'] );

		// A second click (mail scanners) changes nothing.
		$this->assertSame( 303, $this->get_link( 'confirm', $token )->get_status() );

		$res = $this->get_link( 'unsubscribe', $token );
		$this->assertSame( 303, $res->get_status() );
		$this->assertStringContainsString( 'unsubscribed=1', $res->get_headers()['Location'] );
		$this->assertSame( 'unsubscribed', $this->row( $post->ID )['status'] );
		$this->get_link( 'unsubscribe', $token );

		$this->assertSame( [ 'added', 'pending', 'confirmed', 'unsubscribed' ], $this->fired, 'Each hook fires once' );
	}

	public function test_expired_confirmation_token_does_not_confirm(): void {
		global $wpdb;
		$post = $this->page();
		$this->subscribe( $post );
		$token = $this->token_from_mail( 'confirm' );
		$wpdb->update( Store::table(), [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ], [ 'page_id' => $post->ID ] );

		$res = $this->get_link( 'confirm', $token );
		$this->assertSame( 303, $res->get_status() );
		$this->assertStringContainsString( 'subscribed=expired', $res->get_headers()['Location'] );
		$this->assertSame( 'pending', $this->row( $post->ID )['status'] );

		$wpdb->update( Store::table(), [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 6 * DAY_IN_SECONDS ) ], [ 'page_id' => $post->ID ] );
		$this->assertStringContainsString( 'subscribed=1', $this->get_link( 'confirm', $token )->get_headers()['Location'], 'Still valid inside 7 days' );
	}

	public function test_unknown_or_malformed_tokens_get_one_generic_error(): void {
		$a = $this->get_link( 'confirm', str_repeat( 'a', 48 ) );
		$b = $this->get_link( 'unsubscribe', 'nope' );
		$this->assertSame( 404, $a->get_status() );
		$this->assertSame( 404, $b->get_status() );
		$this->assertSame( $a->get_data(), $b->get_data() );
	}

	public function test_double_opt_in_off_stores_confirmed_and_sends_welcome(): void {
		update_option( Settings::OPTION, [ 'double_opt_in' => false ] );
		$post = $this->page();
		$res  = $this->subscribe( $post );
		$this->assertSame( 'confirmed', $res->get_data()['status'] );
		$row = $this->row( $post->ID );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertNotEmpty( $row['confirmed_at'] );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringNotContainsString( 'confirm', $mail->body );
		$this->assertStringContainsString( 'unsubscribe', $mail->body );
		$this->assertSame( [ 'added', 'confirmed' ], $this->fired );
	}

	public function test_responses_do_not_reveal_existing_addresses(): void {
		$post = $this->page();
		$new  = $this->subscribe( $post, [ 'email' => 'new@example.com' ] );
		$this->subscribe( $post, [ 'email' => 'known@example.com' ] );
		$token = $this->token_from_mail( 'confirm', 1 );
		$this->get_link( 'confirm', $token );
		$this->assertSame( 'confirmed', Store::find( $post->ID, 'known@example.com' )['status'] );

		$sent_before = count( tests_retrieve_phpmailer_instance()->mock_sent );
		$known       = $this->subscribe( $post, [ 'email' => 'KNOWN@example.com' ] );
		$pending     = $this->subscribe( $post, [ 'email' => 'new@example.com' ] );
		$this->assertSame( $new->get_data(), $known->get_data() );
		$this->assertSame( $new->get_data(), $pending->get_data() );
		$this->assertSame( $new->get_status(), $known->get_status() );
		$this->assertCount( $sent_before, tests_retrieve_phpmailer_instance()->mock_sent, 'No mail for known or just-mailed addresses' );
		$this->assertSame( 1, (int) Store::query( [ 'search' => 'known@' ] )['total'], 'Still one row' );
	}

	public function test_same_email_on_two_pages_is_two_rows(): void {
		$one = $this->page( 'p-one' );
		$two = $this->page( 'p-two' );
		$this->subscribe( $one );
		$this->subscribe( $two );
		$this->assertSame( 2, Store::query( [ 'search' => 'fan@example.com' ] )['total'] );
	}

	public function test_unsubscribed_address_can_sign_up_again_with_a_new_token(): void {
		$post = $this->page();
		$this->subscribe( $post );
		$old = $this->token_from_mail( 'confirm' );
		$this->get_link( 'unsubscribe', $old );
		reset_phpmailer_instance();

		$this->subscribe( $post );
		$this->assertSame( 'pending', $this->row( $post->ID )['status'] );
		$new = $this->token_from_mail( 'confirm' );
		$this->assertNotSame( $old, $new );
		$this->assertSame( 404, $this->get_link( 'confirm', $old )->get_status(), 'The old link is dead' );
		$this->assertSame( 303, $this->get_link( 'confirm', $new )->get_status() );
		$this->assertSame( 'confirmed', $this->row( $post->ID )['status'] );
	}

	public function test_resend_gives_a_fresh_token_and_only_for_pending(): void {
		$post = $this->page();
		$this->subscribe( $post );
		$old = $this->token_from_mail( 'confirm' );
		$id  = (int) $this->row( $post->ID )['id'];
		$this->assertTrue( Service::resend( $id ) );
		$new = $this->token_from_mail( 'confirm', 1 );
		$this->assertNotSame( $old, $new );
		$this->get_link( 'confirm', $new );
		$this->assertFalse( Service::resend( $id ), 'Confirmed rows are not resent' );
	}

	public function test_validation_errors(): void {
		$post = $this->page();
		$res  = $this->subscribe( $post, [ 'consent' => false ] );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'consent', $res->get_data()['data']['key'] );
		$res = $this->subscribe( $post, [ 'email' => 'not-an-email' ] );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid', $res->get_data()['data']['key'] );
		$this->assertSame( 0, Store::query()['total'] );
		$this->assertSame( 200, $this->subscribe( $this->page( 'no-consent', [] ), [ 'consent' => false ] )->get_status(), 'No consent text, no checkbox' );
	}

	public function test_plain_form_post_redirects_back_to_the_page(): void {
		$post = $this->page();
		$res  = $this->subscribe( $post, [], true );
		$this->assertSame( 303, $res->get_status() );
		$loc = $res->get_headers()['Location'];
		$this->assertStringContainsString( get_permalink( $post ), $loc );
		$this->assertStringContainsString( 'cl_sub=pending', $loc );
		$this->assertStringEndsWith( '#cl-ec-' . self::FORM . '-m-pending', $loc );

		$res = $this->subscribe( $post, [ 'consent' => null ], true );
		$this->assertStringContainsString( 'cl_sub=consent', $res->get_headers()['Location'] );

		$res = $this->subscribe( $post, [ 'block_id' => '01HZZZZZZZZZZZZZZZZZZZZZZQ' ], true );
		$this->assertSame( 404, $res->get_status(), 'Unreachable forms never redirect' );
	}

	public function test_form_template_renders_messages_and_no_js_post(): void {
		$post = $this->page();
		$html = $this->render_form( $post );
		$this->assertStringContainsString( 'data-cl-capture', $html );
		foreach ( [ 'pending', 'confirmed', 'done', 'unsub', 'expired', 'error' ] as $key ) {
			$this->assertStringContainsString( 'id="cl-ec-' . self::FORM . '-m-' . $key . '"', $html );
		}
		$this->assertStringContainsString( 'method="post"', $html );
	}

	private function render_form( \WP_Post $post ): string {
		$doc = \CrazyLinks\PageStore::get_document( $post ) ?? [];
		$ctx = \CrazyLinks\Render\RenderContext::for_post( $post, 'standalone', $doc );
		return ( new \CrazyLinks\Render\Renderer() )->render( $doc, $ctx );
	}

	public function test_integration_filter_short_circuits_local_storage(): void {
		$post = $this->page();
		$seen = [];
		add_filter(
			'crazylinks_subscribe_handle',
			static function ( $handled, $email, $page_id, $block_id ) use ( &$seen ) {
				$seen = [ $email, $page_id, $block_id ];
				return [ 'status' => 'pending' ];
			},
			10,
			4
		);
		$res = $this->subscribe( $post );
		$this->assertSame( 'pending', $res->get_data()['status'] );
		$this->assertSame( [ 'fan@example.com', $post->ID, self::FORM ], $seen );
		$this->assertSame( 0, Store::query()['total'] );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );

		remove_all_filters( 'crazylinks_subscribe_handle' );
		add_filter( 'crazylinks_subscribe_handle', static fn() => new \WP_Error( 'x', 'no' ) );
		$this->assertSame( 400, $this->subscribe( $post )->get_status() );
	}

	public function test_honeypot_and_rate_limit_still_apply(): void {
		$post = $this->page();
		$this->assertSame( 200, $this->subscribe( $post, [ 'website' => 'spam' ] )->get_status() );
		$this->assertSame( 0, Store::query()['total'] );
		remove_all_filters( 'crazylinks_subscribe_rate_limit' );
		$codes = [];
		for ( $i = 0; $i < 7; $i++ ) {
			$codes[] = $this->subscribe( $post, [ 'email' => "u{$i}@example.com" ] )->get_status();
		}
		$this->assertSame( [ 200, 200, 200, 200, 200, 429, 429 ], $codes );
	}

	public function test_purge_removes_stale_pending_and_unsubscribed_but_never_confirmed(): void {
		global $wpdb;
		$post = $this->page();
		foreach ( [ 'a' => 'pending', 'b' => 'unsubscribed', 'c' => 'confirmed', 'd' => 'pending' ] as $name => $status ) {
			Store::insert(
				[
					'page_id'      => $post->ID,
					'block_id'     => self::FORM,
					'email'        => "$name@example.com",
					'status'       => $status,
					'consent_text' => '',
					'token'        => Store::new_token(),
				]
			);
		}
		$old = gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Store::table() . ' SET updated_at = %s WHERE email IN (%s, %s, %s)', $old, 'a@example.com', 'b@example.com', 'c@example.com' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 0, Store::purge( 0 ) );
		$this->assertSame( 2, Store::purge( 90 ) );
		$left = array_column( Store::query()['items'], 'email' );
		sort( $left );
		$this->assertSame( [ 'c@example.com', 'd@example.com' ], $left );
	}

	public function test_exporter_and_eraser(): void {
		$one = $this->page( 'x-one' );
		$two = $this->page( 'x-two' );
		$this->subscribe( $one );
		$this->subscribe( $two );
		$this->subscribe( $one, [ 'email' => 'other@example.com' ] );

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', [] );
		$this->assertArrayHasKey( 'crazylinks-subscribers', $exporters );
		$this->assertArrayHasKey( 'crazylinks-subscribers', $erasers );

		$out = call_user_func( $exporters['crazylinks-subscribers']['callback'], 'FAN@example.com', 1 );
		$this->assertTrue( $out['done'] );
		$this->assertCount( 2, $out['data'] );
		$names = array_column( $out['data'][0]['data'], 'name' );
		$this->assertContains( 'Consent text', $names );
		$this->assertStringNotContainsString( 'other@example.com', wp_json_encode( $out ) );
		$this->assertStringNotContainsString( 'token', strtolower( wp_json_encode( $out ) ) );

		$res = call_user_func( $erasers['crazylinks-subscribers']['callback'], 'fan@example.com', 1 );
		$this->assertTrue( $res['items_removed'] );
		$this->assertTrue( $res['done'] );
		$this->assertNull( Store::find( $one->ID, 'fan@example.com' ) );
		$this->assertNull( Store::find( $two->ID, 'fan@example.com' ) );
		$this->assertNotNull( Store::find( $one->ID, 'other@example.com' ) );
		$again = ( new PersonalData() )->erase( 'fan@example.com' );
		$this->assertFalse( $again['items_removed'] );
	}

	public function test_policy_text_mentions_confirmation_and_unsubscribe(): void {
		$text = \CrazyLinks\Privacy::content();
		$this->assertStringContainsString( 'double opt-in', $text );
		$this->assertStringContainsString( 'unsubscribe', $text );
	}

	public function test_csv_cells_neutralize_formulas(): void {
		foreach ( [ '=1+1', '+SUM(A1)', '-2+3', '@cmd', "\tx", "\rx" ] as $bad ) {
			$this->assertSame( "'" . $bad, Csv::cell( $bad ), $bad );
		}
		$this->assertSame( 'plain', Csv::cell( 'plain' ) );
		$this->assertSame( '-5', Csv::cell( -5 ), 'Real numbers are untouched' );
		$this->assertSame( "\"a,b\",\"say \"\"hi\"\"\",'=x\r\n", Csv::line( [ 'a,b', 'say "hi"', '=x' ] ) );
		$row = Csv::subscriber_row(
			[
				'email'        => '-a@example.com',
				'status'       => 'pending',
				'page_id'      => 3,
				'block_id'     => 'B',
				'consent_text' => '=HYPERLINK("http://x")',
				'consent_at'   => '2026-01-01 00:00:00',
				'confirmed_at' => null,
				'created_at'   => '2026-01-01 00:00:00',
			]
		);
		$line = Csv::line( $row );
		$this->assertStringStartsWith( "'-a@example.com,", $line );
		$this->assertStringContainsString( "\"'=HYPERLINK(\"\"http://x\"\")\"", $line );
	}

	public function test_admin_rest_routes_need_manage_capability(): void {
		$post = $this->page();
		$this->subscribe( $post, [ 'email' => 'a@example.com' ] );
		$this->subscribe( $post, [ 'email' => 'b@other.org' ] );
		$this->get_link( 'confirm', $this->token_from_mail( 'confirm', 1 ) );

		$list = static function ( array $params ): \WP_REST_Response {
			$req = new \WP_REST_Request( 'GET', '/crazylinks/v1/subscribers' );
			$req->set_query_params( $params );
			return rest_get_server()->dispatch( $req );
		};
		$this->assertSame( 401, $list( [] )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertContains( $list( [] )->get_status(), [ 401, 403 ], 'Editors cannot read subscriber emails' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$res = $list( [ 'page_id' => $post->ID ] );
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 2, $res->get_data() );
		$this->assertSame( '2', $res->get_headers()['X-WP-Total'] );
		$this->assertArrayNotHasKey( 'token_hash', $res->get_data()[0] );
		$this->assertCount( 1, $list( [ 'status' => 'confirmed' ] )->get_data() );
		$this->assertCount( 1, $list( [ 'search' => 'other.org' ] )->get_data() );
		$this->assertCount( 1, $list( [ 'per_page' => 1 ] )->get_data() );

		$id  = (int) $list( [ 'search' => 'other.org' ] )->get_data()[0]['id'];
		$del = new \WP_REST_Request( 'DELETE', '/crazylinks/v1/subscribers/' . $id );
		$this->assertSame( 200, rest_get_server()->dispatch( $del )->get_status() );
		$this->assertSame( 404, rest_get_server()->dispatch( $del )->get_status() );
		$this->assertNull( Store::get( $id ) );
	}
}
