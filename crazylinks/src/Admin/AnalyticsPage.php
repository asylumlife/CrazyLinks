<?php
/**
 * Analytics admin screen.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Admin;

use CrazyLinks\Analytics\Activity;
use CrazyLinks\Analytics\Chart;
use CrazyLinks\Analytics\Csv;
use CrazyLinks\Analytics\ListColumns;
use CrazyLinks\Analytics\Summary;
use CrazyLinks\PostType;
use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * CrazyLinks → Analytics. Server-rendered; the only script is the small range-picker helper.
 */
final class AnalyticsPage {

	public const SLUG = 'crazylinks-analytics';

	/**
	 * Range token for a from/to pair picked by hand, next to the Summary::RANGES presets.
	 */
	private const CUSTOM_RANGE = 'custom';

	private const DEFAULT_RANGE = '30d';

	/**
	 * Post statuses of link pages that have an analytics screen.
	 */
	private const PAGE_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

	/**
	 * Most pages listed in the page picker.
	 */
	private const PICKER_LIMIT = 100;

	/**
	 * Hook suffix of the screen.
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
		add_action( 'admin_post_crazylinks_analytics_csv', [ $this, 'export' ] );
		( new ListColumns() )->register_hooks();
	}

	/**
	 * Adds the submenu.
	 */
	public function menu(): void {
		$hook       = add_submenu_page(
			'edit.php?post_type=' . PostType::NAME,
			__( 'CrazyLinks Analytics', 'crazylinks' ),
			__( 'Analytics', 'crazylinks' ),
			'view_crazylinks_analytics',
			self::SLUG,
			[ $this, 'render' ]
		);
		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Loads the screen's style and script, on this screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'crazylinks-admin-analytics', CRAZYLINKS_URL . 'assets/src/admin-analytics.css', [], CRAZYLINKS_VERSION );
		wp_enqueue_script( 'crazylinks-admin-analytics', CRAZYLINKS_URL . 'assets/src/admin-analytics.js', [], CRAZYLINKS_VERSION, true );
	}

	/**
	 * URL of the screen.
	 *
	 * @param array<string, mixed> $args Extra query args.
	 */
	public static function url( array $args = [] ): string {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . PostType::NAME . '&page=' . self::SLUG ) );
	}

	/**
	 * Link pages the current user may see analytics for: the first PICKER_LIMIT by title, plus the
	 * requested page when it is allowed but not among them.
	 *
	 * @param int $requested Page id asked for in the request, or 0.
	 * @return \WP_Post[]
	 */
	private function pages( int $requested ): array {
		$posts = get_posts(
			[
				'post_type'      => PostType::NAME,
				'post_status'    => self::PAGE_STATUSES,
				'posts_per_page' => self::PICKER_LIMIT,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
		$pages = array_values(
			array_filter(
				$posts,
				static fn( $p ) => $p instanceof \WP_Post && current_user_can( 'edit_post', $p->ID )
			)
		);
		if ( $requested > 0 && ! in_array( $requested, wp_list_pluck( $pages, 'ID' ), true ) ) {
			$post = get_post( $requested );
			if ( $post instanceof \WP_Post
				&& PostType::NAME === $post->post_type
				&& in_array( $post->post_status, self::PAGE_STATUSES, true )
				&& current_user_can( 'edit_post', $post->ID )
			) {
				$pages[] = $post;
			}
		}
		return $pages;
	}

	/**
	 * The page id asked for in the query string, or 0.
	 */
	private static function requested_page_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen filter; export() verifies its nonce first.
		return isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0;
	}

	/**
	 * The range asked for in the query string: a known range token and the raw from/to dates.
	 *
	 * @return array{range:string, from:string, to:string}
	 */
	private static function requested_range(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen filters; export() verifies its nonce first.
		$range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : self::DEFAULT_RANGE;
		$from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable
		if ( self::CUSTOM_RANGE !== $range && ! isset( Summary::RANGES[ $range ] ) ) {
			$range = self::DEFAULT_RANGE;
		}
		return [
			'range' => $range,
			'from'  => $from,
			'to'    => $to,
		];
	}

	/**
	 * Reads and validates the filter query args.
	 *
	 * @param \WP_Post[] $pages Allowed pages.
	 * @return array{page_id:int, range:string, from:string, to:string}
	 */
	private function filters( array $pages ): array {
		$page_id = self::requested_page_id();
		$ids     = wp_list_pluck( $pages, 'ID' );
		if ( ! in_array( $page_id, $ids, true ) ) {
			$page_id = $ids ? (int) $ids[0] : 0;
		}
		return [ 'page_id' => $page_id ] + self::requested_range();
	}

	/**
	 * Resolves filters to a report range.
	 *
	 * @param array{page_id:int, range:string, from:string, to:string} $filters Filters.
	 * @return array{from:string, to:string, days:int}|\WP_Error
	 */
	private function resolve( array $filters ) {
		if ( self::CUSTOM_RANGE === $filters['range'] ) {
			return Summary::resolve_range( null, $filters['from'], $filters['to'] );
		}
		return Summary::resolve_range( $filters['range'], null, null );
	}

	/**
	 * Screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'view_crazylinks_analytics' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view analytics.', 'crazylinks' ), 403 );
		}
		$pages   = $this->pages( self::requested_page_id() );
		$filters = $this->filters( $pages );
		echo '<div class="wrap cl-analytics"><h1>' . esc_html__( 'CrazyLinks Analytics', 'crazylinks' ) . '</h1>';
		if ( ! $pages ) {
			echo '<p>' . esc_html__( 'Create a link page first; its analytics will show up here.', 'crazylinks' ) . '</p></div>';
			return;
		}
		if ( ! Settings::analytics_enabled() ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'Analytics are switched off in CrazyLinks settings, so new views and clicks are not being counted.', 'crazylinks' )
				. '</p></div>';
		}
		$this->filter_form( $pages, $filters );

		$range = $this->resolve( $filters );
		if ( is_wp_error( $range ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $range->get_error_message() ) . '</p></div></div>';
			return;
		}
		$report = Summary::report( $filters['page_id'], $range['from'], $range['to'] );
		$this->cards( $report );
		$this->export_links( $filters );
		$this->daily_chart( $report );
		$this->blocks_table( $report, $filters );
		$this->sources_table( $report );
		$this->breakdowns( $report );
		$this->hours_chart( $filters['page_id'], $range );
		$this->activity( $report, $filters, $range );
		echo '</div>';
	}

	/**
	 * Page and range picker (GET form, works without JavaScript).
	 *
	 * @param \WP_Post[]                                               $pages   Pages.
	 * @param array{page_id:int, range:string, from:string, to:string} $filters Filters.
	 */
	private function filter_form( array $pages, array $filters ): void {
		echo '<form class="cl-analytics__filters" method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( PostType::NAME ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label>' . esc_html__( 'Link page', 'crazylinks' ) . '<select name="page_id">';
		foreach ( $pages as $page ) {
			$title = '' !== $page->post_title ? $page->post_title : $page->post_name;
			printf( '<option value="%d"%s>%s</option>', (int) $page->ID, selected( $filters['page_id'], $page->ID, false ), esc_html( $title ) );
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Range', 'crazylinks' ) . '<select name="range">';
		$ranges = [
			'7d'               => __( 'Last 7 days', 'crazylinks' ),
			'30d'              => __( 'Last 30 days', 'crazylinks' ),
			'90d'              => __( 'Last 90 days', 'crazylinks' ),
			self::CUSTOM_RANGE => __( 'Custom', 'crazylinks' ),
		];
		foreach ( $ranges as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $filters['range'], $value, false ), esc_html( $label ) );
		}
		echo '</select></label>';
		// The date fields show only for a custom range; the script toggles them with the picker.
		$dates = [
			'from' => __( 'From', 'crazylinks' ),
			'to'   => __( 'To', 'crazylinks' ),
		];
		foreach ( $dates as $name => $label ) {
			printf(
				'<label data-cl-custom%s>%s<input type="date" name="%s" value="%s"></label>',
				self::CUSTOM_RANGE === $filters['range'] ? '' : ' hidden',
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( $filters[ $name ] )
			);
		}
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Update', 'crazylinks' ) . '</button></form>';
	}

	/**
	 * Totals cards.
	 *
	 * @param array<string, mixed> $report Report.
	 */
	private function cards( array $report ): void {
		$totals = $report['totals'];
		echo '<div class="cl-cards">';
		$cards = [
			[ __( 'Views', 'crazylinks' ), number_format_i18n( $totals['views'] ), '' ],
			[
				__( 'Unique visitors', 'crazylinks' ),
				number_format_i18n( $totals['uniques'] ),
				__( 'Counted per day, then added up. Visitor IDs change every day, so someone who visits on two days counts twice.', 'crazylinks' ),
			],
			[ __( 'Clicks', 'crazylinks' ), number_format_i18n( $totals['clicks'] ), '' ],
			[ __( 'Click rate', 'crazylinks' ), self::percent( $totals['ctr'] ), __( 'Clicks divided by views.', 'crazylinks' ) ],
		];
		foreach ( $cards as [ $label, $value, $tip ] ) {
			echo '<div class="card cl-card"><p class="cl-card__label">';
			if ( '' !== $tip ) {
				printf( '<abbr title="%s" tabindex="0">%s</abbr>', esc_attr( $tip ), esc_html( $label ) );
			} else {
				echo esc_html( $label );
			}
			echo '</p><p class="cl-card__value">' . esc_html( $value ) . '</p></div>';
		}
		echo '</div>';
	}

	/**
	 * CSV download buttons.
	 *
	 * @param array{page_id:int, range:string, from:string, to:string} $filters Filters.
	 */
	private function export_links( array $filters ): void {
		$exports = [
			'daily'    => __( 'Download daily CSV', 'crazylinks' ),
			'blocks'   => __( 'Download links CSV', 'crazylinks' ),
			'sources'  => __( 'Download sources CSV', 'crazylinks' ),
			'activity' => __( 'Download activity CSV', 'crazylinks' ),
		];
		echo '<div class="cl-exports">';
		foreach ( $exports as $which => $label ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( $this->export_url( $which, $filters ) ), esc_html( $label ) );
		}
		echo '</div>';
	}

	/**
	 * Views and clicks per day: the chart and the same numbers as a table, newest day first.
	 *
	 * @param array<string, mixed> $report Report.
	 */
	private function daily_chart( array $report ): void {
		echo '<div class="cl-panel"><h2>' . esc_html__( 'Views and clicks per day (UTC)', 'crazylinks' ) . '</h2>';
		echo Chart::svg( $report['timeseries'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Chart::svg().
		self::legend( 'cl-legend' );
		echo '<details><summary>' . esc_html__( 'Show data table', 'crazylinks' ) . '</summary><div class="cl-table-wrap"><table class="widefat striped">';
		echo '<thead><tr><th>' . esc_html__( 'Date', 'crazylinks' ) . '</th>'
			. '<th class="cl-num">' . esc_html__( 'Views', 'crazylinks' ) . '</th>'
			. '<th class="cl-num">' . esc_html__( 'Clicks', 'crazylinks' ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( $report['timeseries'] ) as $point ) {
			printf(
				'<tr><td>%s</td><td class="cl-num">%s</td><td class="cl-num">%s</td></tr>',
				esc_html( $point['date'] ),
				esc_html( number_format_i18n( $point['views'] ) ),
				esc_html( number_format_i18n( $point['clicks'] ) )
			);
		}
		echo '</tbody></table></div></details></div>';
	}

	/**
	 * Per-block table, sortable by clicks or title via the query string.
	 *
	 * @param array<string, mixed>                                     $report  Report.
	 * @param array{page_id:int, range:string, from:string, to:string} $filters Filters.
	 */
	private function blocks_table( array $report, array $filters ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only sort.
		$orderby = isset( $_GET['orderby'] ) && 'title' === $_GET['orderby'] ? 'title' : 'clicks';
		$order   = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
		// phpcs:enable
		$rows = $report['blocks'];
		usort(
			$rows,
			static function ( $a, $b ) use ( $orderby, $order ) {
				$cmp = 'title' === $orderby ? strcasecmp( (string) $a['title'], (string) $b['title'] ) : $a['clicks'] <=> $b['clicks'];
				return 'asc' === $order ? $cmp : -$cmp;
			}
		);
		$base      = self::url( array_filter( $filters, static fn( $v ) => '' !== $v && 0 !== $v ) );
		$sort_head = static function ( string $key, string $label, string $th_class ) use ( $base, $orderby, $order ): void {
			$next = ( $orderby === $key && 'desc' === $order ) ? 'asc' : 'desc';
			$mark = $orderby === $key ? ( 'asc' === $order ? ' ▲' : ' ▼' ) : '';
			$sort = $orderby === $key ? ( 'asc' === $order ? 'ascending' : 'descending' ) : 'none';
			$url  = add_query_arg(
				[
					'orderby' => $key,
					'order'   => $next,
				],
				$base
			);
			printf(
				'<th scope="col" class="%s" aria-sort="%s"><a href="%s">%s</a></th>',
				esc_attr( $th_class ),
				esc_attr( $sort ),
				esc_url( $url ),
				esc_html( $label . $mark )
			);
		};
		echo '<div class="cl-panel"><h2>' . esc_html__( 'Links', 'crazylinks' ) . '</h2><div class="cl-table-wrap"><table class="widefat striped"><thead><tr>';
		$sort_head( 'title', __( 'Link', 'crazylinks' ), '' );
		echo '<th scope="col">' . esc_html__( 'Type', 'crazylinks' ) . '</th>';
		$sort_head( 'clicks', __( 'Clicks', 'crazylinks' ), 'cl-num' );
		echo '<th scope="col" class="cl-num">' . esc_html__( 'Share', 'crazylinks' ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'This page has no trackable links yet.', 'crazylinks' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td class="cl-num">%s</td><td class="cl-num">%s</td></tr>',
				esc_html( $row['title'] ),
				esc_html( $row['type'] ),
				esc_html( number_format_i18n( $row['clicks'] ) ),
				esc_html( self::percent( $row['share'] ) )
			);
			// A Socials block lists its clicks per platform underneath, a newest-products block per product.
			foreach ( (array) ( $row['items'] ?? [] ) as $item ) {
				printf(
					'<tr class="cl-subrow"><td>%s</td><td></td><td class="cl-num">%s</td><td class="cl-num">%s</td></tr>',
					esc_html( $item['title'] ),
					esc_html( number_format_i18n( $item['clicks'] ) ),
					esc_html( self::percent( $item['share'] ) )
				);
			}
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * Views and clicks per traffic source, with the links each source clicks most.
	 *
	 * @param array<string, mixed> $report Report.
	 */
	private function sources_table( array $report ): void {
		echo '<div class="cl-panel"><h2>' . esc_html__( 'What each source clicks', 'crazylinks' ) . '</h2>';
		echo '<p class="cl-muted">'
			. esc_html__( 'A click is matched to the visit it came from, so you can see which links each audience prefers. Sources are named from your campaign tags (utm_source), the app the page was opened in, or the referring site. Covers the same period as the breakdowns below.', 'crazylinks' )
			. '</p>';
		echo '<div class="cl-table-wrap"><table class="widefat striped">';
		self::table_head(
			[
				[ __( 'Source', 'crazylinks' ), '' ],
				[ __( 'Views', 'crazylinks' ), 'cl-num' ],
				[ __( 'Clicks', 'crazylinks' ), 'cl-num' ],
				[ __( 'Click rate', 'crazylinks' ), 'cl-num' ],
				[ __( 'Most clicked', 'crazylinks' ), '' ],
			]
		);
		if ( ! $report['sources'] ) {
			echo '<tr><td colspan="5" class="cl-muted">' . esc_html__( 'No data yet', 'crazylinks' ) . '</td></tr>';
		}
		foreach ( $report['sources'] as $source ) {
			$top = array_map(
				static fn( $link ) => $link['title'] . ' (' . number_format_i18n( $link['clicks'] ) . ')',
				$source['top']
			);
			printf(
				'<tr><td>%s</td><td class="cl-num">%s</td><td class="cl-num">%s</td><td class="cl-num">%s</td><td>%s</td></tr>',
				esc_html( $source['label'] ),
				esc_html( number_format_i18n( $source['views'] ) ),
				esc_html( number_format_i18n( $source['clicks'] ) ),
				esc_html( null === $source['ctr'] ? '—' : self::percent( $source['ctr'] ) ),
				esc_html( $top ? implode( ', ', $top ) : '—' )
			);
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * Breakdown tables.
	 *
	 * @param array<string, mixed> $report Report.
	 */
	private function breakdowns( array $report ): void {
		$breakdowns = $report['breakdowns'];
		// Bars are a share of the views the breakdowns cover (every such view has a device), which
		// is fewer than the report's total when the range reaches past the raw-event retention.
		$views = max( 1, (int) array_sum( $breakdowns['device'] ) );
		echo '<div class="cl-panel"><h2>' . esc_html__( 'Where visitors come from', 'crazylinks' ) . '</h2>';
		if ( $report['events_available_from'] && $report['events_available_from'] > $report['range']['from'] ) {
			echo '<p class="cl-muted">' . esc_html(
				sprintf(
					/* translators: %s: date. */
					__( 'Breakdowns come from raw visit records, which are only kept for a limited time. These start on %s.', 'crazylinks' ),
					$report['events_available_from']
				)
			) . '</p>';
		}
		echo '<div class="cl-breakdowns">';
		$referrers = [];
		foreach ( $breakdowns['referrer'] as $referrer ) {
			$referrers[ $referrer['host'] ] = $referrer['count'];
		}
		$tables = [
			[ __( 'Device', 'crazylinks' ), $breakdowns['device'] ],
			[ __( 'Operating system', 'crazylinks' ), $breakdowns['os'] ],
			[ __( 'In-app browser', 'crazylinks' ), $breakdowns['in_app'] ],
			[ __( 'Referrer', 'crazylinks' ), $referrers ],
		];
		foreach ( $tables as [ $title, $counts ] ) {
			echo '<div class="cl-table-wrap"><table class="widefat striped">';
			self::table_head(
				[
					[ $title, '' ],
					[ __( 'Views', 'crazylinks' ), 'cl-num' ],
				]
			);
			if ( ! $counts ) {
				echo '<tr><td colspan="2" class="cl-muted">' . esc_html__( 'No data yet', 'crazylinks' ) . '</td></tr>';
			}
			foreach ( $counts as $label => $count ) {
				printf(
					'<tr><td>%s<span class="cl-bar" style="width:%s%%"></span></td><td class="cl-num">%s</td></tr>',
					esc_html( (string) $label ),
					esc_attr( (string) min( 100, round( 100 * $count / $views ) ) ),
					esc_html( number_format_i18n( $count ) )
				);
			}
			echo '</tbody></table></div>';
		}
		echo '</div><p class="cl-muted">' . esc_html__( 'Country: not collected.', 'crazylinks' ) . '</p></div>';
	}

	/**
	 * Views and clicks per hour of the day, in the site's timezone.
	 *
	 * @param int                                     $page_id Page id.
	 * @param array{from:string, to:string, days:int} $range   Range.
	 */
	private function hours_chart( int $page_id, array $range ): void {
		$hours      = Activity::hours( $page_id, $range['from'], $range['to'], wp_timezone() );
		$max        = max( 1, ...array_map( static fn( $hour ) => max( $hour['views'], $hour['clicks'] ), $hours ) );
		$hour_label = static fn( int $hour ): string => wp_date( 'g a', gmmktime( $hour, 0, 0, 1, 1, 2001 ), new \DateTimeZone( 'UTC' ) );
		echo '<div class="cl-panel"><h2>' . esc_html__( 'Time of day', 'crazylinks' ) . '</h2>';
		echo '<p class="cl-muted">' . esc_html(
			sprintf(
				/* translators: %s: timezone name, for example America/Chicago. */
				__( 'Views and clicks by the hour they happened, added up over the period. Times are in the site timezone (%s).', 'crazylinks' ),
				wp_timezone_string()
			)
		) . '</p>';
		echo '<div class="cl-hours" aria-hidden="true">';
		foreach ( $hours as $hour ) {
			$label = $hour_label( $hour['hour'] );
			printf(
				'<div class="cl-hours__col" title="%s"><div class="cl-hours__bars"><i style="height:%s%%"></i><i class="is-clicks" style="height:%s%%"></i></div><span>%s</span></div>',
				esc_attr(
					sprintf(
						/* translators: 1: hour such as 3 pm, 2: views, 3: clicks. */
						__( '%1$s: %2$s views, %3$s clicks', 'crazylinks' ),
						$label,
						number_format_i18n( $hour['views'] ),
						number_format_i18n( $hour['clicks'] )
					)
				),
				esc_attr( (string) round( 100 * $hour['views'] / $max, 1 ) ),
				esc_attr( (string) round( 100 * $hour['clicks'] / $max, 1 ) ),
				// Every third hour is labelled under its bars.
				0 === $hour['hour'] % 3 ? esc_html( $label ) : ''
			);
		}
		echo '</div>';
		self::legend( 'cl-legend cl-legend--bars' );
		echo '<details><summary>' . esc_html__( 'Show data table', 'crazylinks' ) . '</summary><div class="cl-table-wrap"><table class="widefat striped">';
		self::table_head(
			[
				[ __( 'Hour', 'crazylinks' ), '' ],
				[ __( 'Views', 'crazylinks' ), 'cl-num' ],
				[ __( 'Clicks', 'crazylinks' ), 'cl-num' ],
			]
		);
		foreach ( $hours as $hour ) {
			printf(
				'<tr><td>%s</td><td class="cl-num">%s</td><td class="cl-num">%s</td></tr>',
				esc_html( $hour_label( $hour['hour'] ) ),
				esc_html( number_format_i18n( $hour['views'] ) ),
				esc_html( number_format_i18n( $hour['clicks'] ) )
			);
		}
		echo '</tbody></table></div></details></div>';
	}

	/**
	 * Visits in time order: when each one happened, where it came from and what was clicked.
	 *
	 * @param array<string, mixed>                                     $report  Report.
	 * @param array{page_id:int, range:string, from:string, to:string} $filters Filters.
	 * @param array{from:string, to:string, days:int}                  $range   Range.
	 */
	private function activity( array $report, array $filters, array $range ): void {
		$page = isset( $_GET['apage'] ) ? max( 1, absint( $_GET['apage'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging.
		$data = Activity::visits( $filters['page_id'], $range['from'], $range['to'], Summary::link_titles( $report['blocks'] ), $page );
		echo '<div class="cl-panel" id="cl-activity"><h2>' . esc_html__( 'Activity', 'crazylinks' ) . '</h2>';
		echo '<p class="cl-muted">' . esc_html(
			sprintf(
				/* translators: %s: timezone name, for example America/Chicago. */
				__( 'Each row is one visit: when it started, where the visitor came from, and what they did, in order. The newest is first. A visit is one visitor on one day; nobody is identified, and the same person on another day counts as a new visit. Times are in the site timezone (%s).', 'crazylinks' ),
				wp_timezone_string()
			)
		) . '</p>';
		if ( ! $data['visits'] ) {
			echo '<p class="cl-muted">' . esc_html__( 'No data yet', 'crazylinks' ) . '</p></div>';
			return;
		}
		echo '<div class="cl-table-wrap"><table class="widefat cl-activity">';
		self::table_head(
			[
				[ __( 'When', 'crazylinks' ), '' ],
				[ __( 'Came from', 'crazylinks' ), '' ],
				[ __( 'Device', 'crazylinks' ), '' ],
				[ __( 'What they did', 'crazylinks' ), '' ],
			]
		);
		$day_format  = (string) get_option( 'date_format', 'F j, Y' );
		$time_format = 'g:i:s a';
		$last_day    = '';
		foreach ( $data['visits'] as $visit ) {
			$day = wp_date( 'Y-m-d', $visit['started'] );
			if ( $day !== $last_day ) {
				printf(
					'<tr class="cl-activity__day"><th scope="colgroup" colspan="4">%s</th></tr>',
					esc_html( wp_date( 'l, ' . $day_format, $visit['started'] ) )
				);
				$last_day = $day;
			}
			$steps = '';
			foreach ( $visit['events'] as $event ) {
				if ( 'click' === $event['type'] ) {
					/* translators: %s: the name of the link that was clicked. */
					$what = sprintf( esc_html__( 'Clicked %s', 'crazylinks' ), '<strong>' . esc_html( $event['title'] ) . '</strong>' );
				} else {
					$what = esc_html__( 'Opened the page', 'crazylinks' );
				}
				$steps .= sprintf(
					'<li class="%s"><time datetime="%s">%s</time> %s</li>',
					'click' === $event['type'] ? 'is-click' : 'is-view',
					esc_attr( gmdate( 'c', $event['ts'] ) ),
					esc_html( wp_date( $time_format, $event['ts'] ) ),
					$what
				);
			}
			printf(
				'<tr><td class="cl-activity__when">%s</td><td>%s</td><td>%s</td><td><ol class="cl-activity__steps">%s</ol></td></tr>',
				esc_html( wp_date( 'g:i a', $visit['started'] ) ),
				esc_html( $visit['label'] ),
				esc_html( trim( $visit['device'] . ( '' !== $visit['os'] ? ', ' . $visit['os'] : '' ) ) ),
				$steps // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
			);
		}
		echo '</tbody></table></div>';

		$first    = ( $data['page'] - 1 ) * $data['per_page'] + 1;
		$page_url = static fn( int $number ): string => self::url(
			[
				'page_id' => $filters['page_id'],
				'range'   => $filters['range'],
				'from'    => $filters['from'],
				'to'      => $filters['to'],
				'apage'   => $number,
			]
		) . '#cl-activity';
		echo '<p class="cl-activity__nav">';
		echo '<span class="cl-muted">' . esc_html(
			sprintf(
				/* translators: 1: first visit shown, 2: last visit shown, 3: total visits. */
				__( 'Visits %1$s to %2$s of %3$s', 'crazylinks' ),
				number_format_i18n( $first ),
				number_format_i18n( $first + count( $data['visits'] ) - 1 ),
				number_format_i18n( $data['total'] )
			)
		) . '</span>';
		if ( $data['page'] > 1 ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( $page_url( $data['page'] - 1 ) ), esc_html__( 'Newer', 'crazylinks' ) );
		}
		if ( $data['page'] < $data['pages'] ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( $page_url( $data['page'] + 1 ) ), esc_html__( 'Older', 'crazylinks' ) );
		}
		echo '</p></div>';
	}

	/**
	 * Prints a table's header row and opens its body.
	 *
	 * @param array<int, array{0:string, 1:string}> $columns One [ label, CSS class or '' ] pair per column.
	 */
	private static function table_head( array $columns ): void {
		echo '<thead><tr>';
		foreach ( $columns as [ $label, $css_class ] ) {
			if ( '' !== $css_class ) {
				printf( '<th scope="col" class="%s">%s</th>', esc_attr( $css_class ), esc_html( $label ) );
			} else {
				echo '<th scope="col">' . esc_html( $label ) . '</th>';
			}
		}
		echo '</tr></thead><tbody>';
	}

	/**
	 * Prints the Views / Clicks colour key of a chart.
	 *
	 * @param string $css_class Class of the legend paragraph.
	 */
	private static function legend( string $css_class ): void {
		printf(
			'<p class="%s"><span><i></i>%s</span><span><i class="is-clicks"></i>%s</span></p>',
			esc_attr( $css_class ),
			esc_html__( 'Views', 'crazylinks' ),
			esc_html__( 'Clicks', 'crazylinks' )
		);
	}

	/**
	 * A ratio as a localized percentage with one decimal.
	 *
	 * @param float $ratio Ratio (1.0 = 100%).
	 */
	private static function percent( float $ratio ): string {
		return number_format_i18n( $ratio * 100, 1 ) . '%';
	}

	/**
	 * Signed CSV download URL.
	 *
	 * @param string                                                   $which   daily | blocks | sources | activity.
	 * @param array{page_id:int, range:string, from:string, to:string} $filters Filters.
	 */
	private function export_url( string $which, array $filters ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'  => 'crazylinks_analytics_csv',
					'which'   => $which,
					'page_id' => $filters['page_id'],
					'range'   => $filters['range'],
					'from'    => $filters['from'],
					'to'      => $filters['to'],
				],
				admin_url( 'admin-post.php' )
			),
			'crazylinks_analytics_csv'
		);
	}

	/**
	 * CSV download (admin-post handler): nonce, capability, edit rights on the page.
	 */
	public function export(): void {
		check_admin_referer( 'crazylinks_analytics_csv' );
		$page_id = self::requested_page_id();
		$post    = get_post( $page_id );
		if ( ! current_user_can( 'view_crazylinks_analytics' ) || ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type || ! current_user_can( 'edit_post', $page_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export analytics for this page.', 'crazylinks' ), 403 );
		}
		$range = $this->resolve( [ 'page_id' => $page_id ] + self::requested_range() );
		if ( is_wp_error( $range ) ) {
			wp_die( esc_html( $range->get_error_message() ), 400 );
		}
		$report = Summary::report( $page_id, $range['from'], $range['to'] );
		$which  = isset( $_GET['which'] ) && in_array( $_GET['which'], [ 'blocks', 'sources', 'activity' ], true ) ? sanitize_key( wp_unslash( $_GET['which'] ) ) : 'daily';
		Csv::send_headers( sprintf( 'crazylinks-%s-%d-%s-%s.csv', $which, $page_id, $range['from'], $range['to'] ) );
		Csv::output( $this->csv_rows( $which, $page_id, $range, $report ) );
		exit;
	}

	/**
	 * Rows of one CSV export, header first.
	 *
	 * @param string                                  $which   daily | blocks | sources | activity.
	 * @param int                                     $page_id Page id.
	 * @param array{from:string, to:string, days:int} $range   Range.
	 * @param array<string, mixed>                    $report  Report.
	 * @return \Generator<int, array<int, mixed>>
	 */
	private function csv_rows( string $which, int $page_id, array $range, array $report ): \Generator {
		if ( 'blocks' === $which ) {
			// A Socials or newest-products block has one row for the block total, then one `social_icon`
			// or `product_item` row per item (already counted in the block row, so leave them out when
			// adding up clicks).
			yield [ 'block_id', 'title', 'type', 'clicks', 'share', 'item_id' ];
			foreach ( $report['blocks'] as $row ) {
				yield [ $row['block_id'], $row['title'], $row['type'], $row['clicks'], $row['share'], '' ];
				$item_type = 'socials' === $row['type'] ? 'social_icon' : 'product_item';
				foreach ( (array) ( $row['items'] ?? [] ) as $item ) {
					yield [ $row['block_id'], $item['title'], $item_type, $item['clicks'], $item['share'], $item['item_id'] ];
				}
			}
		} elseif ( 'sources' === $which ) {
			// One row per source, then one row per link that source clicked most.
			yield [ 'source', 'views', 'clicks', 'click_rate', 'link', 'link_clicks' ];
			foreach ( $report['sources'] as $source ) {
				yield [ $source['label'], $source['views'], $source['clicks'], $source['ctr'] ?? '', '', '' ];
				foreach ( $source['top'] as $link ) {
					yield [ $source['label'], '', '', '', $link['title'], $link['clicks'] ];
				}
			}
		} elseif ( 'activity' === $which ) {
			// One row per view or click, newest first. `visit` numbers the visits (1 = most recent); time is in the site timezone.
			yield [ 'time', 'visit', 'source', 'device', 'os', 'action', 'link' ];
			foreach ( Activity::rows( $page_id, $range['from'], $range['to'], Summary::link_titles( $report['blocks'] ) ) as $row ) {
				yield [ wp_date( 'Y-m-d H:i:s', $row['ts'] ), $row['visit'], $row['source'], $row['device'], $row['os'], $row['type'], $row['title'] ];
			}
		} else {
			yield [ 'date', 'views', 'clicks' ];
			foreach ( $report['timeseries'] as $point ) {
				yield [ $point['date'], $point['views'], $point['clicks'] ];
			}
		}
	}
}
