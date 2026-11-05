<?php
/**
 * Hooks that must exist on every request.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the privacy exporter and eraser and the WP-CLI subscribers command. Safe to call twice.
 */
final class Bootstrap {

	/**
	 * Guards against double registration.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		( new PersonalData() )->register_hooks();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// After Plugin::boot() added the parent `crazylinks` command.
			add_action(
				'crazylinks_loaded',
				static function (): void {
					\WP_CLI::add_command( 'crazylinks subscribers', \CrazyLinks\Cli\SubscribersCommand::class );
				}
			);
		}
	}
}
