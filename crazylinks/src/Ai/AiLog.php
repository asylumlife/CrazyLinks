<?php
/**
 * The crazylinks_ai_log table.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One row per proposal or suggestion; apply marks the row applied and records the revision.
 *
 * Holds what the user typed and the operations the model returned (both off when the `ai_log` setting
 * is off), the model, token counts and the source (admin editor or an external agent via MCP). Never
 * holds page content beyond the operations, provider keys or visitor data. Rows older than the
 * retention setting are pruned by the daily cron.
 */
final class AiLog {

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'crazylinks_ai_log';
	}

	/**
	 * Inserts a row. Returns its id (0 on failure).
	 *
	 * @param array<string, mixed> $row Columns.
	 */
	public static function insert( array $row ): int {
		global $wpdb;
		$keep = (bool) Settings::get( 'ai_log' );
		$data = [
			'page_id'           => (int) ( $row['page_id'] ?? 0 ),
			'user_id'           => (int) ( $row['user_id'] ?? 0 ),
			'kind'              => substr( (string) ( $row['kind'] ?? 'propose' ), 0, 16 ),
			'mode'              => substr( (string) ( $row['mode'] ?? '' ), 0, 32 ),
			'source'            => 'mcp' === ( $row['source'] ?? '' ) ? 'mcp' : 'admin',
			'instruction'       => $keep ? mb_substr( (string) ( $row['instruction'] ?? '' ), 0, 4000 ) : '',
			'summary'           => $keep ? mb_substr( (string) ( $row['summary'] ?? '' ), 0, 2000 ) : '',
			'operations'        => $keep ? (string) wp_json_encode( $row['operations'] ?? [] ) : '[]',
			'applied'           => 0,
			'provider'          => substr( (string) ( $row['provider'] ?? '' ), 0, 64 ),
			'model'             => substr( (string) ( $row['model'] ?? '' ), 0, 100 ),
			'prompt_tokens'     => max( 0, (int) ( $row['prompt_tokens'] ?? 0 ) ),
			'completion_tokens' => max( 0, (int) ( $row['completion_tokens'] ?? 0 ) ),
			'tokens_estimated'  => empty( $row['tokens_estimated'] ) ? 0 : 1,
			'created_at'        => current_time( 'mysql', true ),
		];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin table.
		$ok = $wpdb->insert( self::table(), $data, [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s' ] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Marks a row applied.
	 *
	 * @param int                              $id          Row id.
	 * @param int|null                         $revision_id Revision created by the save.
	 * @param string                           $source      admin | mcp.
	 * @param array<int, array<string, mixed>> $operations  Operations actually applied.
	 */
	public static function mark_applied( int $id, ?int $revision_id, string $source, array $operations ): void {
		global $wpdb;
		if ( $id <= 0 ) {
			return;
		}
		$data = [
			'applied'     => 1,
			'revision_id' => $revision_id,
			'source'      => 'mcp' === $source ? 'mcp' : 'admin',
			'applied_at'  => current_time( 'mysql', true ),
		];
		if ( Settings::get( 'ai_log' ) ) {
			$data['operations'] = (string) wp_json_encode( $operations );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table.
		$wpdb->update( self::table(), $data, [ 'id' => $id ] );
	}

	/**
	 * One row.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Logged proposals and suggestions since a time, as shown on the status screen. Operations sent
	 * by external agents and failed calls that used no tokens are not in this count, so it can be
	 * lower than what the rate limiter has counted.
	 *
	 * @param int      $since   Unix time.
	 * @param int|null $user_id Limit to one user (null for the whole site).
	 */
	public static function count_since( int $since, ?int $user_id = null ): int {
		global $wpdb;
		$from = gmdate( 'Y-m-d H:i:s', $since );
		$user = (int) $user_id; // 0 switches the user condition off.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table; status counts must be live.
		$n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE created_at >= %s AND kind IN ('propose','suggest') AND ( 0 = %d OR user_id = %d )", self::table(), $from, $user, $user ) );
		return (int) $n;
	}

	/**
	 * Deletes rows older than the retention setting (0 keeps them).
	 */
	public static function prune(): void {
		global $wpdb;
		$days = (int) Settings::get( 'ai_log_retention_days' );
		if ( $days <= 0 ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table write.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Deletes every row of one user (personal-data eraser support and test cleanup).
	 *
	 * @param int $user_id User id.
	 */
	public static function delete_for_user( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table.
		return (int) $wpdb->delete( self::table(), [ 'user_id' => $user_id ], [ '%d' ] );
	}

	/**
	 * Rows of one user, newest first (personal-data export).
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows.
	 * @param int $offset  Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_user( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table; privacy export must be live.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, page_id, instruction, applied, created_at FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), $user_id, $limit, $offset ), ARRAY_A );
	}
}
