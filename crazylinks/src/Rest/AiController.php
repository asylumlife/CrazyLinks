<?php
/**
 * AI proposal and apply REST routes.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Rest;

use CrazyLinks\Ai\Limits;
use CrazyLinks\Ai\OpsValidator;
use CrazyLinks\Ai\Proposals;
use CrazyLinks\Ai\Status;
use CrazyLinks\Ai\AiLog;
use CrazyLinks\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * POST /pages/{id}/ai/propose, /ai/apply, /ai/suggest, /ai/settings and GET /ai/status (SPEC §10.2).
 *
 * Permission: `use_crazylinks_ai` plus `edit_post` on the page (status: `use_crazylinks_ai`). Cookie
 * auth needs the REST nonce like every other route. Rate limits, budget and availability are
 * enforced in Ai\Proposals so the abilities share them.
 */
final class AiController {

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$page = '/pages/(?P<id>[\d]+)/ai';
		$id   = [
			'id' => [
				'description' => __( 'Page id.', 'crazylinks' ),
				'type'        => 'integer',
			],
		];
		register_rest_route(
			RestRoutes::NS,
			$page . '/propose',
			[
				'args' => $id,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'propose' ],
					'permission_callback' => [ $this, 'page_permission' ],
					'args'                => [
						'instruction'       => [
							'type'        => 'string',
							'description' => __( 'What to change, in plain words.', 'crazylinks' ),
							'maxLength'   => Proposals::MAX_INSTRUCTION,
							'default'     => '',
						],
						'mode'              => [
							'type'    => 'string',
							'enum'    => OpsValidator::MODES,
							'default' => 'edit',
						],
						'questions_answers' => [
							'type'     => 'array',
							'maxItems' => 5,
							'items'    => [
								'type'       => 'object',
								'properties' => [
									'question' => [ 'type' => 'string' ],
									'answer'   => [ 'type' => 'string' ],
								],
							],
							'default'  => [],
						],
						'page'              => [
							'type'        => 'object',
							'description' => __( 'Editor working copy to change instead of the saved page.', 'crazylinks' ),
						],
					],
				],
			]
		);
		register_rest_route(
			RestRoutes::NS,
			$page . '/apply',
			[
				'args' => $id,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'apply' ],
					'permission_callback' => [ $this, 'page_permission' ],
					'args'                => [
						'proposal_id' => [
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^[A-Za-z0-9]{24}$',
						],
						'operations'  => [
							'type'        => 'array',
							'description' => __( 'Edited operations (default: as proposed). Validated like the original.', 'crazylinks' ),
							'items'       => [ 'type' => 'object' ],
						],
						'page'        => [
							'type'        => 'object',
							'description' => __( 'Editor working copy the proposal was made for.', 'crazylinks' ),
						],
					],
				],
			]
		);
		register_rest_route(
			RestRoutes::NS,
			$page . '/suggest',
			[
				'args' => $id,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'suggest' ],
					'permission_callback' => [ $this, 'page_permission' ],
					'args'                => [
						'kind'    => [
							'type'     => 'string',
							'enum'     => [ 'titles', 'bio' ],
							'required' => true,
						],
						'context' => [
							'type'    => 'object',
							'default' => [],
						],
					],
				],
			]
		);
		register_rest_route(
			RestRoutes::NS,
			$page . '/settings',
			[
				'args' => $id,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'page_settings' ],
					'permission_callback' => [ $this, 'page_permission' ],
					'args'                => [
						'require_confirmation' => [
							'type'     => 'boolean',
							'required' => true,
						],
					],
				],
			]
		);
		register_rest_route(
			RestRoutes::NS,
			'/ai/status',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'status' ],
					'permission_callback' => static fn() => current_user_can( 'use_crazylinks_ai' ) ? true : new \WP_Error( 'crazylinks_forbidden', __( 'Sorry, you are not allowed to do that.', 'crazylinks' ), [ 'status' => rest_authorization_required_code() ] ),
				],
			]
		);
	}

	/**
	 * Permission: use AI and edit this page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function page_permission( $request ) {
		$forbidden = new \WP_Error( 'crazylinks_forbidden', __( 'Sorry, you are not allowed to do that.', 'crazylinks' ), [ 'status' => rest_authorization_required_code() ] );
		if ( ! current_user_can( 'use_crazylinks_ai' ) ) {
			return $forbidden;
		}
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type ) {
			return current_user_can( 'edit_crazylinks_pages' ) ? new \WP_Error( 'crazylinks_not_found', __( 'Link page not found.', 'crazylinks' ), [ 'status' => 404 ] ) : $forbidden;
		}
		return current_user_can( 'edit_post', $post->ID ) ? true : $forbidden;
	}

	/**
	 * POST /pages/{id}/ai/propose.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function propose( $request ) {
		$post   = get_post( (int) $request['id'] );
		$result = Proposals::propose(
			$post,
			[
				'instruction'       => (string) $request['instruction'],
				'mode'              => (string) $request['mode'],
				'questions_answers' => (array) $request['questions_answers'],
				'page'              => $request['page'],
			],
			'admin'
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /pages/{id}/ai/apply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function apply( $request ) {
		$post   = get_post( (int) $request['id'] );
		$ops    = $request['operations'];
		$page   = $request['page'];
		$result = Proposals::apply( $post, (string) $request['proposal_id'], is_array( $ops ) ? $ops : null, is_array( $page ) ? $page : null, 'admin' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /pages/{id}/ai/suggest.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function suggest( $request ) {
		$list = Proposals::suggest( (string) $request['kind'], (array) $request['context'], (int) $request['id'], 'admin' );
		return is_wp_error( $list ) ? $list : rest_ensure_response( [ 'suggestions' => $list ] );
	}

	/**
	 * POST /pages/{id}/ai/settings: per-page "external agents need confirmation".
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function page_settings( $request ) {
		$id = (int) $request['id'];
		update_post_meta( $id, Proposals::CONFIRM_META, $request['require_confirmation'] ? '1' : '0' );
		return rest_ensure_response( [ 'require_confirmation' => Proposals::requires_confirmation( $id ) ] );
	}

	/**
	 * GET /ai/status.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		$reason = Status::reason();
		$user   = get_current_user_id();
		return rest_ensure_response(
			[
				'available' => '' === $reason,
				'reason'    => $reason,
				'message'   => Status::message( $reason ),
				'providers' => Status::providers(),
				'models'    => [
					'edit'    => Status::models( 'edit' ),
					'suggest' => Status::models( 'suggest' ),
				],
				'limits'    => [
					'user_hourly'      => (int) \CrazyLinks\Settings::get( 'ai_user_hourly' ),
					'site_daily'       => (int) \CrazyLinks\Settings::get( 'ai_site_daily' ),
					'user_last_hour'   => AiLog::count_since( time() - HOUR_IN_SECONDS, $user ),
					'site_last_24h'    => AiLog::count_since( time() - DAY_IN_SECONDS ),
					'proposal_minutes' => (int) ( Proposals::TTL / MINUTE_IN_SECONDS ),
				],
				'budget'    => Limits::budget_state(),
			]
		);
	}
}
