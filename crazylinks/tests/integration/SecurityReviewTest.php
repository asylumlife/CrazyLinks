<?php
/**
 * Regression tests for the Phase 1 security review (SPEC §12): F1, F3, F5, F6, F7, F9, F10.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Frontend\TemplateLoader;
use CrazyLinks\PageStore;
use CrazyLinks\PostType;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Schema\PageValidator;
use CrazyLinks\Settings;

/**
 * Each test names the finding it pins down.
 */
class SecurityReviewTest extends TestCase {

	private const A = '01HZZZZZZZZZZZZZZZZZZZZZZA';

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['REMOTE_ADDR']     = '203.0.113.80';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 Safari/605.1.15';
		$_SERVER['REQUEST_METHOD']  = 'GET';
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ], $_SERVER['HTTPS'] );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * Requests a link page URL the way WordPress would and returns the full HTML document.
	 *
	 * @param string $url URL.
	 */
	private function request_page( string $url ): string {
		$this->go_to( $url );
		$loader = new TemplateLoader();
		$loader->template_redirect();
		$template = $loader->template_include( '' );
		$this->assertStringStartsWith( CRAZYLINKS_DIR . 'templates/', $template );
		ob_start();
		include $template;
		return (string) ob_get_clean();
	}

	private function protected_page( string $mode = 'standalone' ): \WP_Post {
		$post = $this->make_page( $this->doc( [], [ 'render_mode' => $mode ] ), 'locked-' . $mode );
		wp_update_post(
			[
				'ID'            => $post->ID,
				'post_password' => 'secret',
			]
		);
		wp_set_current_user( 0 );
		return get_post( $post->ID );
	}

	private function enter_password( string $password ): void {
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new \PasswordHash( 8, true ) )->HashPassword( $password );
	}

	/**
	 * F1: standalone mode shows only the password form, logs nothing and caches nothing.
	 */
	public function test_f1_password_protected_page_renders_only_the_form(): void {
		$post = $this->protected_page();
		$html = $this->request_page( get_permalink( $post ) );

		$this->assertStringContainsString( 'name="post_password"', $html );
		$this->assertStringNotContainsString( 'One', $html );
		$this->assertStringNotContainsString( 'Two', $html );
		$this->assertStringNotContainsString( '/go/' . self::A, $html, 'No click URLs (the speculation-rules exclusion /l/*/go/* is not one)' );
		$this->assertStringNotContainsString( 'example.com/one', $html );
		$this->assertStringNotContainsString( 'og:title', $html );
		$this->assertCount( 0, $this->events(), 'No view is logged for a locked page' );
		$this->assertFalse( get_transient( 'crazylinks_html_' . $post->ID . '_standalone' ), 'Nothing is cached' );

		$this->enter_password( 'wrong' );
		$this->assertStringNotContainsString( '/go/' . self::A, $this->request_page( get_permalink( $post ) ) );

		$this->enter_password( 'secret' );
		$html = $this->request_page( get_permalink( $post ) );
		$this->assertStringContainsString( 'One', $html );
		$this->assertStringContainsString( '/l/locked-standalone/go/' . self::A . '/', $html );
		$this->assertStringNotContainsString( 'name="post_password"', $html );
		$this->assertCount( 1, $this->events(), 'With the password the view counts' );
		$this->assertFalse( get_transient( 'crazylinks_html_' . $post->ID . '_standalone' ), 'Protected pages are never cached, even unlocked' );
	}

	/**
	 * F1: theme mode too.
	 */
	public function test_f1_password_protected_page_in_theme_mode(): void {
		$post = $this->protected_page( 'theme' );
		$html = $this->request_page( get_permalink( $post ) );
		$this->assertStringContainsString( 'name="post_password"', $html );
		$this->assertStringNotContainsString( 'One', $html );
		$this->assertStringNotContainsString( '/go/' . self::A, $html );
		$this->assertCount( 0, $this->events() );
		$this->assertFalse( get_transient( 'crazylinks_html_' . $post->ID . '_theme' ) );

		$this->enter_password( 'secret' );
		$this->assertStringContainsString( '/go/' . self::A . '/', $this->request_page( get_permalink( $post ) ) );
	}

	/**
	 * F1: the shortcode keeps returning nothing for a locked page.
	 */
	public function test_f1_shortcode_still_respects_password(): void {
		$post = $this->protected_page();
		$this->assertSame( '', do_shortcode( '[crazylinks id="' . $post->ID . '"]' ) );
	}

	/**
	 * F3: the render cache is keyed by home URL and scheme, and links in the HTML are root-relative.
	 */
	public function test_f3_render_cache_is_keyed_by_host_and_scheme(): void {
		$doc   = $this->doc(
			[
				[
					'id'    => self::A,
					'type'  => 'link',
					'props' => [
						'title' => 'One',
						'url'   => 'https://example.com/one',
					],
				],
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZE',
					'type'  => 'email_capture',
					'props' => [],
				],
			]
		);
		$post  = $this->make_page( $doc, 'host-page' );
		$count = 0;
		// Something host-dependent in the markup, plus a render counter.
		add_filter(
			'crazylinks_render_block',
			static function ( $html ) use ( &$count ) {
				++$count;
				return $html . '<!-- ' . home_url( '/' ) . ' -->';
			}
		);
		$r     = new Renderer();
		$first = $r->render_post( $post, 'standalone' );
		$this->assertStringContainsString( 'href="/l/host-page/go/' . self::A . '/"', $first, '/go/ links are root-relative' );
		$this->assertMatchesRegularExpression( '#<form class="cl-capture" method="post" action="/[^/]#', $first, 'REST action is root-relative' );
		$renders = $count;
		$r->render_post( $post, 'standalone' );
		$this->assertSame( $renders, $count, 'Second render is a cache hit' );

		$evil = static fn( $url ) => str_replace( '://example.org', '://evil.example', (string) $url );
		add_filter( 'home_url', $evil );
		$poisoned = $r->render_post( $post, 'standalone' );
		remove_filter( 'home_url', $evil );
		$this->assertGreaterThan( $renders, $count, 'A different host misses the cache' );
		$this->assertStringContainsString( 'evil.example', $poisoned );

		$next = $r->render_post( $post, 'standalone' );
		$this->assertStringNotContainsString( 'evil.example', $next, 'The forged-host render is never served to others' );

		$before          = $count;
		$_SERVER['HTTPS'] = 'on';
		$r->render_post( $post, 'standalone' );
		$this->assertGreaterThan( $before, $count, 'Scheme is part of the key' );
	}

	/**
	 * F5 cases.
	 *
	 * @return array<string, array{string, string|null}>
	 */
	public function text_links(): array {
		return [
			'javascript'        => [ '<a href="javascript:alert(1)">x</a>', null ],
			'entity-obfuscated' => [ '<a href="JaVaScRiPt&#58;alert(1)">x</a>', null ],
			'leading space'     => [ '<a href=" javascript:alert(1)">x</a>', null ],
			'protocol-relative' => [ '<a href="//evil.com/">x</a>', null ],
			'userinfo'          => [ '<a href="https://paypal.com@evil.com/">x</a>', null ],
			'valid https'       => [ '<a href="https://example.com/a?b=1" title="t">x</a>', 'https://example.com/a?b=1' ],
			'valid mailto'      => [ '<a href="mailto:hi@example.com">x</a>', 'mailto:hi@example.com' ],
		];
	}

	/**
	 * F5: text block links are held to the URL policy; failing hrefs are removed, not rewritten.
	 *
	 * @dataProvider text_links
	 */
	public function test_f5_text_block_links_follow_url_policy( string $content, ?string $expected ): void {
		$doc    = $this->doc(
			[
				[
					'id'    => self::A,
					'type'  => 'text',
					'props' => [ 'content' => '<p>' . $content . '</p>' ],
				],
			]
		);
		$result = ( new PageValidator() )->validate( $doc, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( $result->is_valid() );
		$stored = (string) $result->document()['blocks'][0]['props']['content'];
		$tags   = new \WP_HTML_Tag_Processor( $stored );
		$this->assertTrue( $tags->next_tag( [ 'tag_name' => 'A' ] ), $stored );
		$this->assertSame( $expected, $tags->get_attribute( 'href' ), $stored );
		$this->assertStringNotContainsString( 'alert(1)"', $stored );
	}

	/**
	 * F6: a stray ?crazylinks_go= on another URL is ignored instead of 404ing it.
	 */
	public function test_f6_go_query_var_only_on_link_pages(): void {
		$page = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'sample-page',
				'post_status' => 'publish',
			]
		);
		$this->go_to( home_url( '/sample-page/?crazylinks_go=' . self::A ) );
		$this->assertTrue( is_page( $page ) );
		( new TemplateLoader() )->template_redirect();
		$this->assertFalse( is_404(), 'The page must not be turned into a 404' );
		$this->assertSame( '', (string) get_query_var( 'crazylinks_go' ) );
	}

	/**
	 * F6: a bare ?crazylinks_slug= hijacks nothing under pretty permalinks.
	 */
	public function test_f6_bare_slug_query_var_ignored_with_pretty_permalinks(): void {
		$link = $this->make_page( $this->doc(), 'hijack' );
		$page = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'sample-page',
				'post_status' => 'publish',
			]
		);
		$this->go_to( home_url( '/sample-page/?crazylinks_slug=hijack' ) );
		$this->assertTrue( is_page( $page ) );
		$this->assertFalse( is_singular( PostType::NAME ) );

		$this->go_to( home_url( '/?crazylinks_slug=hijack' ) );
		$this->assertFalse( is_singular( PostType::NAME ) );

		$other = $this->make_page( $this->doc(), 'other' );
		$this->go_to( home_url( '/l/hijack/?crazylinks_slug=other' ) );
		$this->assertSame( $link->ID, get_queried_object_id(), 'The query string cannot override the matched rule' );
		$this->assertNotSame( $other->ID, get_queried_object_id() );
	}

	/**
	 * F6: plain permalinks still use the query-string form.
	 */
	public function test_f6_bare_slug_works_with_plain_permalinks(): void {
		$link = $this->make_page( $this->doc(), 'plain-links' );
		$this->set_permalink_structure( '' );
		$this->assertSame( home_url( '/?crazylinks_slug=plain-links' ), get_permalink( $link ) );
		$this->go_to( get_permalink( $link ) );
		$this->assertTrue( is_singular( PostType::NAME ) );
		$this->assertSame( $link->ID, get_queried_object_id() );
	}

	/**
	 * F7: dev scripts refuse to run outside the CLI, and the release zip excludes dev tooling.
	 */
	public function test_f7_bin_scripts_are_cli_only_and_dev_files_are_distignored(): void {
		$scripts = (array) glob( CRAZYLINKS_DIR . 'bin/*.php' );
		$this->assertNotEmpty( $scripts );
		foreach ( $scripts as $file ) {
			$this->assertMatchesRegularExpression( "/^PHP_SAPI === 'cli' \\|\\| exit;/m", (string) file_get_contents( (string) $file ), (string) $file );
		}
		$ignore = array_map( 'trim', (array) file( CRAZYLINKS_DIR . '.distignore' ) );
		foreach ( [ 'vendor', 'tests', 'bin', 'node_modules', 'composer.*', 'phpunit*', 'phpcs*', 'phpstan*', '.phpunit.result.cache', '.editorconfig', '.gitignore', '.distignore' ] as $entry ) {
			$this->assertContains( $entry, $ignore, $entry );
		}
	}

	/**
	 * F9: autosave/preview revisions of a link page are validated too.
	 */
	public function test_f9_revisions_of_link_pages_are_validated(): void {
		$post   = $this->make_page( $this->doc(), 'rev-page' );
		$parent = (string) get_post( $post->ID )->post_content;

		$bad = $this->doc(
			[
				[
					'id'    => self::A,
					'type'  => 'link',
					'props' => [
						'title' => 'x',
						'url'   => 'javascript:alert(1)',
					],
				],
			]
		);
		foreach ( [ wp_json_encode( $bad ), 'not json at all' ] as $content ) {
			$rev = wp_insert_post(
				[
					'post_type'    => 'revision',
					'post_status'  => 'inherit',
					'post_parent'  => $post->ID,
					'post_name'    => $post->ID . '-autosave-v1',
					'post_content' => wp_slash( (string) $content ),
				]
			);
			$this->assertSame( $parent, (string) get_post( $rev )->post_content, 'Invalid revision content falls back to the page document' );
		}

		$good = $this->doc(
			[
				[
					'id'    => self::A,
					'type'  => 'text',
					'props' => [ 'content' => '<p><a href="javascript:alert(1)">x</a><script>bad()</script></p>' ],
				],
			]
		);
		$rev  = wp_insert_post(
			[
				'post_type'    => 'revision',
				'post_status'  => 'inherit',
				'post_parent'  => $post->ID,
				'post_name'    => $post->ID . '-autosave-v1',
				'post_content' => wp_slash( (string) wp_json_encode( $good ) ),
			]
		);
		$doc  = PageStore::get_document( $rev );
		$this->assertSame( 1, $doc['schema'] );
		$this->assertStringNotContainsString( 'javascript', (string) $doc['blocks'][0]['props']['content'] );
		$this->assertStringNotContainsString( '<script', (string) $doc['blocks'][0]['props']['content'] );

		// Revisions of other post types are untouched.
		$other = self::factory()->post->create();
		$rev   = wp_insert_post(
			[
				'post_type'    => 'revision',
				'post_status'  => 'inherit',
				'post_parent'  => $other,
				'post_content' => 'plain words',
			]
		);
		$this->assertSame( 'plain words', get_post( $rev )->post_content );
	}

	/**
	 * F10: network activation and uninstall walk every site, not the first 100/500.
	 */
	public function test_f10_multisite_loops_are_uncapped(): void {
		foreach ( [ 'src/Activator.php', 'uninstall.php' ] as $file ) {
			$src = (string) file_get_contents( CRAZYLINKS_DIR . $file );
			$this->assertMatchesRegularExpression( "/get_sites\\(\\s*(\\[|array\\()\\s*'fields'\\s*=>\\s*'ids',\\s*'number'\\s*=>\\s*0,/", $src, $file );
		}
	}

	/**
	 * 2026-11-06 review: core archive, search and feed queries for the post type would list every
	 * published page with its stored JSON, hidden and scheduled blocks included. Only single-page
	 * requests may load a link page.
	 */
	public function test_review_link_pages_are_never_listed_in_archives_searches_or_feeds(): void {
		$blocks   = $this->doc()['blocks'];
		$blocks[] = [
			'id'      => '01HZZZZZZZZZZZZZZZZZZZZZZH',
			'type'    => 'link',
			'visible' => false,
			'props'   => [
				'title' => 'Hidden',
				'url'   => 'https://example.com/hidden-secret',
			],
		];
		$post     = $this->make_page( $this->doc( $blocks ), 'listed' );
		$regular  = self::factory()->post->create( [ 'post_title' => 'Hidden blog post' ] );
		wp_set_current_user( 0 );

		foreach ( [
			'/?post_type=crazylinks_page',
			'/?post_type=crazylinks_page&feed=rss2',
			'/?post_type=crazylinks_page&feed=atom',
			'/?s=Hidden&post_type=crazylinks_page',
		] as $url ) {
			$this->go_to( home_url( $url ) );
			$this->assertSame( [], $GLOBALS['wp_query']->posts, $url );
		}

		$this->go_to( home_url( '/?s=Hidden&post_type[]=crazylinks_page&post_type[]=post' ) );
		$this->assertSame( [ $regular ], wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), 'Other types still listed' );

		// The page itself is still served, without the hidden block.
		$html = $this->request_page( get_permalink( $post ) );
		$this->assertStringContainsString( 'One', $html );
		$this->assertStringNotContainsString( 'hidden-secret', $html );
		$this->go_to( home_url( '/?post_type=crazylinks_page&name=listed' ) );
		$this->assertSame( [ $post->ID ], wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}
}
