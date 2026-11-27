<?php
/**
 * Social icon click tracking.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Analytics\ClickRedirect;
use CrazyLinks\Analytics\Rollup;
use CrazyLinks\Analytics\Summary;
use CrazyLinks\Analytics\Visitor;
use CrazyLinks\Render\RenderContext;
use CrazyLinks\Render\Renderer;

/**
 * Social icons get their own ids, web links go through /go/ and each click is logged and reported per platform.
 */
class SocialClickTest extends TestCase {

	private const SOCIALS = '01HZZZZZZZZZZZZZZZZZZZZZZS';
	private const ITEM_A  = '01HZZZZZZZZZZZZZZZZZZZZZZ1';
	private const ITEM_B  = '01HZZZZZZZZZZZZZZZZZZZZZZ2';

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function socials_doc(): array {
		return $this->doc(
			[
				[
					'id'    => self::SOCIALS,
					'type'  => 'socials',
					'props' => [
						'items' => [
							[
								'id'      => self::ITEM_A,
								'network' => 'instagram',
								'url'     => 'https://instagram.com/example',
							],
							[
								'id'      => self::ITEM_B,
								'network' => 'email',
								'url'     => 'mailto:a@example.com',
							],
						],
					],
				],
			]
		);
	}

	public function test_missing_social_item_ids_are_generated_and_reused_from_the_previous_document(): void {
		$doc = $this->socials_doc();
		unset( $doc['blocks'][0]['props']['items'][0]['id'], $doc['blocks'][0]['props']['items'][1]['id'] );
		$first = $this->validate_as_admin( $doc );
		$this->assertTrue( $first->is_valid(), wp_json_encode( $first->errors() ) );
		$items = $first->document()['blocks'][0]['props']['items'];
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $items[0]['id'] );
		$this->assertNotSame( $items[0]['id'], $items[1]['id'] );

		$again = $this->validate_as_admin( $doc, $first->document() );
		$this->assertSame( $items[0]['id'], $again->document()['blocks'][0]['props']['items'][0]['id'] );
	}

	public function test_duplicate_or_invalid_social_item_ids(): void {
		$doc                                              = $this->socials_doc();
		$doc['blocks'][0]['props']['items'][1]['id']      = self::ITEM_A;
		$result                                           = $this->validate_as_admin( $doc );
		$items                                            = $result->document()['blocks'][0]['props']['items'];
		$this->assertTrue( $result->is_valid() );
		$this->assertNotSame( $items[0]['id'], $items[1]['id'] );
		$doc['blocks'][0]['props']['items'][1]['id']      = 'not-a-ulid';
		$this->assertFalse( $this->validate_as_admin( $doc )->is_valid() );
	}

	public function test_social_icons_route_web_links_through_go_and_keep_mailto_direct(): void {
		$html = $this->render_as_admin( $this->socials_doc(), 'standalone', true );
		$this->assertStringContainsString( 'href="/l/p/go/' . self::SOCIALS . '/' . self::ITEM_A . '/"', $html );
		$this->assertStringContainsString( 'href="mailto:a@example.com"', $html );
		$this->assertStringNotContainsString( self::ITEM_B . '/', $html );
	}

	public function test_legacy_social_items_without_ids_stay_direct_links(): void {
		$ctx   = new RenderContext( 0, 'p', $this->admin(), 'standalone', true, [], time() );
		$block = [
			'id'       => self::SOCIALS,
			'type'     => 'socials',
			'tracking' => true,
			'props'    => [ 'items' => [ [ 'network' => 'instagram', 'url' => 'https://instagram.com/example' ] ] ],
		];
		$html  = ( new Renderer() )->partial( 'blocks/socials', [ 'block' => $block, 'props' => $block['props'], 'ctx' => $ctx ] );
		$this->assertStringContainsString( 'href="https://instagram.com/example"', $html );
		$this->assertStringNotContainsString( '/go/', $html );
	}

	public function test_social_click_redirects_to_the_item_and_logs_against_the_block(): void {
		$post   = $this->make_page( $this->socials_doc() );
		$result = ( new ClickRedirect() )->resolve( $post, self::SOCIALS, new Visitor( '203.0.113.7', 'Mozilla/5.0 Safari', '' ), self::ITEM_A );
		$this->assertSame( 302, $result['status'] );
		$this->assertSame( 'https://instagram.com/example', $result['location'] );
		$this->assertSame( 'logged', $result['event'] );
		$events = $this->events();
		$this->assertCount( 1, $events );
		$this->assertSame( self::SOCIALS, $events[0]['block_id'] );
		$this->assertSame( self::ITEM_A, $events[0]['item_id'], 'The icon that was clicked is stored' );

		// A second icon is a separate click even inside the de-duplication window.
		$other = $this->socials_doc();
		$other['blocks'][0]['props']['items'][1] = [
			'id'      => self::ITEM_B,
			'network' => 'tiktok',
			'url'     => 'https://tiktok.com/@example',
		];
		$post2 = $this->make_page( $other, 'second' );
		$r     = new ClickRedirect();
		$v     = new Visitor( '198.51.100.4', 'Mozilla/5.0 Safari', '' );
		$this->assertSame( 'logged', $r->resolve( $post2, self::SOCIALS, $v, self::ITEM_A )['event'] );
		$this->assertSame( 'logged', $r->resolve( $post2, self::SOCIALS, $v, self::ITEM_B )['event'] );
		$this->assertSame( 'duplicate', $r->resolve( $post2, self::SOCIALS, $v, self::ITEM_B )['event'] );
	}

	public function test_social_click_edge_cases_are_404(): void {
		$post = $this->make_page( $this->socials_doc() );
		$r    = new ClickRedirect();
		$v    = new Visitor( '203.0.113.8', 'Mozilla/5.0 Safari', '' );
		$this->assertSame( 404, $r->resolve( $post, self::SOCIALS, $v )['status'], 'Socials block without an item' );
		$this->assertSame( 404, $r->resolve( $post, self::SOCIALS, $v, '01HZZZZZZZZZZZZZZZZZZZZZZX' )['status'], 'Unknown item' );
		$this->assertSame( 404, $r->resolve( $post, self::SOCIALS, $v, self::ITEM_B )['status'], 'mailto is never redirected' );
		$this->assertSame( 404, $r->resolve( $post, self::SOCIALS, $v, 'bogus' )['status'] );
		$link = $this->make_page( $this->doc(), 'links' );
		$this->assertSame( 404, $r->resolve( $link, '01HZZZZZZZZZZZZZZZZZZZZZZA', $v, self::ITEM_A )['status'], 'A link block has no items' );
		$this->assertSame( 302, $r->resolve( $link, '01HZZZZZZZZZZZZZZZZZZZZZZA', $v )['status'], 'Plain /go/{block}/ still works' );
		$this->assertCount( 1, $this->events(), 'Only the plain link click was logged' );
	}

	public function test_socials_block_appears_in_the_analytics_block_table(): void {
		$post = $this->make_page( $this->socials_doc() );
		( new ClickRedirect() )->resolve( $post, self::SOCIALS, new Visitor( '203.0.113.9', 'Mozilla/5.0 Safari', '' ), self::ITEM_A );
		$summary = Summary::report( (int) $post->ID, gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) );
		$rows    = array_values( array_filter( (array) $summary['blocks'], static fn( $b ) => self::SOCIALS === $b['block_id'] ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'socials', $rows[0]['type'] );
		$this->assertSame( 1, $rows[0]['clicks'] );
	}

	public function test_socials_clicks_are_reported_per_platform(): void {
		global $wpdb;
		$doc                                  = $this->socials_doc();
		$doc['blocks'][0]['props']['items'][] = [
			'id'      => '01HZZZZZZZZZZZZZZZZZZZZZZT',
			'network' => 'tiktok',
			'url'     => 'https://tiktok.com/@example',
		];
		$post                                 = $this->make_page( $doc );
		$r                                    = new ClickRedirect();
		$r->resolve( $post, self::SOCIALS, new Visitor( '203.0.113.20', 'Mozilla/5.0 Safari', '' ), self::ITEM_A );
		$r->resolve( $post, self::SOCIALS, new Visitor( '203.0.113.21', 'Mozilla/5.0 Safari', '' ), '01HZZZZZZZZZZZZZZZZZZZZZZT' );
		$r->resolve( $post, self::SOCIALS, new Visitor( '203.0.113.22', 'Mozilla/5.0 Safari', '' ), '01HZZZZZZZZZZZZZZZZZZZZZZT' );
		// A click from before item ids were stored, and one on an icon that has since been removed.
		foreach ( [ null, '01HZZZZZZZZZZZZZZZZZZZZZZG' ] as $i => $item ) {
			$wpdb->insert(
				$wpdb->prefix . 'crazylinks_events',
				[
					'page_id'      => $post->ID,
					'block_id'     => self::SOCIALS,
					'item_id'      => $item,
					'type'         => 'click',
					'ts'           => gmdate( 'Y-m-d H:i:s' ),
					'visitor_hash' => 'old' . $i,
				]
			);
		}
		$check = function () use ( $post ): void {
			$summary = Summary::report( (int) $post->ID, gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) );
			$row     = array_values( array_filter( (array) $summary['blocks'], static fn( $b ) => self::SOCIALS === $b['block_id'] ) )[0];
			$this->assertSame( 5, $row['clicks'], 'The block row is still the total' );
			$this->assertSame( 5, $summary['totals']['clicks'] );
			$this->assertSame(
				[
					[ 'TikTok', 2 ],
					[ 'Instagram', 1 ],
					[ '(removed icons)', 1 ],
					[ 'Earlier clicks (platform not recorded)', 1 ],
				],
				array_map( static fn( $i ) => [ $i['title'], $i['clicks'] ], $row['items'] )
			);
			$this->assertSame( 'tiktok', $row['items'][0]['network'] );
			$this->assertSame( 0.4, $row['items'][0]['share'] );
		};
		$check();

		// The same numbers once the events are rolled up and the raw rows are gone.
		$wpdb->query( "UPDATE {$wpdb->prefix}crazylinks_events SET ts = DATE_SUB(ts, INTERVAL 1 DAY)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$day = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		Rollup::roll_range( $day, $day );
		Rollup::roll_range( $day, $day );
		update_option( 'crazylinks_rollup_through', $day, false );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}crazylinks_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$check();
		delete_option( 'crazylinks_rollup_through' );
	}
}
