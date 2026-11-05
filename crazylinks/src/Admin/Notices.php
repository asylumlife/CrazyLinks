<?php
/**
 * Admin notices (URL conflicts).
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Admin;

use CrazyLinks\PostType;
use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Warns when existing content uses the link-page URL base.
 */
final class Notices {

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'save_post', [ $this, 'clear_cache' ] );
		add_action( 'update_option_' . Settings::OPTION, [ $this, 'clear_cache' ] );
	}

	/**
	 * Content that collides with /{base}/.
	 *
	 * @param string $base URL base.
	 * @return string[] Human-readable conflicts.
	 */
	public static function conflicts( string $base ): array {
		$out   = [];
		$types = array_diff( get_post_types( [ 'public' => true ] ), [ PostType::NAME ] );
		foreach ( $types as $type ) {
			$post = get_page_by_path( $base, OBJECT, $type );
			if ( $post instanceof \WP_Post && 'trash' !== $post->post_status ) {
				$out[] = sprintf(
					/* translators: 1: post type label, 2: post title, 3: URL base. */
					__( 'The %1$s "%2$s" uses the slug "%3$s". Link pages live under /%3$s/, so child URLs of that content are taken by CrazyLinks when a link page has the same slug. Rename it or change the CrazyLinks URL base.', 'crazylinks' ),
					strtolower( (string) get_post_type_object( $type )?->labels->singular_name ),
					get_the_title( $post ),
					$base
				);
			}
		}
		foreach ( get_taxonomies( [ 'public' => true ] ) as $tax ) {
			$term = get_term_by( 'slug', $base, $tax );
			if ( $term instanceof \WP_Term ) {
				$out[] = sprintf(
					/* translators: 1: term name, 2: URL base. */
					__( 'The term "%1$s" uses the slug "%2$s", which matches the CrazyLinks URL base. Check that its archive URLs still work.', 'crazylinks' ),
					$term->name,
					$base
				);
			}
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$out[] = __( 'Pretty permalinks are off, so link pages use query-string URLs (?crazylinks_slug=...). Enable permalinks for /l/your-page addresses.', 'crazylinks' );
		}
		return $out;
	}

	/**
	 * Shows conflicts to managers on the dashboard, plugins and CrazyLinks screens (cached 1 hour).
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_crazylinks' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'plugins', 'edit-' . PostType::NAME, PostType::NAME ], true ) ) {
			return;
		}
		$conflicts = get_transient( 'crazylinks_conflicts' );
		if ( ! is_array( $conflicts ) ) {
			$conflicts = self::conflicts( Settings::base() );
			set_transient( 'crazylinks_conflicts', $conflicts, HOUR_IN_SECONDS );
		}
		foreach ( $conflicts as $conflict ) {
			echo '<div class="notice notice-warning"><p><strong>CrazyLinks:</strong> ' . esc_html( (string) $conflict ) . '</p></div>';
		}
	}

	/**
	 * Clears the cached check.
	 */
	public function clear_cache(): void {
		delete_transient( 'crazylinks_conflicts' );
	}
}
