<?php
/**
 * Post-review core changes: payment clicks through /go/, cache bump on post status changes,
 * the contact vCard route, the privacy-policy text and the oEmbed setting.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Analytics\ClickRedirect;
use CrazyLinks\Analytics\Visitor;
use CrazyLinks\Frontend\Embeds;
use CrazyLinks\Frontend\Router;
use CrazyLinks\Frontend\VCard;
use CrazyLinks\PostType;
use CrazyLinks\Privacy;
use CrazyLinks\Render\Cache;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Settings;

/**
 * Small core changes requested by the polish pass and the review's residual items.
 */
class CoreChangesTest extends TestCase {

	private const PAY     = '01HZZZZZZZZZZZZZZZZZZZZZZP';
	private const CONTACT = '01HZZZZZZZZZZZZZZZZZZZZZZC';

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_payment_buttons_go_through_go_with_utm(): void {
		$post = $this->make_page(
			$this->doc(
				[
					[
						'id'    => self::PAY,
						'type'  => 'payment',
						'props' => [
							'service' => 'kofi',
							'handle'  => 'someone',
						],
					],
				],
				[
					'utm' => [
						'enabled'  => true,
						'source'   => 'crazylinks',
						'medium'   => 'bio',
						'campaign' => '{slug}',
					],
				]
			),
			'pay-page'
		);
		$html = ( new Renderer() )->render_post( $post, 'standalone' );
		$this->assertStringContainsString( 'href="/l/pay-page/go/' . self::PAY . '/"', $html );
		$this->assertStringNotContainsString( 'href="https://ko-fi.com', $html );

		$result = ( new ClickRedirect() )->resolve( $post, self::PAY, new Visitor( '203.0.113.9', 'Mozilla/5.0 Safari/605.1.15', '' ) );
		$this->assertSame( 302, $result['status'] );
		$this->assertStringStartsWith( 'https://ko-fi.com/someone', $result['location'] );
		$this->assertStringContainsString( 'utm_source=crazylinks', $result['location'] );
		$this->assertSame( 'logged', $result['event'] );
	}

	public function test_publishing_a_public_post_bumps_the_render_cache(): void {
		Cache::bump();
		$salt = Cache::salt();
		$id   = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->assertSame( $salt, Cache::salt(), 'Draft creation changes nothing' );
		wp_publish_post( $id );
		$this->assertNotSame( $salt, Cache::salt(), 'Publishing a post refreshes latest_posts' );

		$salt = Cache::salt();
		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => 'Edited',
			]
		);
		$this->assertSame( $salt, Cache::salt(), 'Same-status updates do not bump' );
		wp_update_post(
			[
				'ID'          => $id,
				'post_status' => 'draft',
			]
		);
		$this->assertNotSame( $salt, Cache::salt(), 'Unpublishing bumps too' );

		$salt = Cache::salt();
		$this->make_page( $this->doc(), 'self' );
		$this->assertSame( $salt, Cache::salt(), 'Link pages only forget their own cache' );
	}

	private function contact_page( array $block = [], string $status = 'publish', string $slug = 'card' ): \WP_Post {
		$doc                    = $this->doc(
			[
				array_replace_recursive(
					[
						'id'    => self::CONTACT,
						'type'  => 'contact',
						'props' => [
							'phone'   => '+1 (512) 555-0100',
							'email'   => 'hello@example.com',
							'address' => '1 Main St; Suite 2, Austin',
							'vcard'   => true,
						],
					],
					$block
				),
			]
		);
		$doc['profile']['name'] = 'Ada, Lovelace';
		return $this->make_page( $doc, $slug, $status );
	}

	public function test_vcard_is_built_from_public_contact_fields(): void {
		$post   = $this->contact_page();
		$result = ( new VCard() )->resolve( $post );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'card.vcf', $result['filename'] );
		$body = $result['body'];
		$this->assertStringStartsWith( "BEGIN:VCARD\r\nVERSION:3.0\r\n", $body );
		$this->assertStringEndsWith( "END:VCARD\r\n", $body );
		$this->assertStringContainsString( "FN:Ada\\, Lovelace\r\n", $body );
		$this->assertStringContainsString( "TEL;TYPE=VOICE:+15125550100\r\n", $body );
		$this->assertStringContainsString( "EMAIL;TYPE=INTERNET:hello@example.com\r\n", $body );
		$this->assertStringContainsString( "ADR:;;1 Main St\\; Suite 2\\, Austin;;;;\r\n", $body );
		$this->assertStringContainsString( 'URL:' . home_url( '/l/card/' ), $body );
		foreach ( explode( "\r\n", $body ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ) );
		}

		$html = ( new Renderer() )->render_post( $post, 'standalone' );
		$this->assertStringContainsString( 'href="/l/card/vcard/"', $html );
		$this->assertSame( home_url( '/l/card/vcard/' ), Router::vcard_url( 'card' ) );
	}

	public function test_vcard_route_maps_and_respects_visibility_schedule_and_password(): void {
		$post = $this->contact_page();
		$this->go_to( home_url( '/l/card/vcard/' ) );
		$this->assertTrue( is_singular( PostType::NAME ) );
		$this->assertSame( '1', get_query_var( 'crazylinks_vcard' ) );
		$this->assertSame( $post->ID, get_queried_object_id() );

		$this->assertSame( 404, ( new VCard() )->resolve( $this->contact_page( [ 'props' => [ 'vcard' => false ] ], 'publish', 'off' ) )['status'], 'vcard prop off' );
		$this->assertSame( 404, ( new VCard() )->resolve( $this->contact_page( [ 'visible' => false ], 'publish', 'hidden' ) )['status'], 'hidden block' );
		$this->assertSame(
			404,
			( new VCard() )->resolve(
				$this->contact_page(
					[
						'schedule' => [
							'start' => '2999-01-01T00:00:00Z',
							'end'   => null,
						],
					],
					'publish',
					'later'
				)
			)['status'],
			'not started'
		);
		$this->assertSame( 404, ( new VCard() )->resolve( $this->contact_page( [], 'draft', 'draft-card' ) )['status'], 'draft' );

		$locked = $this->contact_page( [], 'publish', 'locked-card' );
		wp_update_post(
			[
				'ID'            => $locked->ID,
				'post_password' => 'pw',
			]
		);
		$this->assertSame( 404, ( new VCard() )->resolve( get_post( $locked->ID ) )['status'], 'password' );
		$this->assertStringNotContainsString( '/vcard/', ( new Renderer() )->render_post( $this->contact_page( [ 'props' => [ 'vcard' => false ] ], 'publish', 'nolink' ), 'standalone' ) );

		// A stray ?crazylinks_vcard=1 on any other URL is ignored.
		$page = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_status' => 'publish',
			]
		);
		$this->go_to( home_url( '/about/?crazylinks_vcard=1' ) );
		$this->assertTrue( is_page( $page ) );
		$this->assertSame( '', (string) get_query_var( 'crazylinks_vcard' ) );
	}

	public function test_privacy_policy_text_is_registered(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		$text = Privacy::content();
		foreach ( [ 'cookies', 'hash', 'every day', 'device class', 'inside an app', 'referred you', 'IP address', 'Limitation', 'email address' ] as $needle ) {
			$this->assertStringContainsString( $needle, $text, $needle );
		}
		set_current_screen( 'dashboard' );
		$GLOBALS['wp_current_filter'][] = 'admin_init';
		( new Privacy() )->add_policy_content();
		array_pop( $GLOBALS['wp_current_filter'] );
		$GLOBALS['current_screen'] = null;
		$names = array_column( \WP_Privacy_Policy_Content::get_suggested_policy_text(), 'plugin_name' );
		$this->assertContains( 'CrazyLinks', $names );
	}

	public function test_oembed_setting_turns_fetching_off(): void {
		$this->assertTrue( (bool) Settings::get( 'fetch_oembed' ), 'On by default' );
		$this->assertFalse( Settings::sanitize( [ 'base' => 'l' ] )['fetch_oembed'] );
		$this->assertTrue( Settings::sanitize( [ 'fetch_oembed' => '1' ] )['fetch_oembed'] );

		remove_filter( 'crazylinks_fetch_oembed', '__return_false' );
		$requests = 0;
		$block    = static function ( $pre ) use ( &$requests ) {
			++$requests;
			return new \WP_Error( 'blocked', 'No network in tests' );
		};
		add_filter( 'pre_http_request', $block );
		$doc = [
			'blocks' => [
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZV',
					'type'  => 'embed',
					'props' => [ 'url' => 'https://www.youtube.com/watch?v=abcdefghijk' ],
				],
			],
		];
		$id  = self::factory()->post->create();

		update_option( Settings::OPTION, [ 'fetch_oembed' => false ] );
		Embeds::prime( $id, $doc );
		$this->assertSame( 0, $requests, 'Setting off: no request' );

		update_option( Settings::OPTION, [ 'fetch_oembed' => true ] );
		Embeds::prime( $id, $doc );
		$this->assertGreaterThan( 0, $requests, 'Setting on: the provider is asked' );

		remove_filter( 'pre_http_request', $block );
		add_filter( 'crazylinks_fetch_oembed', '__return_false' );
	}
}
