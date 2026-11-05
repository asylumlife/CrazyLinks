<?php
/**
 * An operation that cannot be applied.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by OpsApplier::step() with a JSON path relative to the operation.
 */
final class OpsException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable reason.
	 * @param string $field   Path inside the operation (e.g. `/id`).
	 */
	public function __construct( string $message, private string $field = '' ) {
		parent::__construct( $message );
	}

	/**
	 * Path inside the operation.
	 */
	public function field(): string {
		return $this->field;
	}
}
