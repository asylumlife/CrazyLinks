<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with CrazyLinks active.
 *
 * @package CrazyLinks
 */

PHP_SAPI === 'cli' || exit; // Dev tool: never runs over HTTP.

$crazylinks_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/tmp/crazylinks-tests/develop/tests/phpunit';

if ( ! file_exists( $crazylinks_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test library not found in {$crazylinks_tests_dir}. Run bin/install-wp-tests.sh first." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $crazylinks_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/crazylinks.php';
	}
);
// Tests never make remote requests.
tests_add_filter( 'crazylinks_fetch_oembed', '__return_false' );

require $crazylinks_tests_dir . '/includes/bootstrap.php';

// Create tables and caps once, outside the per-test transactions.
\CrazyLinks\Activator::install_tables();
\CrazyLinks\Capabilities::grant();

require __DIR__ . '/TestCase.php';
