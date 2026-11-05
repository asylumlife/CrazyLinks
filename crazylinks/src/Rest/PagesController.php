<?php
/**
 * Pages CRUD.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Rest;

use CrazyLinks\PageStore;
use CrazyLinks\PostType;
use CrazyLinks\Schema\PageValidator;

defined( 'ABSPATH' ) || exit;

/**
 * /crazylinks/v1/pages and /crazylinks/v1/pages/{id}. Accepts and returns the page JSON,
 * validated server-side. Auth is standard REST cookie auth (X-WP-Nonce) or application passwords.
 */
final class PagesController extends \WP_REST_Controller {

	public const STATUSES = [ 'draft', 'pending', 'private', 'publish', 'future' ];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = RestRoutes::NS;
		$this->rest_base = 'pages';
	}

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				'args'   => [
					'id' => [
						'description' => __( 'Page id.', 'crazylinks' ),
						'type'        => 'integer',
					],
				],
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [ 'context' => $this->get_context_param( [ 'default' => 'edit' ] ) ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'delete_item_permissions_check' ],
					'args'                => [
						'force' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Delete permanently instead of moving to the trash.', 'crazylinks' ),
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * The page post or an error.
	 *
	 * @param int $id Id.
	 * @return \WP_Post|\WP_Error
	 */
	private function get_page( int $id ) {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type ) {
			return new \WP_Error( 'crazylinks_not_found', __( 'Link page not found.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		return $post;
	}

	/**
	 * List permission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		unset( $request );
		return current_user_can( 'edit_crazylinks_pages' ) ? true : $this->forbidden();
	}

	/**
	 * Lists pages the user can edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$args = [
			'post_type'      => PostType::NAME,
			'post_status'    => $request['status'] ? (string) $request['status'] : 'any',
			'posts_per_page' => (int) $request['per_page'],
			'paged'          => (int) $request['page'],
			'perm'           => 'editable',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];
		if ( ! empty( $request['search'] ) ) {
			$args['s'] = (string) $request['search'];
		}
		if ( ! current_user_can( 'edit_others_crazylinks_pages' ) ) {
			$args['author'] = get_current_user_id();
		}
		$query = new \WP_Query( $args );
		$items = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID ) ) {
				$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $post, $request ) );
			}
		}
		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );
		return $response;
	}

	/**
	 * Read permission (editors of the page only; the public reads the rendered page).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$post = $this->get_page( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return current_user_can( 'edit_post', $post->ID ) ? true : $this->forbidden();
	}

	/**
	 * Reads one page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$post = $this->get_page( (int) $request['id'] );
		return is_wp_error( $post ) ? $post : $this->prepare_item_for_response( $post, $request );
	}

	/**
	 * Create permission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_crazylinks_pages' ) ) {
			return $this->forbidden();
		}
		return $this->check_status_permission( (string) ( $request['status'] ?? 'draft' ) );
	}

	/**
	 * Creates a page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$doc = $this->validated_document( $request, null );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$postarr = [
			'post_type'    => PostType::NAME,
			'post_title'   => wp_slash( (string) ( $request['title'] ?? '' ) ),
			'post_status'  => (string) ( $request['status'] ?? 'draft' ),
			'post_content' => wp_slash( PageStore::encode( $doc ) ),
			'post_author'  => get_current_user_id(),
		];
		if ( ! empty( $request['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $request['slug'] );
		}
		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) ) {
			$id->add_data( [ 'status' => 500 ] );
			return $id;
		}
		$response = $this->with_warnings( $this->prepare_item_for_response( get_post( $id ), $request ) );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( $this->namespace . '/' . $this->rest_base . '/' . $id ) );
		return $response;
	}

	/**
	 * Update permission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$post = $this->get_page( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $this->forbidden();
		}
		if ( isset( $request['status'] ) && $request['status'] !== $post->post_status ) {
			return $this->check_status_permission( (string) $request['status'] );
		}
		return true;
	}

	/**
	 * Updates a page. `page` replaces the whole document.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$post = $this->get_page( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$postarr = [ 'ID' => $post->ID ];
		if ( isset( $request['page'] ) ) {
			$doc = $this->validated_document( $request, PageStore::get_document( $post, false ) );
			if ( is_wp_error( $doc ) ) {
				return $doc;
			}
			$postarr['post_content'] = wp_slash( PageStore::encode( $doc ) );
		}
		if ( isset( $request['title'] ) ) {
			$postarr['post_title'] = wp_slash( (string) $request['title'] );
		}
		if ( isset( $request['status'] ) ) {
			$postarr['post_status'] = (string) $request['status'];
		}
		if ( isset( $request['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $request['slug'] );
		}
		$id = wp_update_post( $postarr, true ); // Values above are already slashed.
		if ( is_wp_error( $id ) ) {
			$id->add_data( [ 'status' => 500 ] );
			return $id;
		}
		return $this->with_warnings( $this->prepare_item_for_response( get_post( $id ), $request ) );
	}

	/**
	 * Adds the last validation warnings to a write response.
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	private function with_warnings( \WP_REST_Response $response ): \WP_REST_Response {
		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$data['warnings'] = $this->last_warnings;
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Delete permission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$post = $this->get_page( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return current_user_can( 'delete_post', $post->ID ) ? true : $this->forbidden();
	}

	/**
	 * Trashes or deletes a page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$post = $this->get_page( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$previous = $this->prepare_item_for_response( $post, $request )->get_data();
		$result   = $request['force'] ? wp_delete_post( $post->ID, true ) : wp_trash_post( $post->ID );
		if ( ! $result ) {
			return new \WP_Error( 'crazylinks_delete_failed', __( 'The page could not be deleted.', 'crazylinks' ), [ 'status' => 500 ] );
		}
		return rest_ensure_response(
			[
				'deleted'  => (bool) $request['force'],
				'trashed'  => ! $request['force'],
				'previous' => $previous,
			]
		);
	}

	/**
	 * Response shape.
	 *
	 * @param mixed            $item    Post.
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$post = $item instanceof \WP_Post ? $item : get_post( $item );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_REST_Response( null, 404 );
		}
		$data     = [
			'id'       => (int) $post->ID,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'author'   => (int) $post->post_author,
			'link'     => (string) get_permalink( $post ),
			'date'     => mysql_to_rfc3339( $post->post_date_gmt ),
			'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
			'page'     => PageValidator::to_json_shape( PageStore::get_document( $post ) ?? PageValidator::empty_document() ),
		];
		$data     = $this->filter_response_by_context( $data, $request['context'] ?? 'edit' );
		$response = rest_ensure_response( $data );
		$response->add_link( 'self', rest_url( $this->namespace . '/' . $this->rest_base . '/' . $post->ID ) );
		return $response;
	}

	/**
	 * Item schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'crazylinks_page',
			'type'       => 'object',
			'properties' => [
				'id'       => [
					'type'     => 'integer',
					'context'  => [ 'view', 'edit' ],
					'readonly' => true,
				],
				'title'    => [
					'type'        => 'string',
					'description' => __( 'Internal page name.', 'crazylinks' ),
					'context'     => [ 'view', 'edit' ],
					'arg_options' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
				'slug'     => [
					'type'        => 'string',
					'description' => __( 'URL slug.', 'crazylinks' ),
					'context'     => [ 'view', 'edit' ],
					'arg_options' => [ 'sanitize_callback' => 'sanitize_title' ],
				],
				'status'   => [
					'type'    => 'string',
					'enum'    => self::STATUSES,
					'context' => [ 'view', 'edit' ],
				],
				'author'   => [
					'type'     => 'integer',
					'context'  => [ 'view', 'edit' ],
					'readonly' => true,
				],
				'link'     => [
					'type'     => 'string',
					'format'   => 'uri',
					'context'  => [ 'view', 'edit' ],
					'readonly' => true,
				],
				'date'     => [
					'type'     => 'string',
					'format'   => 'date-time',
					'context'  => [ 'view', 'edit' ],
					'readonly' => true,
				],
				'modified' => [
					'type'     => 'string',
					'format'   => 'date-time',
					'context'  => [ 'view', 'edit' ],
					'readonly' => true,
				],
				'page'     => [
					'type'        => 'object',
					'description' => __( 'Page document (schemas/page.v1.json). Validated server-side.', 'crazylinks' ),
					'context'     => [ 'edit' ],
				],
				'warnings' => [
					'type'        => 'array',
					'description' => __( 'Non-blocking validation warnings returned after create and update (for example missing alt text), each with a JSON path and message.', 'crazylinks' ),
					'context'     => [ 'edit' ],
					'readonly'    => true,
					'items'       => [ 'type' => 'object' ],
				],
			],
		];
		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Collection params.
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params() {
		$params           = parent::get_collection_params();
		$params['status'] = [
			'type'        => 'string',
			'enum'        => array_merge( self::STATUSES, [ 'trash' ] ),
			'description' => __( 'Limit to a status.', 'crazylinks' ),
		];
		unset( $params['context']['default'] );
		$params['context']['default'] = 'view';
		return $params;
	}

	/**
	 * Non-blocking warnings from the last validation in this request (e.g. missing alt text).
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $last_warnings = [];

	/**
	 * Validates the `page` param (or returns an empty document on create without one).
	 *
	 * @param \WP_REST_Request          $request  Request.
	 * @param array<string, mixed>|null $previous Stored document.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validated_document( $request, ?array $previous ) {
		$page = $request['page'];
		if ( null === $page ) {
			return PageValidator::empty_document();
		}
		$result              = ( new PageValidator() )->validate( is_array( $page ) ? $page : (string) wp_json_encode( $page ), get_current_user_id(), $previous );
		$this->last_warnings = $result->warnings();
		return $result->is_valid() ? $result->document() : $result->to_wp_error();
	}

	/**
	 * Publishing-type statuses need publish_crazylinks_pages.
	 *
	 * @param string $status Status.
	 * @return true|\WP_Error
	 */
	private function check_status_permission( string $status ) {
		if ( in_array( $status, [ 'publish', 'future', 'private' ], true ) && ! current_user_can( 'publish_crazylinks_pages' ) ) {
			return new \WP_Error( 'crazylinks_cannot_publish', __( 'You are not allowed to publish link pages.', 'crazylinks' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	/**
	 * Generic forbidden error.
	 */
	private function forbidden(): \WP_Error {
		return new \WP_Error( 'crazylinks_forbidden', __( 'Sorry, you are not allowed to do that.', 'crazylinks' ), [ 'status' => rest_authorization_required_code() ] );
	}
}
