<?php
/**
 * Propose / apply / suggest: the one code path behind the REST routes and the abilities.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\PageStore;
use CrazyLinks\Schema\PageValidator;
use CrazyLinks\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Proposals are short-lived (30 minutes), stored as a transient tied to the user and the page, and
 * logged to crazylinks_ai_log with applied = 0. Apply re-validates the operations against the
 * document they will really change: when that document is not the one the proposal was made for
 * (someone saved in between, or the editor's working copy changed) the operations are validated again
 * on the current document and rejected with 409 if they no longer fit. Saving goes through
 * PATCH /crazylinks/v1/pages/{id}, the same path as a human save, so it runs the same permission
 * checks and PageValidator and core creates the revision.
 */
final class Proposals {

	public const TTL = 30 * MINUTE_IN_SECONDS;

	public const TRANSIENT = 'crazylinks_aip_';

	public const CONFIRM_META = '_crazylinks_ai_confirm';

	public const REVISION_META = '_crazylinks_ai_source';

	public const MAX_INSTRUCTION = 2000;

	/**
	 * Post meta listing the ids of proposals from external agents that wait for confirmation.
	 */
	public const PENDING_META = '_crazylinks_ai_pending';

	/**
	 * Pending proposals live longer than interactive ones: a person has to open the editor.
	 */
	public const PENDING_TTL = 7 * DAY_IN_SECONDS;

	public const MAX_PENDING = 10;

	/**
	 * The AI driver (filterable so sites and tests can swap it).
	 */
	public static function driver(): AiDriver {
		/**
		 * Filters the AI driver CrazyLinks uses (default: WordPress's AI client).
		 *
		 * @param AiDriver $driver Driver.
		 */
		$driver = apply_filters( 'crazylinks_ai_driver', new WpAiClientDriver() );
		return $driver instanceof AiDriver ? $driver : new WpAiClientDriver();
	}

	/**
	 * Creates a proposal.
	 *
	 * @param \WP_Post             $post   Page.
	 * @param array<string, mixed> $args   instruction, mode, questions_answers, page (working copy), operations (agent-written).
	 * @param string               $source admin | mcp.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function propose( \WP_Post $post, array $args, string $source = 'admin' ) {
		$user_id     = get_current_user_id();
		$mode        = (string) ( $args['mode'] ?? 'edit' );
		$instruction = trim( (string) ( $args['instruction'] ?? '' ) );
		$agent_ops   = isset( $args['operations'] ) && is_array( $args['operations'] ) ? $args['operations'] : null;
		if ( ! in_array( $mode, OpsValidator::MODES, true ) ) {
			return new WP_Error( 'crazylinks_ai_bad_mode', __( 'Unknown AI mode.', 'crazylinks' ), [ 'status' => 400 ] );
		}
		if ( null === $agent_ops && '' === $instruction ) {
			return new WP_Error( 'crazylinks_ai_empty', __( 'Describe the change you want.', 'crazylinks' ), [ 'status' => 400 ] );
		}
		if ( mb_strlen( $instruction ) > self::MAX_INSTRUCTION ) {
			/* translators: %d: maximum characters. */
			return new WP_Error( 'crazylinks_ai_too_long', sprintf( __( 'Please keep the request under %d characters.', 'crazylinks' ), self::MAX_INSTRUCTION ), [ 'status' => 400 ] );
		}
		$stored = PageStore::get_document( $post, false );
		$base   = self::base_document( $args['page'] ?? null, $user_id, $stored );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		if ( null !== $agent_ops ) {
			$result = ( new OpsValidator() )->validate(
				[
					'summary'    => (string) ( $args['summary'] ?? '' ),
					'operations' => $agent_ops,
					'questions'  => [],
				],
				$base,
				$user_id,
				$mode,
				$stored
			);
			if ( ! $result->is_valid() ) {
				return self::invalid( $result->errors() );
			}
			$proposal = new ProposalResult( (string) ( $args['summary'] ?? '' ), array_values( $agent_ops ), [], (array) $result->document(), $result->added(), '', 'external' );
		} else {
			$ready = self::check_ready( $user_id );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			if ( 'analytics_qa' === $mode && ! current_user_can( 'view_crazylinks_analytics' ) ) {
				return new WP_Error( 'crazylinks_forbidden', __( 'You are not allowed to view analytics.', 'crazylinks' ), [ 'status' => rest_authorization_required_code() ] );
			}
			$answers = [];
			foreach ( array_slice( (array) ( $args['questions_answers'] ?? [] ), 0, 5 ) as $qa ) {
				if ( is_array( $qa ) ) {
					$answers[] = [
						'question' => mb_substr( sanitize_textarea_field( (string) ( $qa['question'] ?? '' ) ), 0, 300 ),
						'answer'   => mb_substr( sanitize_textarea_field( (string) ( $qa['answer'] ?? '' ) ), 0, 1000 ),
					];
				}
			}
			$request  = new ProposalRequest(
				$instruction,
				$base,
				$user_id,
				$mode,
				(int) $post->ID,
				$answers,
				'build_from_site' === $mode ? Context::site_facts( $user_id ) : null,
				'analytics_qa' === $mode ? Context::analytics( (int) $post->ID ) : null,
				$stored
			);
			$driver   = self::driver();
			$proposal = $driver->propose( $request );
			if ( is_wp_error( $proposal ) ) {
				self::record_failure( $driver, $post, $user_id, $mode, $instruction, $source );
				return $proposal;
			}
			Limits::record( $proposal->tokens() );
		}

		$log_id = AiLog::insert(
			[
				'page_id'           => $post->ID,
				'user_id'           => $user_id,
				'kind'              => null === $agent_ops ? 'propose' : 'external',
				'mode'              => $mode,
				'source'            => $source,
				'instruction'       => $instruction,
				'summary'           => $proposal->summary,
				'operations'        => $proposal->operations,
				'provider'          => $proposal->provider,
				'model'             => $proposal->model,
				'prompt_tokens'     => $proposal->prompt_tokens,
				'completion_tokens' => $proposal->completion_tokens,
				'tokens_estimated'  => $proposal->estimated,
			]
		);

		$id = null;
		if ( $proposal->operations ) {
			$id = wp_generate_password( 24, false );
			set_transient(
				self::TRANSIENT . $id,
				[
					'user_id'    => $user_id,
					'page_id'    => (int) $post->ID,
					'mode'       => $mode,
					'source'     => $source,
					'summary'    => $proposal->summary,
					'operations' => $proposal->operations,
					'added'      => $proposal->added,
					'base_hash'  => self::hash( $base ),
					'modified'   => (string) $post->post_modified_gmt,
					'log_id'     => $log_id,
					'expires'    => time() + self::TTL,
					'applied'    => null,
				],
				self::TTL
			);
		}

		return [
			'proposal_id'      => $id,
			'summary'          => $proposal->summary,
			'operations'       => $proposal->operations,
			'questions'        => $proposal->questions,
			'preview_document' => PageValidator::to_json_shape( $proposal->document ),
			'change_list'      => ChangeList::build( $proposal->operations, $base, $proposal->added ),
			'mode'             => $mode,
			'expires_at'       => null === $id ? null : gmdate( 'c', time() + self::TTL ),
			'model'            => $proposal->model,
			'usage'            => [
				'tokens'    => $proposal->tokens(),
				'estimated' => $proposal->estimated,
				'attempts'  => $proposal->attempts,
			],
			'budget'           => Limits::budget_state(),
		];
	}

	/**
	 * Applies a proposal (optionally with edited operations) and saves the page.
	 *
	 * @param \WP_Post                              $post        Page.
	 * @param string                                $proposal_id Proposal id.
	 * @param array<int, array<string, mixed>>|null $operations  Edited operations (default: as proposed).
	 * @param array<string, mixed>|null             $page        Editor working copy the operations apply to (default: the saved page).
	 * @param string                                $source      admin | mcp.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply( \WP_Post $post, string $proposal_id, ?array $operations = null, ?array $page = null, string $source = 'admin' ) {
		$user_id  = get_current_user_id();
		$proposal = self::load( $proposal_id );
		// A change from an external agent waiting for confirmation (SPEC §10.7) belongs to the page, not
		// to the agent's account: anyone who may edit the page can confirm it in the editor.
		$pending = null !== $proposal && ! empty( $proposal['pending'] );
		$owner   = null !== $proposal && ( (int) $proposal['user_id'] === $user_id || ( $pending && 'admin' === $source ) );
		if ( null === $proposal || ! $owner || (int) $proposal['page_id'] !== (int) $post->ID ) {
			return new WP_Error( 'crazylinks_ai_proposal_expired', __( 'This proposal has expired or does not belong to this page. Ask again to get a fresh one.', 'crazylinks' ), [ 'status' => 410 ] );
		}
		if ( is_array( $proposal['applied'] ) ) {
			return $proposal['applied'] + [ 'already_applied' => true ];
		}
		if ( 'mcp' === $source && ( $pending || self::requires_confirmation( (int) $post->ID ) ) ) {
			return new WP_Error( 'crazylinks_ai_confirmation_required', __( 'This page requires changes from external AI agents to be confirmed in the CrazyLinks editor.', 'crazylinks' ), [ 'status' => 409 ] );
		}

		$ops    = null === $operations ? (array) $proposal['operations'] : array_values( $operations );
		$edited = null !== $operations && $ops !== $proposal['operations'];
		$stored = PageStore::get_document( $post, false );
		$base   = self::base_document( $page, $user_id, $stored );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$stale = self::hash( $base ) !== $proposal['base_hash'];

		$validator = new OpsValidator( new OpsApplier( OpsApplier::sequence( (array) $proposal['added'] ) ) );
		$result    = $validator->validate(
			[
				'summary'    => (string) $proposal['summary'],
				'operations' => $ops,
				'questions'  => [],
			],
			$base,
			$user_id,
			(string) $proposal['mode'],
			$stored
		);
		if ( ! $result->is_valid() ) {
			if ( $stale ) {
				return new WP_Error(
					'crazylinks_ai_conflict',
					__( 'The page changed since this proposal was made and the changes no longer fit. Ask again.', 'crazylinks' ),
					[
						'status' => 409,
						'errors' => $result->errors(),
					]
				);
			}
			return self::invalid( $result->errors() );
		}

		// Written by an external agent: badged and logged as such even when a person confirmed it.
		$badge = 'mcp' === $source || $pending ? 'mcp' : 'admin';
		$saved = self::save_document( $post, (array) $result->document(), $badge );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$revision_id = $saved['revision_id'];
		AiLog::mark_applied( (int) $proposal['log_id'], $revision_id, $badge, $ops );

		$out                 = [
			'applied'     => true,
			'revision_id' => $revision_id,
			'source'      => $badge,
			'rebased'     => $stale,
			'edited'      => $edited,
			'change_list' => ChangeList::build( $ops, $base, $result->added() ),
			'page'        => $saved['page'],
		];
		$proposal['applied'] = $out;
		set_transient( self::TRANSIENT . $proposal_id, $proposal, max( 60, (int) $proposal['expires'] - time() ) );
		if ( $pending ) {
			self::forget_pending( (int) $post->ID, $proposal_id );
		}

		/**
		 * Fires after an AI proposal was applied to a link page.
		 *
		 * @param int                              $page_id     Page id.
		 * @param int|null                         $revision_id Revision created by the save.
		 * @param string                           $source      admin | mcp.
		 * @param array<int, array<string, mixed>> $operations  Applied operations.
		 */
		do_action( 'crazylinks_ai_applied', (int) $post->ID, $revision_id, $out['source'], $ops );
		return $out;
	}

	/**
	 * Saves a validated document through PATCH /crazylinks/v1/pages/{id}, the same path as a human save
	 * (permission check, PageValidator, core revision), and badges the revision it created.
	 *
	 * @param \WP_Post             $post   Page.
	 * @param array<string, mixed> $doc    Document (already validated by OpsValidator).
	 * @param string               $source admin | mcp (revision badge).
	 * @return array{revision_id:int|null, page:mixed}|WP_Error
	 */
	public static function save_document( \WP_Post $post, array $doc, string $source ) {
		$before = self::latest_revision( (int) $post->ID );
		$req    = new \WP_REST_Request( 'PATCH', '/crazylinks/v1/pages/' . $post->ID );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( [ 'page' => PageValidator::to_json_shape( $doc ) ] ) );
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			return $res->as_error();
		}
		$after       = self::latest_revision( (int) $post->ID );
		$revision_id = $after > $before ? $after : null;
		if ( null !== $revision_id ) {
			update_metadata( 'post', $revision_id, self::REVISION_META, 'mcp' === $source ? 'mcp' : 'admin' );
		}
		return [
			'revision_id' => $revision_id,
			'page'        => $res->get_data(),
		];
	}

	/**
	 * Stores validated operations from an external agent as a proposal that waits for a person to
	 * confirm it in the editor's AI tab (per-page "require confirmation", SPEC §10.7).
	 *
	 * @param \WP_Post                         $post       Page.
	 * @param array<int, array<string, mixed>> $operations Operations (valid against $base).
	 * @param string                           $summary    Agent's summary.
	 * @param string[]                         $added      Ids the validator gave added blocks.
	 * @param array<string, mixed>             $base       Document the operations were validated on.
	 * @param int                              $log_id     crazylinks_ai_log row.
	 * @return string Proposal id.
	 */
	public static function store_pending( \WP_Post $post, array $operations, string $summary, array $added, array $base, int $log_id ): string {
		$id = wp_generate_password( 24, false );
		set_transient(
			self::TRANSIENT . $id,
			[
				'user_id'    => get_current_user_id(),
				'page_id'    => (int) $post->ID,
				'mode'       => 'edit',
				'source'     => 'mcp',
				'summary'    => $summary,
				'operations' => array_values( $operations ),
				'added'      => $added,
				'base_hash'  => self::hash( $base ),
				'modified'   => (string) $post->post_modified_gmt,
				'log_id'     => $log_id,
				'created'    => time(),
				'expires'    => time() + self::PENDING_TTL,
				'applied'    => null,
				'pending'    => true,
			],
			self::PENDING_TTL
		);
		$ids   = self::pending_ids( (int) $post->ID );
		$ids[] = $id;
		foreach ( array_slice( $ids, 0, max( 0, count( $ids ) - self::MAX_PENDING ) ) as $old ) {
			delete_transient( self::TRANSIENT . $old );
		}
		$ids = array_slice( $ids, -self::MAX_PENDING );
		update_post_meta( $post->ID, self::PENDING_META, $ids );
		return $id;
	}

	/**
	 * Pending proposals of a page, re-validated on the stored page for the current user and shaped like
	 * a proposal for the editor. Proposals that expired, were applied or no longer fit the page are
	 * dropped.
	 *
	 * @param \WP_Post $post Page.
	 * @return array<int, array<string, mixed>>
	 */
	public static function pending( \WP_Post $post ): array {
		$user_id = get_current_user_id();
		$ids     = self::pending_ids( (int) $post->ID );
		$stored  = PageStore::get_document( $post, false );
		$base    = self::base_document( null, $user_id, $stored );
		$keep    = [];
		$out     = [];
		foreach ( $ids as $id ) {
			$p = self::load( $id );
			if ( null === $p || empty( $p['pending'] ) || is_array( $p['applied'] ) || (int) $p['page_id'] !== (int) $post->ID || is_wp_error( $base ) ) {
				continue;
			}
			$ops    = array_values( (array) $p['operations'] );
			$added  = array_map( 'strval', (array) $p['added'] );
			$result = ( new OpsValidator( new OpsApplier( OpsApplier::sequence( $added ) ) ) )->validate(
				[
					'summary'    => (string) $p['summary'],
					'operations' => $ops,
					'questions'  => [],
				],
				$base,
				$user_id,
				'edit',
				$stored
			);
			if ( ! $result->is_valid() ) {
				delete_transient( self::TRANSIENT . $id );
				continue;
			}
			$keep[] = $id;
			$author = get_userdata( (int) $p['user_id'] );
			$out[]  = [
				'proposal_id'      => $id,
				'summary'          => (string) $p['summary'],
				'operations'       => $ops,
				'questions'        => [],
				'preview_document' => PageValidator::to_json_shape( (array) $result->document() ),
				'change_list'      => ChangeList::build( $ops, $base, $result->added() ),
				'mode'             => 'edit',
				'expires_at'       => gmdate( 'c', (int) $p['expires'] ),
				'model'            => 'external',
				'external'         => true,
				'created_at'       => gmdate( 'c', (int) ( $p['created'] ?? time() ) ),
				'author'           => $author instanceof \WP_User ? $author->display_name : '',
			];
		}
		if ( $keep !== $ids ) {
			self::save_pending_ids( (int) $post->ID, $keep );
		}
		return $out;
	}

	/**
	 * Discards a pending proposal (the person pressed Discard in the editor).
	 *
	 * @param \WP_Post $post        Page.
	 * @param string   $proposal_id Proposal id.
	 */
	public static function discard_pending( \WP_Post $post, string $proposal_id ): bool {
		if ( ! in_array( $proposal_id, self::pending_ids( (int) $post->ID ), true ) ) {
			return false;
		}
		delete_transient( self::TRANSIENT . $proposal_id );
		self::forget_pending( (int) $post->ID, $proposal_id );
		return true;
	}

	/**
	 * Ids in the pending index.
	 *
	 * @param int $page_id Page.
	 * @return string[]
	 */
	private static function pending_ids( int $page_id ): array {
		$ids = get_post_meta( $page_id, self::PENDING_META, true );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'strval', $ids ), static fn( $id ) => (bool) preg_match( '/^[A-Za-z0-9]{24}$/', $id ) ) ) : [];
	}

	/**
	 * Writes the pending index (deletes the meta when empty).
	 *
	 * @param int      $page_id Page.
	 * @param string[] $ids     Ids.
	 */
	private static function save_pending_ids( int $page_id, array $ids ): void {
		if ( $ids ) {
			update_post_meta( $page_id, self::PENDING_META, array_values( $ids ) );
		} else {
			delete_post_meta( $page_id, self::PENDING_META );
		}
	}

	/**
	 * Removes one id from the pending index.
	 *
	 * @param int    $page_id     Page.
	 * @param string $proposal_id Proposal id.
	 */
	private static function forget_pending( int $page_id, string $proposal_id ): void {
		self::save_pending_ids( $page_id, array_values( array_diff( self::pending_ids( $page_id ), [ $proposal_id ] ) ) );
	}

	/**
	 * Title or bio suggestions.
	 *
	 * @param string               $kind    titles | bio.
	 * @param array<string, mixed> $context Context from the editor.
	 * @param int                  $page_id Page id.
	 * @param string               $source  admin | mcp.
	 * @return array<int, string>|WP_Error
	 */
	public static function suggest( string $kind, array $context, int $page_id, string $source = 'admin' ) {
		$user_id = get_current_user_id();
		if ( ! in_array( $kind, [ 'titles', 'bio' ], true ) ) {
			return new WP_Error( 'crazylinks_ai_bad_kind', __( 'Unknown suggestion type.', 'crazylinks' ), [ 'status' => 400 ] );
		}
		$ready = self::check_ready( $user_id );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$ctx    = self::clean_context( $context );
		$driver = self::driver();
		$list   = $driver->suggestText( $kind, $ctx );
		$usage  = method_exists( $driver, 'last_usage' ) ? (array) $driver->last_usage() : [];
		Limits::record( (int) ( $usage['prompt_tokens'] ?? 0 ) + (int) ( $usage['completion_tokens'] ?? 0 ) );
		AiLog::insert(
			[
				'page_id'           => $page_id,
				'user_id'           => $user_id,
				'kind'              => 'suggest',
				'mode'              => $kind,
				'source'            => $source,
				'instruction'       => '',
				'operations'        => is_wp_error( $list ) ? [] : $list,
				'provider'          => (string) ( $usage['provider'] ?? '' ),
				'model'             => (string) ( $usage['model'] ?? '' ),
				'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
				'tokens_estimated'  => ! empty( $usage['estimated'] ),
			]
		);
		return $list;
	}

	/**
	 * Whether external agents' changes to this page need confirmation in the editor.
	 *
	 * @param int $page_id Page id.
	 */
	public static function requires_confirmation( int $page_id ): bool {
		$meta = $page_id > 0 ? get_post_meta( $page_id, self::CONFIRM_META, true ) : '';
		return '' === $meta ? (bool) Settings::get( 'ai_mcp_confirm' ) : '1' === $meta;
	}

	/**
	 * Loads a live proposal.
	 *
	 * @param string $id Proposal id.
	 * @return array<string, mixed>|null
	 */
	public static function load( string $id ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9]{24}$/', $id ) ) {
			return null;
		}
		$data = get_transient( self::TRANSIENT . $id );
		if ( ! is_array( $data ) || (int) ( $data['expires'] ?? 0 ) < time() ) {
			return null;
		}
		return $data;
	}

	/**
	 * Stable hash of a document's canonical JSON.
	 *
	 * @param array<string, mixed> $doc Document.
	 */
	public static function hash( array $doc ): string {
		return md5( PageStore::encode( $doc ) );
	}

	/**
	 * AI must be ready and the user within limits and budget.
	 *
	 * @param int $user_id User.
	 * @return true|WP_Error
	 */
	private static function check_ready( int $user_id ) {
		$reason = Status::reason( $user_id );
		if ( '' !== $reason ) {
			return new WP_Error( 'crazylinks_ai_unavailable', Status::message( $reason ), [ 'status' => 'no_capability' === $reason ? rest_authorization_required_code() : 503 ] );
		}
		$budget = Limits::check_budget();
		if ( is_wp_error( $budget ) ) {
			return $budget;
		}
		return Limits::hit( $user_id );
	}

	/**
	 * The document operations apply to: the editor's working copy when given (validated like a save),
	 * otherwise the stored page.
	 *
	 * @param mixed                     $page    Working copy or null.
	 * @param int                       $user_id User.
	 * @param array<string, mixed>|null $stored  Stored document.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function base_document( $page, int $user_id, ?array $stored ) {
		if ( null === $page ) {
			$result = ( new PageValidator() )->validate( $stored ?? PageValidator::empty_document(), $user_id, $stored );
			return $result->is_valid() ? $result->document() : ( $stored ?? PageValidator::empty_document() );
		}
		if ( ! is_array( $page ) ) {
			return new WP_Error( 'crazylinks_invalid_page', __( 'The page document is invalid.', 'crazylinks' ), [ 'status' => 400 ] );
		}
		$result = ( new PageValidator() )->validate( $page, $user_id, $stored );
		if ( ! $result->is_valid() ) {
			$error = $result->to_wp_error();
			$error->add( 'crazylinks_ai_fix_first', __( 'Fix the problems on the page before asking the AI.', 'crazylinks' ) );
			return $error;
		}
		return $result->document();
	}

	/**
	 * 400 with validation errors.
	 *
	 * @param array<int, array{path:string, message:string}> $errors Errors.
	 */
	private static function invalid( array $errors ): WP_Error {
		return new WP_Error(
			'crazylinks_ai_invalid_ops',
			__( 'These changes cannot be applied to this page.', 'crazylinks' ),
			[
				'status' => 400,
				'errors' => $errors,
			]
		);
	}

	/**
	 * Logs a failed model call and counts its tokens (the provider still billed them).
	 *
	 * @param AiDriver $driver      Driver.
	 * @param \WP_Post $post        Page.
	 * @param int      $user_id     User.
	 * @param string   $mode        Mode.
	 * @param string   $instruction Instruction.
	 * @param string   $source      Source.
	 */
	private static function record_failure( AiDriver $driver, \WP_Post $post, int $user_id, string $mode, string $instruction, string $source ): void {
		$usage = method_exists( $driver, 'last_usage' ) ? (array) $driver->last_usage() : [];
		$used  = (int) ( $usage['prompt_tokens'] ?? 0 ) + (int) ( $usage['completion_tokens'] ?? 0 );
		if ( $used <= 0 ) {
			return;
		}
		Limits::record( $used );
		AiLog::insert(
			[
				'page_id'           => $post->ID,
				'user_id'           => $user_id,
				'kind'              => 'propose',
				'mode'              => $mode,
				'source'            => $source,
				'instruction'       => $instruction,
				'provider'          => (string) ( $usage['provider'] ?? '' ),
				'model'             => (string) ( $usage['model'] ?? '' ),
				'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
				'tokens_estimated'  => ! empty( $usage['estimated'] ),
			]
		);
	}

	/**
	 * Newest revision id of a page (0 when none).
	 *
	 * @param int $page_id Page id.
	 */
	private static function latest_revision( int $page_id ): int {
		$revs  = wp_get_post_revisions(
			$page_id,
			[
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
			]
		);
		$first = reset( $revs );
		return $first ? (int) ( is_object( $first ) ? $first->ID : $first ) : 0;
	}

	/**
	 * Suggestion context: strings only, short, no markup.
	 *
	 * @param array<string, mixed> $context Raw.
	 * @return array<string, mixed>
	 */
	private static function clean_context( array $context ): array {
		$out = [];
		foreach ( [ 'title', 'url', 'subtitle', 'name', 'bio', 'page_name', 'site_name' ] as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$out[ $key ] = mb_substr( wp_strip_all_tags( (string) $context[ $key ] ), 0, 'bio' === $key ? 500 : 300 );
			}
		}
		foreach ( [ 'link_titles', 'other_titles' ] as $key ) {
			if ( isset( $context[ $key ] ) && is_array( $context[ $key ] ) ) {
				$out[ $key ] = array_slice( array_map( static fn( $t ) => mb_substr( wp_strip_all_tags( is_scalar( $t ) ? (string) $t : '' ), 0, 120 ), $context[ $key ] ), 0, 30 );
			}
		}
		if ( ! isset( $out['site_name'] ) ) {
			$out['site_name'] = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
		return $out;
	}
}
