<?php
/**
 * REST route registration.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Rest;

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
			\CrazyLinks\Frontend\TemplateLoader::no_page_cache(); // Subscribe, confirm and unsubscribe must never be stored by page caches.
		}
		return $response;
	}
}
