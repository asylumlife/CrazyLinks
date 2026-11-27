<?php
/**
 * oEmbed setting.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Frontend\Embeds;
use CrazyLinks\Settings;

/**
 * The fetch_oembed setting turns provider requests on and off.
 */
class OembedSettingTest extends TestCase {

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
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
