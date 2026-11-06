<?php
/**
 * MCP connection facts shared by the settings screen and `wp crazylinks mcp status`.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the MCP Adapter plugin is active, whether the CrazyLinks server is switched on, the endpoint
 * URLs, the enabled abilities, and the connection snippets (placeholders only, never credentials).
 */
final class McpStatus {

	public const DEFAULT_SERVER_ROUTE = 'mcp/mcp-adapter-default-server';

	/**
	 * Whether the MCP Adapter plugin is loaded.
	 */
	public static function adapter_active(): bool {
		return class_exists( McpServer::ADAPTER_CLASS );
	}

	/**
	 * The adapter's version ('' when absent or unknown).
	 */
	public static function adapter_version(): string {
		$constant = McpServer::ADAPTER_CLASS . '::VERSION';
		return self::adapter_active() && defined( $constant ) ? (string) constant( $constant ) : '';
	}

	/**
	 * Whether CrazyLinks should add its own MCP server.
	 */
	public static function server_enabled(): bool {
		/**
		 * Filters whether CrazyLinks adds its own MCP server (`/wp-json/crazylinks/mcp`) when the MCP
		 * Adapter plugin is active. Enabled abilities stay reachable through the adapter's default
		 * server either way.
		 *
		 * @param bool $enabled Setting value (default true).
		 */
		return (bool) apply_filters( 'crazylinks_mcp_server', (bool) Settings::get( 'ai_mcp_server' ) );
	}

	/**
	 * Endpoint of the CrazyLinks server.
	 */
	public static function endpoint(): string {
		return rest_url( McpServer::ROUTE_NAMESPACE . '/' . McpServer::ROUTE );
	}

	/**
	 * Endpoint of the adapter's default server.
	 */
	public static function default_endpoint(): string {
		return rest_url( self::DEFAULT_SERVER_ROUTE );
	}

	/**
	 * Enabled ability names, in Abilities::NAMES order.
	 *
	 * @return string[]
	 */
	public static function enabled_abilities(): array {
		return array_values( array_filter( Abilities::NAMES, [ Abilities::class, 'enabled' ] ) );
	}

	/**
	 * The endpoint clients should use: the CrazyLinks server when it is on, else the default server.
	 */
	public static function preferred_endpoint(): string {
		return self::server_enabled() ? self::endpoint() : self::default_endpoint();
	}

	/**
	 * State code: no_adapter | no_abilities | ready.
	 */
	public static function state(): string {
		if ( ! self::adapter_active() ) {
			return 'no_adapter';
		}
		return self::enabled_abilities() ? 'ready' : 'no_abilities';
	}

	/**
	 * One line for a state code.
	 *
	 * @param string $state State code.
	 */
	public static function message( string $state ): string {
		switch ( $state ) {
			case 'no_adapter':
				return __( 'The MCP Adapter plugin is not active. Install it from Plugins → Add New (search for "MCP Adapter" by WordPress.org) and activate it.', 'crazylinks' );
			case 'no_abilities':
				return __( 'The MCP Adapter is active, but no CrazyLinks ability is enabled yet.', 'crazylinks' );
		}
		return __( 'Ready: AI assistants can connect with a WordPress username and application password.', 'crazylinks' );
	}

	/**
	 * Advice on the account an agent should use.
	 */
	public static function user_advice(): string {
		return __( 'Create a dedicated WordPress user for the assistant, not your own administrator account, and give it the "CrazyLinks agent" role (created by: wp crazylinks mcp agent-role --assign=<login>), which can edit link pages and nothing else on the site. Avoid Editor: it can also change every post and page and add scripts to them. Then create an Application Password for that user under Users → Profile → Application Passwords. The assistant can do only what that user can, and you can revoke the password at any time. In the snippets, WP_API_USERNAME is that user\'s login name (the name typed on the login screen), not the name you gave the Application Password.', 'crazylinks' );
	}

	/**
	 * The WordPress login name to put in the snippets: the current user's, or a placeholder.
	 *
	 * People paste the Application Password's *name* here by mistake (which only yields an unhelpful
	 * 401), so the snippets carry the real login name whenever someone is logged in.
	 */
	public static function login(): string {
		return is_user_logged_in() ? (string) wp_get_current_user()->user_login : 'YOUR-WORDPRESS-USERNAME';
	}

	/**
	 * Claude Desktop configuration (the mcpServers block of claude_desktop_config.json).
	 *
	 * @param string|null $endpoint Endpoint (default: preferred).
	 */
	public static function desktop_config( ?string $endpoint = null ): string {
		return (string) wp_json_encode(
			[
				'mcpServers' => [
					'crazylinks' => [
						'command' => 'npx',
						'args'    => [ '-y', '@automattic/mcp-wordpress-remote@latest' ],
						'env'     => [
							'WP_API_URL'      => $endpoint ?? self::preferred_endpoint(),
							'WP_API_USERNAME' => self::login(),
							'WP_API_PASSWORD' => 'YOUR-APPLICATION-PASSWORD',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Claude Code command.
	 *
	 * @param string|null $endpoint Endpoint (default: preferred).
	 */
	public static function code_command( ?string $endpoint = null ): string {
		return 'claude mcp add --transport http crazylinks ' . ( $endpoint ?? self::preferred_endpoint() ) . ' --header "Authorization: Basic <base64 of ' . self::login() . ':application-password>"';
	}
}
