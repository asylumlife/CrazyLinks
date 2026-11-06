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

	/**
	 * Role for the account an external AI agent connects with (MCP). Editor, which the 0.2.0 docs
	 * suggested, also carries unfiltered_html and every post and page on the site, so a leaked
	 * Application Password for it could put scripts into any post. This role can edit and publish link
	 * pages and use the CrazyLinks tools, nothing else (2026-11-06 review).
	 */
	public const AGENT_ROLE = 'crazylinks_agent';

	/**
	 * Capabilities of the agent role.
	 *
	 * @return array<string, bool>
	 */
	public static function agent_caps(): array {
		$caps = [ 'read', 'edit_crazylinks_pages', 'edit_others_crazylinks_pages', 'edit_published_crazylinks_pages', 'publish_crazylinks_pages', 'use_crazylinks_ai' ];
		return array_fill_keys( $caps, true );
	}

	/**
	 * Creates the agent role, or resets an existing one to exactly agent_caps().
	 */
	public static function ensure_agent_role(): ?\WP_Role {
		$role = get_role( self::AGENT_ROLE );
		if ( ! $role ) {
			return add_role( self::AGENT_ROLE, __( 'CrazyLinks agent', 'crazylinks' ), self::agent_caps() );
		}
		foreach ( array_keys( $role->capabilities ) as $cap ) {
			if ( ! isset( self::agent_caps()[ $cap ] ) ) {
				$role->remove_cap( (string) $cap );
			}
		}
		foreach ( array_keys( self::agent_caps() ) as $cap ) {
			$role->add_cap( $cap );
		}
		return get_role( self::AGENT_ROLE );
	}
}
