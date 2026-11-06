<?php
/**
 * WP-CLI: subscribers.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Cli;

use CrazyLinks\Analytics\Csv;
use CrazyLinks\Subscribers\Integrations;
use CrazyLinks\Subscribers\Store;
use CrazyLinks\Subscribers\Sync;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage CrazyLinks email subscribers.
 */
final class SubscribersCommand {

	/**
	 * Exports subscribers as CSV (default) or JSON to stdout.
	 *
	 * ## OPTIONS
	 *
	 * [--page=<id>]
	 * : Only this link page.
	 *
	 * [--status=<status>]
	 * : pending, confirmed or unsubscribed.
	 *
	 * [--sync=<sync>]
	 * : queued, sent, failed or skipped.
	 *
	 * [--format=<format>]
	 * : csv or json. Default: csv.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crazylinks subscribers export --page=5 --status=confirmed > subscribers.csv
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function export( $args, $assoc_args ): void {
		unset( $args );
		$status = isset( $assoc_args['status'] ) ? sanitize_key( $assoc_args['status'] ) : '';
		if ( '' !== $status && ! in_array( $status, Store::STATUSES, true ) ) {
			WP_CLI::error( 'Unknown status. Use pending, confirmed or unsubscribed.' );
		}
		$filter = [
			'page_id' => isset( $assoc_args['page'] ) ? absint( $assoc_args['page'] ) : 0,
			'status'  => $status,
			'sync'    => isset( $assoc_args['sync'] ) ? sanitize_key( $assoc_args['sync'] ) : '',
		];
		if ( 'json' === ( $assoc_args['format'] ?? 'csv' ) ) {
			WP_CLI::line( (string) wp_json_encode( iterator_to_array( Store::each( $filter ), false ) ) );
			return;
		}
		echo Csv::line( Csv::SUBSCRIBER_HEADER ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML.
		foreach ( Store::each( $filter ) as $row ) {
			echo Csv::line( Csv::subscriber_row( $row ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Checks the saved Mailchimp API key and lists the audiences it reaches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crazylinks subscribers test-connection
	 *
	 * @subcommand test-connection
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function test_connection( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		$result = Integrations::test();
		if ( true !== $result ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::line( 'Connected to Mailchimp.' );
		$list = Integrations::audiences( true );
		if ( is_wp_error( $list ) ) {
			WP_CLI::warning( $list->get_error_message() );
			return;
		}
		$default = Integrations::default_audience();
		foreach ( $list as $aud ) {
			WP_CLI::line( sprintf( '%s  %s (%d members)%s', $aud['id'], $aud['name'], $aud['member_count'], $aud['id'] === $default ? '  [default]' : '' ) );
		}
		if ( '' === $default ) {
			WP_CLI::warning( 'No default audience is chosen (Settings → Integrations).' );
		}
		WP_CLI::success( sprintf( '%d audience(s).', count( $list ) ) );
	}

	/**
	 * Sends sign-ups to the connected email service.
	 *
	 * Without options, pushes rows that are queued. --retry-failed also starts failed rows again
	 * from the first attempt. --backfill sends every locally confirmed subscriber that was never
	 * sent (as subscribed, with the block's tags).
	 *
	 * ## OPTIONS
	 *
	 * [--retry-failed]
	 * : Retry rows whose sync failed (and skipped ones).
	 *
	 * [--backfill]
	 * : Send every locally confirmed subscriber that is not in Mailchimp yet.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crazylinks subscribers sync --retry-failed
	 *     wp crazylinks subscribers sync --backfill
	 *
	 * @param string[]                   $args       Positional args.
	 * @param array<string, string|bool> $assoc_args Options.
	 */
	public function sync( $args, $assoc_args ): void {
		unset( $args );
		if ( ! \CrazyLinks\Subscribers\Adapters::active() ) {
			WP_CLI::error( 'No email service is connected. Choose Mailchimp and save an API key under CrazyLinks → Settings → Integrations.' );
		}
		$ids = Store::ids_by_sync( 'queued', 10000 );
		if ( ! empty( $assoc_args['retry-failed'] ) ) {
			$ids = array_merge( $ids, Store::ids_by_sync( 'failed', 10000 ), Store::ids_by_sync( 'skipped', 10000 ) );
		}
		$sent = $ids ? Sync::retry( $ids ) : 0;
		WP_CLI::line( sprintf( 'Pushed %d of %d row(s).', $sent, count( $ids ) ) );
		if ( ! empty( $assoc_args['backfill'] ) ) {
			$started = Sync::backfill_start();
			if ( is_wp_error( $started ) ) {
				WP_CLI::error( $started->get_error_message() );
			}
			wp_unschedule_hook( Sync::HOOK_BACKFILL ); // Run it here instead of through cron.
			while ( Sync::backfill_batch() ) {
				$state = Sync::backfill_state();
				WP_CLI::line( sprintf( '… %d sent, %d failed', $state['sent'] ?? 0, $state['failed'] ?? 0 ) );
			}
			$state = Sync::backfill_state();
			WP_CLI::line( sprintf( 'Backfill: %d sent, %d failed.', $state['sent'] ?? 0, $state['failed'] ?? 0 ) );
		}
		WP_CLI::success( 'Done.' );
	}
}
