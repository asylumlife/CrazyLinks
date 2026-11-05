<?php
/**
 * Views / clicks / CTR columns on the link pages list.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

use CrazyLinks\Admin\AnalyticsPage;
use CrazyLinks\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Views (30d), Clicks (30d) and CTR to the `crazylinks_page` list table, plus an Analytics
 * row action. Totals for all pages come from one cached query (Summary::page_totals()).
 */
final class ListColumns {

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'manage_' . PostType::NAME . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . PostType::NAME . '_posts_custom_column', [ $this, 'render' ], 10, 2 );
		add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );
	}

	/**
	 * Adds the columns after the title.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		$out = [];
		foreach ( (array) $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['cl_views']  = __( 'Views (30d)', 'crazylinks' );
				$out['cl_clicks'] = __( 'Clicks (30d)', 'crazylinks' );
				$out['cl_ctr']    = __( 'CTR', 'crazylinks' );
			}
		}
		if ( ! isset( $out['cl_views'] ) ) {
			$out['cl_views']  = __( 'Views (30d)', 'crazylinks' );
			$out['cl_clicks'] = __( 'Clicks (30d)', 'crazylinks' );
			$out['cl_ctr']    = __( 'CTR', 'crazylinks' );
		}
		return $out;
	}

	/**
	 * Cell.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 */
	public function render( $column, $post_id ): void {
		if ( ! in_array( $column, [ 'cl_views', 'cl_clicks', 'cl_ctr' ], true ) ) {
			return;
		}
		if ( ! current_user_can( 'view_crazylinks_analytics' ) ) {
			echo '&mdash;';
			return;
		}
		$t     = Summary::page_totals()[ (int) $post_id ] ?? [
			'views'  => 0,
			'clicks' => 0,
		];
		$value = [
			'cl_views'  => number_format_i18n( $t['views'] ),
			'cl_clicks' => number_format_i18n( $t['clicks'] ),
			'cl_ctr'    => $t['views'] > 0 ? number_format_i18n( 100 * $t['clicks'] / $t['views'], 1 ) . '%' : '&mdash;',
		][ $column ];
		echo wp_kses( $value, [] );
		if ( '&mdash;' === $value ) {
			echo '<span class="screen-reader-text">' . esc_html__( 'No views yet', 'crazylinks' ) . '</span>';
		}
	}

	/**
	 * Adds an "Analytics" row action.
	 *
	 * @param array<string, string> $actions Actions.
	 * @param \WP_Post              $post    Post.
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $post ): array {
		$actions = (array) $actions;
		if ( $post instanceof \WP_Post && PostType::NAME === $post->post_type && current_user_can( 'view_crazylinks_analytics' ) ) {
			$url                  = add_query_arg(
				[
					'post_type' => PostType::NAME,
					'page'      => AnalyticsPage::SLUG,
					'page_id'   => $post->ID,
				],
				admin_url( 'edit.php' )
			);
			$actions['analytics'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Analytics', 'crazylinks' ) . '</a>';
		}
		return $actions;
	}
}
