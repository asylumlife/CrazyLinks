<?php
/**
 * [crazylinks id="123"] shortcode.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Frontend;

use CrazyLinks\PageStore;
use CrazyLinks\PostType;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Render\Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Embeds a published link page in any content.
 */
final class Shortcode {

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_shortcode( 'crazylinks', [ $this, 'render' ] );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], (array) $atts, 'crazylinks' );
		return self::embed( (int) $atts['id'] );
	}

	/**
	 * Embedded page markup with its inline assets (printed once per request).
	 *
	 * @param int $id Page id.
	 */
	public static function embed( int $id ): string {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type ) {
			return '';
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post->ID ) ) {
			return '';
		}
		if ( post_password_required( $post ) ) {
			return '';
		}
		$doc  = PageStore::get_document( $post ) ?? [];
		$html = ( new Renderer() )->render_post( $post, 'embed' );
		return Assets::style_tag( ( new Tokens( (array) ( $doc['design'] ?? [] ) ) )->custom_css() ) . $html . Assets::script_tag();
	}
}
