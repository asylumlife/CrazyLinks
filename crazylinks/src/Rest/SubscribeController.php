<?php
/**
 * Email capture endpoint.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Rest;

use CrazyLinks\Analytics\RateLimiter;
use CrazyLinks\Analytics\Visitor;
use CrazyLinks\PageStore;
use CrazyLinks\Subscribers\Service;
use CrazyLinks\Subscribers\Store;

defined( 'ABSPATH' ) || exit;

/**
 * POST /crazylinks/v1/subscribe, GET /subscribe/confirm?token=, GET /subscribe/unsubscribe?token=.
 *
 * SPEC §12: honeypot, per-IP rate limit (HMAC of the IP, never UA-keyed), the same visibility,
 * schedule and password checks as /go/, one generic not-found error, double opt-in on by default,
 * and no IP or user agent stored with the subscriber. The response never says whether the address
 * was already on the list.
 *
 * A JSON request gets JSON. A plain HTML form post (no JavaScript) gets a 303 back to the page
 * with `?cl_sub=<key>#<message>`.
 */
final class SubscribeController extends \WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = RestRoutes::NS;
		$this->rest_base = 'subscribe';
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
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => '__return_true', // Public form endpoint; protected by honeypot and rate limit.
					'args'                => [
						'page_id'  => [
							'type'     => 'integer',
							'required' => true,
						],
						'block_id' => [
							'type'     => 'string',
							'required' => true,
						],
						'email'    => [
							'type'     => 'string',
							'required' => true,
						],
						'website'  => [
							'type'    => 'string',
							'default' => '',
						],
						'consent'  => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
			]
		);
		foreach ( [
			'confirm'     => 'confirm',
			'unsubscribe' => 'unsubscribe',
		] as $path => $method ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/' . $path,
				[
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => [ $this, $method ],
						'permission_callback' => '__return_true', // The emailed token is the credential.
						'args'                => [
							'token' => [
								'type'     => 'string',
								'required' => true,
							],
						],
					],
				]
			);
		}
	}

	/**
	 * Handles a submission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		if ( '' !== (string) $request['website'] ) {
			return rest_ensure_response( [ 'ok' => true ] ); // Honeypot: pretend success.
		}
		if ( ! RateLimiter::hit( 'subscribe', Visitor::from_request()->abuse_key(), self::rate_limit(), HOUR_IN_SECONDS ) ) {
			return self::error( 'limited', 429 );
		}
		$page_id  = (int) $request['page_id'];
		$block_id = (string) $request['block_id'];
		$page     = get_post( $page_id );
		$block    = PageStore::reachable_block( $page, $block_id, [ 'email_capture' ] );
		if ( ! $block || ! $page instanceof \WP_Post ) {
			// One answer for a missing, unpublished, protected, hidden or unscheduled page or form.
			return new \WP_Error( 'crazylinks_not_found', __( 'This form is not available.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		$form = self::is_form_post( $request );
		if ( ! empty( $block['props']['consent_text'] ) && ! $request['consent'] ) {
			return self::error( 'consent', 400, $form ? self::back( $page_id, $block_id, 'consent' ) : null );
		}
		$email = Store::normalize_email( (string) $request['email'] );
		if ( '' === $email ) {
			return self::error( 'invalid', 400, $form ? self::back( $page_id, $block_id, 'invalid' ) : null );
		}

		$status = Service::subscribe( $page, $block, $email );
		if ( is_wp_error( $status ) ) {
			return self::error( 'error', 400, $form ? self::back( $page_id, $block_id, 'error' ) : null );
		}
		if ( $form ) {
			return self::redirect( self::back( $page_id, $block_id, $status ) );
		}
		return rest_ensure_response(
			[
				'ok'      => true,
				'status'  => $status,
				'message' => Service::messages_for( (array) ( $block['props'] ?? [] ) )[ $status ],
			]
		);
	}

	/**
	 * GET /subscribe/confirm?token=…
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function confirm( $request ) {
		$out = Service::confirm( (string) $request['token'] );
		$row = $out['row'];
		if ( ! $row ) {
			return new \WP_Error( 'crazylinks_invalid_link', __( 'This link is not valid.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		$page_id  = (int) $row['page_id'];
		$block_id = (string) $row['block_id'];
		if ( 'expired' === $out['result'] ) {
			return self::redirect( Service::return_url( $page_id, $block_id, 'expired', [ 'subscribed' => 'expired' ] ) );
		}
		return self::redirect( Service::return_url( $page_id, $block_id, 'done', [ 'subscribed' => '1' ] ) );
	}

	/**
	 * GET /subscribe/unsubscribe?token=…
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function unsubscribe( $request ) {
		$row = Service::unsubscribe( (string) $request['token'] );
		if ( ! $row ) {
			return new \WP_Error( 'crazylinks_invalid_link', __( 'This link is not valid.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		return self::redirect( Service::return_url( (int) $row['page_id'], (string) $row['block_id'], 'unsub', [ 'unsubscribed' => '1' ] ) );
	}

	/**
	 * Whether the request is a plain HTML form post rather than JSON.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function is_form_post( \WP_REST_Request $request ): bool {
		$type = $request->get_content_type();
		return is_array( $type ) && in_array( $type['value'], [ 'application/x-www-form-urlencoded', 'multipart/form-data' ], true );
	}

	/**
	 * Return URL for a form post.
	 *
	 * @param int    $page_id  Page id.
	 * @param string $block_id Block id.
	 * @param string $key      Message key.
	 */
	private static function back( int $page_id, string $block_id, string $key ): string {
		return Service::return_url( $page_id, $block_id, $key );
	}

	/**
	 * A 303 redirect response.
	 *
	 * @param string $url Target (always built from a stored page permalink).
	 */
	private static function redirect( string $url ): \WP_REST_Response {
		return new \WP_REST_Response(
			null,
			303,
			[
				'Location'     => $url,
				'X-Robots-Tag' => 'noindex',
			]
		);
	}

	/**
	 * A visitor-facing error, or a redirect for form posts.
	 *
	 * @param string      $key    Message key.
	 * @param int         $status HTTP status.
	 * @param string|null $url    Redirect target for form posts.
	 * @return \WP_Error|\WP_REST_Response
	 */
	private static function error( string $key, int $status, ?string $url = null ) {
		if ( null !== $url ) {
			return self::redirect( $url );
		}
		return new \WP_Error(
			'crazylinks_' . $key,
			Service::messages()[ $key ],
			[
				'status' => $status,
				'key'    => $key,
			]
		);
	}

	/**
	 * Submissions allowed per IP per hour.
	 */
	public static function rate_limit(): int {
		/**
		 * Filters how many sign-up attempts one IP may make per hour.
		 *
		 * @param int $limit Default 5.
		 */
		return max( 1, (int) apply_filters( 'crazylinks_subscribe_rate_limit', 5 ) );
	}
}
