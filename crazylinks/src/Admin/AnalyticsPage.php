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
	 * Loads the screen assets (only here and on the Subscribers screen).
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
	 * Link pages the current user may see analytics for.
	 *
	 * @return \WP_Post[]
	 */
	private function pages(): array {
		$posts = get_posts(
			[
				'post_type'      => PostType::NAME,
				'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
		return array_values(
			array_filter(
				$posts,
				static fn( $p ) => $p instanceof \WP_Post && current_user_can( 'edit_post', $p->ID )
			)
		);
	}

	/**
	 * Reads and validates the filter query args.
	 *
	 * @param \WP_Post[] $pages Allowed pages.
	 * @return array{page_id:int, range:string, from:string, to:string}
	 */
	private function filters( array $pages ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen filters.
		$page_id = isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0;
		$range   = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30d';
		$from    = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to      = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable
		$ids = wp_list_pluck( $pages, 'ID' );
		if ( ! in_array( $page_id, $ids, true ) ) {
			$page_id = $ids ? (int) $ids[0] : 0;
		}
		if ( ! in_array( $range, [ '7d', '30d', '90d', 'custom' ], true ) ) {
			$range = '30d';
		}
		return [
			'page_id' => $page_id,
			'range'   => $range,
			'from'    => $from,
			'to'      => $to,
		];
	}

	/**
	 * Resolves filters to a report range.
	 *
	 * @param array{page_id:int, range:string, from:string, to:string} $f Filters.
	 * @return array{from:string, to:string, days:int}|\WP_Error
	 */
	private function resolve( array $f ) {
		if ( 'custom' === $f['range'] ) {
			return Summary::resolve_range( null, $f['from'], $f['to'] );
		}
		return Summary::resolve_range( $f['range'], null, null );
	}

	/**
	 * Screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'view_crazylinks_analytics' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view analytics.', 'crazylinks' ), 403 );
		}
		$pages = $this->pages();
		$f     = $this->filters( $pages );
		echo '<div class="wrap cl-analytics"><h1>' . esc_html__( 'CrazyLinks Analytics', 'crazylinks' ) . '</h1>';
		if ( ! $pages ) {
			echo '<p>' . esc_html__( 'Create a link page first; its analytics will show up here.', 'crazylinks' ) . '</p></div>';
			return;
		}
		if ( ! Settings::analytics_enabled() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Analytics are switched off in CrazyLinks settings, so new views and clicks are not being counted.', 'crazylinks' ) . '</p></div>';
		}
		$this->filter_form( $pages, $f );

		$range = $this->resolve( $f );
		if ( is_wp_error( $range ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $range->get_error_message() ) . '</p></div></div>';
			return;
		}
		$report = Summary::report( $f['page_id'], $range['from'], $range['to'] );
		$this->cards( $report );
		echo '<div class="cl-exports">';
		foreach ( [
			'daily'    => __( 'Download daily CSV', 'crazylinks' ),
			'blocks'   => __( 'Download links CSV', 'crazylinks' ),
			'sources'  => __( 'Download sources CSV', 'crazylinks' ),
			'activity' => __( 'Download activity CSV', 'crazylinks' ),
		] as $which => $label ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( $this->export_url( $which, $f ) ), esc_html( $label ) );
		}
		echo '</div>';

		echo '<div class="cl-panel"><h2>' . esc_html__( 'Views and clicks per day (UTC)', 'crazylinks' ) . '</h2>';
		echo Chart::svg( $report['timeseries'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Chart::svg().
		echo '<p class="cl-legend"><span><i></i>' . esc_html__( 'Views', 'crazylinks' ) . '</span><span><i class="is-clicks"></i>' . esc_html__( 'Clicks', 'crazylinks' ) . '</span></p>';
		echo '<details><summary>' . esc_html__( 'Show data table', 'crazylinks' ) . '</summary><div class="cl-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'crazylinks' ) . '</th><th class="cl-num">' . esc_html__( 'Views', 'crazylinks' ) . '</th><th class="cl-num">' . esc_html__( 'Clicks', 'crazylinks' ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( $report['timeseries'] ) as $d ) {
			printf( '<tr><td>%s</td><td class="cl-num">%s</td><td class="cl-num">%s</td></tr>', esc_html( $d['date'] ), esc_html( number_format_i18n( $d['views'] ) ), esc_html( number_format_i18n( $d['clicks'] ) ) );
		}
		echo '</tbody></table></div></details></div>';

		$this->blocks_table( $report, $f );
		$this->sources_table( $report );
		$this->breakdowns( $report );
		$this->hours_chart( $f['page_id'], $range );
		$this->activity( $report, $f, $range );
		echo '</div>';
	}

	/**
	 * Page and range picker (GET form, works without JavaScript).
	 *
	 * @param \WP_Post[]                                               $pages Pages.
	 * @param array{page_id:int, range:string, from:string, to:string} $f     Filters.
	 */
	private function filter_form( array $pages, array $f ): void {
		echo '<form class="cl-analytics__filters" method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( PostType::NAME ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label>' . esc_html__( 'Link page', 'crazylinks' ) . '<select name="page_id">';
		foreach ( $pages as $p ) {
			$title = '' !== $p->post_title ? $p->post_title : $p->post_name;
			printf( '<option value="%d"%s>%s</option>', (int) $p->ID, selected( $f['page_id'], $p->ID, false ), esc_html( $title ) );
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Range', 'crazylinks' ) . '<select name="range">';
		foreach ( [
			'7d'     => __( 'Last 7 days', 'crazylinks' ),
			'30d'    => __( 'Last 30 days', 'crazylinks' ),
			'90d'    => __( 'Last 90 days', 'crazylinks' ),
			'custom' => __( 'Custom', 'crazylinks' ),
		] as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $f['range'], $value, false ), esc_html( $label ) );
		}
		echo '</select></label>';
		$hidden = 'custom' === $f['range'] ? '' : ' hidden';
		printf( '<label data-cl-custom%s>%s<input type="date" name="from" value="%s"></label>', $hidden, esc_html__( 'From', 'crazylinks' ), esc_attr( $f['from'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $hidden is a literal.
		printf( '<label data-cl-custom%s>%s<input type="date" name="to" value="%s"></label>', $hidden, esc_html__( 'To', 'crazylinks' ), esc_attr( $f['to'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Update', 'crazylinks' ) . '</button></form>';
	}

	/**
	 * Totals cards.
	 *
	 * @param array<string, mixed> $report Report.
	 */
	private function cards( array $report ): void {
		$t = $report['totals'];
		echo '<div class="cl-cards">';
		$cards = [
			[ __( 'Views', 'crazylinks' ), number_format_i18n( $t['views'] ), '' ],
			[
				__( 'Unique visitors', 'crazylinks' ),
				number_format_i18n( $t['uniques'] ),
				__( 'Counted per day, then added up. Visitor IDs change every day, so someone who visits on two days counts twice.', 'crazylinks' ),
			],
			[ __( 'Clicks', 'crazylinks' ), number_format_i18n( $t['clicks'] ), '' ],
			[ __( 'Click rate', 'crazylinks' ), number_format_i18n( $t['ctr'] * 100, 1 ) . '%', __( 'Clicks divided by views.', 'crazylinks' ) ],
		];
		foreach ( $cards as [ $label, $value, $tip ] ) {
			$label_html = '' !== $tip ? '<abbr title="' . esc_attr( $tip ) . '" tabindex="0">' . esc_html( $label ) . '</abbr>' : esc_html( $label );
			printf( '<div class="card cl-card"><p class="cl-card__label">%s</p><p class="cl-card__value">%s</p></div>', $label_html, esc_html( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}
		echo '</div>';
	}

	/**
	 * Per-block table, sortable by clicks or title via the query string.
	 *
	 * @param array<string, mixed>                                     $report Report.
	 * @param array{page_id:int, range:string, from:string, to:string} $f      Filters.
	 */
	private function blocks_table( array $report, array $f ): void {
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
		$base = add_query_arg( array_filter( $f, static fn( $v ) => '' !== $v && 0 !== $v ), admin_url( 'edit.php?post_type=' . PostType::NAME . '&page=' . self::SLUG ) );
		$head = static function ( string $key, string $label, string $th_class ) use ( $base, $orderby, $order ): string {
			$next = ( $orderby === $key && 'desc' === $order ) ? 'asc' : 'desc';
			$mark = $orderby === $key ? ( 'asc' === $order ? ' ▲' : ' ▼' ) : '';
			$sort = $orderby === $key ? ( 'asc' === $order ? 'ascending' : 'descending' ) : 'none';
			return '<th scope="col" class="' . esc_attr( $th_class ) . '" aria-sort="' . esc_attr( $sort ) . '"><a href="' . esc_url(
				add_query_arg(
					[
						'orderby' => $key,
						'order'   => $next,
					],
					$base
				)
			) . '">' . esc_html( $label . $mark ) . '</a></th>';
		};
		echo '<div class="cl-panel"><h2>' . esc_html__( 'Links', 'crazylinks' ) . '</h2><div class="cl-table-wrap"><table class="widefat striped"><thead><tr>';
		echo $head( 'title', __( 'Link', 'crazylinks' ), '' ), '<th scope="col">' . esc_html__( 'Type', 'crazylinks' ) . '</th>', $head( 'clicks', __( 'Clicks', 'crazylinks' ), 'cl-num' ), '<th scope="col" class="cl-num">' . esc_html__( 'Share', 'crazylinks' ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $head.
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'This page has no trackable links yet.', 'crazylinks' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td class="cl-num">%s</td><td class="cl-num">%s</td></tr>',
				esc_html( $r['title'] ),
				esc_html( $r['type'] ),
				esc_html( number_format_i18n( $r['clicks'] ) ),
				esc_html( number_format_i18n( $r['share'] * 100, 1 ) . '%' )
			);
			// A Socials block lists its clicks per platform underneath.
			foreach ( (array) ( $r['items'] ?? [] ) as $item ) {
				printf(
					'<tr class="cl-subrow"><td>%s</td><td></td><td class="cl-num">%s</td><td class="cl-num">%s</td></tr>',
					esc_html( $item['title'] ),
					esc_html( number_format_i18n( $item['clicks'] ) ),
					esc_html( number_format_i18n( $item['share'] * 100, 1 ) . '%' )
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
		echo '<p class="cl-muted">' . esc_html__( 'A click is matched to the visit it came from, so you can see which links each audience prefers. Sources are named from your campaign tags (utm_source), the app the page was opened in, or the referring site. Covers the same period as the breakdowns below.', 'crazylinks' ) . '</p>';
		echo '<div class="cl-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Source', 'crazylinks' ) . '</th><th scope="col" class="cl-num">' . esc_html__( 'Views', 'crazylinks' ) . '</th><th scope="col" class="cl-num">' . esc_html__( 'Clicks', 'crazylinks' ) . '</th><th scope="col" class="cl-num">' . esc_html__( 'Click rate', 'crazylinks' ) . '</th><th scope="col">' . esc_html__( 'Most clicked', 'crazylinks' ) . '</th></tr></thead><tbody>';
		if ( ! $report['sources'] ) {
			echo '<tr><td colspan="5" class="cl-muted">' . esc_html__( 'No data yet', 'crazylinks' ) . '</td></tr>';
		}
		foreach ( $report['sources'] as $s ) {
			$top = array_map(
				static fn( $t ) => $t['title'] . ' (' . number_format_i18n( $t['clicks'] ) . ')',
				$s['top']
			);
			printf(
				'<tr><td>%s</td><td class="cl-num">%s</td><td class="cl-num">%s</td><td class="cl-num">%s</td><td>%s</td></tr>',
				esc_html( $s['label'] ),
				esc_html( number_format_i18n( $s['views'] ) ),
				esc_html( number_format_i18n( $s['clicks'] ) ),
				esc_html( null === $s['ctr'] ? '—' : number_format_i18n( $s['ctr'] * 100, 1 ) . '%' ),
				esc_html( $top ? implode( ', ', $top ) : '—' )
			);
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * Views and clicks per hour of the day, in the site's timezone.
	 *
	 * @param int                                     $page_id Page id.
	 * @param array{from:string, to:string, days:int} $range   Range.
	 */
	private function hours_chart( int $page_id, array $range ): void {
		$hours = Activity::hours( $page_id, $range['from'], $range['to'], wp_timezone() );
		$max   = max( 1, ...array_map( static fn( $h ) => max( $h['views'], $h['clicks'] ), $hours ) );
		$label = static fn( int $h ): string => wp_date( 'g a', gmmktime( $h, 0, 0, 1, 1, 2001 ), new \DateTimeZone( 'UTC' ) );
		echo '<div class="cl-panel"><h2>' . esc_html__( 'Time of day', 'crazylinks' ) . '</h2>';
		/* translators: %s: timezone name, for example America/Chicago. */
		echo '<p class="cl-muted">' . esc_html( sprintf( __( 'Views and clicks by the hour they happened, added up over the period. Times are in the site timezone (%s).', 'crazylinks' ), wp_timezone_string() ) ) . '</p>';
		echo '<div class="cl-hours" aria-hidden="true">';
		foreach ( $hours as $h ) {
			printf(
				'<div class="cl-hours__col" title="%s"><div class="cl-hours__bars"><i style="height:%s%%"></i><i class="is-clicks" style="height:%s%%"></i></div><span>%s</span></div>',
				/* translators: 1: hour such as 3 pm, 2: views, 3: clicks. */
				esc_attr( sprintf( __( '%1$s: %2$s views, %3$s clicks', 'crazylinks' ), $label( $h['hour'] ), number_format_i18n( $h['views'] ), number_format_i18n( $h['clicks'] ) ) ),
				esc_attr( (string) round( 100 * $h['views'] / $max, 1 ) ),
				esc_attr( (string) round( 100 * $h['clicks'] / $max, 1 ) ),
				0 === $h['hour'] % 3 ? esc_html( $label( $h['hour'] ) ) : ''
			);
		}
		echo '</div><p class="cl-legend cl-legend--bars"><span><i></i>' . esc_html__( 'Views', 'crazylinks' ) . '</span><span><i class="is-clicks"></i>' . esc_html__( 'Clicks', 'crazylinks' ) . '</span></p>';
		echo '<details><summary>' . esc_html__( 'Show data table', 'crazylinks' ) . '</summary><div class="cl-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Hour', 'crazylinks' ) . '</th><th scope="col" class="cl-num">' . esc_html__( 'Views', 'crazylinks' ) . '</th><th scope="col" class="cl-num">' . esc_html__( 'Clicks', 'crazylinks' ) . '</th></tr></thead><tbody>';
		foreach ( $hours as $h ) {
			printf( '<tr><td>%s</td><td class="cl-num">%s</td><td class="cl-num">%s</td></tr>', esc_html( $label( $h['hour'] ) ), esc_html( number_format_i18n( $h['views'] ) ), esc_html( number_format_i18n( $h['clicks'] ) ) );
		}
		echo '</tbody></table></div></details></div>';
	}

	/**
	 * Visits in time order: when each one happened, where it came from and what was clicked.
	 *
	 * @param array<string, mixed>                                     $report Report.
	 * @param array{page_id:int, range:string, from:string, to:string} $f      Filters.
	 * @param array{from:string, to:string, days:int}                  $range  Range.
	 */
	private function activity( array $report, array $f, array $range ): void {
		$page = isset( $_GET['apage'] ) ? max( 1, absint( $_GET['apage'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging.
		$data = Activity::visits( $f['page_id'], $range['from'], $range['to'], Summary::link_titles( $report['blocks'] ), $page );
		echo '<div class="cl-panel" id="cl-activity"><h2>' . esc_html__( 'Activity', 'crazylinks' ) . '</h2>';
		/* translators: %s: timezone name, for example America/Chicago. */
		echo '<p class="cl-muted">' . esc_html( sprintf( __( 'Each row is one visit: when it started, where the visitor came from, and what they did, in order. The newest is first. A visit is one visitor on one day; nobody is identified, and the same person on another day counts as a new visit. Times are in the site timezone (%s).', 'crazylinks' ), wp_timezone_string() ) ) . '</p>';
		if ( ! $data['visits'] ) {
			echo '<p class="cl-muted">' . esc_html__( 'No data yet', 'crazylinks' ) . '</p></div>';
			return;
		}
		echo '<div class="cl-table-wrap"><table class="widefat cl-activity"><thead><tr><th scope="col">' . esc_html__( 'When', 'crazylinks' ) . '</th><th scope="col">' . esc_html__( 'Came from', 'crazylinks' ) . '</th><th scope="col">' . esc_html__( 'Device', 'crazylinks' ) . '</th><th scope="col">' . esc_html__( 'What they did', 'crazylinks' ) . '</th></tr></thead><tbody>';
		$day_format  = (string) get_option( 'date_format', 'F j, Y' );
		$time_format = 'g:i:s a';
		$last_day    = '';
		foreach ( $data['visits'] as $v ) {
			$day = wp_date( 'Y-m-d', $v['started'] );
			if ( $day !== $last_day ) {
				printf( '<tr class="cl-activity__day"><th scope="colgroup" colspan="4">%s</th></tr>', esc_html( wp_date( 'l, ' . $day_format, $v['started'] ) ) );
				$last_day = $day;
			}
			$steps = '';
			foreach ( $v['events'] as $e ) {
				$steps .= sprintf(
					'<li class="%s"><time datetime="%s">%s</time> %s</li>',
					'click' === $e['type'] ? 'is-click' : 'is-view',
					esc_attr( gmdate( 'c', $e['ts'] ) ),
					esc_html( wp_date( $time_format, $e['ts'] ) ),
					/* translators: %s: the name of the link that was clicked. */
					'click' === $e['type'] ? sprintf( esc_html__( 'Clicked %s', 'crazylinks' ), '<strong>' . esc_html( $e['title'] ) . '</strong>' ) : esc_html__( 'Opened the page', 'crazylinks' )
				);
			}
			printf(
				'<tr><td class="cl-activity__when">%s</td><td>%s</td><td>%s</td><td><ol class="cl-activity__steps">%s</ol></td></tr>',
				esc_html( wp_date( 'g:i a', $v['started'] ) ),
				esc_html( $v['label'] ),
				esc_html( trim( $v['device'] . ( '' !== $v['os'] ? ', ' . $v['os'] : '' ) ) ),
				$steps // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
			);
		}
		echo '</tbody></table></div>';

		$first = ( $data['page'] - 1 ) * $data['per_page'] + 1;
		$url   = static fn( int $p ): string => add_query_arg(
			[
				'post_type' => PostType::NAME,
				'page'      => self::SLUG,
				'page_id'   => $f['page_id'],
				'range'     => $f['range'],
				'from'      => $f['from'],
				'to'        => $f['to'],
				'apage'     => $p,
			],
			admin_url( 'edit.php' )
		) . '#cl-activity';
		echo '<p class="cl-activity__nav">';
		/* translators: 1: first visit shown, 2: last visit shown, 3: total visits. */
		echo '<span class="cl-muted">' . esc_html( sprintf( __( 'Visits %1$s to %2$s of %3$s', 'crazylinks' ), number_format_i18n( $first ), number_format_i18n( $first + count( $data['visits'] ) - 1 ), number_format_i18n( $data['total'] ) ) ) . '</span>';
		if ( $data['page'] > 1 ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( $url( $data['page'] - 1 ) ), esc_html__( 'Newer', 'crazylinks' ) );
		}
		if ( $data['page'] < $data['pages'] ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( $url( $data['page'] + 1 ) ), esc_html__( 'Older', 'crazylinks' ) );
		}
		echo '</p></div>';
	}

	/**
	 * Breakdown tables.
	 *
	 * @param array<string, mixed> $report Report.
	 */
	private function breakdowns( array $report ): void {
		$b     = $report['breakdowns'];
		$views = max( 1, (int) $report['totals']['views'] );
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
		foreach ( $b['referrer'] as $r ) {
			$referrers[ $r['host'] ] = $r['count'];
		}
		foreach ( [
			[ __( 'Device', 'crazylinks' ), $b['device'] ],
			[ __( 'Operating system', 'crazylinks' ), $b['os'] ],
			[ __( 'In-app browser', 'crazylinks' ), $b['in_app'] ],
			[ __( 'Referrer', 'crazylinks' ), $referrers ],
		] as [ $title, $data ] ) {
			echo '<div class="cl-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html( $title ) . '</th><th scope="col" class="cl-num">' . esc_html__( 'Views', 'crazylinks' ) . '</th></tr></thead><tbody>';
			if ( ! $data ) {
				echo '<tr><td colspan="2" class="cl-muted">' . esc_html__( 'No data yet', 'crazylinks' ) . '</td></tr>';
			}
			foreach ( $data as $label => $count ) {
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
	 * Signed CSV download URL.
	 *
	 * @param string                                                   $which daily | blocks.
	 * @param array{page_id:int, range:string, from:string, to:string} $f     Filters.
	 */
	private function export_url( string $which, array $f ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'  => 'crazylinks_analytics_csv',
					'which'   => $which,
					'page_id' => $f['page_id'],
					'range'   => $f['range'],
					'from'    => $f['from'],
					'to'      => $f['to'],
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
		$page_id = isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0;
		$post    = get_post( $page_id );
		if ( ! current_user_can( 'view_crazylinks_analytics' ) || ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type || ! current_user_can( 'edit_post', $page_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export analytics for this page.', 'crazylinks' ), 403 );
		}
		$f     = [
			'page_id' => $page_id,
			'range'   => isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30d',
			'from'    => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'      => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
		];
		$range = $this->resolve( $f );
		if ( is_wp_error( $range ) ) {
			wp_die( esc_html( $range->get_error_message() ), 400 );
		}
		$report = Summary::report( $page_id, $range['from'], $range['to'] );
		$which  = isset( $_GET['which'] ) && in_array( $_GET['which'], [ 'blocks', 'sources', 'activity' ], true ) ? sanitize_key( wp_unslash( $_GET['which'] ) ) : 'daily';
		Csv::send_headers( sprintf( 'crazylinks-%s-%d-%s-%s.csv', $which, $page_id, $range['from'], $range['to'] ) );
		if ( 'blocks' === $which ) {
			// A Socials block has one row for the block total, then one `social_icon` row per platform
			// (already counted in the block row, so leave them out when adding up clicks).
			echo Csv::line( [ 'block_id', 'title', 'type', 'clicks', 'share', 'item_id' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML.
			foreach ( $report['blocks'] as $r ) {
				echo Csv::line( [ $r['block_id'], $r['title'], $r['type'], $r['clicks'], $r['share'], '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( (array) ( $r['items'] ?? [] ) as $item ) {
					echo Csv::line( [ $r['block_id'], $item['title'], 'socials' === $r['type'] ? 'social_icon' : 'product_item', $item['clicks'], $item['share'], $item['item_id'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
		} elseif ( 'sources' === $which ) {
			// One row per source, then one row per link that source clicked most (top three).
			echo Csv::line( [ 'source', 'views', 'clicks', 'click_rate', 'link', 'link_clicks' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $report['sources'] as $s ) {
				echo Csv::line( [ $s['label'], $s['views'], $s['clicks'], $s['ctr'] ?? '', '', '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( $s['top'] as $t ) {
					echo Csv::line( [ $s['label'], '', '', '', $t['title'], $t['clicks'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
		} elseif ( 'activity' === $which ) {
			// One row per view or click, newest first. `visit` numbers the visits (1 = most recent); time is in the site timezone.
			echo Csv::line( [ 'time', 'visit', 'source', 'device', 'os', 'action', 'link' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( Activity::rows( $page_id, $range['from'], $range['to'], Summary::link_titles( $report['blocks'] ) ) as $r ) {
				echo Csv::line( [ wp_date( 'Y-m-d H:i:s', $r['ts'] ), $r['visit'], $r['source'], $r['device'], $r['os'], $r['type'], $r['title'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		} else {
			echo Csv::line( [ 'date', 'views', 'clicks' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $report['timeseries'] as $d ) {
				echo Csv::line( [ $d['date'], $d['views'], $d['clicks'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		exit;
	}
}
