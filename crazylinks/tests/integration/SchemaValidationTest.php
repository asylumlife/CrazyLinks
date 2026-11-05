<?php
/**
 * Page schema validation.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Schema\PageValidator;
use CrazyLinks\Schema\SchemaValidator;

/**
 * Accept/reject cases for schemas/page.v1.json and the business rules.
 */
class SchemaValidationTest extends TestCase {

	private function validate( $doc, string $role = 'administrator', ?array $previous = null ) {
		$user = self::factory()->user->create( [ 'role' => $role ] );
		return ( new PageValidator() )->validate( $doc, $user, $previous );
	}

	private function paths( $result ): array {
		return array_column( $result->errors(), 'path' );
	}

	public function test_schema_only_uses_supported_keywords(): void {
		$schema = PageValidator::schema();
		$this->assertSame( 'https://json-schema.org/draft/2020-12/schema', $schema['$schema'] );
		// Validating the empty document exercises every $ref without "Unsupported keyword" errors.
		$this->assertSame( [], ( new SchemaValidator( $schema ) )->validate( PageValidator::empty_document() ) );
	}

	public function test_schema_is_non_recursive_and_closed(): void {
		$json = (string) file_get_contents( CRAZYLINKS_DIR . 'schemas/page.v1.json' );
		$this->assertStringNotContainsString( '"$ref": "#"', $json );
		$schema = PageValidator::schema();
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertFalse( $schema['$defs']['block']['additionalProperties'] );
		$this->assertFalse( $schema['$defs']['props_link']['additionalProperties'] );
	}

	public function test_accepts_valid_document_and_fills_defaults(): void {
		$result = $this->validate( $this->doc() );
		$this->assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		$block = $result->document()['blocks'][0];
		$this->assertSame( null, $block['parent_id'] );
		$this->assertTrue( $block['visible'] );
		$this->assertTrue( $block['tracking'] );
		$this->assertSame( 'none', $block['animation'] );
		$this->assertSame( [ 'start' => null, 'end' => null ], $block['schedule'] );
	}

	public function test_generates_ulids_for_missing_ids(): void {
		$doc = $this->doc(
			[
				[
					'type'  => 'heading',
					'props' => [ 'text' => 'Hi' ],
				],
			]
		);
		$result = $this->validate( $doc );
		$this->assertTrue( $result->is_valid() );
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $result->document()['blocks'][0]['id'] );
	}

	public function test_accepts_json_string_input(): void {
		$this->assertTrue( $this->validate( wp_json_encode( $this->doc() ) )->is_valid() );
	}

	public function test_rejects_malformed_json(): void {
		$result = $this->validate( '{"schema":1,' );
		$this->assertFalse( $result->is_valid() );
		$this->assertStringContainsString( 'Invalid JSON', $result->errors()[0]['message'] );
	}

	public function test_rejects_json_list(): void {
		$this->assertFalse( $this->validate( '[1,2]' )->is_valid() );
	}

	public function test_rejects_unknown_top_level_property(): void {
		$doc          = $this->doc();
		$doc['extra'] = true;
		$this->assertContains( '/extra', $this->paths( $this->validate( $doc ) ) );
	}

	public function test_rejects_wrong_schema_version(): void {
		$doc           = $this->doc();
		$doc['schema'] = 2;
		$this->assertContains( '/schema', $this->paths( $this->validate( $doc ) ) );
	}

	public function test_rejects_unknown_block_type(): void {
		$doc = $this->doc(
			[
				[
					'type'  => 'iframe',
					'props' => [],
				],
			]
		);
		$this->assertContains( '/blocks/0/type', $this->paths( $this->validate( $doc ) ) );
	}

	public function test_profile_is_not_a_block_type(): void {
		$doc = $this->doc(
			[
				[
					'type'  => 'profile',
					'props' => [ 'name' => 'x' ],
				],
			]
		);
		$this->assertFalse( $this->validate( $doc )->is_valid() );
	}

	public function test_rejects_unknown_prop_for_type(): void {
		$doc                                = $this->doc();
		$doc['blocks'][0]['props']['color'] = 'red';
		$this->assertContains( '/blocks/0/props/color', $this->paths( $this->validate( $doc ) ) );
	}

	public function test_rejects_missing_required_prop(): void {
		$doc = $this->doc();
		unset( $doc['blocks'][0]['props']['url'] );
		$this->assertContains( '/blocks/0/props/url', $this->paths( $this->validate( $doc ) ) );
	}

	public function test_rejects_overlong_strings(): void {
		$doc                   = $this->doc();
		$doc['profile']['bio'] = str_repeat( 'é', 281 );
		$this->assertContains( '/profile/bio', $this->paths( $this->validate( $doc ) ) );
		$doc['profile']['bio'] = str_repeat( 'é', 280 );
		$this->assertTrue( $this->validate( $doc )->is_valid() );
	}

	public function test_rejects_bad_colour_and_enum(): void {
		$doc           = $this->doc();
		$doc['design'] = [
			'accent' => 'red;background:url(x)',
			'layout' => 'masonry',
		];
		$paths = $this->paths( $this->validate( $doc ) );
		$this->assertContains( '/design/accent', $paths );
		$this->assertContains( '/design/layout', $paths );
	}

	public function test_rejects_bad_schedule(): void {
		$doc                           = $this->doc();
		$doc['blocks'][0]['schedule'] = [
			'start' => 'next tuesday',
			'end'   => null,
		];
		$this->assertContains( '/blocks/0/schedule/start', $this->paths( $this->validate( $doc ) ) );

		$doc['blocks'][0]['schedule'] = [
			'start' => '2026-12-01T00:00:00Z',
			'end'   => '2026-11-01T00:00:00Z',
		];
		$this->assertContains( '/blocks/0/schedule', $this->paths( $this->validate( $doc ) ) );
	}

	public function test_rejects_too_many_blocks(): void {
		$blocks = array_fill(
			0,
			201,
			[
				'type'  => 'spacer',
				'props' => [],
			]
		);
		$this->assertContains( '/blocks', $this->paths( $this->validate( $this->doc( $blocks ) ) ) );
	}

	public function test_rejects_duplicate_ids(): void {
		$doc                     = $this->doc();
		$doc['blocks'][1]['id'] = $doc['blocks'][0]['id'];
		$this->assertContains( '/blocks/1/id', $this->paths( $this->validate( $doc ) ) );
	}

	public function test_parent_must_be_a_group_and_depth_is_one(): void {
		$group = [
			'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZG',
			'type'  => 'group',
			'props' => [ 'title' => 'G' ],
		];
		$child = [
			'type'      => 'link',
			'parent_id' => '01HZZZZZZZZZZZZZZZZZZZZZZG',
			'props'     => [
				'title' => 'c',
				'url'   => 'https://example.com',
			],
		];
		$this->assertTrue( $this->validate( $this->doc( [ $group, $child ] ) )->is_valid() );

		$orphan              = $child;
		$orphan['parent_id'] = '01HZZZZZZZZZZZZZZZZZZZZZZX';
		$this->assertContains( '/blocks/0/parent_id', $this->paths( $this->validate( $this->doc( [ $orphan ] ) ) ) );

		$nested              = $group;
		$nested['id']        = '01HZZZZZZZZZZZZZZZZZZZZZZH';
		$nested['parent_id'] = '01HZZZZZZZZZZZZZZZZZZZZZZG';
		$this->assertContains( '/blocks/1/parent_id', $this->paths( $this->validate( $this->doc( [ $group, $nested ] ) ) ) );
	}

	public function test_children_follow_their_group_and_order_is_renumbered(): void {
		$doc    = $this->doc(
			[
				[
					'id'        => '01HZZZZZZZZZZZZZZZZZZZZZZC',
					'type'      => 'heading',
					'parent_id' => '01HZZZZZZZZZZZZZZZZZZZZZZG',
					'order'     => 5,
					'props'     => [ 'text' => 'child' ],
				],
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZT',
					'type'  => 'heading',
					'order' => 9,
					'props' => [ 'text' => 'last' ],
				],
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZG',
					'type'  => 'group',
					'order' => 1,
					'props' => [],
				],
			]
		);
		$blocks = $this->validate( $doc )->document()['blocks'];
		$this->assertSame( [ '01HZZZZZZZZZZZZZZZZZZZZZZG', '01HZZZZZZZZZZZZZZZZZZZZZZC', '01HZZZZZZZZZZZZZZZZZZZZZZT' ], array_column( $blocks, 'id' ) );
		$this->assertSame( [ 0, 0, 1 ], array_column( $blocks, 'order' ) );
	}

	public function test_html_block_requires_unfiltered_html(): void {
		$html = $this->doc(
			[
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZH',
					'type'  => 'html',
					'props' => [ 'html' => '<script>alert(1)</script>' ],
				],
			]
		);
		if ( ! is_multisite() ) {
			$this->assertTrue( $this->validate( $html, 'administrator' )->is_valid() );
		}
		$this->assertFalse( $this->validate( $html, 'author' )->is_valid() );
		// An unchanged html block authored by someone else does not block saving.
		$this->assertTrue( $this->validate( $html, 'author', $html )->is_valid() );
	}

	public function test_custom_css_requires_unfiltered_html(): void {
		$doc           = $this->doc();
		$doc['design'] = [ 'custom_css' => '.cl-page{color:red}</style><script>x</script>' ];
		$this->assertFalse( $this->validate( $doc, 'author' )->is_valid() );
		if ( ! is_multisite() ) {
			$css = $this->validate( $doc, 'administrator' )->document()['design']['custom_css'];
			$this->assertStringNotContainsString( '</style', $css );
		}
	}

	public function test_text_block_is_kses_filtered(): void {
		$doc     = $this->doc(
			[
				[
					'type'  => 'text',
					'props' => [ 'content' => '<p onclick="x()">Hi <strong>there</strong> <img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a><script>bad()</script></p>' ],
				],
			]
		);
		$content = $this->validate( $doc )->document()['blocks'][0]['props']['content'];
		$this->assertStringContainsString( '<strong>there</strong>', $content );
		$this->assertStringNotContainsString( 'onclick', $content );
		$this->assertStringNotContainsString( '<img', $content );
		$this->assertStringNotContainsString( '<script', $content );
		$this->assertStringNotContainsString( 'javascript:', $content );
	}

	public function test_plain_text_keeps_characters_but_strips_control_chars(): void {
		$doc                    = $this->doc();
		$doc['profile']['name'] = "I <3 links\x07";
		$this->assertSame( 'I <3 links', $this->validate( $doc )->document()['profile']['name'] );
	}

	public function test_json_shape_keeps_empty_objects(): void {
		$json = \CrazyLinks\PageStore::encode( PageValidator::empty_document() );
		$this->assertSame( '{"schema":1,"profile":{},"design":{},"blocks":[],"settings":{}}', $json );
	}

	public function test_encoded_json_has_no_raw_markup(): void {
		$doc                    = $this->doc();
		$doc['profile']['name'] = '<b>"Hi" & \'bye\'</b>';
		$json                   = \CrazyLinks\PageStore::encode( $this->validate( $doc )->document() );
		$this->assertStringNotContainsString( '<', $json );
		$this->assertStringNotContainsString( '&', $json );
		$this->assertSame( '<b>"Hi" & \'bye\'</b>', json_decode( $json, true )['profile']['name'] );
	}

	public function test_insert_filter_rejects_invalid_content_and_keeps_previous(): void {
		$post = $this->make_page( $this->doc() );
		wp_update_post(
			[
				'ID'           => $post->ID,
				'post_content' => wp_slash( '{"schema":1,"blocks":[{"type":"link","props":{"title":"x","url":"javascript:alert(1)"}}]}' ),
			]
		);
		$stored = \CrazyLinks\PageStore::get_document( $post->ID );
		$this->assertSame( 'https://example.com/one', $stored['blocks'][0]['props']['url'] );
	}
}
