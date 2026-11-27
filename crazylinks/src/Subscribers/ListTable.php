<?php
/**
 * Subscribers list table.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers;

use CrazyLinks\Admin\SubscribersPage;
use CrazyLinks\PostType;
use CrazyLinks\Subscribers\Adapters\Mailchimp;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Email, page, status and consent date, with search, status and page filters and bulk actions.
 */
final class ListTable extends \WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Most link pages offered in the page filter.
	 */
	private const PAGE_FILTER_LIMIT = 100;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			[
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Current filters from the query string.
	 *
	 * @return array{page_id:int, status:string, search:string, sync:string}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$sync    = isset( $_GET['sync'] ) ? sanitize_key( wp_unslash( $_GET['sync'] ) ) : '';
		$filters = [
			'page_id' => isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0,
			'status'  => in_array( $status, Store::STATUSES, true ) ? $status : '',
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'sync'    => in_array( $sync, Store::SYNC_STATUSES, true ) ? $sync : '',
		];
		// phpcs:enable
		return $filters;
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'cb'         => '<input type="checkbox">',
			'email'      => __( 'Email', 'crazylinks' ),
			'page'       => __( 'Page', 'crazylinks' ),
			'status'     => __( 'Status', 'crazylinks' ),
			'consent_at' => __( 'Consent date', 'crazylinks' ),
			'sync'       => __( 'Sync', 'crazylinks' ),
		];
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0:string, 1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return [
			'email'      => [ 'email', false ],
			'status'     => [ 'status', false ],
			'consent_at' => [ 'consent_at', true ],
		];
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return [
			'resend'     => __( 'Resend confirmation', 'crazylinks' ),
			'retry_sync' => __( 'Retry sync', 'crazylinks' ),
			'delete'     => __( 'Delete', 'crazylinks' ),
		];
	}

	/**
	 * Loads the rows.
	 */
	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only sort.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'consent_at';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable
		$result      = Store::query(
			self::filters() + [
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => self::PER_PAGE,
				'paged'    => $this->get_pagenum(),
			]
		);
		$this->items = $result['items'];
		$this->set_pagination_args(
			[
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			]
		);
	}

	/**
	 * Checkbox cell.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_cb( $item ): string {
		return '<input type="checkbox" name="subscriber[]" value="' . (int) $item['id'] . '"><span class="screen-reader-text">' . esc_html( (string) $item['email'] ) . '</span>';
	}

	/**
	 * Email cell with row actions.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_email( $item ): string {
		$actions = [];
		if ( in_array( (string) $item['sync_status'], array_diff( Store::SYNC_STATUSES, [ 'sent' ] ), true ) ) {
			$actions['retry_sync'] = '<a href="' . esc_url( $this->action_url( 'retry_sync', (int) $item['id'] ) ) . '">' . esc_html__( 'Retry sync', 'crazylinks' ) . '</a>';
		}
		if ( 'pending' === $item['status'] && Mailchimp::ID !== $item['destination'] ) {
			$actions['resend'] = '<a href="' . esc_url( $this->action_url( 'resend', (int) $item['id'] ) ) . '">' . esc_html__( 'Resend confirmation', 'crazylinks' ) . '</a>';
		}
		$actions['delete'] = '<a href="' . esc_url( $this->action_url( 'delete', (int) $item['id'] ) ) . '" class="submitdelete">' . esc_html__( 'Delete', 'crazylinks' ) . '</a>';
		return '<strong>' . esc_html( (string) $item['email'] ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * Page cell.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_page( $item ): string {
		$title = get_the_title( (int) $item['page_id'] );
		return esc_html( '' !== $title ? $title : '#' . $item['page_id'] );
	}

	/**
	 * Status cell.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_status( $item ): string {
		$label = self::status_label( (string) $item['status'] );
		if ( 'pending' === $item['status'] && Mailchimp::ID === $item['destination'] ) {
			/* translators: %s: status label ("Pending"). */
			$label = sprintf( __( '%s (Mailchimp confirms)', 'crazylinks' ), $label );
		}
		return esc_html( $label );
	}

	/**
	 * Sync cell: Queued / Sent / Failed (error on hover) / Skipped, or a dash.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_sync( $item ): string {
		$state = (string) $item['sync_status'];
		if ( '' === $state ) {
			return '&mdash;';
		}
		$label = self::sync_label( $state );
		if ( 'failed' === $state ) {
			/* translators: 1: number of attempts. */
			$label .= ' ' . sprintf( _n( '(%d attempt)', '(%d attempts)', (int) $item['sync_attempts'], 'crazylinks' ), (int) $item['sync_attempts'] );
		}
		$detail = (string) $item['sync_error'];
		if ( 'sent' === $state && ! empty( $item['synced_at'] ) ) {
			$detail = self::local_datetime( Store::timestamp( (string) $item['synced_at'] ) );
		}
		if ( '' === $detail ) {
			return esc_html( $label );
		}
		$cell = '<span title="' . esc_attr( $detail ) . '">' . esc_html( $label ) . '</span>';
		// A sent row keeps its date in the hover text only; any other state shows its error below.
		return 'sent' === $state ? $cell : $cell . '<br><span class="description">' . esc_html( $detail ) . '</span>';
	}

	/**
	 * A timestamp in the site's date and time format and timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private static function local_datetime( int $timestamp ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Translated sync state.
	 *
	 * @param string $state Sync state.
	 */
	public static function sync_label( string $state ): string {
		return [
			'queued'  => __( 'Queued', 'crazylinks' ),
			'sent'    => __( 'Sent', 'crazylinks' ),
			'failed'  => __( 'Failed', 'crazylinks' ),
			'skipped' => __( 'Skipped', 'crazylinks' ),
		][ $state ] ?? $state;
	}

	/**
	 * Consent date cell.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_consent_at( $item ): string {
		if ( empty( $item['consent_at'] ) ) {
			return '&mdash;';
		}
		$timestamp = Store::timestamp( (string) $item['consent_at'] );
		$text      = (string) $item['consent_text'];
		return sprintf(
			'<time datetime="%s" title="%s">%s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( '' !== $text ? $text : __( 'No consent text on this form', 'crazylinks' ) ),
			esc_html( self::local_datetime( $timestamp ) )
		);
	}

	/**
	 * Fallback.
	 *
	 * @param array<string, mixed> $item        Row.
	 * @param string               $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * Empty state.
	 */
	public function no_items(): void {
		esc_html_e( 'No subscribers found.', 'crazylinks' );
	}

	/**
	 * Status views (All / Pending / Confirmed / Unsubscribed with counts).
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$filters = self::filters();
		$counts  = Store::counts( $filters['page_id'] );
		$base    = add_query_arg(
			array_filter(
				[
					'post_type' => PostType::NAME,
					'page'      => SubscribersPage::SLUG,
					'page_id'   => $filters['page_id'],
					's'         => $filters['search'],
				]
			),
			admin_url( 'edit.php' )
		);
		$views   = [
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $base ),
				'' === $filters['status'] ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'crazylinks' ),
				array_sum( $counts )
			),
		];
		foreach ( $counts as $status => $n ) {
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$filters['status'] === $status ? ' class="current" aria-current="page"' : '',
				esc_html( self::status_label( $status ) ),
				$n
			);
		}
		return $views;
	}

	/**
	 * Page filter and export button above the table.
	 *
	 * @param string $which top | bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = self::filters();
		echo '<div class="alignleft actions"><label class="screen-reader-text" for="cl-sub-page">' . esc_html__( 'Filter by page', 'crazylinks' ) . '</label><select name="page_id" id="cl-sub-page"><option value="0">' . esc_html__( 'All pages', 'crazylinks' ) . '</option>';
		foreach ( get_posts(
			[
				'post_type'      => PostType::NAME,
				'post_status'    => 'any',
				'posts_per_page' => self::PAGE_FILTER_LIMIT,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		) as $page ) {
			printf( '<option value="%d"%s>%s</option>', (int) $page->ID, selected( $filters['page_id'], $page->ID, false ), esc_html( '' !== $page->post_title ? $page->post_title : $page->post_name ) );
		}
		echo '</select>';
		echo '<label class="screen-reader-text" for="cl-sub-sync">' . esc_html__( 'Filter by sync', 'crazylinks' ) . '</label><select name="sync" id="cl-sub-sync"><option value="">' . esc_html__( 'Any sync state', 'crazylinks' ) . '</option>';
		foreach ( Store::SYNC_STATUSES as $state ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $state ), selected( $filters['sync'], $state, false ), esc_html( self::sync_label( $state ) ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'crazylinks' ), '', 'filter_action', false );
		echo ' <a class="button" href="' . esc_url( SubscribersPage::export_url( $filters ) ) . '">' . esc_html__( 'Export CSV', 'crazylinks' ) . '</a>';
		if ( Adapters::active() ) {
			echo ' <a class="button" href="' . esc_url( SubscribersPage::backfill_url() ) . '">' . esc_html__( 'Backfill to Mailchimp', 'crazylinks' ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * Translated status.
	 *
	 * @param string $status Status.
	 */
	public static function status_label( string $status ): string {
		return [
			'pending'      => __( 'Pending', 'crazylinks' ),
			'confirmed'    => __( 'Confirmed', 'crazylinks' ),
			'unsubscribed' => __( 'Unsubscribed', 'crazylinks' ),
		][ $status ] ?? $status;
	}

	/**
	 * Nonce-protected single-row action link.
	 *
	 * @param string $action resend | retry_sync | delete.
	 * @param int    $id     Subscriber id.
	 */
	private function action_url( string $action, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'post_type'  => PostType::NAME,
					'page'       => SubscribersPage::SLUG,
					'action'     => $action,
					'subscriber' => $id,
				],
				admin_url( 'edit.php' )
			),
			'crazylinks_subscriber_' . $action . '_' . $id
		);
	}
}
