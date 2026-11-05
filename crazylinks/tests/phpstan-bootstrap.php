<?php
/**
 * Constants for static analysis.
 *
 * @package CrazyLinks
 */

PHP_SAPI === 'cli' || exit; // Dev tool: never runs over HTTP.

define( 'CRAZYLINKS_VERSION', '0.1.0' );
define( 'CRAZYLINKS_FILE', __DIR__ . '/../crazylinks.php' );
define( 'CRAZYLINKS_DIR', __DIR__ . '/../' );
define( 'CRAZYLINKS_URL', 'http://example.org/wp-content/plugins/crazylinks/' );
define( 'CRAZYLINKS_MIN_WP', '6.9' );
define( 'CRAZYLINKS_MIN_PHP', '8.1' );

// Cold PHPStan runs need more than the 256M CLI default for the WordPress stubs.
ini_set( 'memory_limit', '1G' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Static analysis bootstrap only.
