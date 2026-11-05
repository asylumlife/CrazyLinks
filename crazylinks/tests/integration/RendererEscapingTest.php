<?php
/**
 * Renderer output escaping.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\PageStore;
use CrazyLinks\Render\RenderContext;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Schema\PageValidator;

/**
 * Every user-controlled value is escaped in the rendered HTML.
 */
class RendererEscapingTest extends TestCase {

	private const XSS = '"><script>alert(1)</script><img src=x onerror=alert(2)>';
	private const SHORT = '"><svg onload=alert(1)>';

	private function render( array $doc, string $mode = 'standalone', int $author = 0, bool $track = false ): string {
		$author = $author ? $author : self::factory()->user->create( [ 'role' => 'administrator' ] );
		$result = ( new PageValidator() )->validate( $doc, $author );
		$this->assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		$ctx = new RenderContext( 0, 'p', $author, $mode, $track, (array) $result->document()['settings'], time() );
		return ( new Renderer() )->render( $result->document(), $ctx );
	}

	private function assert_no_injection( string $html ): void {
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		// Attribute values are entity-escaped, so drop them and look for real handler attributes.
		$tags = (string) preg_replace( '/"[^"]*"/', '""', $html );
		if ( preg_match( '/<[a-z]+[^>]*\son[a-z]+\s*=[^>]*>/i', $tags, $m ) ) {
			$this->fail( 'Injected handler: ' . $m[0] );
		}
	}

	public function test_profile_fields_are_escaped(): void {
		$doc            = $this->doc();
		$doc['profile'] = [
			'name'     => self::XSS,
			'bio'      => self::XSS,
			'location' => self::XSS,
			'pronouns' => self::SHORT,
		];
		$html           = $this->render( $doc );
		$this->assert_no_injection( $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	public function test_block_fields_are_escaped(): void {
		$doc  = $this->doc(
			[
				[
					'type'  => 'link',
					'props' => [
						'title'    => self::XSS,
						'subtitle' => self::XSS,
						'url'      => 'https://example.com/?q="><script>alert(1)</script>',
						'badge'    => [ 'text' => self::SHORT ],
						'emoji'    => '<b>x',
					],
				],
				[
					'type'  => 'heading',
					'props' => [ 'text' => self::XSS ],
				],
				[
					'type'  => 'group',
					'props' => [ 'title' => self::XSS ],
				],
				[
					'type'  => 'email_capture',
					'props' => [
						'heading'      => self::XSS,
						'button_text'  => self::SHORT,
						'consent_text' => self::XSS,
						'placeholder'  => self::XSS,
					],
				],
				[
					'type'  => 'socials',
					'props' => [
						'items' => [
							[
								'network' => 'instagram',
								'url'     => 'https://instagram.com/"onmouseover="alert(1)',
							],
						],
					],
				],
				[
					'type'  => 'embed',
					'props' => [
						'url'   => 'https://example.com/v',
						'title' => self::XSS,
					],
				],
			]
		);
		$html = $this->render( $doc );
		$this->assert_no_injection( $html );
		$this->assertStringNotContainsString( '"onmouseover=', $html );
		$this->assertStringNotContainsString( '<b>x', $html );
	}

	public function test_design_tokens_cannot_break_out_of_style_attribute(): void {
		$doc           = $this->doc();
		$doc['design'] = [
			'theme'  => 'midnight',
			'accent' => '#abcdef',
			'text'   => [ 'font' => 'system-serif' ],
		];
		$html          = $this->render( $doc );
		$this->assertMatchesRegularExpression( '/<div class="cl-page [^"]+" id="cl-page-0" style="[^"<>]*--cl-accent:#abcdef;[^"<>]*">/', $html );
	}

	public function test_html_block_only_renders_for_unfiltered_html_authors(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'unfiltered_html is super-admin only on multisite.' );
		}
		$doc    = $this->doc(
			[
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZH',
					'type'  => 'html',
					'props' => [ 'html' => '<marquee>raw</marquee>' ],
				],
			]
		);
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->assertStringContainsString( '<marquee>raw</marquee>', $this->render( $doc, 'standalone', $admin ) );

		// Same document, but the page author lost unfiltered_html: the block is skipped.
		$result = ( new PageValidator() )->validate( $doc, $admin );
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		$ctx    = new RenderContext( 1, 'p', $author, 'standalone', false, [], time() );
		$this->assertStringNotContainsString( 'marquee', ( new Renderer() )->render( $result->document(), $ctx ) );
	}

	public function test_scheduled_and_hidden_blocks_are_not_in_html(): void {
		$doc  = $this->doc(
			[
				[
					'type'     => 'link',
					'schedule' => [
						'start' => '2099-01-01T00:00:00Z',
						'end'   => null,
					],
					'props'    => [
						'title' => 'FUTURE',
						'url'   => 'https://example.com/f',
					],
				],
				[
					'type'     => 'link',
					'schedule' => [
						'start' => null,
						'end'   => '2001-01-01T00:00:00Z',
					],
					'props'    => [
						'title' => 'PAST',
						'url'   => 'https://example.com/p',
					],
				],
				[
					'type'    => 'link',
					'visible' => false,
					'props'   => [
						'title' => 'HIDDEN',
						'url'   => 'https://example.com/h',
					],
				],
				[
					'type'     => 'link',
					'schedule' => [
						'start' => null,
						'end'   => '2099-01-01T00:00:00Z',
					],
					'props'    => [
						'title' => 'CURRENT',
						'url'   => 'https://example.com/c',
					],
				],
			]
		);
		$html = $this->render( $doc );
		$this->assertStringNotContainsString( 'FUTURE', $html );
		$this->assertStringNotContainsString( 'PAST', $html );
		$this->assertStringNotContainsString( 'HIDDEN', $html );
		$this->assertStringContainsString( 'CURRENT', $html );
		$this->assertStringContainsString( 'data-cl-end="2099-01-01T00:00:00+00:00"', $html );
	}

	public function test_tracked_links_use_go_redirect_and_untracked_use_direct_url(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$doc                           = $this->doc();
		$doc['blocks'][1]['tracking'] = false;
		$html                          = $this->render( $doc, 'standalone', 0, true );
		$this->assertStringContainsString( '/l/p/go/01HZZZZZZZZZZZZZZZZZZZZZZA/', $html );
		$this->assertStringContainsString( 'href="https://example.com/two?a=1"', $html );
	}

	public function test_unimplemented_types_render_a_comment_only(): void {
		$html = $this->render(
			$this->doc(
				[
					[
						'type'  => 'woo_products',
						'props' => [],
					],
				]
			)
		);
		$this->assertStringContainsString( '<!-- crazylinks: woo_products block is not available yet -->', $html );
	}

	public function test_embedded_mode_uses_h2_for_name(): void {
		$this->assertStringContainsString( '<h2 class="cl-profile__name">', $this->render( $this->doc(), 'embed' ) );
		$this->assertStringContainsString( '<h1 class="cl-profile__name">', $this->render( $this->doc(), 'standalone' ) );
	}

	public function test_shortcode_prints_assets_once(): void {
		$post = $this->make_page( $this->doc(), 'sc-page' );
		\CrazyLinks\Frontend\Assets::reset();
		$html = do_shortcode( sprintf( '[crazylinks id="%1$d"][crazylinks id="%1$d"]', $post->ID ) );
		$this->assertSame( 2, substr_count( $html, 'class="cl-page ' ) );
		$this->assertSame( 1, substr_count( $html, 'id="crazylinks-css"' ) );
		$this->assertSame( 1, substr_count( $html, 'id="crazylinks-js"' ) );
		$this->assertSame( '', do_shortcode( '[crazylinks id="999999"]' ) );
	}

	public function test_draft_is_not_embeddable_by_visitors(): void {
		$post = $this->make_page( $this->doc(), 'draft-page', 'draft' );
		wp_set_current_user( 0 );
		$this->assertSame( '', do_shortcode( sprintf( '[crazylinks id="%d"]', $post->ID ) ) );
	}

	public function test_frontend_js_budget(): void {
		$this->assertLessThanOrEqual( 8192, filesize( CRAZYLINKS_DIR . 'assets/src/frontend.js' ) );
	}

	public function test_css_is_scoped(): void {
		$css = (string) file_get_contents( CRAZYLINKS_DIR . 'assets/src/frontend.css' );
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = (string) preg_replace( '/@keyframes[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css );
		preg_match_all( '/(?:^|\})\s*([^{}@]+)\{/', $css, $m );
		foreach ( $m[1] as $selector_list ) {
			foreach ( explode( ',', $selector_list ) as $selector ) {
				$this->assertMatchesRegularExpression( '/^\s*\.cl-(page|body)\b/', $selector, "Unscoped selector: $selector" );
			}
		}
	}
}
