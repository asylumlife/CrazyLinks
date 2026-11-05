<?php
/**
 * WP-CLI: subscribers.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Cli;

use CrazyLinks\Analytics\Csv;
use CrazyLinks\Subscribers\Store;
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
}
