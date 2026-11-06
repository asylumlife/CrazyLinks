<?php
/**
 * Personal-data exporter and eraser for subscribers.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; values bound with prepare().

/**
 * Hooks into Tools → Export/Erase Personal Data, keyed by email address.
 */
final class PersonalData {

	private const PER_PAGE = 50;

	/**
	 * Registers the callbacks.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function register_exporter( $exporters ): array {
		$exporters                           = (array) $exporters;
		$exporters['crazylinks-subscribers'] = [
			'exporter_friendly_name' => __( 'CrazyLinks email sign-ups', 'crazylinks' ),
			'callback'               => [ $this, 'export' ],
		];
		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function register_eraser( $erasers ): array {
		$erasers                           = (array) $erasers;
		$erasers['crazylinks-subscribers'] = [
			'eraser_friendly_name' => __( 'CrazyLinks email sign-ups', 'crazylinks' ),
			'callback'             => [ $this, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * Exports every sign-up row for the address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  1-based batch.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$email = strtolower( trim( (string) $email ) );
		$page  = max( 1, (int) $page );
		$items = [];
		foreach ( self::rows_for_email( $email, $page ) as $row ) {
			$items[] = [
				'group_id'          => 'crazylinks-subscribers',
				'group_label'       => __( 'CrazyLinks email sign-ups', 'crazylinks' ),
				'group_description' => __( 'Addresses submitted through CrazyLinks email sign-up forms.', 'crazylinks' ),
				'item_id'           => 'crazylinks-subscriber-' . $row['id'],
				'data'              => [
					[
						'name'  => __( 'Email', 'crazylinks' ),
						'value' => (string) $row['email'],
					],
					[
						'name'  => __( 'Page', 'crazylinks' ),
						'value' => (string) get_the_title( (int) $row['page_id'] ),
					],
					[
						'name'  => __( 'Status', 'crazylinks' ),
						'value' => (string) $row['status'],
					],
					[
						'name'  => __( 'Consent text', 'crazylinks' ),
						'value' => (string) $row['consent_text'],
					],
					[
						'name'  => __( 'Consent given (UTC)', 'crazylinks' ),
						'value' => (string) $row['consent_at'],
					],
					[
						'name'  => __( 'Confirmed (UTC)', 'crazylinks' ),
						'value' => (string) $row['confirmed_at'],
					],
					[
						'name'  => __( 'Signed up (UTC)', 'crazylinks' ),
						'value' => (string) $row['created_at'],
					],
				],
			];
		}
		return [
			'data' => $items,
			'done' => count( $items ) < self::PER_PAGE,
		];
	}

	/**
	 * Rows for one address, one batch.
	 *
	 * @param string $email Lowercased email.
	 * @param int    $page  1-based batch.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows_for_email( string $email, int $page ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; privacy export must be live.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, page_id, block_id, email, status, consent_text, consent_at, confirmed_at, created_at, updated_at FROM %i WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d', Store::table(), $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ), ARRAY_A );
	}

	/**
	 * Erases every sign-up row for the address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Batch (unused: one delete removes everything).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$removed = Store::delete_by_email( (string) $email );
		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}
}
