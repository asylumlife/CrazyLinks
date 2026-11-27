<?php
/**
 * Analytics summary queries.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

use CrazyLinks\PageStore;
use CrazyLinks\Render\Icons;
use CrazyLinks\Render\Payments;
use CrazyLinks\Schema\UrlPolicy;
use CrazyLinks\Woo\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Reads rollups for past days plus raw events for today (not rolled up yet).
 *
 * `report()` is the contract used by the editor and the Analytics screen:
 * - Days up to `crazylinks_rollup_through` come from `crazylinks_daily` (so they survive event
 *   pruning); later days (normally today) come from `crazylinks_events`.
 * - `uniques` is the sum of each day's distinct visitor hashes. The hash salt rotates daily, so a
 *   person who visits on two days is counted twice; this holds with or without raw events.
 * - Clicks on a social icon carry the icon's item id, so a Socials block row lists clicks per
 *   platform under `items`. Clicks logged before DB 7 have no item id and show as one extra row.
 *   A newest-products Product block lists clicks per product the same way.
 * - Device, OS, in-app and referrer breakdowns need raw events and cover only the retention window.
 * - `sources` (views and clicks per traffic source) also needs raw events. A click has no source of
 *   its own (its referrer is the link page), so it takes the source of the same visitor's latest
 *   view of the page that day, matched on the daily visitor hash. See sources().
 */
final class Summary {

	public const TOTALS_TRANSIENT = 'crazylinks_page_totals';

	/**
	 * Maximum days in one report.
	 */
	public const MAX_DAYS = 731;

	/**
	 * Maximum days for_page() looks back.
	 */
	private const FOR_PAGE_MAX_DAYS = 3650;

	/**
	 * Preset range tokens and the number of days each covers (today included).
	 */
	public const RANGES = [
		'7d'  => 7,
		'30d' => 30,
		'90d' => 90,
	];

	/**
	 * Days page_totals() covers (today included).
	 */
	private const TOTALS_DAYS = 30;

	/**
	 * How long page_totals() is cached, so today's numbers keep moving.
	 */
	private const TOTALS_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Most clicked links listed per traffic source.
	 */
	private const TOP_LINKS = 3;

	/**
	 * Known platforms for traffic sources: key => [ label, host pattern, utm_source aliases ].
	 * In-app browser tokens (Visitor::in_app()) are keys here too.
	 */
	private const PLATFORMS = [
		'instagram'  => [ 'Instagram', '/(^|\.)instagram\.com$/', [ 'ig', 'insta' ] ],
		'facebook'   => [ 'Facebook', '/(^|\.)(facebook\.com|fb\.com|fb\.me|messenger\.com)$/', [ 'fb', 'meta' ] ],
		'tiktok'     => [ 'TikTok', '/(^|\.)tiktok\.com$/', [ 'tt' ] ],
		'youtube'    => [ 'YouTube', '/(^|\.)(youtube\.com|youtu\.be)$/', [ 'yt' ] ],
		'twitter'    => [ 'X (Twitter)', '/(^|\.)(twitter\.com|x\.com|t\.co)$/', [ 'x' ] ],
		'threads'    => [ 'Threads', '/(^|\.)threads\.(net|com)$/', [] ],
		'bluesky'    => [ 'Bluesky', '/(^|\.)bsky\.(app|social)$/', [ 'bsky' ] ],
		'linkedin'   => [ 'LinkedIn', '/(^|\.)(linkedin\.com|lnkd\.in)$/', [] ],
		'pinterest'  => [ 'Pinterest', '/(^|\.)(pinterest\.[a-z.]+|pin\.it)$/', [] ],
		'snapchat'   => [ 'Snapchat', '/(^|\.)snapchat\.com$/', [ 'snap' ] ],
		'reddit'     => [ 'Reddit', '/(^|\.)(reddit\.com|redd\.it)$/', [] ],
		'twitch'     => [ 'Twitch', '/(^|\.)twitch\.tv$/', [] ],
		'discord'    => [ 'Discord', '/(^|\.)(discord\.com|discord\.gg|discordapp\.com)$/', [] ],
		'google'     => [ 'Google', '/(^|\.)google\.[a-z.]+$/', [] ],
		'bing'       => [ 'Bing', '/(^|\.)bing\.com$/', [] ],
		'duckduckgo' => [ 'DuckDuckGo', '/(^|\.)duckduckgo\.com$/', [ 'ddg' ] ],
	];

	/**
	 * Raw events table name.
	 */
	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'crazylinks_events';
	}

	/**
	 * Daily rollup table name.
	 */
	public static function daily_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'crazylinks_daily';
	}

	/**
	 * The UTC day after the given one.
	 *
	 * @param string $day Y-m-d.
	 * @return string Y-m-d.
	 */
	public static function next_day( string $day ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $day . ' UTC +1 day' ) );
	}

	/**
	 * UTC datetime bounds of an inclusive Y-m-d range: its first midnight and the midnight after
	 * its last day (compare with `ts >= start AND ts < end`).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array{0:string, 1:string}
	 */
	public static function day_bounds( string $from, string $to ): array {
		return [ $from . ' 00:00:00', self::next_day( $to ) . ' 00:00:00' ];
	}

	/**
	 * Resolves request parameters to an inclusive UTC date range. Order of precedence: from/to,
	 * range (7d|30d|90d), days, default 30d.
	 *
	 * @param string|null $range Range token.
	 * @param string|null $from  Y-m-d.
	 * @param string|null $to    Y-m-d.
	 * @param int|null    $days  Legacy day count.
	 * @return array{from:string, to:string, days:int}|\WP_Error
	 */
	public static function resolve_range( ?string $range, ?string $from, ?string $to, ?int $days = null ) {
		$today = gmdate( 'Y-m-d' );
		if ( null !== $from && '' !== $from ) {
			$to = ( null === $to || '' === $to ) ? $today : $to;
			foreach ( [ $from, $to ] as $date ) {
				$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
				if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
					return new \WP_Error( 'crazylinks_bad_range', __( 'Dates must look like 2026-01-31.', 'crazylinks' ), [ 'status' => 400 ] );
				}
			}
			$to = min( $to, $today );
			if ( $from > $to ) {
				return new \WP_Error( 'crazylinks_bad_range', __( 'The start date must not be after the end date.', 'crazylinks' ), [ 'status' => 400 ] );
			}
		} else {
			$count = self::RANGES[ (string) $range ] ?? ( null !== $days && $days > 0 ? $days : 30 );
			$count = max( 1, min( self::MAX_DAYS, $count ) );
			$to    = $today;
			$from  = gmdate( 'Y-m-d', time() - ( $count - 1 ) * DAY_IN_SECONDS );
		}
		$span = (int) round( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS ) + 1;
		if ( $span > self::MAX_DAYS ) {
			return new \WP_Error( 'crazylinks_bad_range', __( 'That range is too long.', 'crazylinks' ), [ 'status' => 400 ] );
		}
		return [
			'from' => $from,
			'to'   => $to,
			'days' => $span,
		];
	}

	/**
	 * Per-day, per-type, per-block view and click counts from the rollup (rolled days) plus
	 * events (the rest).
	 *
	 * @param int|null $page_id Page id, or null for every page.
	 * @param string   $from    Y-m-d.
	 * @param string   $to      Y-m-d.
	 * @return array<int, array{page_id:int, block_id:string, item_id:string, day:string, type:string, count:int, uniques:int}>
	 */
	private static function rows( ?int $page_id, string $from, string $to ): array {
		global $wpdb;
		$through = (string) get_option( 'crazylinks_rollup_through', '' );
		// Both queries take the page id twice: `%d = 0 OR page_id = %d` matches every page when it is 0.
		$page = (int) $page_id;
		$rows = [];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; page_totals() caches the result that is read often.
		if ( '' !== $through && $from <= $through ) {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT page_id, block_id, item_id, day, type, count, uniques FROM %i
					WHERE day >= %s AND day <= %s AND type IN ('view', 'click') AND ( %d = 0 OR page_id = %d )",
					self::daily_table(),
					$from,
					min( $to, $through ),
					$page,
					$page
				),
				ARRAY_A
			);
		}
		$live_from = '' !== $through ? max( $from, self::next_day( $through ) ) : $from;
		if ( $live_from <= $to ) {
			[ $start, $end ] = self::day_bounds( $live_from, $to );
			$live            = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT page_id, COALESCE(block_id, '') AS block_id, COALESCE(item_id, '') AS item_id, DATE(ts) AS day, type, COUNT(*) AS count, COUNT(DISTINCT visitor_hash) AS uniques
					FROM %i WHERE ts >= %s AND ts < %s AND type IN ('view', 'click') AND ( %d = 0 OR page_id = %d )
					GROUP BY page_id, COALESCE(block_id, ''), COALESCE(item_id, ''), DATE(ts), type",
					self::events_table(),
					$start,
					$end,
					$page,
					$page
				),
				ARRAY_A
			);
			$rows            = array_merge( $rows, $live );
		}
		// phpcs:enable
		return array_map(
			static fn( array $row ): array => [
				'page_id'  => (int) $row['page_id'],
				'block_id' => (string) $row['block_id'],
				'item_id'  => (string) $row['item_id'],
				'day'      => (string) $row['day'],
				'type'     => (string) $row['type'],
				'count'    => (int) $row['count'],
				'uniques'  => (int) $row['uniques'],
			],
			$rows
		);
	}

	/**
	 * The analytics report for one page and an inclusive UTC date range (see class docblock).
	 *
	 * @param int    $page_id Page id.
	 * @param string $from    Y-m-d.
	 * @param string $to      Y-m-d.
	 * @return array<string, mixed>
	 */
	public static function report( int $page_id, string $from, string $to ): array {
		$views   = 0;
		$clicks  = 0;
		$uniques = 0;
		$series  = [];
		$last    = (int) strtotime( $to . ' UTC' );
		for ( $t = (int) strtotime( $from . ' UTC' ); $t <= $last; $t += DAY_IN_SECONDS ) {
			$day            = gmdate( 'Y-m-d', $t );
			$series[ $day ] = [
				'date'   => $day,
				'views'  => 0,
				'clicks' => 0,
			];
		}
		$per_block = [];
		$per_item  = [];
		foreach ( self::rows( $page_id, $from, $to ) as $row ) {
			$day = $row['day'];
			if ( ! isset( $series[ $day ] ) ) {
				continue;
			}
			if ( 'view' === $row['type'] ) {
				$views                   += $row['count'];
				$uniques                 += $row['uniques'];
				$series[ $day ]['views'] += $row['count'];
			} elseif ( 'click' === $row['type'] ) {
				$block_id                          = $row['block_id'];
				$item_id                           = $row['item_id'];
				$clicks                           += $row['count'];
				$series[ $day ]['clicks']         += $row['count'];
				$per_block[ $block_id ]            = ( $per_block[ $block_id ] ?? 0 ) + $row['count'];
				$per_item[ $block_id ][ $item_id ] = ( $per_item[ $block_id ][ $item_id ] ?? 0 ) + $row['count'];
			}
		}

		$block_rows = self::block_rows( $page_id, $per_block, $per_item, $clicks, $views );

		return [
			'totals'                => [
				'views'   => $views,
				'uniques' => $uniques,
				'clicks'  => $clicks,
				'ctr'     => $views > 0 ? round( $clicks / $views, 4 ) : 0.0,
			],
			'timeseries'            => array_values( $series ),
			'blocks'                => $block_rows,
			'sources'               => self::sources( $page_id, $from, $to, $block_rows ),
			'breakdowns'            => self::breakdowns( $page_id, $from, $to ),
			/**
			 * Filters whether country data is available (CrazyLinks does not collect it).
			 *
			 * @param bool $available Default false.
			 */
			'country_available'     => (bool) apply_filters( 'crazylinks_country_available', false ),
			'range'                 => [
				'from' => $from,
				'to'   => $to,
				'days' => count( $series ),
			],
			'events_available_from' => self::first_event_day( $page_id ),
		];
	}

	/**
	 * Per-block rows: every clickable block still on the page plus removed blocks that have clicks.
	 *
	 * A Socials block row and a newest-products Product block row also carry `items`: one row per
	 * icon or product (see social_items() and product_items()).
	 *
	 * @param int                               $page_id   Page id.
	 * @param array<string, int>                $per_block Clicks by block id.
	 * @param array<string, array<string, int>> $per_item  Clicks by block id, then item id ('' = no item recorded).
	 * @param int                               $clicks    Total clicks.
	 * @param int                               $views     Total views.
	 * @return array<int, array<string, mixed>>
	 */
	private static function block_rows( int $page_id, array $per_block, array $per_item, int $clicks, int $views ): array {
		$doc  = PageStore::get_document( $page_id ) ?? [];
		$rows = [];
		$seen = [];
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $block ) {
			if ( ! is_array( $block ) || ! in_array( $block['type'] ?? '', ClickRedirect::TYPES, true ) ) {
				continue;
			}
			$id          = (string) $block['id'];
			$seen[ $id ] = true;
			$row         = self::block_row( $id, self::block_title( $block ), (string) $block['type'], $per_block[ $id ] ?? 0, $clicks, $views );
			if ( 'socials' === $block['type'] ) {
				$row['items'] = self::social_items( $block, $per_item[ $id ] ?? [], $clicks );
			} elseif ( 'product' === $block['type'] && 'latest' === ( $block['props']['mode'] ?? 'single' ) ) {
				$row['items'] = self::product_items( $block, $per_item[ $id ] ?? [], $clicks );
			}
			$rows[] = $row;
		}
		foreach ( $per_block as $id => $block_clicks ) {
			if ( '' === (string) $id || isset( $seen[ (string) $id ] ) ) {
				continue;
			}
			$rows[] = self::block_row( (string) $id, self::removed_block_title(), 'unknown', $block_clicks, $clicks, $views );
		}
		usort(
			$rows,
			static fn( $a, $b ) => $b['clicks'] <=> $a['clicks']
		);
		return $rows;
	}

	/**
	 * One block row. `share` is the block's part of all clicks, `ctr` its clicks per page view.
	 *
	 * @param string $id           Block id.
	 * @param string $title        Title.
	 * @param string $type         Type.
	 * @param int    $block_clicks Clicks on the block.
	 * @param int    $clicks       Total clicks.
	 * @param int    $views        Total views.
	 * @return array<string, mixed>
	 */
	private static function block_row( string $id, string $title, string $type, int $block_clicks, int $clicks, int $views ): array {
		return [
			'block_id' => $id,
			'title'    => $title,
			'type'     => $type,
			'clicks'   => $block_clicks,
			'share'    => self::share( $block_clicks, $clicks ),
			'ctr'      => $views > 0 ? round( $block_clicks / $views, 4 ) : null,
		];
	}

	/**
	 * One row under a block: a social icon or a listed product.
	 *
	 * @param string $id          Item id, or ''.
	 * @param string $network     Social network key, or ''.
	 * @param string $title       Title.
	 * @param int    $item_clicks Clicks on the item.
	 * @param int    $clicks      Total clicks on the page.
	 * @return array{item_id:string, network:string, title:string, clicks:int, share:float}
	 */
	private static function item_row( string $id, string $network, string $title, int $item_clicks, int $clicks ): array {
		return [
			'item_id' => $id,
			'network' => $network,
			'title'   => $title,
			'clicks'  => $item_clicks,
			'share'   => self::share( $item_clicks, $clicks ),
		];
	}

	/**
	 * A part of a total as a ratio (0.0 when the total is 0).
	 *
	 * @param int $part  Part.
	 * @param int $total Total.
	 */
	private static function share( int $part, int $total ): float {
		return $total > 0 ? round( $part / $total, 4 ) : 0.0;
	}

	/**
	 * Title shown for clicks on a block that is no longer on the page.
	 */
	private static function removed_block_title(): string {
		return __( '(removed block)', 'crazylinks' );
	}

	/**
	 * Per-platform rows of a Socials block, most clicked first: every web-link icon on the block
	 * (zero clicks included; mailto and the like open directly and are never counted), then icons
	 * that were removed but have clicks, then clicks logged before item ids were stored. Two icons
	 * for the same platform are told apart by their URL.
	 *
	 * @param array<string, mixed> $block    Socials block.
	 * @param array<string, int>   $per_item Clicks by item id ('' = no item recorded).
	 * @param int                  $clicks   Total clicks on the page.
	 * @return array<int, array{item_id:string, network:string, title:string, clicks:int, share:float}>
	 */
	private static function social_items( array $block, array $per_item, int $clicks ): array {
		$items = array_values(
			array_filter(
				(array) ( $block['props']['items'] ?? [] ),
				static fn( $item ) => is_array( $item ) && ! empty( $item['url'] ) && UrlPolicy::is_web( (string) $item['url'] )
			)
		);
		$count = array_count_values( array_map( static fn( $item ) => (string) ( $item['network'] ?? '' ), $items ) );
		$rows  = [];
		foreach ( $items as $item ) {
			$id      = (string) ( $item['id'] ?? '' );
			$network = (string) ( $item['network'] ?? '' );
			$title   = Icons::label( $network );
			if ( ( $count[ $network ] ?? 0 ) > 1 ) {
				$title .= ' (' . (string) preg_replace( '#^https?://(www\.)?#i', '', (string) $item['url'] ) . ')';
			}
			$rows[] = self::item_row( $id, $network, $title, '' !== $id ? ( $per_item[ $id ] ?? 0 ) : 0, $clicks );
			if ( '' !== $id ) {
				unset( $per_item[ $id ] );
			}
		}
		$unknown = $per_item[''] ?? 0;
		unset( $per_item[''] );
		if ( $per_item ) {
			$rows[] = self::item_row( '', '', __( '(removed icons)', 'crazylinks' ), (int) array_sum( $per_item ), $clicks );
		}
		usort(
			$rows,
			static fn( $a, $b ) => $b['clicks'] <=> $a['clicks']
		);
		if ( $unknown > 0 ) {
			$rows[] = self::item_row( '', '', __( 'Earlier clicks (platform not recorded)', 'crazylinks' ), $unknown, $clicks );
		}
		return $rows;
	}

	/**
	 * Per-product rows of a newest-products Product block, most clicked first: only products that
	 * were clicked (the list changes as the store adds products). Names come from cached store data,
	 * never a request to the store; a product that has left the list is shown by its id.
	 *
	 * @param array<string, mixed> $block    Product block (latest mode).
	 * @param array<string, int>   $per_item Clicks by product id ('' = no product recorded).
	 * @param int                  $clicks   Total clicks on the page.
	 * @return array<int, array{item_id:string, network:string, title:string, clicks:int, share:float}>
	 */
	private static function product_items( array $block, array $per_item, int $clicks ): array {
		$props = (array) ( $block['props'] ?? [] );
		$rows  = [];
		arsort( $per_item );
		foreach ( $per_item as $id => $item_clicks ) {
			$id = (string) $id;
			if ( '' === $id ) {
				continue;
			}
			$product = ctype_digit( $id ) ? Store::known_product( (int) $id, 'latest', (int) ( $props['count'] ?? 3 ), (string) ( $props['category'] ?? '' ) ) : null;
			$name    = $product ? (string) $product['name'] : '';
			if ( '' === $name ) {
				/* translators: %s: product id. */
				$name = sprintf( __( 'Product #%s (no longer listed)', 'crazylinks' ), $id );
			}
			$rows[] = self::item_row( $id, '', $name, $item_clicks, $clicks );
		}
		if ( ( $per_item[''] ?? 0 ) > 0 ) {
			$rows[] = self::item_row( '', '', __( 'Earlier clicks (product not recorded)', 'crazylinks' ), $per_item[''], $clicks );
		}
		return $rows;
	}

	/**
	 * Names one traffic source. Order: the campaign tag the owner put on the link (utm_source), then
	 * the in-app browser (apps often send no referrer, but their browser names itself), then the
	 * referring site. Different spellings of one platform share a key.
	 *
	 * @param string $utm     utm_source of the view, or ''.
	 * @param string $in_app  In-app browser token, or ''.
	 * @param string $host    Referrer host, or ''.
	 * @param bool   $matched False for a click whose view was not found.
	 * @return array{0:string, 1:string} Key and label.
	 */
	public static function source_name( string $utm, string $in_app, string $host, bool $matched = true ): array {
		$utm = strtolower( trim( $utm ) );
		if ( '' !== $utm ) {
			foreach ( self::PLATFORMS as $key => [ $label, , $aliases ] ) {
				if ( $key === $utm || in_array( $utm, $aliases, true ) || strtolower( $label ) === $utm ) {
					return [ $key, $label ];
				}
			}
			return [ 'utm:' . $utm, $utm ];
		}
		if ( '' !== $in_app && 'none' !== $in_app ) {
			return [ $in_app, self::PLATFORMS[ $in_app ][0] ?? ucfirst( $in_app ) ];
		}
		$host = strtolower( $host );
		if ( '' !== $host ) {
			foreach ( self::PLATFORMS as $key => [ $label, $pattern ] ) {
				if ( preg_match( $pattern, $host ) ) {
					return [ $key, $label ];
				}
			}
			$host = (string) preg_replace( '/^(www|m|l|lm)\./', '', $host );
			return [ 'host:' . $host, $host ];
		}
		return $matched ? [ 'direct', __( 'Direct or not shared by the app', 'crazylinks' ) ] : [ 'unknown', __( 'Unknown (no matching visit)', 'crazylinks' ) ];
	}

	/**
	 * Views and clicks per traffic source, most clicks first, each with its most clicked links
	 * (raw events only, so the retention window applies).
	 *
	 * A view knows where it came from (campaign tag, in-app browser, referrer). A click does not:
	 * it is a request from the link page itself. So each click takes the source of the same
	 * visitor's latest view of this page on the same UTC day, found through the visitor hash (which
	 * changes daily and is not a cookie). A click with no such view (the view was not counted, or
	 * the visit crossed midnight UTC) falls back to its own in-app browser, else 'unknown'.
	 *
	 * @param int                              $page_id    Page id.
	 * @param string                           $from       Y-m-d.
	 * @param string                           $to         Y-m-d.
	 * @param array<int, array<string, mixed>> $block_rows Rows from block_rows(), for link titles.
	 * @return array<int, array{source:string, label:string, views:int, clicks:int, ctr:float|null, top:array<int, array{block_id:string, item_id:string, title:string, clicks:int}>}>
	 */
	private static function sources( int $page_id, string $from, string $to, array $block_rows ): array {
		global $wpdb;
		[ $start, $end ] = self::day_bounds( $from, $to );
		$events          = self::events_table();
		$sources         = [];
		$links           = [];
		$bucket          = static function ( string $utm, string $in_app, string $host, bool $matched ) use ( &$sources ): string {
			[ $key, $label ]   = self::source_name( $utm, $in_app, $host, $matched );
			$sources[ $key ] ??= [
				'source' => $key,
				'label'  => $label,
				'views'  => 0,
				'clicks' => 0,
				'ctr'    => null,
				'top'    => [],
			];
			return $key;
		};
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the report is built per request.
		$view_rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(utm_source, '') AS utm, COALESCE(in_app, '') AS in_app, COALESCE(referrer_host, '') AS host, COUNT(*) AS n
				FROM %i WHERE page_id = %d AND type = 'view' AND ts >= %s AND ts < %s
				GROUP BY COALESCE(utm_source, ''), COALESCE(in_app, ''), COALESCE(referrer_host, '')",
				$events,
				$page_id,
				$start,
				$end
			),
			ARRAY_A
		);
		$click_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT block_id, item_id, utm, in_app, host, matched, COUNT(*) AS n FROM (
					SELECT COALESCE(c.block_id, '') AS block_id, COALESCE(c.item_id, '') AS item_id, COALESCE(v.utm_source, '') AS utm,
						COALESCE(v.in_app, c.in_app, '') AS in_app, COALESCE(v.referrer_host, '') AS host, (v.id IS NOT NULL) AS matched
					FROM %i c LEFT JOIN %i v ON v.id = (
						SELECT v2.id FROM %i v2
						WHERE v2.page_id = c.page_id AND v2.type = 'view' AND v2.visitor_hash = c.visitor_hash AND v2.ts <= c.ts AND v2.ts >= DATE(c.ts)
						ORDER BY v2.ts DESC, v2.id DESC LIMIT 1
					)
					WHERE c.page_id = %d AND c.type = 'click' AND c.ts >= %s AND c.ts < %s
				) t GROUP BY block_id, item_id, utm, in_app, host, matched",
				$events,
				$events,
				$events,
				$page_id,
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable
		foreach ( $view_rows as $row ) {
			$key                       = $bucket( (string) $row['utm'], (string) $row['in_app'], (string) $row['host'], true );
			$sources[ $key ]['views'] += (int) $row['n'];
		}
		foreach ( $click_rows as $row ) {
			$key                        = $bucket( (string) $row['utm'], (string) $row['in_app'], (string) $row['host'], (bool) $row['matched'] );
			$link                       = $row['block_id'] . '|' . $row['item_id'];
			$sources[ $key ]['clicks'] += (int) $row['n'];
			$links[ $key ][ $link ]     = ( $links[ $key ][ $link ] ?? 0 ) + (int) $row['n'];
		}

		$titles = self::link_titles( $block_rows );
		foreach ( $sources as $key => $source ) {
			$sources[ $key ]['ctr'] = $source['views'] > 0 ? round( $source['clicks'] / $source['views'], 4 ) : null;
			$top                    = $links[ $key ] ?? [];
			arsort( $top );
			foreach ( array_slice( $top, 0, self::TOP_LINKS, true ) as $link => $link_clicks ) {
				[ $block_id, $item_id ]   = explode( '|', (string) $link, 2 );
				$sources[ $key ]['top'][] = [
					'block_id' => $block_id,
					'item_id'  => $item_id,
					'title'    => self::link_title( $titles, $block_id, $item_id ),
					'clicks'   => $link_clicks,
				];
			}
		}
		$sources = array_values( $sources );
		usort(
			$sources,
			static fn( $a, $b ) => [ $b['clicks'], $b['views'] ] <=> [ $a['clicks'], $a['views'] ]
		);
		return $sources;
	}

	/**
	 * Titles of everything clickable, keyed "block_id|item_id" (item id empty for the block
	 * itself); an icon or product reads "Block: Item".
	 *
	 * @param array<int, array<string, mixed>> $block_rows Rows from a report's `blocks`.
	 * @return array<string, string>
	 */
	public static function link_titles( array $block_rows ): array {
		$titles = [];
		foreach ( $block_rows as $row ) {
			$titles[ $row['block_id'] . '|' ] = (string) $row['title'];
			foreach ( (array) ( $row['items'] ?? [] ) as $item ) {
				if ( '' !== $item['item_id'] ) {
					$titles[ $row['block_id'] . '|' . $item['item_id'] ] = $row['title'] . ': ' . $item['title'];
				}
			}
		}
		return $titles;
	}

	/**
	 * The name of what was clicked: the item's title, else its block's, else "(removed block)".
	 *
	 * @param array<string, string> $titles   Titles from link_titles().
	 * @param string                $block_id Block id.
	 * @param string                $item_id  Item id, or ''.
	 */
	public static function link_title( array $titles, string $block_id, string $item_id = '' ): string {
		return $titles[ $block_id . '|' . $item_id ] ?? $titles[ $block_id . '|' ] ?? self::removed_block_title();
	}

	/**
	 * A human label for a clickable block.
	 *
	 * @param array<string, mixed> $block Block.
	 */
	private static function block_title( array $block ): string {
		$props = (array) ( $block['props'] ?? [] );
		foreach ( [ 'title', 'alt', 'label' ] as $key ) {
			if ( ! empty( $props[ $key ] ) && is_string( $props[ $key ] ) ) {
				return $props[ $key ];
			}
		}
		if ( 'payment' === ( $block['type'] ?? '' ) ) {
			// A payment button without its own label shows the service's default one ("Support me on Ko-fi").
			$label = (string) ( Payments::get( (string) ( $props['service'] ?? 'custom' ) )['label'] ?? '' );
			if ( '' !== $label ) {
				return $label;
			}
		}
		if ( ! empty( $props['url'] ) && is_string( $props['url'] ) ) {
			return $props['url'];
		}
		return ucfirst( str_replace( '_', ' ', (string) $block['type'] ) );
	}

	/**
	 * Device, OS, in-app and referrer breakdowns of page views (raw events only).
	 *
	 * @param int    $page_id Page id.
	 * @param string $from    Y-m-d.
	 * @param string $to      Y-m-d.
	 * @return array<string, mixed>
	 */
	private static function breakdowns( int $page_id, string $from, string $to ): array {
		global $wpdb;
		[ $start, $end ] = self::day_bounds( $from, $to );
		$breakdowns      = [
			'device'   => [
				'mobile'  => 0,
				'tablet'  => 0,
				'desktop' => 0,
			],
			'os'       => [],
			'in_app'   => [],
			'referrer' => [],
		];
		$columns         = [
			'device' => 'device',
			'os'     => "COALESCE(os, 'other')",
			'in_app' => "COALESCE(in_app, 'none')",
		];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the report is built per request.
		foreach ( $columns as $key => $expression ) {
			$counts = (array) $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $expression is one of the fixed column expressions above.
					"SELECT {$expression} AS k, COUNT(*) AS n FROM %i WHERE page_id = %d AND type = 'view' AND ts >= %s AND ts < %s GROUP BY k ORDER BY n DESC",
					self::events_table(),
					$page_id,
					$start,
					$end
				),
				ARRAY_A
			);
			foreach ( $counts as $row ) {
				$breakdowns[ $key ][ (string) $row['k'] ] = (int) $row['n'];
			}
		}
		$referrers = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(referrer_host, '') AS k, COUNT(*) AS n FROM %i WHERE page_id = %d AND type = 'view' AND ts >= %s AND ts < %s GROUP BY k ORDER BY n DESC LIMIT 10",
				self::events_table(),
				$page_id,
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable
		foreach ( $referrers as $row ) {
			$breakdowns['referrer'][] = [
				'host'  => '' === $row['k'] ? __( '(direct)', 'crazylinks' ) : (string) $row['k'],
				'count' => (int) $row['n'],
			];
		}
		return $breakdowns;
	}

	/**
	 * Day of the oldest raw event for the page, or null (breakdowns only cover days from here on).
	 *
	 * @param int $page_id Page id.
	 */
	private static function first_event_day( int $page_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the report is built per request.
		$ts = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(ts) FROM %i WHERE page_id = %d', self::events_table(), $page_id ) );
		return $ts ? substr( (string) $ts, 0, 10 ) : null;
	}

	/**
	 * Views and clicks over the last TOTALS_DAYS days (UTC, including today) for every page, cached
	 * in a transient that the rollup clears and that expires after TOTALS_TTL.
	 *
	 * @return array<int, array{views:int, clicks:int}>
	 */
	public static function page_totals(): array {
		$cached = get_transient( self::TOTALS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$totals = [];
		$from   = gmdate( 'Y-m-d', time() - ( self::TOTALS_DAYS - 1 ) * DAY_IN_SECONDS );
		foreach ( self::rows( null, $from, gmdate( 'Y-m-d' ) ) as $row ) {
			$totals[ $row['page_id'] ] ??= [
				'views'  => 0,
				'clicks' => 0,
			];
			if ( 'view' === $row['type'] ) {
				$totals[ $row['page_id'] ]['views'] += $row['count'];
			} elseif ( 'click' === $row['type'] ) {
				$totals[ $row['page_id'] ]['clicks'] += $row['count'];
			}
		}
		set_transient( self::TOTALS_TRANSIENT, $totals, self::TOTALS_TTL );
		return $totals;
	}

	/**
	 * Clears the cached per-page totals (called by the rollup).
	 */
	public static function flush_page_totals(): void {
		delete_transient( self::TOTALS_TRANSIENT );
	}

	/**
	 * Summary for a page over the last N days (including today), UTC: totals, clicks per block
	 * (most clicked first) and the days that have any views or clicks.
	 *
	 * @param int $page_id Page id.
	 * @param int $days    Days.
	 * @return array<string, mixed>
	 */
	public static function for_page( int $page_id, int $days ): array {
		$days  = max( 1, min( self::FOR_PAGE_MAX_DAYS, $days ) );
		$today = gmdate( 'Y-m-d' );
		$from  = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );

		$views     = 0;
		$clicks    = 0;
		$uniques   = 0;
		$daily     = [];
		$per_block = [];
		foreach ( self::rows( $page_id, $from, $today ) as $row ) {
			$day             = $row['day'];
			$daily[ $day ] ??= [
				'day'    => $day,
				'views'  => 0,
				'clicks' => 0,
			];
			if ( 'view' === $row['type'] ) {
				$views                  += $row['count'];
				$uniques                += $row['uniques'];
				$daily[ $day ]['views'] += $row['count'];
			} elseif ( 'click' === $row['type'] ) {
				$clicks                       += $row['count'];
				$daily[ $day ]['clicks']      += $row['count'];
				$per_block[ $row['block_id'] ] = ( $per_block[ $row['block_id'] ] ?? 0 ) + $row['count'];
			}
		}
		ksort( $daily );
		arsort( $per_block );

		$doc        = PageStore::get_document( $page_id ) ?? [];
		$block_rows = [];
		foreach ( $per_block as $block_id => $block_clicks ) {
			$block        = PageStore::find_block( $doc, (string) $block_id );
			$block_rows[] = [
				'block_id' => (string) $block_id,
				'title'    => (string) ( $block['props']['title'] ?? $block['props']['alt'] ?? '' ),
				'clicks'   => $block_clicks,
				'ctr'      => $views > 0 ? round( $block_clicks / $views, 4 ) : null,
			];
		}

		return [
			'page_id' => $page_id,
			'range'   => [
				'from' => $from,
				'to'   => $today,
				'days' => $days,
			],
			'views'   => $views,
			'uniques' => $uniques,
			'clicks'  => $clicks,
			'ctr'     => $views > 0 ? round( $clicks / $views, 4 ) : null,
			'blocks'  => $block_rows,
			'daily'   => array_values( $daily ),
		];
	}
}
