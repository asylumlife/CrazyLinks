<?php
/**
 * Subscribers admin screen.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Admin;

use CrazyLinks\Analytics\Csv;
use CrazyLinks\PostType;
use CrazyLinks\Subscribers\ListTable;
use CrazyLinks\Subscribers\Service;
use CrazyLinks\Subscribers\Store;

defined( 'ABSPATH' ) || exit;

/**
 * CrazyLinks → Subscribers: list, search, filter, bulk delete / resend, CSV export.
 */
final class SubscribersPage {

	public const SLUG = 'crazylinks-subscribers';

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 9 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_crazylinks_subscribers_csv', [ $this, 'export' ] );
	}

	/**
	 * Adds the submenu (managers only: it holds email addresses).
	 */
	public function menu(): void {
		$hook       = add_submenu_page(
			'edit.php?post_type=' . PostType::NAME,
			__( 'CrazyLinks Subscribers', 'crazylinks' ),
			__( 'Subscribers', 'crazylinks' ),
			'manage_crazylinks',
			self::SLUG,
			[ $this, 'render' ]
		);
		$this->hook = is_string( $hook ) ? $hook : '';
		if ( '' !== $this->hook ) {
			add_action( 'load-' . $this->hook, [ $this, 'handle_actions' ] );
		}
	}

	/**
	 * Screen styles.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ): void {
		if ( '' !== $this->hook && $hook === $this->hook ) {
			wp_enqueue_style( 'crazylinks-admin-analytics', CRAZYLINKS_URL . 'assets/src/admin-analytics.css', [], CRAZYLINKS_VERSION );
		}
	}

	/**
	 * Processes bulk and row actions, then redirects with a result notice.
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_crazylinks' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}
		if ( ! in_array( $action, [ 'delete', 'resend' ], true ) ) {
			return;
		}
		if ( isset( $_GET['subscriber'] ) && ! is_array( $_GET['subscriber'] ) ) {
			$ids = [ absint( $_GET['subscriber'] ) ];
			check_admin_referer( 'crazylinks_subscriber_' . $action . '_' . $ids[0] );
		} else {
			check_admin_referer( 'bulk-subscribers' );
			$ids = isset( $_REQUEST['subscriber'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['subscriber'] ) ) : [];
		}
		// phpcs:enable
		$ids = array_values( array_filter( $ids ) );
		$n   = 0;
		if ( 'delete' === $action ) {
			$n = Store::delete( $ids );
		} else {
			foreach ( $ids as $id ) {
				$n += Service::resend( $id ) ? 1 : 0;
			}
		}
		wp_safe_redirect(
			add_query_arg(
				[
					'cl_done' => $action,
					'cl_n'    => $n,
				],
				remove_query_arg( [ 'action', 'action2', 'subscriber', '_wpnonce', '_wp_http_referer', 'cl_done', 'cl_n' ], wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . PostType::NAME . '&page=' . self::SLUG ) )
			)
		);
		exit;
	}

	/**
	 * Screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_crazylinks' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage subscribers.', 'crazylinks' ), 403 );
		}
		$table = new ListTable();
		$table->prepare_items();
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Subscribers', 'crazylinks' ) . '</h1><hr class="wp-header-end">';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Result notice only.
		if ( isset( $_GET['cl_done'], $_GET['cl_n'] ) ) {
			$n    = absint( $_GET['cl_n'] );
			$text = 'delete' === $_GET['cl_done']
				/* translators: %d: number of subscribers. */
				? _n( '%d subscriber deleted.', '%d subscribers deleted.', $n, 'crazylinks' )
				/* translators: %d: number of emails. */
				: _n( '%d confirmation email sent.', '%d confirmation emails sent.', $n, 'crazylinks' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $text, $n ) ) . '</p></div>';
		}
		// phpcs:enable
		$table->views();
		echo '<form method="get">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( PostType::NAME ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		$f = ListTable::filters();
		if ( '' !== $f['status'] ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $f['status'] ) . '">';
		}
		$table->search_box( __( 'Search emails', 'crazylinks' ), 'crazylinks-subscriber' );
		$table->display();
		echo '</form></div>';
	}

	/**
	 * Nonce-protected CSV link honouring the current filters.
	 *
	 * @param array{page_id:int, status:string, search:string} $f Filters.
	 */
	public static function export_url( array $f ): string {
		return wp_nonce_url(
			add_query_arg(
				array_filter(
					[
						'action'  => 'crazylinks_subscribers_csv',
						'page_id' => $f['page_id'],
						'status'  => $f['status'],
						's'       => $f['search'],
					]
				),
				admin_url( 'admin-post.php' )
			),
			'crazylinks_subscribers_csv'
		);
	}

	/**
	 * Streams the CSV (admin-post handler).
	 */
	public function export(): void {
		if ( ! current_user_can( 'manage_crazylinks' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export subscribers.', 'crazylinks' ), 403 );
		}
		check_admin_referer( 'crazylinks_subscribers_csv' );
		Csv::send_headers( 'crazylinks-subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );
		echo Csv::line( Csv::SUBSCRIBER_HEADER ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML.
		foreach ( Store::each( ListTable::filters() ) as $row ) {
			echo Csv::line( Csv::subscriber_row( $row ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		exit;
	}
}
