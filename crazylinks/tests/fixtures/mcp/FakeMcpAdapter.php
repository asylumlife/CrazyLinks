<?php
/**
 * A stand-in for the MCP Adapter plugin's McpAdapter, declared only when the real plugin is absent.
 *
 * It records create_server() calls so tests can check what CrazyLinks registers. Loading this file
 * makes class_exists( 'WP\MCP\Core\McpAdapter' ) true for the rest of the test run.
 *
 * @package CrazyLinks
 */

namespace WP\MCP\Core;

if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
	/**
	 * Recorder.
	 */
	final class McpAdapter {

		public const VERSION = '0.0.0-test';

		/**
		 * Recorded create_server() calls (named arguments).
		 *
		 * @var array<int, array<string, mixed>>
		 */
		public static array $calls = [];

		/**
		 * What create_server() returns (null: $this).
		 *
		 * @var mixed
		 */
		public static $result = null;

		/**
		 * Throw from create_server().
		 *
		 * @var bool
		 */
		public static bool $throw = false;

		/**
		 * Resets the recorder.
		 */
		public static function reset(): void {
			self::$calls  = [];
			self::$result = null;
			self::$throw  = false;
		}

		/**
		 * Same signature as MCP Adapter 0.7.1.
		 *
		 * @return mixed
		 */
		public function create_server( string $server_id, string $server_route_namespace, string $server_route, string $server_name, string $server_description, string $server_version, array $mcp_transports, ?string $error_handler, ?string $observability_handler = null, array $tools = [], array $resources = [], array $prompts = [], ?callable $transport_permission_callback = null ) {
			self::$calls[] = compact( 'server_id', 'server_route_namespace', 'server_route', 'server_name', 'server_description', 'server_version', 'mcp_transports', 'error_handler', 'observability_handler', 'tools', 'resources', 'prompts', 'transport_permission_callback' );
			if ( self::$throw ) {
				throw new \RuntimeException( 'Adapter failure' );
			}
			return self::$result ?? $this;
		}
	}
}
