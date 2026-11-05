<?php
/**
 * Validation result value object.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Either a normalized document or a list of errors.
 */
final class ValidationResult {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null                      $document Normalized document when valid.
	 * @param array<int, array{path:string, message:string}> $errors   Errors.
	 * @param array<int, array<string, string>>              $warnings Non-blocking warnings (valid documents only).
	 */
	public function __construct( private ?array $document, private array $errors, private array $warnings = [] ) {}

	/**
	 * Whether the input was valid.
	 */
	public function is_valid(): bool {
		return [] === $this->errors && null !== $this->document;
	}

	/**
	 * Normalized document (empty document when invalid).
	 *
	 * @return array<string, mixed>
	 */
	public function document(): array {
		return $this->document ?? PageValidator::empty_document();
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
	 * Warnings: problems that do not block saving (missing alt text). Each has `path`, `message`,
	 * `code` and, for block problems, `block_id`.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Errors as a WP_Error for REST responses.
	 */
	public function to_wp_error(): \WP_Error {
		return new \WP_Error(
			'crazylinks_invalid_page',
			__( 'The page document is invalid.', 'crazylinks' ),
			[
				'status' => 400,
				'errors' => $this->errors,
			]
		);
	}
}
