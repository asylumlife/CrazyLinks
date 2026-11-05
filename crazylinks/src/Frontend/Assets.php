<?php
/**
 * Front-end CSS and JS (inlined, SPEC §6.1).
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the built front-end assets and prints them inline, once per request.
 */
final class Assets {

	/**
	 * Whether the inline assets were printed in this request.
	 *
	 * @var array<string, bool>
	 */
	private static array $printed = [];

	/**
	 * File contents from assets/build (or assets/src with SCRIPT_DEBUG / no build).
	 *
	 * @param string $name frontend.css | frontend.js.
	 */
	public static function read( string $name ): string {
		$debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$build = CRAZYLINKS_DIR . 'assets/build/' . preg_replace( '/\.(css|js)$/', '.min.$1', $name );
		$file  = ( ! $debug && is_readable( $build ) ) ? $build : CRAZYLINKS_DIR . 'assets/src/' . $name;
		return is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	}

	/**
	 * Inline <style> with the plugin CSS (once).
	 *
	 * @param string $extra Extra CSS (page custom CSS), printed every time.
	 */
	public static function style_tag( string $extra = '' ): string {
		$out = '';
		if ( empty( self::$printed['css'] ) ) {
			self::$printed['css'] = true;
			$out                 .= '<style id="crazylinks-css">' . self::read( 'frontend.css' ) . '</style>';
		}
		if ( '' !== $extra ) {
			$out .= '<style class="crazylinks-custom-css">' . str_replace( '</', '<\/', $extra ) . '</style>';
		}
		return $out;
	}

	/**
	 * Inline <script> (once).
	 */
	public static function script_tag(): string {
		if ( ! empty( self::$printed['js'] ) ) {
			return '';
		}
		self::$printed['js'] = true;
		return wp_get_inline_script_tag( self::read( 'frontend.js' ), [ 'id' => 'crazylinks-js' ] );
	}

	/**
	 * Resets print state (tests).
	 */
	public static function reset(): void {
		self::$printed = [];
	}
}
