<?php
/**
 * Database upgrades.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Activator;
use CrazyLinks\Subscribers\Store;

/**
 * Upgrading an older install adds the columns and keys introduced since. These tests run ALTER TABLE, which commits.
 */
class MigrationsTest extends TestCase {

	public function test_db_7_upgrade_adds_item_id_to_an_existing_rollup_table(): void {
		global $wpdb;
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

	public function test_db_5_upgrade_adds_the_subscriber_sync_columns(): void {
		global $wpdb;
		$table = Store::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX sync_status, DROP INDEX audience_email, DROP COLUMN destination, DROP COLUMN audience_id, DROP COLUMN sync_status, DROP COLUMN sync_attempts, DROP COLUMN sync_error, DROP COLUMN synced_at, DROP COLUMN external_id" );
		// phpcs:enable
		$this->assertNotContains( 'sync_status', $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( 'crazylinks_db_version', '5' );
		Activator::maybe_upgrade();
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( [ 'destination', 'audience_id', 'sync_status', 'sync_attempts', 'sync_error', 'synced_at', 'external_id' ] as $col ) {
			$this->assertContains( $col, $cols );
		}
		$this->assertSame( Activator::DB_VERSION, get_option( 'crazylinks_db_version' ) );
	}
}
