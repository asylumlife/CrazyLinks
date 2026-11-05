<?php
/**
 * Plugin wiring.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Boots every component. Each component registers its own hooks.
 */
final class Plugin {

	/**
	 * Guards against double boot.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Registers all hooks. Runs on plugins_loaded.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		load_plugin_textdomain( 'crazylinks', false, dirname( plugin_basename( CRAZYLINKS_FILE ) ) . '/languages' );

		Activator::maybe_upgrade();
		Activator::register_multisite_hooks();

		( new PostType() )->register_hooks();
		( new Frontend\Router() )->register_hooks();
		( new Frontend\TemplateLoader() )->register_hooks();
		( new Frontend\Embeds() )->register_hooks();
		( new Analytics\Rollup() )->register_hooks();
		( new Rest\RestRoutes() )->register_hooks();
		( new Render\ImageSizes() )->register_hooks();
		( new Ai\Abilities() )->register_hooks();

		if ( is_admin() ) {
			( new Admin\SettingsPage() )->register_hooks();
			( new Admin\EditorPage() )->register_hooks();
			( new Admin\AnalyticsPage() )->register_hooks();
			( new Admin\SubscribersPage() )->register_hooks();
			( new Admin\JsonMetaBox() )->register_hooks();
			( new Admin\Notices() )->register_hooks();
			( new Privacy() )->register_hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'crazylinks', Cli\Command::class );
		}

		/**
		 * Fires after CrazyLinks has registered its hooks.
		 */
		do_action( 'crazylinks_loaded' );
	}
}
