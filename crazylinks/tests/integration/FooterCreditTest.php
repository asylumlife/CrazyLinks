<?php
/**
 * Footer credit.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

/**
 * The optional "Made with CrazyLinks" credit: off by default, filterable and escaped, boolean setting.
 */
class FooterCreditTest extends TestCase {

	public function test_footer_credit_is_off_by_default_and_on_in_every_mode(): void {
		$this->assertStringNotContainsString( 'cl-credit', $this->render_as_admin( $this->doc() ) );
		$on = $this->doc( [], [ 'footer_credit' => true ] );
		foreach ( [ 'standalone', 'theme', 'embed', 'preview' ] as $mode ) {
			$html = $this->render_as_admin( $on, $mode );
			$this->assertStringContainsString( '<a href="https://wordpress.org/plugins/crazylinks/" rel="nofollow noopener">Made with CrazyLinks</a>', $html, $mode );
		}
	}

	public function test_footer_credit_text_and_url_are_filterable_and_escaped(): void {
		add_filter( 'crazylinks_footer_credit_text', static fn() => 'Built <b>with</b> care' );
		add_filter( 'crazylinks_footer_credit_url', static fn() => 'https://example.com/?a=1&b="2"' );
		$html = $this->render_as_admin( $this->doc( [], [ 'footer_credit' => true ] ) );
		remove_all_filters( 'crazylinks_footer_credit_text' );
		remove_all_filters( 'crazylinks_footer_credit_url' );
		$this->assertStringContainsString( 'Built &lt;b&gt;with&lt;/b&gt; care', $html );
		$this->assertStringNotContainsString( '<b>with</b>', $html );
		$this->assertStringContainsString( 'href="https://example.com/?a=1&#038;b=2" rel="nofollow noopener"', $html );
	}

	public function test_footer_credit_must_be_boolean(): void {
		$this->assertFalse( $this->validate_as_admin( $this->doc( [], [ 'footer_credit' => 'yes' ] ) )->is_valid() );
	}
}
