<?php
/**
 * AI module hooks that are not abilities.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI `wp crazylinks ai` and `wp crazylinks mcp`, the "AI" badge in the page history, and log pruning on the daily cron.
 */
final class Bootstrap {

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'rest_request_after_callbacks', [ $this, 'revision_badges' ], 10, 3 );
		add_action( 'crazylinks_daily_rollup', [ AiLog::class, 'prune' ], 20 );
		add_action(
			'deleted_user',
			static function ( $user_id ): void {
				AiLog::delete_for_user( (int) $user_id );
			}
		);
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// After Plugin::boot() has added the parent `crazylinks` command.
			add_action(
				'crazylinks_loaded',
				static function (): void {
					\WP_CLI::add_command( 'crazylinks ai', \CrazyLinks\Cli\AiCommand::class );
					\WP_CLI::add_command( 'crazylinks mcp', \CrazyLinks\Cli\McpCommand::class );
				}
			);
		}
	}

	/**
	 * Adds `ai: {source, label}` to revisions created by an AI apply in
	 * GET /crazylinks/v1/pages/{id}/revisions (and the single-revision route).
	 *
	 * @param mixed            $response Response (or WP_Error).
	 * @param mixed            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function revision_badges( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $response instanceof \WP_HTTP_Response || 'GET' !== $request->get_method() || $response->get_status() >= 300 ) {
			return $response;
		}
		$route = (string) $request->get_route();
		if ( ! preg_match( '#^/crazylinks/v1/pages/\d+/revisions(/\d+)?$#', $route, $m ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		if ( ! empty( $m[1] ) ) {
			$data = self::badge( $data );
		} else {
			$data = array_map( [ self::class, 'badge' ], $data );
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Badge for one revision summary.
	 *
	 * @param mixed $item Revision summary.
	 * @return mixed
	 */
	public static function badge( $item ) {
		if ( ! is_array( $item ) || empty( $item['id'] ) ) {
			return $item;
		}
		$source     = (string) get_metadata( 'post', (int) $item['id'], Proposals::REVISION_META, true );
		$item['ai'] = '' === $source ? null : [
			'source' => $source,
			'label'  => 'mcp' === $source ? __( 'AI (external)', 'crazylinks' ) : __( 'AI', 'crazylinks' ),
		];
		return $item;
	}

	/**
	 * Registers the AI history exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function register_exporter( $exporters ) {
		$exporters['crazylinks-ai'] = [
			'exporter_friendly_name' => __( 'CrazyLinks AI requests', 'crazylinks' ),
			'callback'               => [ self::class, 'export' ],
		];
		return $exporters;
	}

	/**
	 * Registers the AI history eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function register_eraser( $erasers ) {
		$erasers['crazylinks-ai'] = [
			'eraser_friendly_name' => __( 'CrazyLinks AI requests', 'crazylinks' ),
			'callback'             => [ self::class, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * Exports the AI requests of the user with this email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user instanceof \WP_User ) {
			return [
				'data' => [],
				'done' => true,
			];
		}
		$rows = AiLog::for_user( (int) $user->ID, 100, ( max( 1, (int) $page ) - 1 ) * 100 );
		$data = [];
		foreach ( $rows as $row ) {
			$data[] = [
				'group_id'    => 'crazylinks-ai',
				'group_label' => __( 'CrazyLinks AI requests', 'crazylinks' ),
				'item_id'     => 'crazylinks-ai-' . $row['id'],
				'data'        => [
					[
						'name'  => __( 'Date', 'crazylinks' ),
						'value' => (string) $row['created_at'],
					],
					[
						'name'  => __( 'Link page', 'crazylinks' ),
						'value' => (string) $row['page_id'],
					],
					[
						'name'  => __( 'Request', 'crazylinks' ),
						'value' => (string) $row['instruction'],
					],
					[
						'name'  => __( 'Applied', 'crazylinks' ),
						'value' => $row['applied'] ? __( 'Yes', 'crazylinks' ) : __( 'No', 'crazylinks' ),
					],
				],
			];
		}
		return [
			'data' => $data,
			'done' => count( $rows ) < 100,
		];
	}

	/**
	 * Erases the AI requests of the user with this email address.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( $email ): array {
		$user    = get_user_by( 'email', (string) $email );
		$removed = $user instanceof \WP_User ? AiLog::delete_for_user( (int) $user->ID ) : 0;
		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}
}
