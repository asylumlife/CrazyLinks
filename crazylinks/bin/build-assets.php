<?php
/**
 * Builds assets/build/*.min.{css,js} from assets/src by stripping comments and whitespace.
 * Usage: php bin/build-assets.php
 *
 * @package CrazyLinks
 */

PHP_SAPI === 'cli' || exit; // Dev tool: never runs over HTTP.

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped

$crazylinks_root = dirname( __DIR__ );

$crazylinks_css = (string) file_get_contents( $crazylinks_root . '/assets/src/frontend.css' );
$crazylinks_css = (string) preg_replace( '#/\*.*?\*/#s', '', $crazylinks_css );
$crazylinks_css = (string) preg_replace( '/\s*\n\s*/', '', $crazylinks_css );
file_put_contents( $crazylinks_root . '/assets/build/frontend.min.css', trim( $crazylinks_css ) . "\n" );

$crazylinks_js = (string) file_get_contents( $crazylinks_root . '/assets/src/frontend.js' );
$crazylinks_js = (string) preg_replace( '#^\s*/\*.*?\*/\s*$#ms', '', $crazylinks_js );
$crazylinks_js = (string) preg_replace( '#^\s*//.*$#m', '', $crazylinks_js );
$crazylinks_js = (string) preg_replace( '/^\s+/m', '', $crazylinks_js );
$crazylinks_js = (string) preg_replace( '/\n+/', "\n", $crazylinks_js );
file_put_contents( $crazylinks_root . '/assets/build/frontend.min.js', trim( $crazylinks_js ) . "\n" );

foreach ( [ 'assets/src/frontend.css', 'assets/build/frontend.min.css', 'assets/src/frontend.js', 'assets/build/frontend.min.js' ] as $crazylinks_file ) {
	printf( "%-32s %6d bytes\n", $crazylinks_file, filesize( $crazylinks_root . '/' . $crazylinks_file ) );
}
