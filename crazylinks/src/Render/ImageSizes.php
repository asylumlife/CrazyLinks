<?php
/**
 * Registered image sizes.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Image sizes from SPEC §5A.6.
 */
final class ImageSizes {

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'after_setup_theme', [ $this, 'register' ] );
	}

	/**
	 * Registers sizes.
	 */
	public function register(): void {
		add_image_size( 'cl-thumb', 400, 400, true );
		add_image_size( 'cl-featured', 1360, 765, true );
		add_image_size( 'cl-tile', 800, 1000, true );
		add_image_size( 'cl-avatar', 320, 320, true );
		add_image_size( 'cl-banner', 1600, 600, true );
	}
}
