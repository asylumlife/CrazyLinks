<?php
/**
 * Minimal WP-CLI stub for static analysis (php-stubs/wp-cli-stubs does not yet accept wordpress-stubs 7.x).
 *
 * @package CrazyLinks
 */

// phpcs:ignoreFile

class WP_CLI {
	/** @param class-string|callable|object $callable */
	public static function add_command( string $name, $callable, array $args = [] ): bool { return true; }
	public static function line( string $message = '' ): void {}
	public static function success( string $message ): void {}
	public static function warning( string $message ): void {}
	/** @param string|\WP_Error|\Throwable $message */
	public static function error( $message, bool $exit = true ): never { exit( 1 ); }
	/** @return mixed */
	public static function runcommand( string $command, array $options = [] ) { return ''; }
	public static function halt( int $return_code ): never { exit( $return_code ); }
}
