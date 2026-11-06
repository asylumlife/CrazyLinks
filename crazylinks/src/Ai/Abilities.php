<?php
/**
 * Abilities API registrations for external agents (MCP).
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\Analytics\Summary;
use CrazyLinks\PageStore;
use CrazyLinks\PostType;
use CrazyLinks\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC §10.5 and §10.7. Nine abilities in the `crazylinks` category, exposed in REST (`abilities/v1`)
 * and to the MCP Adapter so Claude, ChatGPT and other agents can use them. Each runs as the
 * authenticated user and checks that user's capabilities; each is private until an administrator
 * enables it under CrazyLinks → Settings → AI. The enable switch is applied twice: as the MCP exposure
 * meta (`meta.public`, `meta.mcp.public`, read when the abilities are registered, once per request)
 * and in every permission callback. `get-editing-guide`, `preview-operations` and `apply-operations`
 * serve agents whose own model writes the operations (no site provider needed). Changes applied
 * through `apply-changes` or `apply-operations` are logged with source `mcp` and badged
 * "AI (external)" in the page history.
 *
 * This class is also the entry point for the rest of the AI module (CLI command, history badge, log
 * pruning) because it is the one AI class Plugin::boot() wires.
 */
final class Abilities {

	public const CATEGORY = 'crazylinks';

	public const NAMES = [
		'crazylinks/list-pages',
		'crazylinks/get-page',
		'crazylinks/get-editing-guide',
		'crazylinks/preview-operations',
		'crazylinks/apply-operations',
		'crazylinks/propose-changes',
		'crazylinks/apply-changes',
		'crazylinks/get-analytics-summary',
		'crazylinks/suggest-titles',
	];

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
		( new McpServer() )->register_hooks();
		( new Bootstrap() )->register_hooks();
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'CrazyLinks', 'crazylinks' ),
				'description' => __( 'Read and edit CrazyLinks link-in-bio pages.', 'crazylinks' ),
			]
		);
	}

	/**
	 * Whether the administrator enabled an ability.
	 *
	 * @param string $name Ability name.
	 */
	public static function enabled( string $name ): bool {
		$on = in_array( $name, (array) Settings::get( 'ai_abilities' ), true );
		/**
		 * Filters whether a CrazyLinks ability may be used by external agents.
		 *
		 * @param bool   $on   Setting value (abilities are off until enabled).
		 * @param string $name Ability name.
		 */
		return (bool) apply_filters( 'crazylinks_ability_enabled', $on, $name );
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$page_id  = [
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Link page id.', 'crazylinks' ),
		];
		$readonly = [
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		];
		$proposal = [
			'type'       => 'object',
			'properties' => [
				'proposal_id'      => [ 'type' => [ 'string', 'null' ] ],
				'summary'          => [ 'type' => 'string' ],
				'operations'       => [ 'type' => 'array' ],
				'questions'        => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'preview_document' => [ 'type' => 'object' ],
				'change_list'      => [ 'type' => 'array' ],
				'expires_at'       => [ 'type' => [ 'string', 'null' ] ],
			],
		];

		$this->register(
			'crazylinks/list-pages',
			__( 'List link pages', 'crazylinks' ),
			__( 'Lists the CrazyLinks link-in-bio pages the current user can edit, newest first: id, title, slug, status, public URL and last change.', 'crazylinks' ),
			[
				'type'                 => 'object',
				'properties'           => [
					'search'   => [ 'type' => 'string' ],
					'per_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					],
				],
				'additionalProperties' => false,
			],
			[
				'type'  => 'array',
				'items' => [ 'type' => 'object' ],
			],
			[ $this, 'list_pages' ],
			static fn() => current_user_can( 'edit_crazylinks_pages' ),
			$readonly
		);
		$this->register(
			'crazylinks/get-page',
			__( 'Get a link page', 'crazylinks' ),
			__( 'Returns one link page as JSON (profile, design, blocks with ids) and its modified time. Custom HTML and custom CSS are summarized, not returned. Use the block ids in operations for preview-operations and apply-operations (or propose-changes), and pass modified as expected_modified.', 'crazylinks' ),
			[
				'type'                 => 'object',
				'properties'           => [ 'id' => $page_id ],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			[ 'type' => 'object' ],
			[ $this, 'get_page' ],
			static fn( $input = null ) => self::can_edit( $input ),
			$readonly
		);
		$operations = [
			'type'        => 'array',
			'description' => __( 'Operations in the CrazyLinks format (see get-editing-guide), applied in order.', 'crazylinks' ),
			'items'       => [
				'type'                 => 'object',
				'additionalProperties' => true,
			],
		];
		$messages   = [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'path'    => [ 'type' => 'string' ],
					'message' => [ 'type' => 'string' ],
				],
			],
		];
		$this->register(
			'crazylinks/get-editing-guide',
			__( 'Get the CrazyLinks editing guide', 'crazylinks' ),
			__( 'Call this before writing operations for a CrazyLinks link page: returns the block types and their props, the operations format with examples, the rules and the recommended workflow. Optional input id (a link page id) also returns that page\'s current content and modified time.', 'crazylinks' ),
			[
				'type'                 => 'object',
				'properties'           => [ 'id' => $page_id ],
				'additionalProperties' => false,
				// Lets core's REST run route and MCP clients call it with no input at all.
				'default'              => [],
			],
			[
				'type'       => 'object',
				'properties' => [
					'guide'       => [ 'type' => 'string' ],
					'block_types' => [ 'type' => 'array' ],
					'operations'  => [ 'type' => 'array' ],
					'page'        => [ 'type' => 'object' ],
					'modified'    => [ 'type' => 'string' ],
				],
			],
			[ $this, 'editing_guide' ],
			static fn( $input = null ) => self::can_edit_or_missing( $input, false ),
			$readonly
		);
		$this->register(
			'crazylinks/preview-operations',
			__( 'Preview operations on a link page', 'crazylinks' ),
			__( 'Checks operations against a link page without saving anything. Input: id and operations (format in get-editing-guide). Returns valid, the errors to fix, warnings, a plain-English change_list, changed_blocks (only the blocks the operations touched, as they will be, with provisional ids for added blocks), removed_block_ids and block_count; invalid operations come back as valid false, so fix them and preview again before apply-operations. The whole resulting page is returned only with include_document true, which you rarely need.', 'crazylinks' ),
			[
				'type'                 => 'object',
				'properties'           => [
					'id'               => $page_id,
					'operations'       => $operations,
					'include_document' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Also return preview_document, the whole resulting page. Off by default: changed_blocks and change_list already show what the operations do.', 'crazylinks' ),
					],
				],
				'required'             => [ 'id', 'operations' ],
				'additionalProperties' => false,
			],
			[
				'type'       => 'object',
				'properties' => [
					'valid'             => [ 'type' => 'boolean' ],
					'errors'            => $messages,
					'warnings'          => $messages,
					'summary'           => [ 'type' => 'string' ],
					'change_list'       => [ 'type' => 'array' ],
					'changed_blocks'    => [ 'type' => 'array' ],
					'removed_block_ids' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'block_count'       => [ 'type' => 'integer' ],
					'preview_document'  => [ 'type' => 'object' ],
					'modified'          => [ 'type' => 'string' ],
				],
			],
			[ $this, 'preview_operations' ],
			static fn( $input = null ) => self::can_edit_or_missing( $input, true ),
			$readonly
		);
		$this->register(
			'crazylinks/apply-operations',
			__( 'Apply operations to a link page', 'crazylinks' ),
			__( 'Validates operations like preview-operations and saves them to the link page, creating a revision shown as "AI (external)" in the page history. Input: id, operations, optional summary (for the history), expected_modified (modified from get-page; refused if the page changed since) and idempotency_key (a retry with the same key returns the first result). Returns change_list, changed_blocks, removed_block_ids and block_count, so no second get-page is needed. Use after a valid preview. If the page requires confirmation, nothing is saved and the change waits in the editor.', 'crazylinks' ),
			[
				'type'                 => 'object',
				'properties'           => [
					'id'                => $page_id,
					'operations'        => $operations,
					'summary'           => [
						'type'        => 'string',
						'maxLength'   => ExternalOps::MAX_SUMMARY,
						'description' => __( 'One sentence describing the change, shown in the page history.', 'crazylinks' ),
					],
					'expected_modified' => [
						'type'        => 'string',
						'description' => __( 'The modified value returned by get-page. If the page changed since, nothing is saved and a conflict error is returned.', 'crazylinks' ),
					],
					'idempotency_key'   => [
						'type'        => 'string',
						'pattern'     => ExternalOps::KEY_PATTERN,
						'description' => __( 'Any unique string for this change. Repeating a call with the same key within 30 minutes returns the first result without applying again.', 'crazylinks' ),
					],
				],
				'required'             => [ 'id', 'operations' ],
				'additionalProperties' => false,
			],
			[
				'type'       => 'object',
				'properties' => [
					'applied'             => [ 'type' => 'boolean' ],
					'revision_id'         => [ 'type' => [ 'integer', 'null' ] ],
					'pending_proposal_id' => [ 'type' => [ 'string', 'null' ] ],
					'change_list'         => [ 'type' => 'array' ],
					'changed_blocks'      => [ 'type' => 'array' ],
					'removed_block_ids'   => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'block_count'         => [ 'type' => 'integer' ],
					'summary'             => [ 'type' => 'string' ],
					'modified'            => [ 'type' => 'string' ],
					'link'                => [ 'type' => 'string' ],
					'source'              => [ 'type' => 'string' ],
					'message'             => [ 'type' => 'string' ],
					'replayed'            => [ 'type' => 'boolean' ],
				],
			],
			[ $this, 'apply_operations' ],
			static fn( $input = null ) => self::can_edit_or_missing( $input, true ),
			[
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			]
		);
		$this->register(
			'crazylinks/propose-changes',
			__( 'Propose changes to a link page', 'crazylinks' ),
			__( 'Turns an instruction into validated operations and a preview of the resulting page, without saving. Either give "instruction" (the site\'s own AI writes the operations) or "operations" in the CrazyLinks ops format (schemas/ai-ops.v1.json: add_block, update_block, move_block, remove_block, set_design, set_profile). Returns a proposal_id for apply-changes; proposals expire after 30 minutes. If "questions" is non-empty, answer them and propose again with questions_answers.', 'crazylinks' ),
			[
				'type'                 => 'object',
				'properties'           => [
					'id'                => $page_id,
					'instruction'       => [
						'type'      => 'string',
						'maxLength' => Proposals::MAX_INSTRUCTION,
					],
					'mode'              => [
						'type'    => 'string',
						'enum'    => OpsValidator::MODES,
						'default' => 'edit',
					],
					'operations'        => [
						'type'  => 'array',
						'items' => [ 'type' => 'object' ],
					],
					'summary'           => [ 'type' => 'string' ],
					'questions_answers' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'question' => [ 'type' => 'string' ],
								'answer'   => [ 'type' => 'string' ],
							],
						],
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			$proposal,
			[ $this, 'propose' ],
			static fn( $input = null ) => current_user_can( 'use_crazylinks_ai' ) && self::can_edit( $input ),
			$readonly
		);
		$this->register(
			'crazylinks/apply-changes',
			__( 'Apply proposed changes', 'crazylinks' ),
			__( 'Applies a proposal from propose-changes and saves the page through the normal save path, creating a revision that appears in the page history as "AI (external)". Applying the same proposal twice has no further effect. Pages can require changes from external agents to be confirmed in the editor instead.', 'crazylinks' ),
			[
				'type'                 => 'object',
				'properties'           => [
					'id'          => $page_id,
					'proposal_id' => [
						'type'    => 'string',
						'pattern' => '^[A-Za-z0-9]{24}$',
					],
				],
				'required'             => [ 'id', 'proposal_id' ],
				'additionalProperties' => false,
			],
			[
				'type'       => 'object',
				'properties' => [
					'applied'     => [ 'type' => 'boolean' ],
					'revision_id' => [ 'type' => [ 'integer', 'null' ] ],
					'source'      => [ 'type' => 'string' ],
				],
			],
			[ $this, 'apply' ],
			static fn( $input = null ) => self::can_edit( $input ),
			[
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			]
		);
		$this->register(
			'crazylinks/get-analytics-summary',
			__( 'Get link page analytics', 'crazylinks' ),
			__( 'Returns aggregate views and clicks per block for a link page over the last N days (no visitor data).', 'crazylinks' ),
			[
				'type'                 => 'object',
				'properties'           => [
					'id'   => $page_id,
					'days' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 365,
						'default' => 30,
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			[ 'type' => 'object' ],
			[ $this, 'analytics' ],
			static fn( $input = null ) => current_user_can( 'view_crazylinks_analytics' ) && self::can_edit( $input ),
			$readonly
		);
		$this->register(
			'crazylinks/suggest-titles',
			__( 'Suggest link titles', 'crazylinks' ),
			__( 'Suggests five alternative titles for a link, using the site\'s AI provider.', 'crazylinks' ),
			[
				'type'                 => 'object',
				'properties'           => [
					'title'    => [
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 120,
					],
					'url'      => [
						'type'      => 'string',
						'maxLength' => 2048,
					],
					'subtitle' => [
						'type'      => 'string',
						'maxLength' => 160,
					],
				],
				'required'             => [ 'title' ],
				'additionalProperties' => false,
			],
			[
				'type'       => 'object',
				'properties' => [
					'suggestions' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
				],
			],
			[ $this, 'suggest_titles' ],
			static fn() => current_user_can( 'use_crazylinks_ai' ),
			$readonly
		);
	}

	/**
	 * Registers one ability with the shared meta and the "enabled by the admin" gate.
	 *
	 * @param string               $name        Name.
	 * @param string               $label       Label.
	 * @param string               $description Description.
	 * @param array<string, mixed> $input       Input schema.
	 * @param array<string, mixed> $output      Output schema.
	 * @param callable             $execute     Execute callback.
	 * @param callable             $permission  Capability check.
	 * @param array<string, bool>  $annotations Annotations.
	 */
	private function register( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		// Exposure to the MCP Adapter (SPEC §10.7). Settings are read per request and abilities are
		// registered once per request, so switching an ability on or off takes effect on the next call.
		$public = self::enabled( $name );
		wp_register_ability(
			$name,
			[
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => static function ( $input = null ) use ( $name, $permission ) {
					if ( ! self::enabled( $name ) ) {
						return new WP_Error( 'crazylinks_ability_disabled', __( 'This CrazyLinks ability is turned off. An administrator can enable it under CrazyLinks → Settings → AI.', 'crazylinks' ) );
					}
					return (bool) $permission( $input );
				},
				'meta'                => [
					'annotations'  => $annotations,
					'show_in_rest' => true,
					'public'       => $public,
					'mcp'          => [
						'public' => $public,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	/**
	 * Whether the current user can edit the page named in the input.
	 *
	 * @param mixed $input Input.
	 */
	private static function can_edit( $input ): bool {
		$post = self::page( $input );
		return $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * For abilities that report a missing page as 404 (like the REST routes do): true when the user may
	 * edit link pages and the named page either does not exist or is editable by them.
	 *
	 * @param mixed $input       Input.
	 * @param bool  $requires_id Whether input.id is required.
	 */
	private static function can_edit_or_missing( $input, bool $requires_id ): bool {
		if ( ! current_user_can( 'edit_crazylinks_pages' ) ) {
			return false;
		}
		$id = is_array( $input ) ? (int) ( $input['id'] ?? 0 ) : 0;
		if ( $id <= 0 ) {
			return ! $requires_id;
		}
		$post = self::page( $input );
		return null === $post || current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * The link page named by input.id.
	 *
	 * @param mixed $input Input.
	 */
	private static function page( $input ): ?\WP_Post {
		$id   = is_array( $input ) ? (int) ( $input['id'] ?? 0 ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && PostType::NAME === $post->post_type ? $post : null;
	}

	/**
	 * Execute: list-pages.
	 *
	 * @param mixed $input Input.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_pages( $input = null ): array {
		$input = is_array( $input ) ? $input : [];
		$args  = [
			'post_type'      => PostType::NAME,
			'post_status'    => 'any',
			'posts_per_page' => min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) ),
			'perm'           => 'editable',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}
		if ( ! current_user_can( 'edit_others_crazylinks_pages' ) ) {
			$args['author'] = get_current_user_id();
		}
		$out = [];
		foreach ( ( new \WP_Query( $args ) )->posts as $post ) {
			if ( $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID ) ) {
				$out[] = [
					'id'       => (int) $post->ID,
					'title'    => $post->post_title,
					'slug'     => $post->post_name,
					'status'   => $post->post_status,
					'link'     => (string) get_permalink( $post ),
					'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
				];
			}
		}
		return $out;
	}

	/**
	 * Execute: get-page.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_page( $input = null ) {
		$post = self::page( $input );
		if ( null === $post ) {
			return new WP_Error( 'crazylinks_not_found', __( 'Link page not found.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		return [
			'id'       => (int) $post->ID,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'link'     => (string) get_permalink( $post ),
			'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
			'page'     => Context::page( PageStore::get_document( $post ) ?? [] ),
		];
	}

	/**
	 * Execute: get-editing-guide.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function editing_guide( $input = null ) {
		if ( is_array( $input ) && ! empty( $input['id'] ) ) {
			$post = self::page( $input );
			if ( null === $post ) {
				return new WP_Error( 'crazylinks_not_found', __( 'Link page not found.', 'crazylinks' ), [ 'status' => 404 ] );
			}
			return EditingGuide::build( $post );
		}
		return EditingGuide::build();
	}

	/**
	 * Execute: preview-operations.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function preview_operations( $input = null ) {
		$post = self::page( $input );
		if ( null === $post ) {
			return new WP_Error( 'crazylinks_not_found', __( 'Link page not found.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		$input = (array) $input;
		return ExternalOps::preview( $post, $input['operations'] ?? null, $input['include_document'] ?? false );
	}

	/**
	 * Execute: apply-operations.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function apply_operations( $input = null ) {
		$post = self::page( $input );
		if ( null === $post ) {
			return new WP_Error( 'crazylinks_not_found', __( 'Link page not found.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		return ExternalOps::apply( $post, (array) $input );
	}

	/**
	 * Execute: propose-changes.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function propose( $input = null ) {
		$post = self::page( $input );
		if ( null === $post ) {
			return new WP_Error( 'crazylinks_not_found', __( 'Link page not found.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		return Proposals::propose( $post, (array) $input, 'mcp' );
	}

	/**
	 * Execute: apply-changes.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function apply( $input = null ) {
		$post = self::page( $input );
		if ( null === $post ) {
			return new WP_Error( 'crazylinks_not_found', __( 'Link page not found.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		$result = Proposals::apply( $post, (string) ( (array) $input )['proposal_id'], null, null, 'mcp' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		unset( $result['page'] ); // The agent can call get-page; keep the response small.
		return $result;
	}

	/**
	 * Execute: get-analytics-summary.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function analytics( $input = null ) {
		$post = self::page( $input );
		if ( null === $post ) {
			return new WP_Error( 'crazylinks_not_found', __( 'Link page not found.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		return Summary::for_page( (int) $post->ID, (int) ( ( (array) $input )['days'] ?? 30 ) );
	}

	/**
	 * Execute: suggest-titles.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function suggest_titles( $input = null ) {
		$list = Proposals::suggest( 'titles', (array) $input, 0, 'mcp' );
		return is_wp_error( $list ) ? $list : [ 'suggestions' => $list ];
	}
}
