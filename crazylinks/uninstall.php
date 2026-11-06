<?php
/**
 * Uninstall handler. Deletes data only when the owner opted in.
 *
 * @package CrazyLinks
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'crazylinks_uninstall_site' ) ) {
	/**
	 * Removes scheduled events, and all data when the setting asks for it, for one site.
	 *
	 * @return void
	 */
	function crazylinks_uninstall_site() {
		global $wpdb;

		wp_clear_scheduled_hook( 'crazylinks_daily_rollup' );

		$settings = get_option( 'crazylinks_settings', array() );
		if ( empty( $settings['delete_on_uninstall'] ) ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'crazylinks_page',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}crazylinks_events" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}crazylinks_daily" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}crazylinks_limits" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}crazylinks_subscribers" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}crazylinks_ai_log" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'crazylinks\_salt\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_crazylinks\_%' OR option_name LIKE '\_transient\_timeout\_crazylinks\_%'" );
		// phpcs:enable

		foreach ( array( 'crazylinks_settings', 'crazylinks_db_version', 'crazylinks_salt', 'crazylinks_rollup_through', 'crazylinks_flush_rewrite', 'crazylinks_cache_salt', 'crazylinks_ai_usage' ) as $option ) {
			delete_option( $option );
		}

		$caps = array(
			'edit_crazylinks_pages',
			'edit_others_crazylinks_pages',
			'edit_published_crazylinks_pages',
			'edit_private_crazylinks_pages',
			'publish_crazylinks_pages',
			'read_private_crazylinks_pages',
			'delete_crazylinks_pages',
			'delete_others_crazylinks_pages',
			'delete_published_crazylinks_pages',
			'delete_private_crazylinks_pages',
			'view_crazylinks_analytics',
			'manage_crazylinks',
			'use_crazylinks_ai',
		);
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( $caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		remove_role( 'crazylinks_agent' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $crazylinks_site_id ) {
		switch_to_blog( (int) $crazylinks_site_id );
		crazylinks_uninstall_site();
		restore_current_blog();
	}
} else {
	crazylinks_uninstall_site();
}
