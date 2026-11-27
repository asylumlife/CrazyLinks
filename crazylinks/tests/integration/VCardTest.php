<?php
/**
 * Contact vCard.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Frontend\Router;
use CrazyLinks\Frontend\VCard;
use CrazyLinks\PostType;
use CrazyLinks\Render\Renderer;

/**
 * The /vcard/ route is built from public contact fields and respects visibility, schedule and password.
 */
class VCardTest extends TestCase {

	private const CONTACT = '01HZZZZZZZZZZZZZZZZZZZZZZC';

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
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
}
