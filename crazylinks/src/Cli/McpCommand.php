<?php
/**
 * WP-CLI: wp crazylinks mcp.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Cli;

use CrazyLinks\Ai\EditingGuide;
use CrazyLinks\Ai\McpStatus;
use CrazyLinks\Capabilities;
use CrazyLinks\PostType;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Connection status for external AI agents (MCP) and the editing guide they receive.
 */
final class McpCommand {

	/**
	 * Shows whether the MCP Adapter is active, the CrazyLinks server and its endpoints, and the enabled abilities.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crazylinks mcp status
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function status( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		$version   = McpStatus::adapter_version();
		$abilities = McpStatus::enabled_abilities();
		WP_CLI::line( 'MCP Adapter:        ' . ( McpStatus::adapter_active() ? 'active' . ( '' === $version ? '' : ' (' . $version . ')' ) : 'not active' ) );
		WP_CLI::line( 'CrazyLinks server:  ' . ( McpStatus::server_enabled() ? 'on' : 'off' ) );
		WP_CLI::line( 'Endpoint:           ' . McpStatus::endpoint() );
		WP_CLI::line( 'Default server:     ' . ( McpStatus::default_server_off() ? 'off (turned off by CrazyLinks)' : McpStatus::default_endpoint() ) );
		if ( ! McpStatus::default_server_off() ) {
			$others = McpStatus::other_public_abilities();
			$parts  = [];
			foreach ( $others as $prefix => $count ) {
				$parts[] = $prefix . ' (' . $count . ')';
			}
			WP_CLI::line( 'Also exposes:       ' . ( $parts ? implode( ', ', $parts ) : '(nothing besides CrazyLinks)' ) );
		}
		if ( ! McpStatus::reachable() ) {
			WP_CLI::warning( 'No MCP server is reachable: the CrazyLinks server and the default server are both off.' );
		}
		WP_CLI::line( 'Enabled abilities:  ' . ( $abilities ? implode( ', ', $abilities ) : '(none)' ) );
		WP_CLI::line( 'Status:             ' . McpStatus::message( McpStatus::state() ) );
		WP_CLI::line( '' );
		WP_CLI::line( McpStatus::user_advice() );
		WP_CLI::line( '' );
		WP_CLI::line( 'Claude Code:' );
		WP_CLI::line( '  ' . McpStatus::code_command() );
		WP_CLI::line( '' );
		WP_CLI::line( 'Claude Desktop (claude_desktop_config.json):' );
		WP_CLI::line( McpStatus::desktop_config() );
	}

	/**
	 * Prints the editing guide external agents receive from crazylinks/get-editing-guide.
	 *
	 * ## OPTIONS
	 *
	 * [--page=<id>]
	 * : Also print the trimmed content of this link page, as the guide sends it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crazylinks mcp guide
	 *     wp crazylinks mcp guide --page=12
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function guide( $args, $assoc_args ): void {
		unset( $args );
		$post = null;
		if ( ! empty( $assoc_args['page'] ) ) {
			$post = get_post( (int) $assoc_args['page'] );
			if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type ) {
				WP_CLI::error( 'Link page not found.' );
			}
		}
		$guide = EditingGuide::build( $post );
		WP_CLI::line( (string) $guide['guide'] );
		if ( isset( $guide['page'] ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( (string) wp_json_encode( $guide['page'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
	}

	/**
	 * Creates (or resets) the "CrazyLinks agent" role for the account an AI agent connects with: it can
	 * edit and publish link pages and use the CrazyLinks tools, nothing else on the site.
	 *
	 * ## OPTIONS
	 *
	 * [--assign=<login>]
	 * : Also give this user the role (replacing their current roles). (`--user` is taken by WP-CLI itself.)
	 *
	 * ## EXAMPLES
	 *
	 *     wp crazylinks mcp agent-role
	 *     wp crazylinks mcp agent-role --assign=claude-agent
	 *
	 * @subcommand agent-role
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function agent_role( $args, $assoc_args ): void {
		unset( $args );
		$role = Capabilities::ensure_agent_role();
		if ( ! $role ) {
			WP_CLI::error( 'The role could not be created.' );
		}
		WP_CLI::line( 'Role ' . Capabilities::AGENT_ROLE . ': ' . implode( ', ', array_keys( Capabilities::agent_caps() ) ) );
		if ( ! empty( $assoc_args['assign'] ) ) {
			$user = get_user_by( 'login', (string) $assoc_args['assign'] );
			if ( ! $user instanceof \WP_User ) {
				WP_CLI::error( 'User not found.' );
			}
			if ( user_can( $user, 'manage_options' ) ) {
				WP_CLI::error( 'That user is an administrator. Create a separate user for the agent.' );
			}
			$user->set_role( Capabilities::AGENT_ROLE );
			WP_CLI::success( $user->user_login . ' now has only the ' . Capabilities::AGENT_ROLE . ' role.' );
			return;
		}
		WP_CLI::success( 'Done. Give the role to the agent\'s user: wp user set-role <login> ' . Capabilities::AGENT_ROLE );
	}
}
