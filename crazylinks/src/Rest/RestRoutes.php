<?php
/**
 * REST route registration.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Rest;

use CrazyLinks\Frontend\TemplateLoader;
use CrazyLinks\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every controller under crazylinks/v1 and marks responses uncacheable.
 */
final class RestRoutes {

	public const NS = 'crazylinks/v1';

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register' ] );
		add_filter( 'rest_post_dispatch', [ $this, 'no_store' ], 10, 3 );
	}

	/**
	 * Registers routes.
	 */
	public function register(): void {
		( new PagesController() )->register_routes();
		( new PreviewController() )->register_routes();
		( new AnalyticsController() )->register_routes();
		( new SubscribeController() )->register_routes();
		( new RevisionsController() )->register_routes();
		( new SubscribersController() )->register_routes();
		( new AiController() )->register_routes();
		( new StoreController() )->register_routes();
		( new IntegrationsController() )->register_routes();
	}

	/**
	 * Cache-Control: no-store and DONOTCACHEPAGE on our routes (SPEC §6.2).
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public function no_store( $response, $server, $request ) {
		unset( $server );
		if ( $response instanceof \WP_HTTP_Response && str_starts_with( (string) $request->get_route(), '/' . self::NS ) ) {
			$response->header( 'Cache-Control', 'no-store' );
			TemplateLoader::no_page_cache(); // Page caches must never store an API answer (subscribe, confirm, unsubscribe).
		}
		return $response;
	}

	/**
	 * The error for a caller who may not use a route (401 logged out, 403 otherwise).
	 */
	public static function forbidden(): \WP_Error {
		return new \WP_Error( 'crazylinks_forbidden', __( 'Sorry, you are not allowed to do that.', 'crazylinks' ), [ 'status' => rest_authorization_required_code() ] );
	}

	/**
	 * The link page with this id, or a 404 error.
	 *
	 * @param int $id Post id.
	 * @return \WP_Post|\WP_Error
	 */
	public static function link_page( int $id ) {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type ) {
			return new \WP_Error( 'crazylinks_not_found', __( 'Link page not found.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		return $post;
	}

	/**
	 * The link page for a permission callback. Only users who may edit link pages learn that an id
	 * does not exist (404); everyone else gets the forbidden error, so ids cannot be probed.
	 *
	 * @param int $id Post id.
	 * @return \WP_Post|\WP_Error
	 */
	public static function link_page_or_denied( int $id ) {
		$post = self::link_page( $id );
		if ( is_wp_error( $post ) && ! current_user_can( 'edit_crazylinks_pages' ) ) {
			return self::forbidden();
		}
		return $post;
	}
}
