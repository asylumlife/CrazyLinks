<?php
/**
 * Read-only analytics summary.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Rest;

use CrazyLinks\Analytics\Summary;
use CrazyLinks\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * GET /crazylinks/v1/analytics/summary?page_id=123&range=7d|30d|90d (or from=&to=, or the older days=)
 */
final class AnalyticsController extends \WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = RestRoutes::NS;
		$this->rest_base = 'analytics/summary';
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
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [
						'page_id' => [
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						],
						'range'   => [
							'type' => 'string',
							'enum' => [ '7d', '30d', '90d' ],
						],
						'from'    => [
							'type'    => 'string',
							'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
						],
						'to'      => [
							'type'    => 'string',
							'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
						],
						'days'    => [
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => Summary::MAX_DAYS,
							'description' => 'Older alias for range; ignored when range or from is given.',
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * Needs view_crazylinks_analytics and edit rights on the page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$post = get_post( (int) $request['page_id'] );
		if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type ) {
			return new \WP_Error( 'crazylinks_not_found', __( 'Link page not found.', 'crazylinks' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'view_crazylinks_analytics' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'crazylinks_forbidden', __( 'Sorry, you are not allowed to view analytics for this page.', 'crazylinks' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	/**
	 * Summary.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$range = Summary::resolve_range(
			isset( $request['range'] ) ? (string) $request['range'] : null,
			isset( $request['from'] ) ? (string) $request['from'] : null,
			isset( $request['to'] ) ? (string) $request['to'] : null,
			isset( $request['days'] ) ? (int) $request['days'] : null
		);
		if ( is_wp_error( $range ) ) {
			return $range;
		}
		$report = Summary::report( (int) $request['page_id'], $range['from'], $range['to'] );
		// Older top-level fields stay so existing callers keep working.
		$report['page_id'] = (int) $request['page_id'];
		$report['views']   = $report['totals']['views'];
		$report['uniques'] = $report['totals']['uniques'];
		$report['clicks']  = $report['totals']['clicks'];
		$report['ctr']     = $report['totals']['views'] > 0 ? $report['totals']['ctr'] : null;
		$report['daily']   = array_map(
			static fn( $d ) => [
				'day'    => $d['date'],
				'views'  => $d['views'],
				'clicks' => $d['clicks'],
			],
			$report['timeseries']
		);
		return rest_ensure_response( $report );
	}

	/**
	 * Schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		$int = [
			'type'     => 'integer',
			'readonly' => true,
		];
		$arr = [
			'type'     => 'array',
			'readonly' => true,
		];
		$obj = [
			'type'     => 'object',
			'readonly' => true,
		];
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'crazylinks_analytics_summary',
			'type'       => 'object',
			'properties' => [
				'totals'            => $obj,
				'timeseries'        => $arr,
				'blocks'            => $arr,
				'breakdowns'        => $obj,
				'country_available' => [
					'type'     => 'boolean',
					'readonly' => true,
				],
				'range'             => $obj,
				'page_id'           => $int,
				'views'             => $int,
				'uniques'           => $int,
				'clicks'            => $int,
				'ctr'               => [
					'type'     => [ 'number', 'null' ],
					'readonly' => true,
				],
				'daily'             => $arr,
			],
		];
	}
}
