<?php
/**
 * Payment buttons.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Analytics\ClickRedirect;
use CrazyLinks\Analytics\Visitor;
use CrazyLinks\Render\Renderer;

/**
 * Payment buttons go through /go/ so clicks are counted, and the redirect carries the UTM parameters.
 */
class PaymentButtonTest extends TestCase {

	private const PAY = '01HZZZZZZZZZZZZZZZZZZZZZZP';

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
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
}
