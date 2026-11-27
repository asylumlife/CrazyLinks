<?php
/**
 * Focal point.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Render\Renderer;

/**
 * Focal points on thumbnails and banners render as object-position and are validated.
 */
class FocalPointTest extends TestCase {

	public function test_focal_point_renders_object_position(): void {
		$id   = $this->image();
		$doc  = $this->doc(
			[
				[
					'type'  => 'link',
					'props' => [
						'title'       => 'F',
						'url'         => 'https://example.com',
						'display'     => 'featured',
						'thumbnail'   => $id,
						'focal_point' => [ 'x' => 0.25, 'y' => 0.8 ],
					],
				],
			]
		);
		$doc['profile'] = [
			'name'               => 'B',
			'header_layout'      => 'banner',
			'banner'             => $id,
			'banner_focal_point' => [ 'x' => 1, 'y' => 0 ],
		];
		$html = $this->render_as_admin( $doc );
		$this->assertStringContainsString( 'object-position:25% 80%', $html );
		$this->assertStringContainsString( 'object-position:100% 0%', $html );
		$this->assertSame( '', Renderer::focal_style( [ 'x' => 0.5, 'y' => 0.5 ] ) );
		$this->assertSame( '', Renderer::focal_style( 'x' ) );
		$this->assertSame( 'object-position:100% 0%', Renderer::focal_style( [ 'x' => 7, 'y' => -3 ] ) );
		$this->assertFalse( $this->validate_as_admin( $this->doc( [ [ 'type' => 'link', 'props' => [ 'title' => 'F', 'url' => 'https://example.com', 'focal_point' => [ 'x' => 2, 'y' => 0 ] ] ] ] ) )->is_valid() );
	}
}
