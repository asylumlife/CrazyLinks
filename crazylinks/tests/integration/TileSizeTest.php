<?php
/**
 * Bento tile size.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

/**
 * Link tile sizes add a grid class and are validated.
 */
class TileSizeTest extends TestCase {

	public function test_tile_size_adds_a_class_and_is_validated(): void {
		$doc  = $this->doc(
			[
				[
					'type'  => 'link',
					'props' => [
						'title'     => 'Big',
						'url'       => 'https://example.com',
						'tile_size' => '2x2',
					],
				],
				[
					'type'  => 'link',
					'props' => [
						'title' => 'Small',
						'url'   => 'https://example.com/s',
					],
				],
			]
		);
		$doc['design'] = [ 'layout' => 'grid' ];
		$html          = $this->render_as_admin( $doc );
		$this->assertSame( 1, substr_count( $html, 'cl-tile-2x2' ) );
		$this->assertStringContainsString( 'cl-layout-grid', $html );
		$doc['blocks'][0]['props']['tile_size'] = '3x3';
		$this->assertFalse( $this->validate_as_admin( $doc )->is_valid() );
	}
}
