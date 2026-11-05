<?php
/**
 * Subscriber table access.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers;

defined( 'ABSPATH' ) || exit;

// Table names come from $wpdb->prefix and the SELECT column list is a constant; every value is bound with prepare().
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

/**
 * Reads and writes {prefix}crazylinks_subscribers. Stores email, status, the consent text snapshot,
 * timestamps, page and block ids and a hash of the one-time token. Never an IP address or user agent.
 */
final class Store {

	public const STATUSES = [ 'pending', 'confirmed', 'unsubscribed' ];

	/**
	 * Columns that are safe to hand to hooks, REST and exports (everything but the token hash).
	 */
	private const PUBLIC_COLUMNS = 'id, page_id, block_id, email, status, consent_text, consent_at, confirmed_at, created_at, updated_at';

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'crazylinks_subscribers';
	}

	/**
	 * Normalizes an address: trimmed, lowercased, valid and at most 190 characters. '' when unusable.
	 *
	 * @param string $email Raw email.
	 */
	public static function normalize_email( string $email ): string {
		$email = strtolower( trim( sanitize_email( $email ) ) );
		return ( '' !== $email && strlen( $email ) <= 190 && is_email( $email ) ) ? $email : '';
	}

	/**
	 * A fresh random token (192 bits, hex).
	 */
	public static function new_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	/**
	 * What is stored for a token.
	 *
	 * @param string $token Token.
	 */
	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Current UTC time in MySQL format.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * One row by id (without the token hash).
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The row for an address on a page (without the token hash).
	 *
	 * @param int    $page_id Page id.
	 * @param string $email   Normalized email.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $page_id, string $email ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . self::table() . ' WHERE page_id = %d AND email = %s', $page_id, $email ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The row a token belongs to (without the token hash).
	 *
	 * @param string $token Token from an email link.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_token( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . self::table() . ' WHERE token_hash = %s', self::hash_token( $token ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Inserts a row. Returns its id, or 0 when it already exists or the insert failed.
	 *
	 * @param array<string, mixed> $data page_id, block_id, email, status, consent_text, consent_at, token.
	 */
	public static function insert( array $data ): int {
		global $wpdb;
		$now = self::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
		$ok = $wpdb->insert(
			self::table(),
			[
				'page_id'      => (int) $data['page_id'],
				'block_id'     => (string) $data['block_id'],
				'email'        => (string) $data['email'],
				'status'       => (string) $data['status'],
				'consent_text' => (string) $data['consent_text'],
				'consent_at'   => $now,
				'confirmed_at' => 'confirmed' === $data['status'] ? $now : null,
				'token_hash'   => self::hash_token( (string) $data['token'] ),
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates columns on a row and touches updated_at.
	 *
	 * @param int                  $id   Id.
	 * @param array<string, mixed> $data Columns: status, consent_text, consent_at, confirmed_at, block_id, token.
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$set = [ 'updated_at' => self::now() ];
		foreach ( [ 'status', 'consent_text', 'consent_at', 'confirmed_at', 'block_id' ] as $col ) {
			if ( array_key_exists( $col, $data ) ) {
				$set[ $col ] = $data[ $col ];
			}
		}
		if ( isset( $data['token'] ) ) {
			$set['token_hash'] = self::hash_token( (string) $data['token'] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		return false !== $wpdb->update( self::table(), $set, [ 'id' => $id ] );
	}

	/**
	 * Deletes rows by id.
	 *
	 * @param int[] $ids Ids.
	 * @return int Rows deleted.
	 */
	public static function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE id IN ({$in})", $ids ) );
	}

	/**
	 * Deletes every row for an address (privacy eraser).
	 *
	 * @param string $email Email, any case.
	 * @return int Rows deleted.
	 */
	public static function delete_by_email( string $email ): int {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE email = %s', $email ) );
	}

	/**
	 * Builds the WHERE clause shared by query(), count and export.
	 *
	 * @param array<string, mixed> $args page_id, status, search.
	 * @return array{0:string, 1:array<int, mixed>}
	 */
	private static function where( array $args ): array {
		global $wpdb;
		$where  = [ '1=1' ];
		$params = [];
		if ( ! empty( $args['page_id'] ) ) {
			$where[]  = 'page_id = %d';
			$params[] = (int) $args['page_id'];
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$where[]  = 'email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( strtolower( trim( (string) $args['search'] ) ) ) . '%';
		}
		return [ implode( ' AND ', $where ), $params ];
	}

	/**
	 * A page of rows.
	 *
	 * @param array<string, mixed> $args page_id, status, search, orderby (email|status|created_at|consent_at|page_id), order, per_page, paged.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;
		[ $where, $params ] = self::where( $args );
		$orderby            = in_array( $args['orderby'] ?? '', [ 'email', 'status', 'created_at', 'consent_at', 'page_id', 'id' ], true ) ? (string) $args['orderby'] : 'created_at';
		$order              = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$per_page           = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset             = max( 0, ( (int) ( $args['paged'] ?? 1 ) - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var(
			$params
			? $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}", $params )
			: 'SELECT COUNT(*) FROM ' . self::table()
		);
		$items = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . self::table() . " WHERE {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
				array_merge( $params, [ $per_page, $offset ] )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Every matching row in id order, in batches (for streaming exports).
	 *
	 * @param array<string, mixed> $args page_id, status, search.
	 * @return \Generator<int, array<string, mixed>>
	 */
	public static function each( array $args = [] ): \Generator {
		global $wpdb;
		[ $where, $params ] = self::where( $args );
		$last               = 0;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . self::table() . " WHERE {$where} AND id > %d ORDER BY id ASC LIMIT 500",
					array_merge( $params, [ $last ] )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				yield $row;
			}
			$more = 500 === count( $rows );
		} while ( $more );
	}

	/**
	 * Rows per status.
	 *
	 * @param int $page_id Optional page filter.
	 * @return array<string, int>
	 */
	public static function counts( int $page_id = 0 ): array {
		global $wpdb;
		$out = array_fill_keys( self::STATUSES, 0 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$sql = 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ( $page_id > 0 ? $wpdb->prepare( ' WHERE page_id = %d', $page_id ) : '' ) . ' GROUP BY status';
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $out;
	}

	/**
	 * Deletes sign-ups that were never confirmed and people who unsubscribed, once they have not
	 * changed for the retention period. Confirmed subscribers are never removed automatically.
	 *
	 * @param int $days Days (0 = keep everything).
	 * @return int Rows deleted.
	 */
	public static function purge( int $days ): int {
		global $wpdb;
		if ( $days < 1 ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE status IN ('pending', 'unsubscribed') AND updated_at < %s", $cutoff ) );
	}
}
