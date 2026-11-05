<?php
/**
 * Minimal JSON Schema 2020-12 validator.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Validates decoded JSON against the subset of JSON Schema the plugin's schemas use.
 *
 * Supported keywords: type, const, enum, properties, required, additionalProperties,
 * items, minItems, maxItems, uniqueItems, minLength, maxLength, pattern, minimum,
 * maximum, format (date-time), $ref (local #/$defs/...), allOf, if/then/else.
 * Annotation keywords ($schema, $id, title, description) are ignored. Anything else
 * is reported as an unsupported keyword so a schema change cannot silently weaken validation.
 */
final class SchemaValidator {

	public const SUPPORTED = [ 'type', 'const', 'enum', 'properties', 'required', 'additionalProperties', 'items', 'minItems', 'maxItems', 'uniqueItems', 'minLength', 'maxLength', 'pattern', 'minimum', 'maximum', 'format', '$ref', 'allOf', 'if', 'then', 'else', '$schema', '$id', '$defs', 'title', 'description' ];

	/**
	 * Root schema (for $ref resolution).
	 *
	 * @var array<string, mixed>
	 */
	private array $root;

	/**
	 * Collected errors.
	 *
	 * @var array<int, array{path:string, message:string}>
	 */
	private array $errors = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $root Root schema.
	 */
	public function __construct( array $root ) {
		$this->root = $root;
	}

	/**
	 * Validates a value.
	 *
	 * @param mixed $value Decoded JSON (associative arrays for objects).
	 * @return array<int, array{path:string, message:string}> Errors (empty when valid).
	 */
	public function validate( $value ): array {
		$this->errors = [];
		$this->check( $value, $this->root, '' );
		return $this->errors;
	}

	/**
	 * Recursive check.
	 *
	 * @param mixed                $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   JSON pointer.
	 */
	private function check( $value, array $schema, string $path ): bool {
		$before = count( $this->errors );

		foreach ( array_keys( $schema ) as $keyword ) {
			if ( ! in_array( $keyword, self::SUPPORTED, true ) ) {
				$this->error( $path, sprintf( 'Unsupported schema keyword "%s".', $keyword ) );
			}
		}

		if ( isset( $schema['$ref'] ) ) {
			$this->check( $value, $this->resolve( (string) $schema['$ref'] ), $path );
		}

		if ( isset( $schema['type'] ) && ! $this->type_matches( $value, (array) $schema['type'] ) ) {
			$this->error( $path, sprintf( 'Expected %s.', implode( ' or ', (array) $schema['type'] ) ) );
			return false;
		}

		if ( array_key_exists( 'const', $schema ) && $value !== $schema['const'] ) {
			$this->error( $path, sprintf( 'Must be %s.', wp_json_encode( $schema['const'] ) ) );
		}
		if ( isset( $schema['enum'] ) && ! in_array( $value, (array) $schema['enum'], true ) ) {
			$this->error( $path, sprintf( 'Must be one of: %s.', implode( ', ', array_map( 'strval', (array) $schema['enum'] ) ) ) );
		}

		if ( is_string( $value ) ) {
			$this->check_string( $value, $schema, $path );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
				$this->error( $path, sprintf( 'Must be at least %s.', $schema['minimum'] ) );
			}
			if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
				$this->error( $path, sprintf( 'Must be at most %s.', $schema['maximum'] ) );
			}
		}
		if ( is_array( $value ) && array_is_list( $value ) && $this->is_array_schema( $schema ) ) {
			$this->check_array( $value, $schema, $path );
		} elseif ( is_array( $value ) ) {
			$this->check_object( $value, $schema, $path );
		}

		foreach ( (array) ( $schema['allOf'] ?? [] ) as $sub ) {
			$this->check( $value, (array) $sub, $path );
		}
		if ( isset( $schema['if'] ) ) {
			$probe = new self( $this->root );
			$ok    = [] === $probe->validate_with( $value, (array) $schema['if'] );
			if ( $ok && isset( $schema['then'] ) ) {
				$this->check( $value, (array) $schema['then'], $path );
			} elseif ( ! $ok && isset( $schema['else'] ) ) {
				$this->check( $value, (array) $schema['else'], $path );
			}
		}

		return count( $this->errors ) === $before;
	}

	/**
	 * Validates against a sub-schema while resolving refs against this root.
	 *
	 * @param mixed                $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @return array<int, array{path:string, message:string}>
	 */
	public function validate_with( $value, array $schema ): array {
		$this->errors = [];
		$this->check( $value, $schema, '' );
		return $this->errors;
	}

	/**
	 * String keywords.
	 *
	 * @param string               $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Path.
	 */
	private function check_string( string $value, array $schema, string $path ): void {
		$len = mb_strlen( $value, 'UTF-8' );
		if ( isset( $schema['minLength'] ) && $len < $schema['minLength'] ) {
			$this->error( $path, 0 === $len ? 'Must not be empty.' : sprintf( 'Must be at least %d characters.', $schema['minLength'] ) );
		}
		if ( isset( $schema['maxLength'] ) && $len > $schema['maxLength'] ) {
			$this->error( $path, sprintf( 'Must be at most %d characters.', $schema['maxLength'] ) );
		}
		if ( isset( $schema['pattern'] ) && ! preg_match( '/' . str_replace( '/', '\/', (string) $schema['pattern'] ) . '/u', $value ) ) {
			$this->error( $path, 'Has an invalid format.' );
		}
		if ( 'date-time' === ( $schema['format'] ?? '' ) && ! self::is_date_time( $value ) ) {
			$this->error( $path, 'Must be an ISO 8601 date-time with timezone, e.g. 2026-11-05T09:00:00Z.' );
		}
	}

	/**
	 * Array keywords.
	 *
	 * @param array<int, mixed>    $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Path.
	 */
	private function check_array( array $value, array $schema, string $path ): void {
		$count = count( $value );
		if ( isset( $schema['minItems'] ) && $count < $schema['minItems'] ) {
			$this->error( $path, sprintf( 'Must contain at least %d items.', $schema['minItems'] ) );
		}
		if ( isset( $schema['maxItems'] ) && $count > $schema['maxItems'] ) {
			$this->error( $path, sprintf( 'Must contain at most %d items.', $schema['maxItems'] ) );
		}
		if ( ! empty( $schema['uniqueItems'] ) && count( array_unique( array_map( 'serialize', $value ) ) ) !== $count ) {
			$this->error( $path, 'Items must be unique.' );
		}
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			foreach ( $value as $i => $item ) {
				$this->check( $item, $schema['items'], $path . '/' . $i );
			}
		}
	}

	/**
	 * Object keywords.
	 *
	 * @param array<mixed>         $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Path.
	 */
	private function check_object( array $value, array $schema, string $path ): void {
		$props = (array) ( $schema['properties'] ?? [] );
		foreach ( (array) ( $schema['required'] ?? [] ) as $req ) {
			if ( ! array_key_exists( $req, $value ) ) {
				$this->error( $path . '/' . $req, 'Is required.' );
			}
		}
		foreach ( $value as $key => $item ) {
			$key = (string) $key;
			if ( isset( $props[ $key ] ) ) {
				$this->check( $item, (array) $props[ $key ], $path . '/' . $key );
			} elseif ( array_key_exists( 'additionalProperties', $schema ) ) {
				if ( false === $schema['additionalProperties'] ) {
					$this->error( $path . '/' . $key, 'Unknown property.' );
				} elseif ( is_array( $schema['additionalProperties'] ) ) {
					$this->check( $item, $schema['additionalProperties'], $path . '/' . $key );
				}
			}
		}
	}

	/**
	 * Whether the schema describes an array (so `[]` is not treated as an empty object).
	 *
	 * @param array<string, mixed> $schema Schema.
	 */
	private function is_array_schema( array $schema ): bool {
		if ( isset( $schema['$ref'] ) ) {
			return $this->is_array_schema( $this->resolve( (string) $schema['$ref'] ) );
		}
		return in_array( 'array', (array) ( $schema['type'] ?? [] ), true ) || isset( $schema['items'] );
	}

	/**
	 * Type check. PHP decodes both `{}` and `[]` as arrays; an empty array is accepted as an object.
	 *
	 * @param mixed    $value Value.
	 * @param string[] $types Allowed types.
	 */
	private function type_matches( $value, array $types ): bool {
		foreach ( $types as $type ) {
			$ok = match ( $type ) {
				'string'  => is_string( $value ),
				'integer' => is_int( $value ),
				'number'  => is_int( $value ) || is_float( $value ),
				'boolean' => is_bool( $value ),
				'null'    => null === $value,
				'array'   => is_array( $value ) && array_is_list( $value ),
				'object'  => is_array( $value ) && ( [] === $value || ! array_is_list( $value ) ),
				default   => false,
			};
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolves a local $ref.
	 *
	 * @param string $ref Reference.
	 * @return array<string, mixed>
	 */
	private function resolve( string $ref ): array {
		if ( ! str_starts_with( $ref, '#/' ) ) {
			$this->error( '', sprintf( 'Unsupported $ref "%s".', $ref ) );
			return [];
		}
		$node = $this->root;
		foreach ( explode( '/', substr( $ref, 2 ) ) as $part ) {
			if ( ! is_array( $node ) || ! isset( $node[ $part ] ) ) {
				$this->error( '', sprintf( 'Unresolvable $ref "%s".', $ref ) );
				return [];
			}
			$node = $node[ $part ];
		}
		return (array) $node;
	}

	/**
	 * RFC 3339 date-time.
	 *
	 * @param string $value Value.
	 */
	public static function is_date_time( string $value ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value ) ) {
			return false;
		}
		return false !== strtotime( $value );
	}

	/**
	 * Adds an error.
	 *
	 * @param string $path    Path.
	 * @param string $message Message.
	 */
	private function error( string $path, string $message ): void {
		$this->errors[] = [
			'path'    => '' === $path ? '/' : $path,
			'message' => $message,
		];
	}
}
