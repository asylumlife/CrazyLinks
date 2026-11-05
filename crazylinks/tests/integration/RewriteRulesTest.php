<?php
/**
 * Rewrite rules.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Frontend\Router;
use CrazyLinks\Settings;

/**
 * Three scoped rules under the base, no shadowing of real content.
 */
class RewriteRulesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_generates_exactly_three_rules_for_default_base(): void {
		$this->assertSame(
			[
				'^l/([^/]+)/go/([A-Za-z0-9]{1,64})(?:/([A-Za-z0-9]{1,64}))?/?$' => 'index.php?crazylinks_slug=$matches[1]&crazylinks_go=$matches[2]&crazylinks_item=$matches[3]',
				'^l/([^/]+)/vcard/?$' => 'index.php?crazylinks_slug=$matches[1]&crazylinks_vcard=1',
				'^l/([^/]+)/?$'       => 'index.php?crazylinks_slug=$matches[1]',
			],
			Router::rules()
		);
	}

	public function test_rules_are_registered_at_top_and_no_cpt_rules_exist(): void {
		( new Router() )->add_rules();
		flush_rewrite_rules( false );
		$rules = (array) get_option( 'rewrite_rules' );
		$keys  = array_keys( $rules );
		$go    = array_search( '^l/([^/]+)/go/([A-Za-z0-9]{1,64})(?:/([A-Za-z0-9]{1,64}))?/?$', $keys, true );
		$vcard = array_search( '^l/([^/]+)/vcard/?$', $keys, true );
		$page  = array_search( '^l/([^/]+)/?$', $keys, true );
		$this->assertIsInt( $go );
		$this->assertSame( $go + 1, $vcard );
		$this->assertSame( $go + 2, $page );
		// Both precede every post/page/attachment rule.
		foreach ( $keys as $i => $key ) {
			if ( str_contains( (string) $rules[ $key ], 'pagename=' ) || str_contains( (string) $rules[ $key ], 'name=' ) ) {
				$this->assertGreaterThan( $page, $i, (string) $key );
			}
		}
		$ours = array_filter( $keys, static fn( $k ) => str_contains( (string) $k, 'crazylinks' ) || str_starts_with( (string) $k, 'l/' ) || str_starts_with( (string) $k, '^l/' ) );
		$this->assertCount( 3, $ours, 'Only the three scoped rules mention the base: ' . implode( ', ', $ours ) );
		foreach ( $rules as $query ) {
			$this->assertStringNotContainsString( 'crazylinks_page=', (string) $query );
		}
	}

	public function test_base_is_settable_and_filterable(): void {
		update_option( Settings::OPTION, [ 'base' => 'bio' ] );
		$this->assertArrayHasKey( '^bio/([^/]+)/?$', Router::rules() );

		add_filter( 'crazylinks_base_path', static fn() => 'links' );
		$this->assertArrayHasKey( '^links/([^/]+)/?$', Router::rules() );
		$this->assertSame( home_url( '/links/me/' ), Router::page_url( 'me' ) );

		add_filter( 'crazylinks_base_path', static fn() => 'wp-admin', 20 );
		$this->assertSame( 'l', Settings::base(), 'Reserved bases fall back to l' );
	}

	public function test_base_change_schedules_flush(): void {
		delete_option( 'crazylinks_flush_rewrite' );
		Settings::sanitize( [ 'base' => 'go-here' ] );
		$this->assertSame( 1, (int) get_option( 'crazylinks_flush_rewrite' ) );
	}

	public function test_sanitize_base_rejects_bad_values(): void {
		foreach ( [ '', '../x', 'has space', 'UPPER/../x', 'wp-json', 'feed', str_repeat( 'a', 33 ) ] as $bad ) {
			$this->assertSame( '', Settings::sanitize_base( $bad ), $bad );
		}
		$this->assertSame( 'bio', Settings::sanitize_base( '/Bio/' ) );
	}

	public function test_link_page_resolves_and_permalink_is_pretty(): void {
		$post = $this->make_page( $this->doc(), 'my-links' );
		$this->assertSame( home_url( '/l/my-links/' ), get_permalink( $post ) );
		$this->go_to( home_url( '/l/my-links/' ) );
		$this->assertTrue( is_singular( 'crazylinks_page' ) );
		$this->assertSame( $post->ID, get_queried_object_id() );
	}

	public function test_go_url_maps_query_vars(): void {
		$post = $this->make_page( $this->doc(), 'go-page' );
		$this->go_to( Router::go_url( 'go-page', '01HZZZZZZZZZZZZZZZZZZZZZZA' ) );
		$this->assertSame( '01HZZZZZZZZZZZZZZZZZZZZZZA', get_query_var( 'crazylinks_go' ) );
		$this->assertSame( $post->ID, get_queried_object_id() );
	}

	public function test_go_url_with_an_item_maps_both_query_vars(): void {
		$post = $this->make_page( $this->doc(), 'go-item' );
		$this->go_to( Router::go_url( 'go-item', '01HZZZZZZZZZZZZZZZZZZZZZZA', '01HZZZZZZZZZZZZZZZZZZZZZZB' ) );
		$this->assertStringEndsWith( '/go/01HZZZZZZZZZZZZZZZZZZZZZZA/01HZZZZZZZZZZZZZZZZZZZZZZB/', Router::go_url( 'go-item', '01HZZZZZZZZZZZZZZZZZZZZZZA', '01HZZZZZZZZZZZZZZZZZZZZZZB' ) );
		$this->assertSame( '01HZZZZZZZZZZZZZZZZZZZZZZA', get_query_var( 'crazylinks_go' ) );
		$this->assertSame( '01HZZZZZZZZZZZZZZZZZZZZZZB', get_query_var( 'crazylinks_item' ) );
		$this->assertSame( $post->ID, get_queried_object_id() );
	}

	public function test_does_not_shadow_a_real_page_under_the_base(): void {
		$parent = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'l',
				'post_status' => 'publish',
			]
		);
		$child  = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $parent,
				'post_status' => 'publish',
			]
		);
		$this->go_to( home_url( '/l/about/' ) );
		$this->assertTrue( is_page( $child ), 'No link page named "about": the WordPress page must win.' );
		$this->assertNotEmpty( \CrazyLinks\Admin\Notices::conflicts( 'l' ) );
	}

	public function test_unknown_slug_is_404(): void {
		$this->go_to( home_url( '/l/does-not-exist/' ) );
		$this->assertTrue( is_404() );
	}

	public function test_plain_permalinks_fall_back_to_query_vars(): void {
		$this->set_permalink_structure( '' );
		$this->assertSame( home_url( '/?crazylinks_slug=me' ), Router::page_url( 'me' ) );
		$post = $this->make_page( $this->doc(), 'plain' );
		$this->go_to( Router::page_url( 'plain' ) );
		$this->assertSame( $post->ID, get_queried_object_id() );
	}
}
