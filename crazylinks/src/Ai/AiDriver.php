<?php
/**
 * AI driver contract (SPEC §10.2). Phase 1 ships the interface only.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Method names fixed by SPEC §10.2.

/**
 * A provider-agnostic AI backend.
 */
interface AiDriver {

	/**
	 * Provider configured and model supports text generation.
	 */
	public function isAvailable(): bool;

	/**
	 * Returns proposed operations (ops JSON) for an instruction.
	 *
	 * @param ProposalRequest $r Request.
	 */
	public function propose( ProposalRequest $r ): ProposalResult|WP_Error;

	/**
	 * Suggests text (titles, bios).
	 *
	 * @param string               $kind Kind of suggestion.
	 * @param array<string, mixed> $ctx  Context.
	 * @return array<int, string>|WP_Error
	 */
	public function suggestText( string $kind, array $ctx ): array|WP_Error;
}
