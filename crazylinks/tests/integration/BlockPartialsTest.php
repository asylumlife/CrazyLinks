<?php
/**
 * Escaping and behaviour of the content block partials, link presentation, icons and patterns.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Render\Icons;
use CrazyLinks\Render\RenderContext;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Render\Tokens;
use CrazyLinks\Schema\PageValidator;

/**
 * Every user-controlled value in the gallery, latest_posts, video, contact, countdown, map and payment partials is escaped.
 */
class BlockPartialsTest extends TestCase {

	private const XSS   = '"><script>alert(1)</script><img src=x onerror=alert(2)>';
	private const SHORT = '"><svg onload=alert(1)>';

	/**
	 * Validates and renders a document.
	 *
	 * @param array<string, mixed> $doc  Document.
	 * @param int                  $now  Render time.
	 * @param string               $mode Render mode.
	 */
	private function render( array $doc, int $now = 0, string $mode = 'standalone' ): string {
		$author = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$result = ( new PageValidator() )->validate( $doc, $author );
		$this->assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		$ctx = new RenderContext( 0, 'p', $author, $mode, false, (array) $result->document()['settings'], $now ? $now : time() );
		return ( new Renderer() )->render( $result->document(), $ctx );
	}

	/**
	 * Validation result for one block.
	 *
	 * @param string               $type  Block type.
	 * @param array<string, mixed> $props Props.
	 */
	private function validate_block( string $type, array $props ): \CrazyLinks\Schema\ValidationResult {
		return ( new PageValidator() )->validate(
			$this->doc(
				[
					[
						'type'  => $type,
						'props' => $props,
					],
				]
			),
			self::factory()->user->create( [ 'role' => 'administrator' ] )
		);
	}

	private function assert_no_injection( string $html ): void {
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$tags = (string) preg_replace( '/"[^"]*"/', '""', $html );
		if ( preg_match( '/<[a-z]+[^>]*\son[a-z]+\s*=[^>]*>/i', $tags, $m ) ) {
			$this->fail( 'Injected handler: ' . $m[0] );
		}
	}

	private function image(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	public function test_gallery_renders_escaped_lazy_grid(): void {
		$a    = $this->image();
		$b    = $this->image();
		$html = $this->render(
			$this->doc(
				[
					[
						'type'  => 'gallery',
						'props' => [
							'columns' => 3,
							'items'   => [
								[
									'image'   => $a,
									'alt'     => self::XSS,
									'caption' => self::XSS,
									'url'     => 'https://example.com/?q="><script>alert(1)</script>',
								],
								[
									'image'   => $b,
									'caption' => 'Second',
								],
								[ 'image' => 999999 ],
							],
						],
					],
				]
			)
		);
		$this->assert_no_injection( $html );
		$this->assertStringContainsString( 'cl-gallery--cols-3', $html );
		$this->assertSame( 2, substr_count( $html, 'class="cl-gallery__item"' ), 'Missing attachments are skipped.' );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	public function test_gallery_rejects_script_urls_and_empty_gallery_renders_nothing(): void {
		$bad = $this->validate_block(
			'gallery',
			[
				'items' => [
					[
						'image' => 1,
						'url'   => 'javascript:alert(1)',
					],
				],
			]
		);
		$this->assertFalse( $bad->is_valid() );
		$html = $this->render( $this->doc( [ [ 'type' => 'gallery', 'props' => [ 'items' => [] ] ] ] ) );
		$this->assertStringNotContainsString( 'cl-gallery', $html );
	}

	public function test_latest_posts_lists_only_public_published_posts_and_escapes_titles(): void {
		self::factory()->post->create(
			[
				'post_title'  => self::SHORT,
				'post_status' => 'publish',
			]
		);
		self::factory()->post->create(
			[
				'post_title'  => 'Draft secret',
				'post_status' => 'draft',
			]
		);
		self::factory()->post->create(
			[
				'post_title'    => 'Protected secret',
				'post_status'   => 'publish',
				'post_password' => 'pw',
			]
		);
		self::factory()->post->create(
			[
				'post_title'  => 'Visible post',
				'post_status' => 'publish',
			]
		);
		$html = $this->render( $this->doc( [ [ 'type' => 'latest_posts', 'props' => [ 'count' => 5 ] ] ] ) );
		$this->assert_no_injection( $html );
		$this->assertStringContainsString( 'Visible post', $html );
		$this->assertStringNotContainsString( 'Draft secret', $html );
		$this->assertStringNotContainsString( 'Protected secret', $html );
		$this->assertStringNotContainsString( '<svg onload', $html );
	}

	public function test_latest_posts_falls_back_for_unknown_or_internal_post_types_and_honours_count(): void {
		self::factory()->post->create_many( 4, [ 'post_status' => 'publish' ] );
		foreach ( [ 'nope', 'attachment', 'crazylinks_page', 'nav_menu_item' ] as $type ) {
			$html = $this->render( $this->doc( [ [ 'type' => 'latest_posts', 'props' => [ 'count' => 2, 'post_type' => $type ] ] ] ) );
			$this->assertSame( 2, substr_count( $html, 'class="cl-posts__item"' ), $type );
		}
		$this->assertFalse( $this->validate_block( 'latest_posts', [ 'post_type' => 'a"><script>' ] )->is_valid() );
	}

	public function test_latest_posts_caps_the_render_cache_lifetime(): void {
		$ctx = new RenderContext( 0, 'p', 1, 'standalone', false, [], 1000 );
		$doc = $this->doc( [ [ 'type' => 'latest_posts', 'props' => [] ] ] );
		$res = ( new PageValidator() )->validate( $doc, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		( new Renderer() )->render( $res->document(), $ctx );
		$this->assertSame( 1000 + 15 * MINUTE_IN_SECONDS, $ctx->next_boundary );
	}

	public function test_video_url_uses_the_embed_facade_and_escapes_title(): void {
		$html = $this->render(
			$this->doc(
				[
					[
						'type'  => 'video',
						'props' => [
							'url'   => 'https://example.com/v?x="><script>alert(1)</script>',
							'title' => self::XSS,
						],
					],
				]
			)
		);
		$this->assert_no_injection( $html );
		$this->assertStringContainsString( 'cl-embed__facade', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	public function test_video_attachment_renders_native_player_with_motion_safe_defaults(): void {
		$video  = self::factory()->attachment->create(
			[
				'post_mime_type' => 'video/mp4',
				'file'           => 'clip.mp4',
			]
		);
		$other  = self::factory()->attachment->create(
			[
				'post_mime_type' => 'application/pdf',
				'file'           => 'doc.pdf',
			]
		);
		$html   = $this->render( $this->doc( [ [ 'type' => 'video', 'props' => [ 'video' => $video, 'title' => self::XSS ] ] ] ) );
		$this->assert_no_injection( $html );
		$this->assertStringContainsString( '<video class="cl-video" playsinline preload="none"', $html );
		$this->assertStringContainsString( ' controls', $html );
		$this->assertStringNotContainsString( 'autoplay', $html );
		$this->assertStringContainsString( 'type="video/mp4"', $html );

		$auto = $this->render( $this->doc( [ [ 'type' => 'video', 'props' => [ 'video' => $video, 'autoplay' => true, 'loop' => true ] ] ] ) );
		$this->assertStringContainsString( ' autoplay muted', $auto );
		$this->assertStringNotContainsString( ' controls', $auto );

		$this->assertStringNotContainsString( '<video', $this->render( $this->doc( [ [ 'type' => 'video', 'props' => [ 'video' => $other ] ] ] ) ) );
		$this->assertFalse( $this->validate_block( 'video', [] )->is_valid(), 'A video block needs a source.' );
	}

	public function test_contact_rows_are_links_and_escaped(): void {
		$html = $this->render(
			$this->doc(
				[
					[
						'type'  => 'contact',
						'props' => [
							'phone'   => '+1 (555) 010-0100',
							'email'   => 'hello@example.com',
							'address' => self::XSS,
						],
					],
				]
			)
		);
		$this->assert_no_injection( $html );
		$this->assertStringContainsString( 'href="tel:+15550100100"', $html );
		$this->assertStringContainsString( 'href="mailto:hello@example.com"', $html );
		$this->assertStringContainsString( 'https://www.google.com/maps/search/?api=1&#038;query=', $html );
		$this->assertFalse( $this->validate_block( 'contact', [ 'email' => 'x"><b>@example.com' ] )->is_valid() );
		$this->assertFalse( $this->validate_block( 'contact', [ 'phone' => '1"><script>' ] )->is_valid() );
	}

	public function test_countdown_escapes_text_renders_units_and_sets_cache_boundary(): void {
		$now    = 1800000000;
		$target = gmdate( 'c', $now + 2 * DAY_IN_SECONDS + 3 * HOUR_IN_SECONDS + 4 * MINUTE_IN_SECONDS + 5 );
		$doc    = $this->doc(
			[
				[
					'type'  => 'countdown',
					'props' => [
						'target'       => $target,
						'label'        => self::XSS,
						'expired_text' => self::XSS,
						'link_label'   => self::SHORT,
						'url'          => 'https://example.com/live?a="><script>alert(1)</script>',
					],
				],
			]
		);
		$html   = $this->render( $doc, $now );
		$this->assert_no_injection( $html );
		$this->assertStringContainsString( 'data-cl-countdown="' . $target, $html );
		$this->assertMatchesRegularExpression( '/data-cl-unit="d">2</', $html );
		$this->assertMatchesRegularExpression( '/data-cl-unit="h">03</', $html );
		$this->assertMatchesRegularExpression( '/data-cl-unit="m">04</', $html );
		$this->assertMatchesRegularExpression( '/data-cl-unit="s">05</', $html );
		$this->assertMatchesRegularExpression( '/cl-countdown__done" hidden/', $html );

		$after = $this->render( $doc, $now + 3 * DAY_IN_SECONDS );
		$this->assertMatchesRegularExpression( '/cl-countdown__live" hidden/', $after );
		$this->assertDoesNotMatchRegularExpression( '/cl-countdown__done" hidden/', $after );

		$this->assertFalse( $this->validate_block( 'countdown', [ 'target' => '2030-01-01T00:00:00Z', 'url' => 'javascript:alert(1)' ] )->is_valid() );
		$this->assertFalse( $this->validate_block( 'countdown', [ 'target' => 'soon' ] )->is_valid() );
	}

	public function test_map_links_out_with_encoded_address(): void {
		$html = $this->render( $this->doc( [ [ 'type' => 'map', 'props' => [ 'address' => self::XSS, 'label' => self::SHORT ] ] ] ) );
		$this->assert_no_injection( $html );
		$this->assertStringContainsString( 'https://www.google.com/maps/search/?api=1&#038;query=%22%3E%3Cscript%3E', $html );
		$this->assertStringContainsString( 'https://maps.apple.com/?q=%22%3E%3Cscript%3E', $html );
		$this->assertStringNotContainsString( '<iframe', $html );

		$google = $this->render( $this->doc( [ [ 'type' => 'map', 'props' => [ 'address' => '1 Main St', 'provider' => 'google' ] ] ] ) );
		$this->assertStringNotContainsString( 'maps.apple.com', $google );
	}

	public function test_payment_presets_build_urls_and_enforce_hosts(): void {
		$html = $this->render(
			$this->doc(
				[
					[
						'type'  => 'payment',
						'props' => [
							'service'     => 'kofi',
							'handle'      => '@someone',
							'label'       => self::XSS,
							'amount_hint' => self::SHORT,
						],
					],
					[
						'type'  => 'payment',
						'props' => [
							'service' => 'cashapp',
							'handle'  => '$cash.tag',
						],
					],
					[
						'type'  => 'payment',
						'props' => [
							'service' => 'stripe_link',
							'url'     => 'https://buy.stripe.com/test_abc123',
						],
					],
				]
			)
		);
		$this->assert_no_injection( $html );
		$this->assertStringContainsString( 'href="https://ko-fi.com/someone"', $html );
		$this->assertStringContainsString( 'href="https://cash.app/$cash.tag"', $html );
		$this->assertStringContainsString( 'href="https://buy.stripe.com/test_abc123"', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringNotContainsString( '/go/', $html );

		$stored = $this->validate_block( 'payment', [ 'service' => 'paypal', 'handle' => 'jane' ] );
		$this->assertTrue( $stored->is_valid() );
		$this->assertSame( 'https://paypal.me/jane', $stored->document()['blocks'][0]['props']['url'] );
	}

	public function test_payment_rejects_wrong_hosts_bad_handles_and_unsafe_urls(): void {
		$cases = [
			[ 'kofi', [ 'url' => 'https://evil.example/ko-fi.com/x' ] ],
			[ 'kofi', [ 'url' => 'http://ko-fi.com/x' ] ],
			[ 'kofi', [ 'handle' => 'a"><script>' ] ],
			[ 'stripe_link', [ 'handle' => 'abc' ] ],
			[ 'paypal', [ 'url' => 'javascript:alert(1)' ] ],
			[ 'custom', [ 'url' => 'ftp://example.com/pay' ] ],
			[ 'venmo', [] ],
		];
		foreach ( $cases as [ $service, $extra ] ) {
			$result = $this->validate_block( 'payment', [ 'service' => $service ] + $extra );
			$this->assertFalse( $result->is_valid(), $service . ' ' . wp_json_encode( $extra ) );
		}
		$this->assertTrue( $this->validate_block( 'payment', [ 'service' => 'custom', 'url' => 'https://pay.example.com/me' ] )->is_valid() );
		$this->assertTrue( $this->validate_block( 'payment', [ 'service' => 'gofundme', 'handle' => 'help-me' ] )->is_valid() );
	}

	public function test_link_presentation_variants(): void {
		$img  = $this->image();
		$soon = gmdate( 'c', time() + DAY_IN_SECONDS );
		$html = $this->render(
			$this->doc(
				[
					[
						'type'   => 'link',
						'pinned' => true,
						'props'  => [
							'title'      => 'Right thumb',
							'url'        => 'https://example.com/a',
							'display'    => 'thumbnail',
							'thumb_side' => 'right',
							'thumbnail'  => $img,
							'new_until'  => $soon,
						],
					],
					[
						'type'  => 'link',
						'props' => [
							'title'     => 'Expired new',
							'url'       => 'https://example.com/b',
							'new_until' => '2001-01-01T00:00:00Z',
						],
					],
					[
						'type'  => 'link',
						'props' => [
							'title' => 'Badge wins',
							'url'   => 'https://example.com/c',
							'badge' => [ 'text' => self::SHORT ],
							'new_until' => $soon,
						],
					],
					[
						'type'  => 'link',
						'props' => [
							'title' => 'Icon',
							'url'   => 'https://example.com/d',
							'icon'  => 'github',
						],
					],
					[
						'type'  => 'link',
						'props' => [
							'title'   => 'Featured',
							'url'     => 'https://example.com/e',
							'display' => 'featured',
						],
					],
				]
			)
		);
		$this->assert_no_injection( $html );
		$this->assertStringContainsString( 'cl-link--thumb-right', $html );
		$this->assertStringContainsString( 'cl-pinned', $html );
		$this->assertStringContainsString( 'class="cl-pin"', $html );
		$this->assertSame( 1, substr_count( $html, 'data-cl-end="' . gmdate( 'c', strtotime( $soon ) ) . '"' ), 'NEW flag only on the link without an explicit badge.' );
		$this->assertStringContainsString( '>New</span>', $html );
		$this->assertSame( 1, substr_count( $html, '>New</span>' ) );
		$this->assertStringContainsString( 'cl-link__icon', $html );
		$this->assertStringContainsString( 'cl-block--featured', $html );
	}

	public function test_grid_and_featured_layouts_set_page_and_block_classes(): void {
		$grid = $this->doc();
		$grid['design'] = [ 'layout' => 'grid' ];
		$this->assertStringContainsString( 'cl-layout-grid', $this->render( $grid ) );
		$feat = $this->doc();
		$feat['design'] = [ 'layout' => 'featured' ];
		$html = $this->render( $feat );
		$this->assertStringContainsString( 'cl-layout-featured', $html );
		$this->assertSame( 1, substr_count( $html, 'cl-block--lead' ) );
	}

	public function test_every_social_network_has_a_brand_icon_and_label(): void {
		$networks = (array) PageValidator::schema()['$defs']['props_socials']['properties']['items']['items']['properties']['network']['enum'];
		$this->assertGreaterThanOrEqual( 32, count( $networks ) );
		$items = [];
		foreach ( $networks as $network ) {
			$this->assertTrue( Icons::has( (string) $network ), $network . ' has no icon' );
			$items[] = [
				'network' => $network,
				'url'     => 'email' === $network ? 'mailto:a@example.com' : 'https://example.com/' . $network,
			];
		}
		$html = $this->render( $this->doc( [ [ 'type' => 'socials', 'props' => [ 'items' => $items ] ] ] ) );
		$this->assertSame( count( $networks ), substr_count( $html, 'class="cl-icon" viewBox="0 0 24 24"' ) );
		$this->assertSame( count( $networks ), substr_count( $html, 'aria-hidden="true" focusable="false"' ) );
		$this->assertStringNotContainsString( 'cl-icon--mono', $html );
		$this->assertStringContainsString( 'fill="currentColor"><path d="M', $html );
		$this->assertMatchesRegularExpression( '/<span class="cl-sr">Instagram<\/span>/', $html );
		$this->assertMatchesRegularExpression( '/<span class="cl-sr">Apple Music<\/span>/', $html );
	}

	public function test_icon_filter_registers_icons_and_drops_unsafe_paths(): void {
		add_filter(
			'crazylinks_icons',
			static function ( array $icons ): array {
				$icons['myservice']  = [
					'title' => 'My Service',
					'path'  => 'M0 0h24v24H0z',
				];
				$icons['evil']       = [
					'title' => 'Evil',
					'path'  => '"/><script>alert(1)</script>',
				];
				$icons['Bad Slug']   = [
					'title' => 'Bad',
					'path'  => 'M0 0z',
				];
				return $icons;
			}
		);
		Icons::reset();
		$this->assertTrue( Icons::has( 'myservice' ) );
		$this->assertFalse( Icons::has( 'evil' ) );
		$this->assertFalse( Icons::has( 'Bad Slug' ) );
		$this->assertSame( 'My Service', Icons::label( 'myservice' ) );
		$this->assertStringContainsString( '<path d="M0 0h24v24H0z"/>', Icons::svg( 'myservice' ) );
		remove_all_filters( 'crazylinks_icons' );
		Icons::reset();
		$this->assertFalse( Icons::has( 'myservice' ) );
	}

	public function test_pattern_backgrounds_validate_and_resolve_to_css_only(): void {
		foreach ( [ 'dots', 'grid', 'stripes', 'lines', 'checks', 'noise' ] as $pattern ) {
			$design = [
				'background' => [
					'type'          => 'pattern',
					'color'         => '#ffffff',
					'pattern'       => $pattern,
					'pattern_size'  => 24,
					'pattern_color' => '#00000022',
				],
			];
			$doc           = $this->doc();
			$doc['design'] = $design;
			$result        = ( new PageValidator() )->validate( $doc, 0 );
			$this->assertTrue( $result->is_valid(), $pattern . wp_json_encode( $result->errors() ) );
			$style = ( new Tokens( $design ) )->style();
			$this->assertSame( 1, preg_match( '/--cl-bg:(.*?);--cl-text:/', $style, $m ), $pattern );
			$this->assertStringEndsWith( ',#ffffff', $m[1], $pattern );
			$this->assertStringNotContainsString( 'http://', str_replace( 'http://www.w3.org/2000/svg', '', $m[1] ) );
			$this->assertStringNotContainsString( '<', $m[1] );
		}
		$doc           = $this->doc();
		$doc['design'] = [ 'background' => [ 'type' => 'pattern', 'pattern' => 'url(javascript:x)' ] ];
		$this->assertFalse( ( new PageValidator() )->validate( $doc, 0 )->is_valid() );
		$doc['design'] = [ 'background' => [ 'type' => 'pattern', 'pattern' => 'dots', 'pattern_color' => 'red;}</style>' ] ];
		$this->assertFalse( ( new PageValidator() )->validate( $doc, 0 )->is_valid() );
		$doc['design'] = [ 'background' => [ 'type' => 'pattern', 'pattern' => 'dots', 'pattern_size' => 5000 ] ];
		$this->assertFalse( ( new PageValidator() )->validate( $doc, 0 )->is_valid() );
	}
}
