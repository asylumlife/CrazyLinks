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
		$schema = self::close_objects( self::strip( self::compact( self::get() ) ) );
		/**
		 * Filters the JSON schema CrazyLinks sends to the AI provider for structured output.
		 * The response is validated against the full schemas/ai-ops.v1.json either way.
		 *
		 * @param array<string, mixed> $schema Provider schema.
		 */
		return (array) apply_filters( 'crazylinks_ai_ops_schema', $schema );
	}

	/**
	 * A compact envelope for constrained decoding. The full schema spells out every block type's props
	 * twice (new and patch) in ~70 definitions, which Anthropic rejects as "compiled grammar is too
	 * large" (first live call, AsylumLife.com, 2026-11-05). The provider gets the operation envelope with
	 * typed ids, schedules, design and profile, but block props as a free object; OpsValidator then
	 * checks the response against the full schema and the driver retries once with the errors.
	 *
	 * @param array<string, mixed> $schema Full schema.
	 * @return array<string, mixed>
	 */
	public static function compact( array $schema ): array {
		$defs                                        = $schema['$defs'];
		$types                                       = self::block_types();
		$first                                       = $defs[ 'new_' . $types[0] ];
		$block                                       = $first;
		$block['properties']['type']                 = [
			'type' => 'string',
			'enum' => $types,
		];
		$block['properties']['props']                = self::props_as_string( 'Props for this block type, exactly as listed in the instructions, as a JSON object encoded in a string. Example: {"title":"Merch","url":"https://example.com/shop"}' );
		$block['description']                        = 'A new block. The props object must contain the fields listed for its type.';
		$defs['op_add_block']['properties']['block'] = $block;
		$defs['op_update_block']['properties']['props'] = self::props_as_string( 'Props to merge into the block, using the field names listed for its type, as a JSON object encoded in a string.' );
		foreach ( array_keys( $defs ) as $name ) {
			if ( preg_match( '/^(new|patch|props)_/', $name ) ) {
				unset( $defs[ $name ] );
			}
		}
		$schema['$defs'] = $defs;
		return $schema;
	}

	/**
	 * Anthropic's structured outputs allow no free-form objects (every object needs additionalProperties
	 * false), so block props travel as a JSON-encoded string and are decoded by inflate_props().
	 *
	 * @param string $description Field description.
	 * @return array<string, string>
	 */
	private static function props_as_string( string $description ): array {
		return [
			'type'        => 'string',
			'description' => $description,
		];
	}

	/**
	 * Decodes props that the provider returned as JSON strings (see props_as_string()). Props that are
	 * already objects, and strings that are not valid JSON objects, are left as they are so the validator
	 * reports them.
	 *
	 * @param mixed $payload Decoded model output.
	 * @return mixed
	 */
	public static function inflate_props( $payload ) {
		if ( ! is_array( $payload ) || ! isset( $payload['operations'] ) || ! is_array( $payload['operations'] ) ) {
			return $payload;
		}
		foreach ( $payload['operations'] as &$op ) {
			if ( ! is_array( $op ) ) {
				continue;
			}
			if ( isset( $op['props'] ) && is_string( $op['props'] ) ) {
				$decoded = json_decode( $op['props'], true );
				if ( is_array( $decoded ) ) {
					$op['props'] = $decoded;
				}
			}
			if ( isset( $op['block']['props'] ) && is_string( $op['block']['props'] ) ) {
				$decoded = json_decode( $op['block']['props'], true );
				if ( is_array( $decoded ) ) {
					$op['block']['props'] = $decoded;
				}
			}
		}
		unset( $op );
		return $payload;
	}

	/**
	 * Sets additionalProperties false on every object node that does not declare it (strict providers
	 * reject open objects).
	 *
	 * @param array<mixed> $node Node.
	 * @return array<mixed>
	 */
	public static function close_objects( array $node ): array {
		if ( ( $node['type'] ?? '' ) === 'object' && ! array_key_exists( 'additionalProperties', $node ) ) {
			$node['additionalProperties'] = false;
		}
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) && 'enum' !== $key && 'required' !== $key ) {
				$node[ $key ] = self::close_objects( $value );
			}
		}
		return $node;
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
