<?php
/**
 * Link page listing.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

/**
 * Core archive, search and feed queries never list link pages; only single-page requests load one.
 */
class LinkPageListingTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['REMOTE_ADDR']     = '203.0.113.80';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 Safari/605.1.15';
		$_SERVER['REQUEST_METHOD']  = 'GET';
	}

	/**
	 * Core archive, search and feed queries for the post type would list every
	 * published page with its stored JSON, hidden and scheduled blocks included. Only single-page
	 * requests may load a link page.
	 */
	public function test_link_pages_are_never_listed_in_archives_searches_or_feeds(): void {
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
