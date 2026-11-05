<?php
/**
 * Input for AiDriver::propose().
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * What to change and the data the model may see.
 */
final class ProposalRequest {

	/**
	 * Constructor.
	 *
	 * @param string                                            $instruction What the user asked for.
	 * @param array<string, mixed>                              $document    Base document (what the operations apply to).
	 * @param int                                               $user_id     Acting user (capability gates in validation).
	 * @param string                                            $mode        edit | build_from_site | build_from_description | restyle | analytics_qa.
	 * @param int                                               $page_id     Page id (0 for none).
	 * @param array<int, array{question:string, answer:string}> $answers   Answers to earlier questions.
	 * @param array<string, mixed>|null                         $site_facts  Public site facts (build_from_site).
	 * @param array<string, mixed>|null                         $analytics   Aggregate clicks (analytics_qa).
	 * @param array<string, mixed>|null                         $previous    Stored document for capability gates (defaults to $document).
	 */
	public function __construct(
		public string $instruction,
		public array $document,
		public int $user_id,
		public string $mode = 'edit',
		public int $page_id = 0,
		public array $answers = [],
		public ?array $site_facts = null,
		public ?array $analytics = null,
		public ?array $previous = null
	) {}
}
