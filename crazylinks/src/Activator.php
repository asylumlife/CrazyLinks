<?php
/**
 * Activation, deactivation and schema upgrades.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables, grants caps, schedules cron, flushes rewrites.
 */
final class Activator {

	public const DB_VERSION = '6';

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ): void {
		// Store the option once so later saves never take WordPress's add_option path, which runs
		// the sanitizer twice.
		add_option( Settings::OPTION, Settings::defaults(), '', true );
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	/**
	 * Per-site activation.
	 */
	public static function activate_site(): void {
		self::install_tables();
		Capabilities::grant();
		Analytics\Rollup::schedule();
		( new PostType() )->register_post_type();
		( new Frontend\Router() )->add_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Deactivation hook.
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( $network_wide = false ): void {
		unset( $network_wide );
		Analytics\Rollup::unschedule();
		Subscribers\Sync::unschedule();
		delete_option( 'crazylinks_flush_rewrite' );
		Frontend\Router::remove_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Creates or updates custom tables.
	 */
	public static function install_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}crazylinks_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  page_id bigint(20) unsigned NOT NULL,
  block_id char(26) DEFAULT NULL,
  type varchar(16) NOT NULL,
  ts datetime NOT NULL,
  visitor_hash char(32) NOT NULL,
  country char(2) DEFAULT NULL,
  device varchar(8) NOT NULL DEFAULT 'desktop',
  os varchar(16) DEFAULT NULL,
  in_app varchar(16) DEFAULT NULL,
  referrer_host varchar(255) DEFAULT NULL,
  utm_source varchar(64) DEFAULT NULL,
  utm_medium varchar(64) DEFAULT NULL,
  utm_campaign varchar(64) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY page_ts (page_id,ts),
  KEY page_block_ts (page_id,block_id,ts),
  KEY ts (ts)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}crazylinks_daily (
  page_id bigint(20) unsigned NOT NULL,
  block_id char(26) NOT NULL DEFAULT '',
  day date NOT NULL,
  type varchar(16) NOT NULL,
  count int(10) unsigned NOT NULL DEFAULT 0,
  uniques int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (page_id,block_id,day,type),
  KEY day (day)
) {$charset};"
		);
		// Rate-limit and de-duplication state (Analytics\RateLimiter). Keys are hashes; no IPs.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}crazylinks_limits (
  k char(32) NOT NULL,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  expires int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (k),
  KEY expires (expires)
) {$charset};"
		);
		// Email sign-ups (Subscribers\Store). Never holds an IP address or user agent. DB 6 added the
		// destination, audience and sync columns for email services (Subscribers\Sync).
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}crazylinks_subscribers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  page_id bigint(20) unsigned NOT NULL,
  block_id char(26) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL,
  status varchar(12) NOT NULL DEFAULT 'pending',
  consent_text varchar(500) NOT NULL DEFAULT '',
  consent_at datetime DEFAULT NULL,
  confirmed_at datetime DEFAULT NULL,
  token_hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  destination varchar(16) NOT NULL DEFAULT 'local',
  audience_id varchar(32) NOT NULL DEFAULT '',
  sync_status varchar(8) NOT NULL DEFAULT '',
  sync_attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  sync_error varchar(255) NOT NULL DEFAULT '',
  synced_at datetime DEFAULT NULL,
  external_id varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY page_email (page_id,email),
  KEY token_hash (token_hash),
  KEY email (email),
  KEY status_updated (status,updated_at),
  KEY sync_status (sync_status),
  KEY audience_email (audience_id,email)
) {$charset};"
		);
		// AI proposals and applied changes (SPEC §7.3, §10). Instructions and operations only; no secrets.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}crazylinks_ai_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  page_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(16) NOT NULL DEFAULT 'propose',
  mode varchar(32) NOT NULL DEFAULT '',
  source varchar(8) NOT NULL DEFAULT 'admin',
  instruction text NULL,
  summary text NULL,
  operations longtext NULL,
  applied tinyint(1) NOT NULL DEFAULT 0,
  revision_id bigint(20) unsigned DEFAULT NULL,
  provider varchar(64) NOT NULL DEFAULT '',
  model varchar(100) NOT NULL DEFAULT '',
  prompt_tokens int(10) unsigned NOT NULL DEFAULT 0,
  completion_tokens int(10) unsigned NOT NULL DEFAULT 0,
  tokens_estimated tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  applied_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY page_created (page_id,created_at),
  KEY user_created (user_id,created_at),
  KEY revision_id (revision_id),
  KEY created_at (created_at)
) {$charset};"
		);
		update_option( 'crazylinks_db_version', self::DB_VERSION, false );
	}

	/**
	 * Runs table upgrades when the stored DB version is behind (activation does not run on updates).
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'crazylinks_db_version' ) !== self::DB_VERSION ) {
			self::install_tables();
			Capabilities::grant();
		}
	}

	/**
	 * Sets up new sites on a network where the plugin is network-active.
	 */
	public static function register_multisite_hooks(): void {
		if ( ! is_multisite() ) {
			return;
		}
		add_action(
			'wp_initialize_site',
			static function ( \WP_Site $site ): void {
				if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				if ( is_plugin_active_for_network( plugin_basename( CRAZYLINKS_FILE ) ) ) {
					switch_to_blog( (int) $site->blog_id );
					self::activate_site();
					restore_current_blog();
				}
			},
			20
		);
	}
}
