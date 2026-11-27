<?php
/**
 * Builds assets/build/frontend.min.{css,js} from assets/src/frontend.{css,js} by stripping
 * comments, whitespace and the last semicolon of each rule. The editor bundle is built separately by `npm run build`.
 *
 * Usage: php bin/build-assets.php  (or: composer build:assets)
 *
 * @package CrazyLinks
 */

PHP_SAPI === 'cli' || exit; // Dev tool: never runs over HTTP.

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- local CLI build script reading and writing repository files and printing to the terminal.

( static function ( string $root ): void {

	$css = (string) file_get_contents( $root . '/assets/src/frontend.css' );
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	$css = (string) preg_replace( '/\s*\n\s*/', '', $css );
	$css = (string) preg_replace( '/\s*\{/', '{', $css );
	$css = str_replace( [ ': ', ';}' ], [ ':', '}' ], $css );
	file_put_contents( $root . '/assets/build/frontend.min.css', trim( $css ) . "\n" );

	$js = (string) file_get_contents( $root . '/assets/src/frontend.js' );
	$js = (string) preg_replace( '#^\s*/\*.*?\*/\s*$#ms', '', $js );
	$js = (string) preg_replace( '#^\s*//.*$#m', '', $js );
	$js = (string) preg_replace( '/^\s+/m', '', $js );
	$js = (string) preg_replace( '/\n+/', "\n", $js );
	file_put_contents( $root . '/assets/build/frontend.min.js', trim( $js ) . "\n" );

	foreach ( [ 'assets/src/frontend.css', 'assets/build/frontend.min.css', 'assets/src/frontend.js', 'assets/build/frontend.min.js' ] as $file ) {
		printf( "%-32s %6d bytes\n", $file, filesize( $root . '/' . $file ) );
	}
} )( $argv[1] ?? dirname( __DIR__ ) );
