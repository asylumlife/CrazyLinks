<?php
/**
 * Result of validating AI operations.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Either the resulting (validated, sanitized) document or a list of errors.
 */
final class OpsResult {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null                      $document Result document when valid.
	 * @param array<int, array{path:string, message:string}> $errors   Errors.
	 * @param string[]                                       $added    Ids given to added blocks, in order.
	 */
	public function __construct( private ?array $document, private array $errors, private array $added ) {}

	/**
	 * Whether the operations are valid.
	 */
	public function is_valid(): bool {
		return [] === $this->errors && null !== $this->document;
	}

	/**
	 * The resulting document (null when invalid).
	 *
	 * @return array<string, mixed>|null
	 */
	public function document(): ?array {
		return $this->document;
	}

	/**
	 * Errors.
	 *
	 * @return array<int, array{path:string, message:string}>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Ids given to added blocks, in order (reused on apply so the preview and the save agree).
	 *
	 * @return string[]
	 */
	public function added(): array {
		return $this->added;
	}

	/**
	 * Errors as one line each, for retry prompts and CLI output.
	 */
	public function error_text(): string {
		return implode( "\n", array_map( static fn( $e ) => $e['path'] . ': ' . $e['message'], $this->errors ) );
	}
}
