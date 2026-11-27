<?php
/**
 * Render cache.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Render\Cache;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Settings;

/**
 * The rendered-HTML cache is salted per post-status change and keyed by host and scheme.
 */
class RenderCacheTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_publishing_a_public_post_bumps_the_render_cache(): void {
		Cache::bump();
		$salt = Cache::salt();
		$id   = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->assertSame( $salt, Cache::salt(), 'Draft creation changes nothing' );
		wp_publish_post( $id );
		$this->assertNotSame( $salt, Cache::salt(), 'Publishing a post refreshes latest_posts' );

		$salt = Cache::salt();
		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => 'Edited',
			]
		);
		$this->assertSame( $salt, Cache::salt(), 'Same-status updates do not bump' );
		wp_update_post(
			[
				'ID'          => $id,
				'post_status' => 'draft',
			]
		);
		$this->assertNotSame( $salt, Cache::salt(), 'Unpublishing bumps too' );

		$salt = Cache::salt();
		$this->make_page( $this->doc(), 'self' );
		$this->assertSame( $salt, Cache::salt(), 'Link pages only forget their own cache' );
	}

	/**
	 * The render cache is keyed by home URL and scheme, and links in the HTML are root-relative.
	 */
	public function test_render_cache_is_keyed_by_host_and_scheme(): void {
		$doc   = $this->doc(
			[
				[
					'id'    => self::LINK_A,
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
		$this->assertStringContainsString( 'href="/l/host-page/go/' . self::LINK_A . '/"', $first, '/go/ links are root-relative' );
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
}
