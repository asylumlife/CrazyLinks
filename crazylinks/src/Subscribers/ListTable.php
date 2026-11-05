<?php
/**
 * Subscribers list table.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers;

use CrazyLinks\Admin\SubscribersPage;
use CrazyLinks\PostType;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Email, page, status and consent date, with search, status and page filters and bulk actions.
 */
final class ListTable extends \WP_List_Table {

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
	 * @return array{page_id:int, status:string, search:string}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		return [
			'page_id' => isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0,
			'status'  => in_array( $status, Store::STATUSES, true ) ? $status : '',
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		];
		// phpcs:enable
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
			'resend' => __( 'Resend confirmation', 'crazylinks' ),
			'delete' => __( 'Delete', 'crazylinks' ),
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
		$per_page    = 20;
		$result      = Store::query(
			self::filters() + [
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'paged'    => $this->get_pagenum(),
			]
		);
		$this->items = $result['items'];
		$this->set_pagination_args(
			[
				'total_items' => $result['total'],
				'per_page'    => $per_page,
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
		if ( 'pending' === $item['status'] ) {
			$actions['resend'] = '<a href="' . esc_url( $this->action_url( 'resend', (int) $item['id'] ) ) . '">' . esc_html__( 'Resend confirmation', 'crazylinks' ) . '</a>';
		}
		$actions['delete'] = '<a href="' . esc_url( $this->action_url( 'delete', (int) $item['id'] ) ) . '" class="submitdelete">' . esc_html__( 'Delete', 'crazylinks' ) . '</a>';
		return '<strong>' . esc_html( (string) $item['email'] ) . '</strong>' . $this->row_actions( $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
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
		return esc_html( self::status_label( (string) $item['status'] ) );
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
		$ts   = (int) strtotime( (string) $item['consent_at'] . ' UTC' );
		$text = (string) $item['consent_text'];
		return '<time datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '" title="' . esc_attr( '' !== $text ? $text : __( 'No consent text on this form', 'crazylinks' ) ) . '">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) . '</time>';
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
		$f      = self::filters();
		$counts = Store::counts( $f['page_id'] );
		$base   = add_query_arg(
			array_filter(
				[
					'post_type' => PostType::NAME,
					'page'      => SubscribersPage::SLUG,
					'page_id'   => $f['page_id'],
					's'         => $f['search'],
				]
			),
			admin_url( 'edit.php' )
		);
		$views  = [
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $base ),
				'' === $f['status'] ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'crazylinks' ),
				array_sum( $counts )
			),
		];
		foreach ( $counts as $status => $n ) {
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$f['status'] === $status ? ' class="current" aria-current="page"' : '',
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
		$f = self::filters();
		echo '<div class="alignleft actions"><label class="screen-reader-text" for="cl-sub-page">' . esc_html__( 'Filter by page', 'crazylinks' ) . '</label><select name="page_id" id="cl-sub-page"><option value="0">' . esc_html__( 'All pages', 'crazylinks' ) . '</option>';
		foreach ( get_posts(
			[
				'post_type'      => PostType::NAME,
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		) as $p ) {
			printf( '<option value="%d"%s>%s</option>', (int) $p->ID, selected( $f['page_id'], $p->ID, false ), esc_html( '' !== $p->post_title ? $p->post_title : $p->post_name ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'crazylinks' ), '', 'filter_action', false );
		echo ' <a class="button" href="' . esc_url( SubscribersPage::export_url( $f ) ) . '">' . esc_html__( 'Export CSV', 'crazylinks' ) . '</a></div>';
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
	 * @param string $action resend | delete.
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
