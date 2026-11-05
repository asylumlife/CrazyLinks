<?php
/**
 * Subscriber management REST routes.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Rest;

use CrazyLinks\Subscribers\Store;

defined( 'ABSPATH' ) || exit;

/**
 * GET /crazylinks/v1/subscribers and DELETE /crazylinks/v1/subscribers/{id}.
 * Capability: manage_crazylinks. Responses never include token hashes.
 */
final class SubscribersController extends \WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = RestRoutes::NS;
		$this->rest_base = 'subscribers';
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => [
						'page_id'  => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						'status'   => [
							'type' => 'string',
							'enum' => Store::STATUSES,
						],
						'search'   => [
							'type' => 'string',
						],
						'page'     => [
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						],
						'per_page' => [
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						],
					],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				],
			]
		);
	}

	/**
	 * Needs manage_crazylinks.
	 *
	 * @return true|\WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_crazylinks' ) ) {
			return new \WP_Error( 'crazylinks_forbidden', __( 'Sorry, you are not allowed to manage subscribers.', 'crazylinks' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	/**
	 * List.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page = (int) $request['per_page'];
		$result   = Store::query(
			[
				'page_id'  => (int) $request['page_id'],
				'status'   => (string) $request['status'],
				'search'   => (string) $request['search'],
				'per_page' => $per_page,
				'paged'    => (int) $request['page'],
			]
		);
		$items    = array_map(
			static function ( array $row ): array {
				return [
					'id'           => (int) $row['id'],
					'page_id'      => (int) $row['page_id'],
					'page_title'   => get_the_title( (int) $row['page_id'] ),
					'block_id'     => (string) $row['block_id'],
					'email'        => (string) $row['email'],
					'status'       => (string) $row['status'],
					'consent_text' => (string) $row['consent_text'],
					'consent_at'   => $row['consent_at'] ? (string) $row['consent_at'] : null,
					'confirmed_at' => $row['confirmed_at'] ? (string) $row['confirmed_at'] : null,
					'created_at'   => (string) $row['created_at'],
				];
			},
			$result['items']
		);
		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / $per_page ) ) );
		return $response;
	}

	/**
	 * Delete one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$id = (int) $request['id'];
		if ( ! Store::get( $id ) ) {
			return new \WP_Error( 'crazylinks_not_found', __( 'Subscriber not found.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		Store::delete( [ $id ] );
		return rest_ensure_response(
			[
				'deleted' => true,
				'id'      => $id,
			]
		);
	}
}
