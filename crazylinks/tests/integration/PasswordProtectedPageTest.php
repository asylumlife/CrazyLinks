<?php
/**
 * Password-protected link pages.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Settings;

/**
 * A locked page shows only the password form: no blocks, click URLs, views or cache entries until the password is entered.
 */
class PasswordProtectedPageTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['REMOTE_ADDR']     = '203.0.113.80';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 Safari/605.1.15';
		$_SERVER['REQUEST_METHOD']  = 'GET';
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		delete_option( Settings::OPTION );
		parent::tear_down();
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
	 * Standalone mode shows only the password form, logs nothing and caches nothing.
	 */
	public function test_standalone_page_renders_only_the_password_form(): void {
		$post = $this->protected_page();
		$html = $this->request_page( get_permalink( $post ) );

		$this->assertStringContainsString( 'name="post_password"', $html );
		$this->assertStringNotContainsString( 'One', $html );
		$this->assertStringNotContainsString( 'Two', $html );
		$this->assertStringNotContainsString( '/go/' . self::LINK_A, $html, 'No click URLs (the speculation-rules exclusion /l/*/go/* is not one)' );
		$this->assertStringNotContainsString( 'example.com/one', $html );
		$this->assertStringNotContainsString( 'og:title', $html );
		$this->assertCount( 0, $this->events(), 'No view is logged for a locked page' );
		$this->assertFalse( get_transient( 'crazylinks_html_' . $post->ID . '_standalone' ), 'Nothing is cached' );

		$this->enter_password( 'wrong' );
		$this->assertStringNotContainsString( '/go/' . self::LINK_A, $this->request_page( get_permalink( $post ) ) );

		$this->enter_password( 'secret' );
		$html = $this->request_page( get_permalink( $post ) );
		$this->assertStringContainsString( 'One', $html );
		$this->assertStringContainsString( '/l/locked-standalone/go/' . self::LINK_A . '/', $html );
		$this->assertStringNotContainsString( 'name="post_password"', $html );
		$this->assertCount( 1, $this->events(), 'With the password the view counts' );
		$this->assertFalse( get_transient( 'crazylinks_html_' . $post->ID . '_standalone' ), 'Protected pages are never cached, even unlocked' );
	}

	/**
	 * Theme mode too.
	 */
	public function test_theme_mode_page_renders_only_the_password_form(): void {
		$post = $this->protected_page( 'theme' );
		$html = $this->request_page( get_permalink( $post ) );
		$this->assertStringContainsString( 'name="post_password"', $html );
		$this->assertStringNotContainsString( 'One', $html );
		$this->assertStringNotContainsString( '/go/' . self::LINK_A, $html );
		$this->assertCount( 0, $this->events() );
		$this->assertFalse( get_transient( 'crazylinks_html_' . $post->ID . '_theme' ) );

		$this->enter_password( 'secret' );
		$this->assertStringContainsString( '/go/' . self::LINK_A . '/', $this->request_page( get_permalink( $post ) ) );
	}

	/**
	 * The shortcode keeps returning nothing for a locked page.
	 */
	public function test_shortcode_returns_nothing_for_a_locked_page(): void {
		$post = $this->protected_page();
		$this->assertSame( '', do_shortcode( '[crazylinks id="' . $post->ID . '"]' ) );
	}
}
