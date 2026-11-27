<?php
/**
 * Release package and source guards.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Activator;

/**
 * Dev scripts refuse to run outside the CLI, dev tooling stays out of the release zip and multisite loops are uncapped.
 */
class PackagingTest extends TestCase {

	/**
	 * Dev scripts refuse to run outside the CLI, and the release zip excludes dev tooling.
	 */
	public function test_bin_scripts_are_cli_only_and_dev_files_are_distignored(): void {
		$scripts = (array) glob( CRAZYLINKS_DIR . 'bin/*.php' );
		$this->assertNotEmpty( $scripts );
		foreach ( $scripts as $file ) {
			$this->assertMatchesRegularExpression( "/^PHP_SAPI === 'cli' \\|\\| exit;/m", (string) file_get_contents( (string) $file ), (string) $file );
		}
		$ignore = array_map( 'trim', (array) file( CRAZYLINKS_DIR . '.distignore' ) );
		foreach ( [ 'vendor', 'tests', 'bin', 'node_modules', 'composer.*', 'phpunit*', 'phpcs*', 'phpstan*', '.phpunit.result.cache', '.editorconfig', '.gitignore', '.distignore' ] as $entry ) {
			$this->assertContains( $entry, $ignore, $entry );
		}
	}

	/**
	 * Network activation and uninstall walk every site, not the first 100/500.
	 */
	public function test_multisite_loops_are_uncapped(): void {
		foreach ( [ 'src/Activator.php', 'uninstall.php' ] as $file ) {
			$src = (string) file_get_contents( CRAZYLINKS_DIR . $file );
			$this->assertMatchesRegularExpression( "/get_sites\\(\\s*(\\[|array\\()\\s*'fields'\\s*=>\\s*'ids',\\s*'number'\\s*=>\\s*0,/", $src, $file );
		}
	}
}
