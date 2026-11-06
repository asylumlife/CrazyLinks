<?php
/**
 * Email service routes: audiences for the admin, Mailchimp's webhook.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Rest;

use CrazyLinks\Analytics\RateLimiter;
use CrazyLinks\Analytics\Visitor;
use CrazyLinks\Subscribers\Adapters;
use CrazyLinks\Subscribers\Adapters\Mailchimp;
use CrazyLinks\Subscribers\Integrations;
use CrazyLinks\Subscribers\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * GET /crazylinks/v1/integrations/audiences (manage_crazylinks): the Mailchimp audiences for the
 * saved key, cached 10 minutes. Never returns the key.
 *
 * GET|POST /crazylinks/v1/integrations/mailchimp/webhook?key=<secret> (public, no cookie auth):
 * GET answers 200 for Mailchimp's URL check; POST needs the stored secret (hash_equals), is
 * rate-limited per IP (HMAC of the IP) and updates matching local rows. It logs nothing.
 */
final class IntegrationsController {

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			RestRoutes::NS,
			'/integrations/audiences',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'audiences' ],
				'permission_callback' => [ $this, 'admin_check' ],
				'args'                => [
					'refresh' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
		register_rest_route(
			RestRoutes::NS,
			'/integrations/mailchimp/webhook',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'webhook_check' ],
					'permission_callback' => '__return_true', // Mailchimp's URL check; reveals nothing.
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'webhook' ],
					'permission_callback' => '__return_true', // The shared secret in the URL is the credential (checked in the callback).
				],
			]
		);
	}

	/**
	 * Needs manage_crazylinks (401 logged out, 403 otherwise).
	 *
	 * @return true|\WP_Error
	 */
	public function admin_check() {
		if ( current_user_can( 'manage_crazylinks' ) ) {
			return true;
		}
		return new \WP_Error( 'crazylinks_forbidden', __( 'Sorry, you are not allowed to do that.', 'crazylinks' ), [ 'status' => rest_authorization_required_code() ] );
	}

	/**
	 * Audiences.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function audiences( $request ) {
		$connected = Integrations::mailchimp_connected();
		$list      = $connected ? Integrations::audiences( (bool) $request['refresh'] ) : [];
		return rest_ensure_response(
			[
				'adapter'   => Integrations::adapter_id(),
				'available' => $connected && ! is_wp_error( $list ),
				'default'   => Integrations::default_audience(),
				'audiences' => is_wp_error( $list ) ? [] : $list,
				'error'     => is_wp_error( $list ) ? $list->get_error_message() : ( $connected ? '' : __( 'Mailchimp is not connected. Add the API key under CrazyLinks → Settings → Integrations.', 'crazylinks' ) ),
			]
		);
	}

	/**
	 * GET: Mailchimp checks that the URL answers before it saves a webhook.
	 *
	 * @return \WP_REST_Response
	 */
	public function webhook_check() {
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/**
	 * POST: a Mailchimp event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function webhook( $request ) {
		$ip = Visitor::from_request()->abuse_key();
		if ( ! RateLimiter::hit( 'mc_webhook', $ip, self::rate_limit(), MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'crazylinks_limited', __( 'Too many requests.', 'crazylinks' ), [ 'status' => 429 ] );
		}
		$given  = $request->get_param( 'key' );
		$secret = (string) get_option( Integrations::SECRET_OPTION, '' );
		if ( '' === $secret || ! is_string( $given ) || ! hash_equals( $secret, $given ) ) {
			// Guessing is slowed further: 10 wrong keys per IP per hour.
			if ( ! RateLimiter::hit( 'mc_webhook_bad', $ip, 10, HOUR_IN_SECONDS ) ) {
				return new \WP_Error( 'crazylinks_limited', __( 'Too many requests.', 'crazylinks' ), [ 'status' => 429 ] );
			}
			return new \WP_Error( 'crazylinks_forbidden', __( 'Sorry, you are not allowed to do that.', 'crazylinks' ), [ 'status' => 403 ] );
		}
		$adapter = Adapters::get( Mailchimp::ID );
		if ( ! $adapter ) {
			return rest_ensure_response( [ 'ok' => true ] );
		}
		$event = $adapter->webhook( $request );
		if ( is_wp_error( $event ) ) {
			// Malformed or a non-audience event: acknowledge so Mailchimp does not retry it.
			return rest_ensure_response( [ 'ok' => true ] );
		}
		Sync::apply_webhook( $event );
		return rest_ensure_response( [ 'ok' => true ] ); // Same answer whether or not the address is known.
	}

	/**
	 * Webhook deliveries allowed per IP per minute (Mailchimp sends bursts for bulk changes).
	 */
	public static function rate_limit(): int {
		/**
		 * Filters how many webhook deliveries one IP may make per minute.
		 *
		 * @param int $limit Default 120.
		 */
		return max( 1, (int) apply_filters( 'crazylinks_webhook_rate_limit', 120 ) );
	}
}
