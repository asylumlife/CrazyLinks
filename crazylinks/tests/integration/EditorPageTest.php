<?php
/**
 * React editor screen.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Admin\EditorPage;
use CrazyLinks\PostType;
use CrazyLinks\Schema\PageValidator;

/**
 * Screen takeover, enqueueing and boot data.
 */
class EditorPageTest extends TestCase {

	public function tear_down(): void {
		wp_dequeue_script( EditorPage::HANDLE );
		wp_deregister_script( EditorPage::HANDLE );
		wp_dequeue_style( EditorPage::HANDLE );
		wp_deregister_style( EditorPage::HANDLE );
		parent::tear_down();
	}

	public function test_build_exists(): void {
		$this->assertFileExists( CRAZYLINKS_DIR . 'assets/build/editor/index.js' );
		$this->assertFileExists( CRAZYLINKS_DIR . 'assets/build/editor/index.asset.php' );
		$this->assertFileExists( CRAZYLINKS_DIR . 'assets/build/editor/style-index.css' );
		$this->assertFileExists( CRAZYLINKS_DIR . 'assets/build/editor/style-index-rtl.css' );
	}

	public function test_replace_editor_ignores_other_post_types_and_the_screen_setup_call(): void {
		$page = new EditorPage();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post = get_post( self::factory()->post->create() );
		$this->assertFalse( $page->replace_editor( false, $post ) );
		$this->assertTrue( $page->replace_editor( true, $post ), 'Another plugin already replaced it' );

		// WP_Screen::get() applies the filter before load-post.php: nothing may be printed or enqueued.
		$link = $this->make_page( $this->doc() );
		ob_start();
		$result = $page->replace_editor( false, $link );
		$this->assertSame( '', ob_get_clean() );
		$this->assertFalse( $result );
		$this->assertFalse( wp_script_is( EditorPage::HANDLE, 'enqueued' ) );
	}

	public function test_classic_switch_keeps_the_json_screen(): void {
		$_GET[ EditorPage::CLASSIC_ARG ] = '1';
		$this->assertTrue( EditorPage::wants_classic() );
		unset( $_GET[ EditorPage::CLASSIC_ARG ] );
		$this->assertFalse( EditorPage::wants_classic() );
		$this->assertStringContainsString( 'crazylinks_classic=1', EditorPage::classic_url( 5 ) );
	}

	public function test_enqueue_registers_the_bundle_with_boot_data(): void {
		$post = $this->make_page( $this->doc() );
		( new EditorPage() )->enqueue( $post );
		$this->assertTrue( wp_script_is( EditorPage::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( EditorPage::HANDLE, 'enqueued' ) );
		$before = (string) wp_scripts()->get_inline_script_data( EditorPage::HANDLE, 'before' );
		$this->assertStringStartsWith( 'window.crazylinks = {', $before );
		$this->assertStringNotContainsString( '</script', $before );
		$deps = wp_scripts()->registered[ EditorPage::HANDLE ]->deps;
		foreach ( [ 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n' ] as $dep ) {
			$this->assertContains( $dep, $deps );
		}
	}

	public function test_editor_assets_never_load_on_the_front_end(): void {
		$this->make_page( $this->doc(), 'front' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_script_is( EditorPage::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( EditorPage::HANDLE, 'enqueued' ) );
		// The only hook is replace_editor, which core applies on post.php / post-new.php alone.
		$page = new EditorPage();
		$page->register_hooks();
		$this->assertSame( 10, has_filter( 'replace_editor', [ $page, 'replace_editor' ] ) );
		$this->assertFalse( has_action( 'admin_enqueue_scripts', [ $page, 'enqueue' ] ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', [ $page, 'enqueue' ] ) );
		remove_filter( 'replace_editor', [ $page, 'replace_editor' ] );
	}

	public function test_boot_data_shape(): void {
		$post = $this->make_page( $this->doc() );
		$data = ( new EditorPage() )->boot_data( $post );

		$this->assertSame( $post->ID, $data['post']['id'] );
		$this->assertSame( 'One', $data['post']['page']['blocks'][0]['props']['title'] );
		$this->assertFalse( $data['post']['isNew'] );
		$this->assertStringContainsString( 'crazylinks_classic=1', $data['post']['classicUrl'] );
		$this->assertSame( PageValidator::schema(), $data['schema'] );
		$this->assertArrayHasKey( 'default', $data['themes'] );
		$this->assertArrayHasKey( 'midnight', $data['themes'] );
		$this->assertNotEmpty( $data['icons'] );
		$this->assertArrayHasKey( 'instagram', $data['networks'] );
		$this->assertArrayHasKey( 'kofi', $data['payments'] );
		$this->assertTrue( $data['caps']['unfiltered_html'] );
		$this->assertTrue( $data['caps']['publish_crazylinks_pages'] );
		$this->assertFalse( $data['ai']['available'] );
		$this->assertNotEmpty( $data['rest']['nonce'] );
		$this->assertSame( 10, $data['settings']['autosaveInterval'] );
		$this->assertNull( $data['autosave'] );

		$types = $data['schema']['$defs']['block']['properties']['type']['enum'];
		$this->assertSame( $types, array_column( $data['blockCatalog'], 'type' ), 'Every schema type is offered' );
	}

	public function test_block_catalog_reflects_capabilities(): void {
		add_role(
			'cl_writer',
			'CL Writer',
			[
				'read'                  => true,
				'edit_crazylinks_pages' => true,
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'cl_writer' ] ) );
		$catalog = array_column( EditorPage::block_catalog(), null, 'type' );
		$this->assertFalse( $catalog['html']['available'] );
		$this->assertNotSame( '', $catalog['html']['reason'] );
		$this->assertTrue( $catalog['link']['available'] );
		$this->assertSame( class_exists( 'WooCommerce' ), $catalog['woo_products']['available'] );
		remove_role( 'cl_writer' );
	}

	public function test_filters(): void {
		$post = $this->make_page( $this->doc() );
		add_filter( 'crazylinks_editor_ai_available', '__return_true' );
		add_filter(
			'crazylinks_editor_data',
			static function ( $data ) {
				$data['extra'] = 1;
				return $data;
			}
		);
		$data = ( new EditorPage() )->boot_data( $post );
		$this->assertTrue( $data['ai']['available'] );
		$this->assertSame( 1, $data['extra'] );
	}

	public function test_newer_autosave_is_offered(): void {
		$post = $this->make_page( $this->doc() );
		$doc  = $this->doc();
		$doc['blocks'][0]['props']['title'] = 'Autosaved';
		$req = new \WP_REST_Request( 'POST', '/crazylinks/v1/pages/' . $post->ID . '/autosave' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( [ 'page' => $doc ] ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $req )->get_status() );
		global $wpdb;
		// Make the page older than its autosave.
		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => '2000-01-01 00:00:00' ], [ 'ID' => $post->ID ] );
		clean_post_cache( $post->ID );

		$data = ( new EditorPage() )->boot_data( get_post( $post->ID ) );
		$this->assertIsArray( $data['autosave'] );
		$this->assertSame( 'Autosaved', $data['autosave']['page']['blocks'][0]['props']['title'] );
	}

	public function test_new_page_is_flagged(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$id   = self::factory()->post->create(
			[
				'post_type'   => PostType::NAME,
				'post_status' => 'auto-draft',
				'post_title'  => 'Auto Draft',
			]
		);
		$data = ( new EditorPage() )->boot_data( get_post( $id ) );
		$this->assertTrue( $data['post']['isNew'] );
		$this->assertSame( '', $data['post']['title'] );
		$this->assertSame( [], $data['post']['page']['blocks'] );
	}

	public function test_media_lookup_covers_referenced_attachments(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$att = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/png',
				'post_title'     => 'Avatar',
			]
		);
		update_post_meta( $att, '_wp_attachment_image_alt', 'Me' );
		$doc  = [
			'profile'  => [ 'avatar' => $att ],
			'design'   => [],
			'blocks'   => [
				[
					'props' => [
						'items' => [ [ 'image' => $att ] ],
					],
				],
			],
			'settings' => [],
		];
		$media = EditorPage::media_for( $doc );
		$this->assertArrayHasKey( $att, $media );
		$this->assertSame( 'Me', $media[ $att ]['alt'] );
		$this->assertSame( 'image/png', $media[ $att ]['mime'] );
		$this->assertSame( [], EditorPage::media_for( [ 'profile' => [ 'avatar' => 999999 ] ] ) );
	}
}
