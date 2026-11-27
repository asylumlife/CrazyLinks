<?php
/**
 * Alt text warnings.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

/**
 * Missing alt text is a non-blocking warning; alt text, decorative images and the media library silence it.
 */
class AltTextWarningsTest extends TestCase {

	public function test_missing_alt_text_is_a_warning_not_an_error(): void {
		$id     = $this->image();
		$result = $this->validate_as_admin(
			$this->doc(
				[
					[
						'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZA',
						'type'  => 'image',
						'props' => [ 'image' => $id ],
					],
					[
						'type'  => 'gallery',
						'props' => [
							'items' => [
								[ 'image' => $id ],
								[
									'image' => $id,
									'alt'   => 'A field',
								],
								[
									'image'      => $id,
									'decorative' => true,
								],
							],
						],
					],
					[
						'type'  => 'link',
						'props' => [
							'title'     => 'L',
							'url'       => 'https://example.com',
							'display'   => 'featured',
							'thumbnail' => $id,
						],
					],
				]
			)
		);
		$this->assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		$paths = array_column( $result->warnings(), 'path' );
		$this->assertSame( [ '/blocks/0/props/alt', '/blocks/1/props/items/0/alt', '/blocks/2/props/thumbnail_alt' ], $paths );
		$this->assertSame( 'missing_alt', $result->warnings()[0]['code'] );
		$this->assertSame( '01HZZZZZZZZZZZZZZZZZZZZZZA', $result->warnings()[0]['block_id'] );
	}

	public function test_alt_text_silences_the_warning_and_decorative_renders_empty_alt(): void {
		$id   = $this->image();
		$doc  = $this->doc(
			[
				[
					'type'  => 'image',
					'props' => [
						'image' => $id,
						'alt'   => 'Canola field',
					],
				],
				[
					'type'  => 'image',
					'props' => [
						'image'      => $id,
						'decorative' => true,
					],
				],
			]
		);
		$result = $this->validate_as_admin( $doc );
		$this->assertSame( [], $result->warnings() );
		$html = $this->render_as_admin( $doc );
		$this->assertStringContainsString( 'alt="Canola field"', $html );
		$this->assertStringContainsString( 'alt=""', $html );
	}

	public function test_media_library_alt_counts_and_is_rendered(): void {
		$id = $this->image();
		update_post_meta( $id, '_wp_attachment_image_alt', 'From the library' );
		$doc = $this->doc( [ [ 'type' => 'image', 'props' => [ 'image' => $id ] ] ] );
		$this->assertSame( [], $this->validate_as_admin( $doc )->warnings() );
		$this->assertStringContainsString( 'alt="From the library"', $this->render_as_admin( $doc ) );
	}

	public function test_avatar_without_a_name_warns(): void {
		$doc                    = $this->doc();
		$doc['profile']         = [ 'avatar' => $this->image() ];
		$this->assertSame( [ '/profile/name' ], array_column( $this->validate_as_admin( $doc )->warnings(), 'path' ) );
	}

	public function test_invalid_documents_have_no_warnings(): void {
		$this->assertSame( [], $this->validate_as_admin( [ 'blocks' => 'nope' ] )->warnings() );
	}
}
