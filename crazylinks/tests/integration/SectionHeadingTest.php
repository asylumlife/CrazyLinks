<?php
/**
 * Sections (block type group) and configurable headings.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Render\Contrast;
use CrazyLinks\Render\RenderContext;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Schema\PageValidator;

/**
 * Rendering, escaping, schedule inheritance and contrast of sections; heading modifiers.
 */
class SectionHeadingTest extends TestCase {

	private const GROUP = '01HZZZZZZZZZZZZZZZZZZZZZSG';
	private const CHILD = '01HZZZZZZZZZZZZZZZZZZZZZSC';

	private function admin(): int {
		return self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @return array{0: string, 1: \CrazyLinks\Schema\ValidationResult}
	 */
	private function render( array $blocks, array $design = [] ): array {
		$doc           = $this->doc( $blocks );
		$doc['design'] = $design;
		$result        = ( new PageValidator() )->validate( $doc, $this->admin() );
		$this->assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		$ctx = new RenderContext( 0, 'p', $this->admin(), 'standalone', false, [], time() );
		return [ ( new Renderer() )->render( $result->document(), $ctx ), $result ];
	}

	/**
	 * @param array<string, mixed> $props Section props.
	 * @param array<string, mixed> $extra Extra section fields.
	 */
	private function section( array $props, array $extra = [] ): array {
		return [
			$extra + [
				'id'    => self::GROUP,
				'type'  => 'group',
				'props' => $props,
			],
			[
				'id'        => self::CHILD,
				'type'      => 'link',
				'parent_id' => self::GROUP,
				'props'     => [
					'title' => 'Inside link',
					'url'   => 'https://example.com/in',
				],
			],
		];
	}

	public function test_legacy_group_with_title_still_collapses(): void {
		[ $html ] = $this->render( $this->section( [ 'title' => 'Menus', 'collapsed' => true ] ) );
		$this->assertMatchesRegularExpression( '#<details class="cl-group cl-section[^"]*">#', $html, 'Closed by default' );
		$this->assertStringContainsString( '<summary class="cl-group__title cl-section__title">Menus</summary>', $html );
		[ $open ] = $this->render( $this->section( [ 'title' => 'Menus' ] ) );
		$this->assertMatchesRegularExpression( '#<details class="cl-group cl-section[^"]*" open>#', $open );
		[ $plain ] = $this->render( $this->section( [] ) );
		$this->assertStringContainsString( '<section class="cl-group cl-section cl-section--pad-none cl-section--radius-inherit">', $plain );
		$this->assertStringContainsString( 'Inside link', $plain );
	}

	public function test_section_props_render_scoped_and_escaped(): void {
		[ $html ] = $this->render(
			$this->section(
				[
					'heading'     => 'Live <b>now</b>',
					'description' => 'On air "1–2" & more',
					'background'  => [
						'type'  => 'gradient',
						'from'  => '#111827',
						'to'    => '#312e81',
						'angle' => 135,
					],
					'padding'     => 'lg',
					'radius'      => 'md',
					'layout'      => 'grid',
				]
			)
		);
		$this->assertStringContainsString( '<h2 class="cl-section__title">Live &lt;b&gt;now&lt;/b&gt;</h2>', $html );
		$this->assertStringContainsString( 'On air &quot;1–2&quot; &amp; more', $html );
		$this->assertStringContainsString( 'cl-section--pad-lg cl-section--radius-md cl-section--bg cl-section--grid', $html );
		$this->assertStringContainsString( 'background:linear-gradient(135deg, #111827, #312e81);', $html );
		$this->assertStringContainsString( '--cl-text:#ffffff;--cl-muted:#ffffff;', $html, 'Readable text picked for a dark background' );
		$this->assertStringNotContainsString( '<b>now</b>', $html );
	}

	public function test_collapsible_section_and_explicit_text_colour(): void {
		[ $html ] = $this->render(
			$this->section(
				[
					'heading'     => 'More',
					'collapsible' => true,
					'collapsed'   => true,
					'text_color'  => '#0f172a',
					'background'  => [
						'type'  => 'solid',
						'color' => '#fde68a',
					],
				]
			)
		);
		$this->assertMatchesRegularExpression( '#<details class="[^"]*cl-section--bg[^"]*" style="background:\#fde68a;--cl-text:\#0f172a;--cl-muted:\#0f172a;">#', $html );
		$this->assertStringContainsString( '<summary class="cl-group__title cl-section__title">More</summary>', $html );
		// Collapsible without any heading text renders as a plain section.
		[ $plain ] = $this->render( $this->section( [ 'collapsible' => true ] ) );
		$this->assertStringNotContainsString( '<details', $plain );
	}

	public function test_section_rejects_bad_props(): void {
		foreach ( [
			[ 'padding' => 'xl' ],
			[ 'radius' => 'round' ],
			[ 'layout' => 'bento' ],
			[ 'text_color' => 'red' ],
			[ 'background' => [ 'type' => 'video' ] ],
			[ 'background' => [ 'type' => 'solid', 'color' => 'url(x)' ] ],
			[ 'nope' => true ],
		] as $props ) {
			$r = ( new PageValidator() )->validate( $this->doc( $this->section( $props ) ), $this->admin() );
			$this->assertFalse( $r->is_valid(), wp_json_encode( $props ) );
		}
	}

	public function test_scheduling_or_hiding_a_section_hides_its_children(): void {
		$end      = gmdate( 'c', time() + HOUR_IN_SECONDS );
		[ $html ] = $this->render(
			$this->section(
				[ 'heading' => 'Soon' ],
				[
					'schedule' => [
						'start' => gmdate( 'c', time() + HOUR_IN_SECONDS ),
						'end'   => null,
					],
				]
			)
		);
		$this->assertStringNotContainsString( 'Inside link', $html );
		$this->assertStringNotContainsString( 'Soon', $html );

		[ $hidden ] = $this->render( $this->section( [ 'heading' => 'Off' ], [ 'visible' => false ] ) );
		$this->assertStringNotContainsString( 'Inside link', $hidden );

		[ $live ] = $this->render(
			$this->section(
				[ 'heading' => 'Now' ],
				[
					'schedule' => [
						'start' => null,
						'end'   => $end,
					],
				]
			)
		);
		$this->assertMatchesRegularExpression( '#<div class="cl-block cl-block--group" id="cl-b-' . self::GROUP . '" data-cl-end="[^"]+">#', $live, 'The JS hides the whole section at its end' );
		$this->assertStringContainsString( 'Inside link', $live );
	}

	public function test_section_contrast_pair_and_warning(): void {
		$props = [
			'background' => [
				'type'  => 'solid',
				'color' => '#ffffff',
			],
			'text_color' => '#bbbbbb',
		];
		$pairs = Contrast::section_pairs( $props, '#111111', [ '#ffffff' ] );
		$this->assertCount( 1, $pairs );
		$this->assertSame( [ '#bbbbbb', '#ffffff' ], [ $pairs[0]['fg'], $pairs[0]['bg'] ] );
		$this->assertLessThan( 4.5, Contrast::ratio( $pairs[0]['fg'], $pairs[0]['bg'] ) );
		$this->assertSame( [], Contrast::section_pairs( [], '#111111', [ '#ffffff' ] ), 'A plain section inherits the page pair' );
		$this->assertSame( '#000000', Contrast::section_text( [ 'background' => [ 'type' => 'solid', 'color' => '#fde68a' ] ], '#ffffff' ) );
		$this->assertSame( '#ffffff', Contrast::section_text( [ 'background' => [ 'type' => 'image', 'overlay' => '#000000cc' ] ], '#111111' ) );
		$this->assertCount( 5, Contrast::section_pairs( [ 'background' => [ 'type' => 'gradient', 'from' => '#000000', 'to' => '#333333' ] ], '#111111', [] ) );

		[ , $result ] = $this->render( $this->section( $props ) );
		$codes        = array_column( $result->warnings(), 'code' );
		$this->assertContains( 'low_contrast', $codes );
		$this->assertContains( '/blocks/0/props/text_color', array_column( $result->warnings(), 'path' ) );

		[ , $fine ] = $this->render( $this->section( [ 'background' => [ 'type' => 'solid', 'color' => '#1e293b' ] ] ) );
		$this->assertNotContains( 'low_contrast', array_column( $fine->warnings(), 'code' ) );
	}

	public function test_heading_defaults_render_as_before(): void {
		[ $html ] = $this->render(
			[
				[
					'type'  => 'heading',
					'props' => [ 'text' => 'Plain' ],
				],
			]
		);
		$this->assertStringContainsString( '<h2 class="cl-heading cl-align-center">Plain</h2>', $html );
	}

	public function test_heading_modifiers_icon_subtext_and_escaping(): void {
		[ $html ] = $this->render(
			[
				[
					'type'  => 'heading',
					'props' => [
						'text'      => 'Tour <script>',
						'level'     => 'h3',
						'size'      => 'xl',
						'align'     => 'left',
						'weight'    => 'black',
						'color'     => '#ff0055',
						'uppercase' => true,
						'subtext'   => 'Dates & "tickets"',
						'divider'   => 'dots',
						'spacing'   => 'loose',
						'icon'      => 'github',
					],
				],
				[
					'type'  => 'heading',
					'props' => [
						'text'  => 'Emoji',
						'emoji' => '🎤',
						'size'  => 'md',
					],
				],
			]
		);
		$this->assertStringContainsString( '<h3 class="cl-heading cl-align-left cl-heading--xl cl-heading--black cl-heading--loose cl-heading--div-dots cl-heading--upper" style="color:#ff0055">', $html );
		$this->assertStringContainsString( 'cl-heading__icon', $html );
		$this->assertStringContainsString( '<span class="cl-heading__text">Tour &lt;script&gt;</span>', $html );
		$this->assertStringContainsString( '<p class="cl-heading__sub cl-align-left">Dates &amp; &quot;tickets&quot;</p>', $html );
		$this->assertStringContainsString( '<span class="cl-heading__emoji" aria-hidden="true">🎤</span>', $html );
		$this->assertStringContainsString( '<h2 class="cl-heading cl-align-center"><span class="cl-heading__emoji"', $html, 'md is the default size: no modifier' );
	}

	public function test_heading_rejects_bad_props(): void {
		foreach ( [
			[ 'size' => 'huge' ],
			[ 'weight' => '900' ],
			[ 'divider' => 'wave' ],
			[ 'spacing' => 'none' ],
			[ 'color' => 'javascript:x' ],
			[ 'icon' => 'Bad Icon' ],
			[ 'subtext' => str_repeat( 'x', 161 ) ],
		] as $props ) {
			$doc = $this->doc(
				[
					[
						'type'  => 'heading',
						'props' => [ 'text' => 'H' ] + $props,
					],
				]
			);
			$this->assertFalse( ( new PageValidator() )->validate( $doc, $this->admin() )->is_valid(), wp_json_encode( $props ) );
		}
	}
}
