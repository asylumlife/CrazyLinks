<?php
/**
 * Personal-data exporter and eraser for subscribers.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into Tools → Export/Erase Personal Data, keyed by email address.
 */
final class PersonalData {

	private const PER_PAGE = 50;
	private const GROUP_ID = 'crazylinks-subscribers';

	/**
	 * Registers the callbacks.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	/**
	 * Name of the data group in the privacy tools.
	 */
	private static function group_label(): string {
		return __( 'CrazyLinks email sign-ups', 'crazylinks' );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function register_exporter( $exporters ): array {
		$exporters                   = (array) $exporters;
		$exporters[ self::GROUP_ID ] = [
			'exporter_friendly_name' => self::group_label(),
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
		$erasers                   = (array) $erasers;
		$erasers[ self::GROUP_ID ] = [
			'eraser_friendly_name' => self::group_label(),
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
				'group_id'          => self::GROUP_ID,
				'group_label'       => self::group_label(),
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
					[
						'name'  => __( 'Destination', 'crazylinks' ),
						'value' => Adapters\Mailchimp::ID === $row['destination'] ? 'Mailchimp' : __( 'This site only', 'crazylinks' ),
					],
					[
						'name'  => __( 'Sent to email service', 'crazylinks' ),
						'value' => '' === (string) $row['sync_status'] ? __( 'No', 'crazylinks' ) : (string) $row['sync_status'] . ( $row['synced_at'] ? ' (' . (string) $row['synced_at'] . ' UTC)' : '' ),
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; privacy export must be live. The column list is a constant and every value is bound.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ' . Store::COLUMNS . ' FROM %i WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d', Store::table(), $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ), ARRAY_A );
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
		$email    = (string) $email;
		$messages = [];
		$retained = false;
		$seen     = [];
		// Also remove the address from every email service audience it was sent to.
		foreach ( Store::synced_for_email( $email ) as $row ) {
			$adapter  = Adapters::get( Sync::service_for( $row ) ) ?? Adapters::get( Adapters\Mailchimp::ID );
			$audience = (string) $row['audience_id'];
			if ( ! $adapter || '' === $audience || isset( $seen[ $adapter->id() . '|' . $audience ] ) ) {
				continue;
			}
			$seen[ $adapter->id() . '|' . $audience ] = true;
			if ( $adapter->is_configured() ) {
				$result = $adapter->remove( $email, $audience );
			} else {
				$result = new \WP_Error( 'crazylinks_sync_not_connected', __( 'the service is no longer connected', 'crazylinks' ) );
			}
			if ( is_wp_error( $result ) ) {
				$retained   = true;
				$messages[] = sprintf(
					/* translators: 1: service name, 2: audience id, 3: error. */
					__( 'Could not remove the address from %1$s audience %2$s: %3$s Remove it there by hand.', 'crazylinks' ),
					$adapter->label(),
					$audience,
					$result->get_error_message()
				);
			}
		}
		$removed = Store::delete_by_email( $email );
		return [
			'items_removed'  => $removed > 0,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		];
	}
}
