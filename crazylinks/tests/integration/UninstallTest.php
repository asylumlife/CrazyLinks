<?php
/**
 * Uninstall behaviour.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Capabilities;
use CrazyLinks\Settings;

/**
 * uninstall.php keeps data unless the owner opted in. (Table drops cannot be asserted here:
 * the WP test suite rewrites DROP TABLE to DROP TEMPORARY TABLE inside tests.)
 */
class UninstallTest extends TestCase {

	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'crazylinks/crazylinks.php' );
		}
		include CRAZYLINKS_DIR . 'uninstall.php';
	}

	public function tear_down(): void {
		Capabilities::grant();
		parent::tear_down();
	}

	public function test_file_is_guarded(): void {
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", (string) file_get_contents( CRAZYLINKS_DIR . 'uninstall.php' ) );
	}

	public function test_keeps_data_by_default(): void {
		$post = $this->make_page( $this->doc() );
		wp_schedule_event( time() + 60, 'daily', 'crazylinks_daily_rollup' );
		$this->run_uninstall();
		$this->assertNotNull( get_post( $post->ID ) );
		$this->assertFalse( wp_next_scheduled( 'crazylinks_daily_rollup' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_crazylinks' ) );
	}

	public function test_deletes_data_when_opted_in(): void {
		global $wpdb;
		$post = $this->make_page( $this->doc() );
		update_option( Settings::OPTION, [ 'delete_on_uninstall' => true ] );
		$wpdb->suppress_errors( true );
		$this->run_uninstall();
		$wpdb->suppress_errors( false );
		$this->assertNull( get_post( $post->ID ) );
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'manage_crazylinks' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'edit_crazylinks_pages' ) );
	}
}
