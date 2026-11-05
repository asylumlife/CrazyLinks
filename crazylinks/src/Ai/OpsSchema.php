<?php
/**
 * The AI operations schema (schemas/ai-ops.v1.json).
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the ops schema, gives the plugin's validator an anyOf-free view of it, and gives providers a
 * copy without the keywords structured-output engines reject (length and number limits, patterns).
 * The plugin enforces those itself, so the provider copy is only a shape hint.
 */
final class OpsSchema {

	public const FILE = 'schemas/ai-ops.v1.json';

	/**
	 * Keywords removed from the provider copy. Anthropic's structured outputs reject numeric and
	 * string constraints; other providers vary. The validator still enforces every one of them.
	 */
	private const PROVIDER_STRIP = [ 'minLength', 'maxLength', 'minimum', 'maximum', 'pattern', 'minItems', 'maxItems', 'uniqueItems', '$id', '$schema', 'title' ];

	/**
	 * Cached schema.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $schema = null;

	/**
	 * The decoded schema file.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		if ( null === self::$schema ) {
			$raw          = (string) file_get_contents( CRAZYLINKS_DIR . self::FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$decoded      = json_decode( $raw, true );
			self::$schema = is_array( $decoded ) ? $decoded : [];
		}
		return self::$schema;
	}

	/**
	 * Block types the AI may add or edit (every page type except `html`).
	 *
	 * @return string[]
	 */
	public static function block_types(): array {
		$types = [];
		foreach ( array_keys( (array) ( self::get()['$defs'] ?? [] ) ) as $key ) {
			if ( str_starts_with( (string) $key, 'new_' ) ) {
				$types[] = substr( (string) $key, 4 );
			}
		}
		return $types;
	}

	/**
	 * A sub-schema by $defs name.
	 *
	 * @param string $name Definition name.
	 * @return array<string, mixed>|null
	 */
	public static function def( string $name ): ?array {
		$defs = (array) ( self::get()['$defs'] ?? [] );
		return isset( $defs[ $name ] ) && is_array( $defs[ $name ] ) ? $defs[ $name ] : null;
	}

	/**
	 * Replaces every `anyOf` node with `{"type":"object"}` so the plugin's SchemaValidator (which does
	 * not implement anyOf) can check the outer shape; OpsValidator dispatches the union members itself.
	 *
	 * @param array<string, mixed> $schema Schema node.
	 * @return array<string, mixed>
	 */
	public static function without_unions( array $schema ): array {
		if ( isset( $schema['anyOf'] ) ) {
			return [ 'type' => 'object' ];
		}
		foreach ( $schema as $key => $value ) {
			if ( is_array( $value ) && '$defs' !== $key ) {
				$schema[ $key ] = self::without_unions( $value );
			}
		}
		return $schema;
	}

	/**
	 * The schema sent to the provider through as_json_response().
	 *
	 * @return array<string, mixed>
	 */
	public static function for_provider(): array {
		$schema = self::strip( self::get() );
		/**
		 * Filters the JSON schema CrazyLinks sends to the AI provider for structured output.
		 * The response is validated against the full schemas/ai-ops.v1.json either way.
		 *
		 * @param array<string, mixed> $schema Provider schema.
		 */
		return (array) apply_filters( 'crazylinks_ai_ops_schema', $schema );
	}

	/**
	 * Recursively removes PROVIDER_STRIP keywords (never inside `properties`, whose keys are names).
	 *
	 * @param array<mixed> $node Node.
	 * @param bool         $names Whether this node maps property names to schemas.
	 * @return array<mixed>
	 */
	private static function strip( array $node, bool $names = false ): array {
		foreach ( $node as $key => $value ) {
			if ( ! $names && in_array( $key, self::PROVIDER_STRIP, true ) ) {
				unset( $node[ $key ] );
				continue;
			}
			if ( is_array( $value ) ) {
				$node[ $key ] = self::strip( $value, ! $names && in_array( $key, [ 'properties', '$defs' ], true ) );
			}
		}
		return $node;
	}
}
