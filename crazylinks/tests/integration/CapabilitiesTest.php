<?php
/**
 * Capabilities.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Activator;

/**
 * The agent role is created on activation and on upgrade without WP-CLI.
 */
class CapabilitiesTest extends TestCase {

	public function test_agent_role_is_created_on_activation_and_upgrade_without_cli(): void {
		remove_role( \CrazyLinks\Capabilities::AGENT_ROLE );
		$this->assertNull( get_role( \CrazyLinks\Capabilities::AGENT_ROLE ) );
		\CrazyLinks\Capabilities::grant();
		$role = get_role( \CrazyLinks\Capabilities::AGENT_ROLE );
		$this->assertNotNull( $role, 'grant() (activation and DB upgrades) creates the role' );
		$this->assertTrue( $role->has_cap( 'edit_crazylinks_pages' ) );
		$this->assertFalse( $role->has_cap( 'edit_posts' ), 'The agent role cannot touch ordinary posts' );

		remove_role( \CrazyLinks\Capabilities::AGENT_ROLE );
		update_option( 'crazylinks_db_version', '4' );
		\CrazyLinks\Activator::maybe_upgrade();
		$this->assertNotNull( get_role( \CrazyLinks\Capabilities::AGENT_ROLE ), 'A plugin update recreates it' );
		$this->assertSame( \CrazyLinks\Activator::DB_VERSION, get_option( 'crazylinks_db_version' ) );
	}
}
