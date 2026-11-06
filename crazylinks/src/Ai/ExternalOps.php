<?php
/**
 * Operations written by an external agent: preview and apply (SPEC §10.7).
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\PageStore;
use CrazyLinks\Schema\PageValidator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Behind `crazylinks/preview-operations` and `crazylinks/apply-operations`. The agent's model runs on
 * the agent's side, so these calls never reach an AI provider and use no site tokens. Both run the
 * operations through OpsValidator (schema, business rules, then PageValidator with the acting user's
 * capabilities) on the stored page; apply saves through the same PATCH path as a human save
 * (Proposals::save_document()).
 *
 * Errors meant for the agent carry their details in the message as well as in the data, because the
 * MCP Adapter passes only WP_Error messages to the client.
 */
final class ExternalOps {

	public const IDEMPOTENCY_TRANSIENT = 'crazylinks_aik_';

	public const IDEMPOTENCY_TTL = 30 * MINUTE_IN_SECONDS;

	public const MAX_SUMMARY = 300;

	public const KEY_PATTERN = '^[A-Za-z0-9_-]{1,64}$';

	/**
	 * Validates operations on a copy of the page. Saves nothing and stores no proposal.
	 *
	 * @param \WP_Post $post       Page.
	 * @param mixed    $operations Operations.
	 * @param mixed    $full_page  Whether to add the whole resulting page as preview_document (true, "true" or "1").
	 * @return array<string, mixed> valid, errors, warnings, summary, change_list, changed_blocks, removed_block_ids, block_count, modified, and preview_document when asked for.
	 */
	public static function preview( \WP_Post $post, $operations, $full_page = false ): array {
		$user_id = get_current_user_id();
		$stored  = PageStore::get_document( $post, false );
		$base    = self::base( $user_id, $stored );
		$ops     = is_array( $operations ) ? array_values( $operations ) : [];
		$result  = ( new OpsValidator() )->validate( self::payload( $operations, '' ), $base, $user_id, 'edit', $stored );
		$full    = rest_sanitize_boolean( is_scalar( $full_page ) ? $full_page : false );
		$out     = [
			'valid'             => false,
			'errors'            => $result->errors(),
			'warnings'          => [],
			'summary'           => '',
			'change_list'       => [],
			'changed_blocks'    => [],
			'removed_block_ids' => [],
			'block_count'       => count( (array) ( $base['blocks'] ?? [] ) ),
			'modified'          => mysql_to_rfc3339( $post->post_modified_gmt ),
		];
		if ( $full ) {
			$out['preview_document'] = Context::page( $base );
		}
		if ( ! $result->is_valid() ) {
			return $out;
		}
		$doc     = (array) $result->document();
		$changes = ChangeList::build( $ops, $base, $result->added() );
		$out     = array_merge(
			$out,
			[
				'valid'       => true,
				'errors'      => [],
				'warnings'    => PageValidator::warnings_for( $doc ),
				'summary'     => self::describe( $changes ),
				'change_list' => $changes,
			],
			self::touched( $changes, $base, $doc )
		);
		if ( $full ) {
			$out['preview_document'] = Context::page( $doc );
		}
		return $out;
	}

	/**
	 * The blocks the operations touched, as Context::page() shows blocks, in document order after the
	 * operations (added blocks carry their provisional ids), the ids of blocks that are gone, and the
	 * total block count.
	 *
	 * @param array<int, array<string, mixed>> $changes Change list.
	 * @param array<string, mixed>             $base    Document before.
	 * @param array<string, mixed>             $doc     Document after.
	 * @return array{changed_blocks: array<int, array<string, mixed>>, removed_block_ids: string[], block_count: int}
	 */
	private static function touched( array $changes, array $base, array $doc ): array {
		$ids = [];
		foreach ( $changes as $change ) {
			if ( in_array( $change['kind'] ?? '', [ 'add', 'update', 'move' ], true ) && ! empty( $change['block_id'] ) ) {
				$ids[ (string) $change['block_id'] ] = true;
			}
		}
		$after   = [];
		$changed = [];
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $block ) {
			if ( is_array( $block ) ) {
				$after[ (string) ( $block['id'] ?? '' ) ] = true;
				if ( isset( $ids[ (string) ( $block['id'] ?? '' ) ] ) ) {
					$changed[] = $block;
				}
			}
		}
		$removed = [];
		foreach ( (array) ( $base['blocks'] ?? [] ) as $block ) {
			$id = is_array( $block ) ? (string) ( $block['id'] ?? '' ) : '';
			if ( '' !== $id && ! isset( $after[ $id ] ) ) {
				$removed[] = $id;
			}
		}
		return [
			'changed_blocks'    => Context::page( [ 'blocks' => $changed ] )['blocks'],
			'removed_block_ids' => $removed,
			'block_count'       => count( $after ),
		];
	}

	/**
	 * Validates and saves operations, or stores them for confirmation when the page requires it.
	 *
	 * @param \WP_Post             $post  Page.
	 * @param array<string, mixed> $input operations, summary?, expected_modified?, idempotency_key?.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply( \WP_Post $post, array $input ) {
		$user_id = get_current_user_id();
		$key     = (string) ( $input['idempotency_key'] ?? '' );
		if ( '' !== $key && ! preg_match( '/' . self::KEY_PATTERN . '/', $key ) ) {
			return new WP_Error( 'crazylinks_bad_idempotency_key', __( 'idempotency_key must be 1 to 64 letters, digits, hyphens or underscores.', 'crazylinks' ), [ 'status' => 400 ] );
		}
		$slot = '' === $key ? '' : self::IDEMPOTENCY_TRANSIENT . md5( get_current_blog_id() . '|' . $user_id . '|' . $post->ID . '|' . $key );
		if ( '' !== $slot ) {
			$previous = get_transient( $slot );
			if ( is_array( $previous ) ) {
				return array_merge( $previous, [ 'replayed' => true ] );
			}
		}

		$limited = Limits::hit( $user_id );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$modified = mysql_to_rfc3339( $post->post_modified_gmt );
		$expected = trim( (string) ( $input['expected_modified'] ?? '' ) );
		if ( '' !== $expected && strtotime( $expected ) !== strtotime( $post->post_modified_gmt . ' UTC' ) ) {
			return new WP_Error(
				'crazylinks_conflict',
				sprintf(
					/* translators: %s: the page's current modified time. */
					__( 'The page changed since you read it (it was last modified %s). Call get-page again, rebuild the operations from the current blocks and retry with that modified value.', 'crazylinks' ),
					$modified
				),
				[
					'status'   => 409,
					'modified' => $modified,
				]
			);
		}

		$ops     = is_array( $input['operations'] ?? null ) ? array_values( $input['operations'] ) : [];
		$summary = mb_substr( sanitize_text_field( (string) ( $input['summary'] ?? '' ) ), 0, self::MAX_SUMMARY );
		$stored  = PageStore::get_document( $post, false );
		$base    = self::base( $user_id, $stored );
		$result  = ( new OpsValidator() )->validate( self::payload( $input['operations'] ?? null, $summary ), $base, $user_id, 'edit', $stored );
		if ( ! $result->is_valid() ) {
			return self::invalid( $result->errors() );
		}
		$changes = ChangeList::build( $ops, $base, $result->added() );
		$summary = '' === $summary ? self::describe( $changes ) : $summary;
		$touched = self::touched( $changes, $base, (array) $result->document() );
		$confirm = Proposals::requires_confirmation( (int) $post->ID );

		if ( ! $confirm ) {
			$holder = self::lock_holder( (int) $post->ID );
			if ( $holder > 0 ) {
				return new WP_Error( 'crazylinks_page_locked', __( 'Someone is editing this page in the CrazyLinks editor right now, so the change was not saved. Try again in a few minutes, or ask them to close the editor.', 'crazylinks' ), [ 'status' => 409 ] );
			}
		}

		$log_id = AiLog::insert(
			[
				'page_id'          => $post->ID,
				'user_id'          => $user_id,
				'kind'             => 'external',
				'mode'             => 'edit',
				'source'           => 'mcp',
				'instruction'      => '',
				'summary'          => $summary,
				'operations'       => $ops,
				'provider'         => '',
				'model'            => 'external',
				'tokens_estimated' => false,
			]
		);

		$out = [
			'applied'             => false,
			'revision_id'         => null,
			'pending_proposal_id' => null,
			'change_list'         => $changes,
			'changed_blocks'      => $touched['changed_blocks'],
			'removed_block_ids'   => $touched['removed_block_ids'],
			'block_count'         => $touched['block_count'],
			'summary'             => $summary,
			'modified'            => $modified,
			'link'                => (string) get_permalink( $post ),
			'source'              => 'mcp',
			'message'             => '',
		];

		if ( $confirm ) {
			$out['pending_proposal_id'] = Proposals::store_pending( $post, $ops, $summary, $result->added(), $base, $log_id );
			$out['message']             = __( 'Nothing was saved yet: this page requires changes from external AI agents to be confirmed. The change is waiting in the AI tab of the page editor.', 'crazylinks' );
		} else {
			$saved = Proposals::save_document( $post, (array) $result->document(), 'mcp' );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			AiLog::mark_applied( $log_id, $saved['revision_id'], 'mcp', $ops );
			$fresh              = get_post( $post->ID );
			$out['applied']     = true;
			$out['revision_id'] = $saved['revision_id'];
			$out['modified']    = $fresh instanceof \WP_Post ? mysql_to_rfc3339( $fresh->post_modified_gmt ) : $modified;
			$out['message']     = null === $saved['revision_id'] ? __( 'Saved. The page content did not change, so no revision was created.', 'crazylinks' ) : __( 'Saved. The change is in the page history as "AI (external)".', 'crazylinks' );
			/** This action is documented in src/Ai/Proposals.php */
			do_action( 'crazylinks_ai_applied', (int) $post->ID, $saved['revision_id'], 'mcp', $ops );
		}

		if ( '' !== $slot ) {
			set_transient( $slot, $out, self::IDEMPOTENCY_TTL );
		}
		return $out;
	}

	/**
	 * The validated stored page (what the operations apply to).
	 *
	 * @param int                       $user_id User.
	 * @param array<string, mixed>|null $stored  Stored document.
	 * @return array<string, mixed>
	 */
	private static function base( int $user_id, ?array $stored ): array {
		$base = Proposals::base_document( null, $user_id, $stored );
		return is_wp_error( $base ) ? ( $stored ?? PageValidator::empty_document() ) : $base;
	}

	/**
	 * The validator's envelope.
	 *
	 * @param mixed  $operations Operations as sent.
	 * @param string $summary    Summary.
	 * @return array<string, mixed>
	 */
	private static function payload( $operations, string $summary ): array {
		return [
			'summary'    => $summary,
			'operations' => $operations,
			'questions'  => [],
		];
	}

	/**
	 * Who else holds the editor lock on the page (0 when nobody, or the current user).
	 *
	 * @param int $page_id Page.
	 */
	private static function lock_holder( int $page_id ): int {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		return (int) wp_check_post_lock( $page_id );
	}

	/**
	 * One-line summary from a change list.
	 *
	 * @param array<int, array<string, mixed>> $changes Change list.
	 */
	private static function describe( array $changes ): string {
		$texts = array_map( static fn( $c ) => (string) ( $c['text'] ?? '' ), $changes );
		return mb_substr( implode( '; ', array_filter( $texts ) ), 0, self::MAX_SUMMARY );
	}

	/**
	 * 400 with the validation errors, listed in the message too.
	 *
	 * @param array<int, array{path:string, message:string}> $errors Errors.
	 */
	private static function invalid( array $errors ): WP_Error {
		$lines = array_map( static fn( $e ) => $e['path'] . ': ' . $e['message'], array_slice( $errors, 0, 10 ) );
		return new WP_Error(
			'crazylinks_ai_invalid_ops',
			__( 'These operations cannot be applied to this page. Nothing was saved. Fix these problems (preview-operations shows them too):', 'crazylinks' ) . "\n" . implode( "\n", $lines ),
			[
				'status' => 400,
				'errors' => $errors,
			]
		);
	}
}
