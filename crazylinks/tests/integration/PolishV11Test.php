<?php
/**
 * v1.1 polish: footer credit, social click tracking, alt-text warnings, post lock, email wording,
 * focal points and bento tile sizes.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Analytics\ClickRedirect;
use CrazyLinks\Analytics\Rollup;
use CrazyLinks\Analytics\Summary;
use CrazyLinks\Analytics\Visitor;
use CrazyLinks\PostLock;
use CrazyLinks\Render\RenderContext;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Schema\PageValidator;

/**
 * Behaviour of the v1.1 polish items.
 */
class PolishV11Test extends TestCase {

	private const SOCIALS = '01HZZZZZZZZZZZZZZZZZZZZZZS';
	private const ITEM_A  = '01HZZZZZZZZZZZZZZZZZZZZZZ1';
	private const ITEM_B  = '01HZZZZZZZZZZZZZZZZZZZZZZ2';

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
	}

	private function admin(): int {
		return self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * @param array<string, mixed> $doc Document.
	 */
	private function validate( array $doc, ?array $previous = null ): \CrazyLinks\Schema\ValidationResult {
		return ( new PageValidator() )->validate( $doc, $this->admin(), $previous );
	}

	/**
	 * @param array<string, mixed> $doc  Document.
	 * @param string               $mode Mode.
	 * @param bool                 $track Track clicks.
	 */
	private function render( array $doc, string $mode = 'standalone', bool $track = false ): string {
		$result = $this->validate( $doc );
		$this->assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		$ctx = new RenderContext( 0, 'p', $this->admin(), $mode, $track, (array) $result->document()['settings'], time() );
		return ( new Renderer() )->render( $result->document(), $ctx );
	}

	private function image(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
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

	// Footer credit.

	public function test_footer_credit_is_off_by_default_and_on_in_every_mode(): void {
		$this->assertStringNotContainsString( 'cl-credit', $this->render( $this->doc() ) );
		$on = $this->doc( [], [ 'footer_credit' => true ] );
		foreach ( [ 'standalone', 'theme', 'embed', 'preview' ] as $mode ) {
			$html = $this->render( $on, $mode );
			$this->assertStringContainsString( '<a href="https://wordpress.org/plugins/crazylinks/" rel="nofollow noopener">Made with CrazyLinks</a>', $html, $mode );
		}
	}

	public function test_footer_credit_text_and_url_are_filterable_and_escaped(): void {
		add_filter( 'crazylinks_footer_credit_text', static fn() => 'Built <b>with</b> care' );
		add_filter( 'crazylinks_footer_credit_url', static fn() => 'https://example.com/?a=1&b="2"' );
		$html = $this->render( $this->doc( [], [ 'footer_credit' => true ] ) );
		remove_all_filters( 'crazylinks_footer_credit_text' );
		remove_all_filters( 'crazylinks_footer_credit_url' );
		$this->assertStringContainsString( 'Built &lt;b&gt;with&lt;/b&gt; care', $html );
		$this->assertStringNotContainsString( '<b>with</b>', $html );
		$this->assertStringContainsString( 'href="https://example.com/?a=1&#038;b=2" rel="nofollow noopener"', $html );
	}

	public function test_footer_credit_must_be_boolean(): void {
		$this->assertFalse( $this->validate( $this->doc( [], [ 'footer_credit' => 'yes' ] ) )->is_valid() );
	}

	// Social icon click tracking.

	public function test_missing_social_item_ids_are_generated_and_reused_from_the_previous_document(): void {
		$doc = $this->socials_doc();
		unset( $doc['blocks'][0]['props']['items'][0]['id'], $doc['blocks'][0]['props']['items'][1]['id'] );
		$first = $this->validate( $doc );
		$this->assertTrue( $first->is_valid(), wp_json_encode( $first->errors() ) );
		$items = $first->document()['blocks'][0]['props']['items'];
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $items[0]['id'] );
		$this->assertNotSame( $items[0]['id'], $items[1]['id'] );

		$again = $this->validate( $doc, $first->document() );
		$this->assertSame( $items[0]['id'], $again->document()['blocks'][0]['props']['items'][0]['id'] );
	}

	public function test_duplicate_or_invalid_social_item_ids(): void {
		$doc                                              = $this->socials_doc();
		$doc['blocks'][0]['props']['items'][1]['id']      = self::ITEM_A;
		$result                                           = $this->validate( $doc );
		$items                                            = $result->document()['blocks'][0]['props']['items'];
		$this->assertTrue( $result->is_valid() );
		$this->assertNotSame( $items[0]['id'], $items[1]['id'] );
		$doc['blocks'][0]['props']['items'][1]['id']      = 'not-a-ulid';
		$this->assertFalse( $this->validate( $doc )->is_valid() );
	}

	public function test_social_icons_route_web_links_through_go_and_keep_mailto_direct(): void {
		$html = $this->render( $this->socials_doc(), 'standalone', true );
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

	public function test_db_7_upgrade_adds_item_id_to_an_existing_rollup_table(): void {
		global $wpdb;
		// ALTER TABLE commits whatever the test has changed so far, so put back what set_up() changed first.
		$this->set_permalink_structure( '' );
		$daily = $wpdb->prefix . 'crazylinks_daily';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$daily} DROP PRIMARY KEY, DROP COLUMN item_id, ADD PRIMARY KEY (page_id,block_id,day,type)" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}crazylinks_events DROP COLUMN item_id" );
		$wpdb->query( "INSERT INTO {$daily} (page_id, block_id, day, type, count, uniques) VALUES (1, 'B', '2026-01-01', 'click', 4, 3)" );
		\CrazyLinks\Activator::install_tables();
		$this->assertSame( '', $wpdb->last_error );
		$key = $wpdb->get_col( "SHOW KEYS FROM {$daily} WHERE Key_name = 'PRIMARY'", 4 );
		$this->assertSame( [ 'page_id', 'block_id', 'item_id', 'day', 'type' ], $key );
		$this->assertSame( '4', $wpdb->get_var( "SELECT count FROM {$daily} WHERE page_id = 1 AND item_id = ''" ), 'Existing rollups are kept' );
		$this->assertNotEmpty( $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}crazylinks_events LIKE 'item_id'" ) );
		// ALTER TABLE commits, so the test row is not rolled back with the test: remove it by hand.
		$wpdb->query( "DELETE FROM {$daily} WHERE page_id = 1 AND block_id = 'B'" );
		$wpdb->query( 'COMMIT' );
		// phpcs:enable
	}

	// Alt text warnings.

	public function test_missing_alt_text_is_a_warning_not_an_error(): void {
		$id     = $this->image();
		$result = $this->validate(
			$this->doc(
				[
					[
						'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZA',
						'type'  => 'image',
						'props' => [ 'image' => $id ],
					],
					[
						'type'  => 'gallery',
						'props' => [
							'items' => [
								[ 'image' => $id ],
								[
									'image' => $id,
									'alt'   => 'A field',
								],
								[
									'image'      => $id,
									'decorative' => true,
								],
							],
						],
					],
					[
						'type'  => 'link',
						'props' => [
							'title'     => 'L',
							'url'       => 'https://example.com',
							'display'   => 'featured',
							'thumbnail' => $id,
						],
					],
				]
			)
		);
		$this->assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		$paths = array_column( $result->warnings(), 'path' );
		$this->assertSame( [ '/blocks/0/props/alt', '/blocks/1/props/items/0/alt', '/blocks/2/props/thumbnail_alt' ], $paths );
		$this->assertSame( 'missing_alt', $result->warnings()[0]['code'] );
		$this->assertSame( '01HZZZZZZZZZZZZZZZZZZZZZZA', $result->warnings()[0]['block_id'] );
	}

	public function test_alt_text_silences_the_warning_and_decorative_renders_empty_alt(): void {
		$id   = $this->image();
		$doc  = $this->doc(
			[
				[
					'type'  => 'image',
					'props' => [
						'image' => $id,
						'alt'   => 'Canola field',
					],
				],
				[
					'type'  => 'image',
					'props' => [
						'image'      => $id,
						'decorative' => true,
					],
				],
			]
		);
		$result = $this->validate( $doc );
		$this->assertSame( [], $result->warnings() );
		$html = $this->render( $doc );
		$this->assertStringContainsString( 'alt="Canola field"', $html );
		$this->assertStringContainsString( 'alt=""', $html );
	}

	public function test_media_library_alt_counts_and_is_rendered(): void {
		$id = $this->image();
		update_post_meta( $id, '_wp_attachment_image_alt', 'From the library' );
		$doc = $this->doc( [ [ 'type' => 'image', 'props' => [ 'image' => $id ] ] ] );
		$this->assertSame( [], $this->validate( $doc )->warnings() );
		$this->assertStringContainsString( 'alt="From the library"', $this->render( $doc ) );
	}

	public function test_avatar_without_a_name_warns(): void {
		$doc                    = $this->doc();
		$doc['profile']         = [ 'avatar' => $this->image() ];
		$this->assertSame( [ '/profile/name' ], array_column( $this->validate( $doc )->warnings(), 'path' ) );
	}

	public function test_invalid_documents_have_no_warnings(): void {
		$this->assertSame( [], $this->validate( [ 'blocks' => 'nope' ] )->warnings() );
	}

	// Post lock.

	public function test_second_user_sees_a_lock_and_a_core_takeover_url(): void {
		$post = $this->make_page( $this->doc() );
		$a    = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Alice Editor' ] );
		$b    = self::factory()->user->create( [ 'role' => 'administrator' ] );

		wp_set_current_user( $a );
		$mine = PostLock::state( $post );
		$this->assertFalse( $mine['locked'] );
		$this->assertMatchesRegularExpression( '/^\d+:' . $a . '$/', $mine['activeLock'] );

		wp_set_current_user( $b );
		$theirs = PostLock::state( $post );
		$this->assertTrue( $theirs['locked'] );
		$this->assertSame( 'Alice Editor', $theirs['user']['name'] );
		$this->assertStringContainsString( 'get-post-lock=1', $theirs['takeOverUrl'] );
		$this->assertStringContainsString( '_wpnonce=', $theirs['takeOverUrl'] );
		$this->assertSame( $a, (int) wp_check_post_lock( $post->ID ), 'Opening the editor as B did not steal the lock' );

		// Core's takeover: B sets the lock, A is now the one locked out.
		wp_set_post_lock( $post->ID );
		$this->assertFalse( PostLock::state( $post )['locked'] );
		wp_set_current_user( $a );
		$this->assertTrue( PostLock::state( $post )['locked'] );
	}

	public function test_lock_state_is_added_to_the_editor_boot_data(): void {
		$post = $this->make_page( $this->doc() );
		$data = apply_filters( 'crazylinks_editor_data', [ 'x' => 1 ], $post );
		$this->assertArrayHasKey( 'lock', $data );
		$this->assertFalse( $data['lock']['locked'] );
		$this->assertTrue( wp_script_is( 'heartbeat', 'enqueued' ) );
	}

	// Email capture wording.

	public function test_email_capture_prefers_the_blocks_wording(): void {
		$doc  = $this->doc(
			[
				[
					'type'  => 'email_capture',
					'props' => [
						'success_text' => 'Welcome <aboard>',
						'pending_text' => 'Tap the link in your inbox.',
					],
				],
			]
		);
		$html = $this->render( $doc );
		$this->assertStringContainsString( 'Tap the link in your inbox.', $html );
		$this->assertStringContainsString( 'Welcome &lt;aboard&gt;', $html );
		$this->assertStringNotContainsString( 'Check your inbox and click the link', $html );
		$this->assertStringNotContainsString( 'You are in. Thanks', $html );
		$this->assertStringContainsString( 'Please enter a valid email address.', $html, 'Other messages keep the defaults' );

		$plain = $this->render( $this->doc( [ [ 'type' => 'email_capture', 'props' => [] ] ] ) );
		$this->assertStringContainsString( 'Check your inbox and click the link', $plain );
	}

	// Focal point and tile size.

	public function test_focal_point_renders_object_position(): void {
		$id   = $this->image();
		$doc  = $this->doc(
			[
				[
					'type'  => 'link',
					'props' => [
						'title'       => 'F',
						'url'         => 'https://example.com',
						'display'     => 'featured',
						'thumbnail'   => $id,
						'focal_point' => [ 'x' => 0.25, 'y' => 0.8 ],
					],
				],
			]
		);
		$doc['profile'] = [
			'name'               => 'B',
			'header_layout'      => 'banner',
			'banner'             => $id,
			'banner_focal_point' => [ 'x' => 1, 'y' => 0 ],
		];
		$html = $this->render( $doc );
		$this->assertStringContainsString( 'object-position:25% 80%', $html );
		$this->assertStringContainsString( 'object-position:100% 0%', $html );
		$this->assertSame( '', Renderer::focal_style( [ 'x' => 0.5, 'y' => 0.5 ] ) );
		$this->assertSame( '', Renderer::focal_style( 'x' ) );
		$this->assertSame( 'object-position:100% 0%', Renderer::focal_style( [ 'x' => 7, 'y' => -3 ] ) );
		$this->assertFalse( $this->validate( $this->doc( [ [ 'type' => 'link', 'props' => [ 'title' => 'F', 'url' => 'https://example.com', 'focal_point' => [ 'x' => 2, 'y' => 0 ] ] ] ] ) )->is_valid() );
	}

	public function test_tile_size_adds_a_class_and_is_validated(): void {
		$doc  = $this->doc(
			[
				[
					'type'  => 'link',
					'props' => [
						'title'     => 'Big',
						'url'       => 'https://example.com',
						'tile_size' => '2x2',
					],
				],
				[
					'type'  => 'link',
					'props' => [
						'title' => 'Small',
						'url'   => 'https://example.com/s',
					],
				],
			]
		);
		$doc['design'] = [ 'layout' => 'grid' ];
		$html          = $this->render( $doc );
		$this->assertSame( 1, substr_count( $html, 'cl-tile-2x2' ) );
		$this->assertStringContainsString( 'cl-layout-grid', $html );
		$doc['blocks'][0]['props']['tile_size'] = '3x3';
		$this->assertFalse( $this->validate( $doc )->is_valid() );
	}
}
