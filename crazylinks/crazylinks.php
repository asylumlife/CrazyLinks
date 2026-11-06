<?php
/**
 * Plugin Name:       CrazyLinks
 * Plugin URI:        https://github.com/asylumlife/crazylinks
 * Description:       A self-hosted link-in-bio page builder. Your link page, your domain, your data.
 * Version:           0.1.1
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Asylum Life
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       crazylinks
 * Domain Path:       /languages
 *
 * @package CrazyLinks
 */

defined( 'ABSPATH' ) || exit;

define( 'CRAZYLINKS_VERSION', '0.1.1' );
define( 'CRAZYLINKS_FILE', __FILE__ );
define( 'CRAZYLINKS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRAZYLINKS_URL', plugin_dir_url( __FILE__ ) );
define( 'CRAZYLINKS_MIN_WP', '6.9' );
define( 'CRAZYLINKS_MIN_PHP', '8.1' );

/**
 * Returns a list of unmet requirements (empty when the environment is fine).
 *
 * @return string[]
 */
function crazylinks_unmet_requirements() {
	$unmet = array();
	if ( version_compare( PHP_VERSION, CRAZYLINKS_MIN_PHP, '<' ) ) {
		/* translators: 1: required PHP version, 2: current PHP version. */
		$unmet[] = sprintf( __( 'PHP %1$s or newer is required (this site runs %2$s).', 'crazylinks' ), CRAZYLINKS_MIN_PHP, PHP_VERSION );
	}
	if ( version_compare( get_bloginfo( 'version' ), CRAZYLINKS_MIN_WP, '<' ) ) {
		/* translators: 1: required WordPress version, 2: current WordPress version. */
		$unmet[] = sprintf( __( 'WordPress %1$s or newer is required (this site runs %2$s).', 'crazylinks' ), CRAZYLINKS_MIN_WP, get_bloginfo( 'version' ) );
	}
	return $unmet;
}

if ( crazylinks_unmet_requirements() ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'CrazyLinks is inactive.', 'crazylinks' ) . '</strong> ';
			echo esc_html( implode( ' ', crazylinks_unmet_requirements() ) );
			echo '</p></div>';
		}
	);
	return;
}

/*
 * PSR-4 autoloader for CrazyLinks\ classes. The plugin has no runtime Composer
 * dependencies, so it never loads vendor/ (which holds dev tooling only).
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'CrazyLinks\\' ) ) {
			return;
		}
		$file = CRAZYLINKS_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, 11 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( \CrazyLinks\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \CrazyLinks\Activator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \CrazyLinks\Plugin::class, 'boot' ) );
