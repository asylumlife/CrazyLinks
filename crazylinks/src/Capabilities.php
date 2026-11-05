<?php
/**
 * Capability map.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Custom capabilities (SPEC §2) and their default role mapping.
 */
final class Capabilities {

	/**
	 * Primitive capabilities for the crazylinks_page post type, keyed by WP cap name.
	 *
	 * @return array<string, string>
	 */
	public static function post_type_caps(): array {
		return [
			'edit_posts'             => 'edit_crazylinks_pages',
			'edit_others_posts'      => 'edit_others_crazylinks_pages',
			'edit_published_posts'   => 'edit_published_crazylinks_pages',
			'edit_private_posts'     => 'edit_private_crazylinks_pages',
			'publish_posts'          => 'publish_crazylinks_pages',
			'read_private_posts'     => 'read_private_crazylinks_pages',
			'delete_posts'           => 'delete_crazylinks_pages',
			'delete_others_posts'    => 'delete_others_crazylinks_pages',
			'delete_published_posts' => 'delete_published_crazylinks_pages',
			'delete_private_posts'   => 'delete_private_crazylinks_pages',
			'create_posts'           => 'edit_crazylinks_pages',
		];
	}

	/**
	 * Every custom cap.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_values( array_unique( array_merge( array_values( self::post_type_caps() ), [ 'view_crazylinks_analytics', 'manage_crazylinks', 'use_crazylinks_ai' ] ) ) );
	}

	/**
	 * Default role → caps map. Administrator and Editor only; Authors can be added via the filter.
	 *
	 * @return array<string, string[]>
	 */
	public static function role_map(): array {
		$editor = array_values( array_diff( self::all(), [ 'manage_crazylinks' ] ) );
		$map    = [
			'administrator' => self::all(),
			'editor'        => $editor,
		];
		/**
		 * Filters the role → capability map applied on activation.
		 *
		 * @param array<string, string[]> $map Role slug → capabilities.
		 */
		return (array) apply_filters( 'crazylinks_role_caps', $map );
	}

	/**
	 * Grants caps to roles.
	 */
	public static function grant(): void {
		foreach ( self::role_map() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( (array) $caps as $cap ) {
				$role->add_cap( (string) $cap );
			}
		}
	}
}
