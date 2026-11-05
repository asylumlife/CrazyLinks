<?php
/**
 * Dynamic block crazylinks/page.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered Gutenberg block wrapping the shortcode renderer.
 */
final class PageBlock {

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ] );
	}

	/**
	 * Registers the block from blocks/page/block.json.
	 */
	public function register(): void {
		wp_register_script(
			'crazylinks-page-block-editor',
			CRAZYLINKS_URL . 'assets/src/block-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-api-fetch', 'wp-i18n' ],
			CRAZYLINKS_VERSION,
			true
		);
		wp_set_script_translations( 'crazylinks-page-block-editor', 'crazylinks', CRAZYLINKS_DIR . 'languages' );
		register_block_type(
			CRAZYLINKS_DIR . 'blocks/page',
			[ 'render_callback' => [ $this, 'render' ] ]
		);
	}

	/**
	 * Render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( $attributes ): string {
		$id = (int) ( $attributes['id'] ?? 0 );
		if ( $id <= 0 ) {
			return '';
		}
		$html = Shortcode::embed( $id );
		return '' === $html ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}
}
