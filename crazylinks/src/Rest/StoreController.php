<?php
/**
 * Store product search for the editor's product picker.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Rest;

use CrazyLinks\Woo\Store;

defined( 'ABSPATH' ) || exit;

/**
 * GET /crazylinks/v1/store/products?search=&per_page=10 (or ?include=123 for one product).
 * Proxies Store::search(); the request carries only a search term and a count, never an address.
 */
final class StoreController {

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			RestRoutes::NS,
			'/store/products',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'search'   => [
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 100,
						'sanitize_callback' => static fn( $value ) => sanitize_text_field( (string) $value ),
					],
					'per_page' => [
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 50,
					],
					'include'  => [
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					],
				],
			]
		);
	}

	/**
	 * Needs edit_crazylinks_pages.
	 *
	 * @return true|\WP_Error
	 */
	public function permissions_check() {
		if ( current_user_can( 'edit_crazylinks_pages' ) ) {
			return true;
		}
		return new \WP_Error( 'crazylinks_forbidden', __( 'Sorry, you are not allowed to do that.', 'crazylinks' ), [ 'status' => rest_authorization_required_code() ] );
	}

	/**
	 * Products.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$include = (int) $request['include'];
		if ( $include > 0 ) {
			$product = Store::product( $include );
			$items   = $product ? [ $product ] : [];
		} else {
			$items = Store::search( (string) $request['search'], (int) $request['per_page'] );
		}
		return rest_ensure_response(
			[
				'available' => Store::is_available(),
				'products'  => array_map( [ Store::class, 'brief' ], $items ),
			]
		);
	}
}
