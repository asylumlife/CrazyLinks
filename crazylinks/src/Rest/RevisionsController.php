<?php
/**
 * Revisions and autosave REST routes.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Rest;

use CrazyLinks\PageStore;
use CrazyLinks\Schema\PageValidator;

defined( 'ABSPATH' ) || exit;

/**
 * /crazylinks/v1/pages/{id}/autosave, /revisions, /revisions/{rev}, /revisions/{rev}/restore.
 *
 * The post type keeps `show_in_rest` false, so these replace core's autosave and revisions controllers.
 * Every document is validated by PageValidator exactly like a normal save; PostType::filter_insert_data()
 * validates once more on the way into the database.
 */
final class RevisionsController extends \WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = RestRoutes::NS;
		$this->rest_base = 'pages';
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$page = '/' . $this->rest_base . '/(?P<id>[\d]+)';
		$id   = [
			'id' => [
				'description' => __( 'Page id.', 'crazylinks' ),
				'type'        => 'integer',
			],
		];
		$rev  = $id + [
			'revision' => [
				'description' => __( 'Revision id.', 'crazylinks' ),
				'type'        => 'integer',
			],
		];
		register_rest_route(
			$this->namespace,
			$page . '/autosave',
			[
				'args' => $id,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'autosave' ],
					'permission_callback' => [ $this, 'edit_permission' ],
					'args'                => [
						'page'  => [
							'type'        => 'object',
							'required'    => true,
							'description' => __( 'Working copy of the page document.', 'crazylinks' ),
						],
						'title' => [
							'type'              => 'string',
							'description'       => __( 'Working copy of the page name.', 'crazylinks' ),
							'sanitize_callback' => static fn( $value ) => sanitize_text_field( (string) $value ),
						],
					],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			$page . '/revisions',
			[
				'args' => $id,
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_revisions' ],
					'permission_callback' => [ $this, 'edit_permission' ],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			$page . '/revisions/(?P<revision>[\d]+)',
			[
				'args' => $rev,
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_revision' ],
					'permission_callback' => [ $this, 'revision_permission' ],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			$page . '/revisions/(?P<revision>[\d]+)/restore',
			[
				'args' => $rev,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'restore' ],
					'permission_callback' => [ $this, 'revision_permission' ],
				],
			]
		);
	}

	/**
	 * A revision (or autosave) of the page, or an error.
	 *
	 * @param \WP_Post $page   Page.
	 * @param int      $rev_id Revision id.
	 * @return \WP_Post|\WP_Error
	 */
	private function get_revision_post( \WP_Post $page, int $rev_id ) {
		$rev = wp_get_post_revision( $rev_id );
		if ( ! $rev instanceof \WP_Post || (int) $rev->post_parent !== (int) $page->ID ) {
			return new \WP_Error( 'crazylinks_revision_not_found', __( 'Revision not found.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		return $rev;
	}

	/**
	 * Permission: may edit this page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function edit_permission( $request ) {
		$post = RestRoutes::link_page_or_denied( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return current_user_can( 'edit_post', $post->ID ) ? true : RestRoutes::forbidden();
	}

	/**
	 * Permission: may edit the page, and the revision belongs to it. Other users' autosaves stay private.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function revision_permission( $request ) {
		$allowed = $this->edit_permission( $request );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$page = RestRoutes::link_page( (int) $request['id'] );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$rev = $this->get_revision_post( $page, (int) $request['revision'] );
		if ( is_wp_error( $rev ) ) {
			return $rev;
		}
		if ( self::is_foreign_autosave( $rev ) ) {
			return RestRoutes::forbidden();
		}
		return true;
	}

	/**
	 * Stores the working copy as the user's autosave revision. A never-saved page (auto-draft) is saved
	 * as a draft instead, so the content survives the auto-draft cleanup.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function autosave( $request ) {
		$post = RestRoutes::link_page( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$result = ( new PageValidator() )->validate( (array) $request['page'], get_current_user_id(), PageStore::get_document( $post, false ) );
		if ( ! $result->is_valid() ) {
			return $result->to_wp_error();
		}
		$doc     = $result->document();
		$content = PageStore::encode( $doc );
		$title   = isset( $request['title'] ) ? (string) $request['title'] : $post->post_title;

		if ( 'auto-draft' === $post->post_status ) {
			$saved = wp_update_post(
				wp_slash(
					[
						'ID'           => $post->ID,
						'post_title'   => $title,
						'post_content' => $content,
						'post_status'  => 'draft',
					]
				),
				true
			);
			if ( is_wp_error( $saved ) ) {
				$saved->add_data( [ 'status' => 500 ] );
				return $saved;
			}
			return $this->autosave_response( 'draft', (int) $saved, $doc, $post );
		}

		$existing = wp_get_post_autosave( $post->ID, get_current_user_id() );
		if ( $content === $post->post_content && $title === $post->post_title ) {
			// Same as the saved page: an older autosave would only be stale.
			if ( $existing instanceof \WP_Post ) {
				wp_delete_post_revision( $existing->ID );
			}
			return $this->autosave_response( 'unchanged', 0, $doc, $post );
		}

		$fields = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $post->post_excerpt,
		];
		if ( $existing instanceof \WP_Post ) {
			$rev_id = wp_update_post(
				wp_slash(
					$fields + [
						'ID'          => $existing->ID,
						'post_author' => get_current_user_id(),
					]
				),
				true
			);
		} else {
			$rev_id = _wp_put_post_revision( array_merge( $post->to_array(), $fields ), true );
		}
		if ( is_wp_error( $rev_id ) || ! $rev_id ) {
			return new \WP_Error( 'crazylinks_autosave_failed', __( 'The autosave could not be stored.', 'crazylinks' ), [ 'status' => 500 ] );
		}
		/**
		 * Fires after the editor stored an autosave revision of a link page.
		 *
		 * @param int $autosave_id Autosave revision id.
		 * @param int $page_id     Page id.
		 */
		do_action( 'crazylinks_page_autosaved', (int) $rev_id, (int) $post->ID );
		return $this->autosave_response( 'autosave', (int) $rev_id, $doc, $post );
	}

	/**
	 * Autosave response.
	 *
	 * @param string               $mode autosave | draft | unchanged.
	 * @param int                  $id   Autosave revision id, the page id for `draft`, 0 for `unchanged`.
	 * @param array<string, mixed> $doc  Normalized document.
	 * @param \WP_Post             $page The page, whose modified time is reported when nothing was stored.
	 */
	private function autosave_response( string $mode, int $id, array $doc, \WP_Post $page ): \WP_REST_Response {
		$saved = $id > 0 ? get_post( $id ) : null;
		$saved = $saved instanceof \WP_Post ? $saved : $page;
		return rest_ensure_response(
			[
				'mode'        => $mode,
				'autosave_id' => 'autosave' === $mode ? $id : 0,
				'modified'    => mysql_to_rfc3339( $saved->post_modified_gmt ),
				'page'        => PageValidator::to_json_shape( $doc ),
			]
		);
	}

	/**
	 * Lists revisions and autosaves, newest first.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_revisions( $request ) {
		$post = RestRoutes::link_page( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$items = [];
		foreach ( wp_get_post_revisions( $post->ID, [ 'order' => 'DESC' ] ) as $rev ) {
			if ( self::is_foreign_autosave( $rev ) ) {
				continue;
			}
			$items[] = $this->summary( $rev );
		}
		return rest_ensure_response( $items );
	}

	/**
	 * One revision with its document.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_revision( $request ) {
		$page = RestRoutes::link_page( (int) $request['id'] );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$rev = $this->get_revision_post( $page, (int) $request['revision'] );
		if ( is_wp_error( $rev ) ) {
			return $rev;
		}
		$doc = PageStore::get_document( $rev ) ?? PageValidator::empty_document();
		return rest_ensure_response(
			$this->summary( $rev ) + [
				'parent' => (int) $page->ID,
				'page'   => PageValidator::to_json_shape( $doc ),
			]
		);
	}

	/**
	 * Restores a revision onto the page. Core keeps the replaced content as a new revision.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore( $request ) {
		$page = RestRoutes::link_page( (int) $request['id'] );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$rev = $this->get_revision_post( $page, (int) $request['revision'] );
		if ( is_wp_error( $rev ) ) {
			return $rev;
		}
		// Validate first so a capability gate (custom HTML, custom CSS) fails loudly instead of being
		// silently replaced by the current document in PostType::filter_insert_data().
		$result = ( new PageValidator() )->validate( $rev->post_content, get_current_user_id(), PageStore::get_document( $page, false ) );
		if ( ! $result->is_valid() ) {
			return $result->to_wp_error();
		}
		$restored = wp_restore_post_revision( $rev->ID );
		if ( ! $restored ) {
			return new \WP_Error( 'crazylinks_restore_failed', __( 'The revision could not be restored.', 'crazylinks' ), [ 'status' => 500 ] );
		}
		/**
		 * Fires after the editor restored a revision of a link page.
		 *
		 * @param int $page_id     Page id.
		 * @param int $revision_id Restored revision id.
		 */
		do_action( 'crazylinks_revision_restored', (int) $page->ID, (int) $rev->ID );

		$response = ( new PagesController() )->prepare_item_for_response( get_post( $page->ID ), $request );
		$data     = $response->get_data();
		if ( is_array( $data ) ) {
			$data['restored_from'] = (int) $rev->ID;
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * List entry for a revision.
	 *
	 * @param \WP_Post $rev Revision.
	 * @return array<string, mixed>
	 */
	private function summary( \WP_Post $rev ): array {
		$doc    = PageStore::get_document( $rev, false );
		$author = get_userdata( (int) $rev->post_author );
		return [
			'id'       => (int) $rev->ID,
			'date'     => mysql_to_rfc3339( $rev->post_modified_gmt ),
			'author'   => [
				'id'   => (int) $rev->post_author,
				'name' => $author instanceof \WP_User ? $author->display_name : '',
			],
			'autosave' => (bool) wp_is_post_autosave( $rev ),
			'title'    => $rev->post_title,
			'blocks'   => is_array( $doc ) ? count( (array) ( $doc['blocks'] ?? [] ) ) : 0,
		];
	}

	/**
	 * Whether a revision is another user's autosave that the current user may not see.
	 *
	 * @param \WP_Post $rev Revision.
	 */
	private static function is_foreign_autosave( \WP_Post $rev ): bool {
		return wp_is_post_autosave( $rev ) && get_current_user_id() !== (int) $rev->post_author && ! current_user_can( 'edit_others_crazylinks_pages' );
	}
}
