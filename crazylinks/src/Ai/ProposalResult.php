<?php
/**
 * Output of AiDriver::propose().
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * A validated proposal: operations plus the document they produce (not saved).
 */
final class ProposalResult {

	/**
	 * Constructor.
	 *
	 * @param string                           $summary           Model summary (or analytics answer).
	 * @param array<int, array<string, mixed>> $operations        Validated operations.
	 * @param string[]                         $questions         Clarifying questions.
	 * @param array<string, mixed>             $document          Resulting document (validated, not saved).
	 * @param string[]                         $added             Ids given to added blocks, in order.
	 * @param string                           $provider          Provider name.
	 * @param string                           $model             Model id.
	 * @param int                              $prompt_tokens     Input tokens (reported or estimated).
	 * @param int                              $completion_tokens Output tokens (reported or estimated).
	 * @param bool                             $estimated         Whether token counts are estimates.
	 * @param int                              $attempts          1, or 2 after a retry.
	 */
	public function __construct(
		public string $summary,
		public array $operations,
		public array $questions,
		public array $document,
		public array $added = [],
		public string $provider = '',
		public string $model = '',
		public int $prompt_tokens = 0,
		public int $completion_tokens = 0,
		public bool $estimated = false,
		public int $attempts = 1
	) {}

	/**
	 * Total tokens.
	 */
	public function tokens(): int {
		return $this->prompt_tokens + $this->completion_tokens;
	}
}
