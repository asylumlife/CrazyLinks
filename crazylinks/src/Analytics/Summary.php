<?php
/**
 * Analytics summary queries.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

use CrazyLinks\PageStore;
use CrazyLinks\Render\Icons;
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
			$n    = [
				'7d'  => 7,
				'30d' => 30,
				'90d' => 90,
			][ (string) $range ] ?? ( null !== $days && $days > 0 ? $days : 30 );
			$n    = max( 1, min( self::MAX_DAYS, $n ) );
			$to   = $today;
			$from = gmdate( 'Y-m-d', time() - ( $n - 1 ) * DAY_IN_SECONDS );
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
	 * Per-day, per-type, per-block counts from the rollup (rolled days) plus events (the rest).
	 *
	 * @param int|null $page_id Page id, or null for every page.
	 * @param string   $from    Y-m-d.
	 * @param string   $to      Y-m-d.
	 * @return array<int, array{page_id:int, block_id:string, item_id:string, day:string, type:string, count:int, uniques:int}>
	 */
	private static function rows( ?int $page_id, string $from, string $to ): array {
		global $wpdb;
		$through = (string) get_option( 'crazylinks_rollup_through', '' );
		$out     = [];
		$only    = null === $page_id ? '' : $wpdb->prepare( ' AND page_id = %d', $page_id );
		$normal  = static function ( $r ): array {
			return [
				'page_id'  => (int) $r['page_id'],
				'block_id' => (string) $r['block_id'],
				'item_id'  => (string) $r['item_id'],
				'day'      => (string) $r['day'],
				'type'     => (string) $r['type'],
				'count'    => (int) $r['count'],
				'uniques'  => (int) $r['uniques'],
			];
		};
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== $through && $from <= $through ) {
			$rolled_to = min( $to, $through );
			foreach ( (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT page_id, block_id, item_id, day, type, count, uniques FROM {$wpdb->prefix}crazylinks_daily WHERE day >= %s AND day <= %s{$only}", $from, $rolled_to ),
				ARRAY_A
			) as $r ) {
				$out[] = $normal( $r );
			}
		}
		$live_from = '' !== $through ? max( $from, gmdate( 'Y-m-d', (int) strtotime( $through . ' UTC +1 day' ) ) ) : $from;
		if ( $live_from <= $to ) {
			foreach ( (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT page_id, COALESCE(block_id, '') AS block_id, COALESCE(item_id, '') AS item_id, DATE(ts) AS day, type, COUNT(*) AS count, COUNT(DISTINCT visitor_hash) AS uniques
					FROM {$wpdb->prefix}crazylinks_events WHERE ts >= %s AND ts < %s AND type IN ('view', 'click'){$only}
					GROUP BY page_id, COALESCE(block_id, ''), COALESCE(item_id, ''), DATE(ts), type",
					$live_from . ' 00:00:00',
					gmdate( 'Y-m-d', (int) strtotime( $to . ' UTC +1 day' ) ) . ' 00:00:00'
				),
				ARRAY_A
			) as $r ) {
				$out[] = $normal( $r );
			}
		}
		// phpcs:enable
		return $out;
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
		foreach ( self::rows( $page_id, $from, $to ) as $r ) {
			if ( ! isset( $series[ $r['day'] ] ) ) {
				continue;
			}
			if ( 'view' === $r['type'] ) {
				$views                        += $r['count'];
				$uniques                      += $r['uniques'];
				$series[ $r['day'] ]['views'] += $r['count'];
			} else {
				$clicks                                     += $r['count'];
				$series[ $r['day'] ]['clicks']              += $r['count'];
				$per_block[ $r['block_id'] ]                 = ( $per_block[ $r['block_id'] ] ?? 0 ) + $r['count'];
				$per_item[ $r['block_id'] ][ $r['item_id'] ] = ( $per_item[ $r['block_id'] ][ $r['item_id'] ] ?? 0 ) + $r['count'];
			}
		}

		$block_rows = self::block_rows( $page_id, $per_block, $clicks, $views, $per_item );

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
			 * Filters whether country data is available (v1.0 never collects it).
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
	 * A Socials block row also carries `items`: one row per icon (see social_items()).
	 *
	 * @param int                               $page_id   Page id.
	 * @param array<string, int>                $per_block Clicks by block id.
	 * @param int                               $clicks    Total clicks.
	 * @param int                               $views     Total views.
	 * @param array<string, array<string, int>> $per_item  Clicks by block id, then item id ('' = no item recorded).
	 * @return array<int, array<string, mixed>>
	 */
	private static function block_rows( int $page_id, array $per_block, int $clicks, int $views, array $per_item = [] ): array {
		$doc  = PageStore::get_document( $page_id ) ?? [];
		$rows = [];
		$seen = [];
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $block ) {
			if ( ! is_array( $block ) || ! in_array( $block['type'] ?? '', ClickRedirect::TYPES, true ) ) {
				continue;
			}
			$id          = (string) $block['id'];
			$seen[ $id ] = true;
			$n           = $per_block[ $id ] ?? 0;
			$row         = self::block_row( $id, self::block_title( $block ), (string) $block['type'], $n, $clicks, $views );
			if ( 'socials' === $block['type'] ) {
				$row['items'] = self::social_items( $block, $per_item[ $id ] ?? [], $clicks );
			} elseif ( 'product' === $block['type'] && 'latest' === ( $block['props']['mode'] ?? 'single' ) ) {
				$row['items'] = self::product_items( $block, $per_item[ $id ] ?? [], $clicks );
			}
			$rows[] = $row;
		}
		foreach ( $per_block as $id => $n ) {
			if ( '' === (string) $id || isset( $seen[ (string) $id ] ) ) {
				continue;
			}
			$rows[] = self::block_row( (string) $id, __( '(removed block)', 'crazylinks' ), 'unknown', $n, $clicks, $views );
		}
		usort(
			$rows,
			static fn( $a, $b ) => $b['clicks'] <=> $a['clicks']
		);
		return $rows;
	}

	/**
	 * One block row. `ctr` is clicks per page view (kept for the older summary shape).
	 *
	 * @param string $id     Block id.
	 * @param string $title  Title.
	 * @param string $type   Type.
	 * @param int    $n      Clicks.
	 * @param int    $clicks Total clicks.
	 * @param int    $views  Total views.
	 * @return array<string, mixed>
	 */
	private static function block_row( string $id, string $title, string $type, int $n, int $clicks, int $views ): array {
		return [
			'block_id' => $id,
			'title'    => $title,
			'type'     => $type,
			'clicks'   => $n,
			'share'    => $clicks > 0 ? round( $n / $clicks, 4 ) : 0.0,
			'ctr'      => $views > 0 ? round( $n / $views, 4 ) : null,
		];
	}

	/**
	 * Per-platform rows of a Socials block, most clicked first: every web-link icon on the block (zero
	 * clicks included; mailto and the like open directly and are never counted), then icons that were removed but have clicks, then clicks logged before item ids
	 * were stored. Two icons for the same platform are told apart by their URL.
	 *
	 * @param array<string, mixed> $block    Socials block.
	 * @param array<string, int>   $per_item Clicks by item id ('' = no item recorded).
	 * @param int                  $clicks   Total clicks on the page.
	 * @return array<int, array{item_id:string, network:string, title:string, clicks:int, share:float}>
	 */
	private static function social_items( array $block, array $per_item, int $clicks ): array {
		$items = array_values( array_filter( (array) ( $block['props']['items'] ?? [] ), static fn( $i ) => is_array( $i ) && ! empty( $i['url'] ) && UrlPolicy::is_web( (string) $i['url'] ) ) );
		$count = array_count_values( array_map( static fn( $i ) => (string) ( $i['network'] ?? '' ), $items ) );
		$row   = static fn( string $id, string $network, string $title, int $n ): array => [
			'item_id' => $id,
			'network' => $network,
			'title'   => $title,
			'clicks'  => $n,
			'share'   => $clicks > 0 ? round( $n / $clicks, 4 ) : 0.0,
		];
		$rows  = [];
		foreach ( $items as $item ) {
			$id      = (string) ( $item['id'] ?? '' );
			$network = (string) ( $item['network'] ?? '' );
			$title   = Icons::label( $network );
			if ( ( $count[ $network ] ?? 0 ) > 1 ) {
				$title .= ' (' . (string) preg_replace( '#^https?://(www\.)?#i', '', (string) $item['url'] ) . ')';
			}
			$rows[] = $row( $id, $network, $title, '' !== $id ? ( $per_item[ $id ] ?? 0 ) : 0 );
			if ( '' !== $id ) {
				unset( $per_item[ $id ] );
			}
		}
		$unknown = $per_item[''] ?? 0;
		unset( $per_item[''] );
		if ( $per_item ) {
			$rows[] = $row( '', '', __( '(removed icons)', 'crazylinks' ), (int) array_sum( $per_item ) );
		}
		usort(
			$rows,
			static fn( $a, $b ) => $b['clicks'] <=> $a['clicks']
		);
		if ( $unknown > 0 ) {
			$rows[] = $row( '', '', __( 'Earlier clicks (platform not recorded)', 'crazylinks' ), $unknown );
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
		$row   = static fn( string $id, string $title, int $n ): array => [
			'item_id' => $id,
			'network' => '',
			'title'   => $title,
			'clicks'  => $n,
			'share'   => $clicks > 0 ? round( $n / $clicks, 4 ) : 0.0,
		];
		$rows  = [];
		arsort( $per_item );
		foreach ( $per_item as $id => $n ) {
			$id = (string) $id;
			if ( '' === $id ) {
				continue;
			}
			$product = ctype_digit( $id ) ? Store::known_product( (int) $id, 'latest', (int) ( $props['count'] ?? 3 ), (string) ( $props['category'] ?? '' ) ) : null;
			/* translators: %s: product id. */
			$rows[] = $row( $id, $product && '' !== (string) $product['name'] ? (string) $product['name'] : sprintf( __( 'Product #%s (no longer listed)', 'crazylinks' ), $id ), $n );
		}
		if ( ( $per_item[''] ?? 0 ) > 0 ) {
			$rows[] = $row( '', __( 'Earlier clicks (product not recorded)', 'crazylinks' ), $per_item[''] );
		}
		return $rows;
	}

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
	 * Views and clicks per traffic source, most clicks first, each with its three most clicked
	 * links (raw events only, so the retention window applies).
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
		$start  = $from . ' 00:00:00';
		$end    = gmdate( 'Y-m-d', (int) strtotime( $to . ' UTC +1 day' ) ) . ' 00:00:00';
		$events = $wpdb->prefix . 'crazylinks_events';
		$out    = [];
		$links  = [];
		$bucket = static function ( string $utm, string $in_app, string $host, bool $matched ) use ( &$out ): string {
			[ $key, $label ] = self::source_name( $utm, $in_app, $host, $matched );
			$out[ $key ]   ??= [
				'source' => $key,
				'label'  => $label,
				'views'  => 0,
				'clicks' => 0,
				'ctr'    => null,
				'top'    => [],
			];
			return $key;
		};
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(utm_source, '') AS utm, COALESCE(in_app, '') AS in_app, COALESCE(referrer_host, '') AS host, COUNT(*) AS n
				FROM {$events} WHERE page_id = %d AND type = 'view' AND ts >= %s AND ts < %s
				GROUP BY COALESCE(utm_source, ''), COALESCE(in_app, ''), COALESCE(referrer_host, '')",
				$page_id,
				$start,
				$end
			),
			ARRAY_A
		) as $r ) {
			$out[ $bucket( (string) $r['utm'], (string) $r['in_app'], (string) $r['host'], true ) ]['views'] += (int) $r['n'];
		}
		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT block_id, item_id, utm, in_app, host, matched, COUNT(*) AS n FROM (
					SELECT COALESCE(c.block_id, '') AS block_id, COALESCE(c.item_id, '') AS item_id, COALESCE(v.utm_source, '') AS utm,
						COALESCE(v.in_app, c.in_app, '') AS in_app, COALESCE(v.referrer_host, '') AS host, (v.id IS NOT NULL) AS matched
					FROM {$events} c LEFT JOIN {$events} v ON v.id = (
						SELECT v2.id FROM {$events} v2
						WHERE v2.page_id = c.page_id AND v2.type = 'view' AND v2.visitor_hash = c.visitor_hash AND v2.ts <= c.ts AND v2.ts >= DATE(c.ts)
						ORDER BY v2.ts DESC, v2.id DESC LIMIT 1
					)
					WHERE c.page_id = %d AND c.type = 'click' AND c.ts >= %s AND c.ts < %s
				) t GROUP BY block_id, item_id, utm, in_app, host, matched",
				$page_id,
				$start,
				$end
			),
			ARRAY_A
		) as $r ) {
			$key                    = $bucket( (string) $r['utm'], (string) $r['in_app'], (string) $r['host'], (bool) $r['matched'] );
			$link                   = $r['block_id'] . '|' . $r['item_id'];
			$out[ $key ]['clicks'] += (int) $r['n'];
			$links[ $key ][ $link ] = ( $links[ $key ][ $link ] ?? 0 ) + (int) $r['n'];
		}
		// phpcs:enable

		$titles = [];
		foreach ( $block_rows as $row ) {
			$titles[ $row['block_id'] . '|' ] = (string) $row['title'];
			foreach ( (array) ( $row['items'] ?? [] ) as $item ) {
				if ( '' !== $item['item_id'] ) {
					$titles[ $row['block_id'] . '|' . $item['item_id'] ] = $row['title'] . ': ' . $item['title'];
				}
			}
		}
		foreach ( $out as $key => $row ) {
			$out[ $key ]['ctr'] = $row['views'] > 0 ? round( $row['clicks'] / $row['views'], 4 ) : null;
			$top                = $links[ $key ] ?? [];
			arsort( $top );
			foreach ( array_slice( $top, 0, 3, true ) as $link => $n ) {
				[ $block_id, $item_id ] = explode( '|', (string) $link, 2 );
				$out[ $key ]['top'][]   = [
					'block_id' => $block_id,
					'item_id'  => $item_id,
					'title'    => $titles[ $link ] ?? $titles[ $block_id . '|' ] ?? __( '(removed block)', 'crazylinks' ),
					'clicks'   => $n,
				];
			}
		}
		$out = array_values( $out );
		usort(
			$out,
			static fn( $a, $b ) => [ $b['clicks'], $b['views'] ] <=> [ $a['clicks'], $a['views'] ]
		);
		return $out;
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
		$start = $from . ' 00:00:00';
		$end   = gmdate( 'Y-m-d', (int) strtotime( $to . ' UTC +1 day' ) ) . ' 00:00:00';
		$out   = [
			'device'   => [
				'mobile'  => 0,
				'tablet'  => 0,
				'desktop' => 0,
			],
			'os'       => [],
			'in_app'   => [],
			'referrer' => [],
		];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cols = [
			'device' => 'device',
			'os'     => "COALESCE(os, 'other')",
			'in_app' => "COALESCE(in_app, 'none')",
		];
		foreach ( $cols as $key => $expr ) {
			foreach ( (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$expr} AS k, COUNT(*) AS n FROM {$wpdb->prefix}crazylinks_events WHERE page_id = %d AND type = 'view' AND ts >= %s AND ts < %s GROUP BY k ORDER BY n DESC",
					$page_id,
					$start,
					$end
				),
				ARRAY_A
			) as $r ) {
				$out[ $key ][ (string) $r['k'] ] = (int) $r['n'];
			}
		}
		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(referrer_host, '') AS k, COUNT(*) AS n FROM {$wpdb->prefix}crazylinks_events WHERE page_id = %d AND type = 'view' AND ts >= %s AND ts < %s GROUP BY k ORDER BY n DESC LIMIT 10",
				$page_id,
				$start,
				$end
			),
			ARRAY_A
		) as $r ) {
			$out['referrer'][] = [
				'host'  => '' === $r['k'] ? '(direct)' : (string) $r['k'],
				'count' => (int) $r['n'],
			];
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Day of the oldest raw event for the page, or null (breakdowns only cover days from here on).
	 *
	 * @param int $page_id Page id.
	 */
	private static function first_event_day( int $page_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ts = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(ts) FROM {$wpdb->prefix}crazylinks_events WHERE page_id = %d", $page_id ) );
		return $ts ? substr( (string) $ts, 0, 10 ) : null;
	}

	/**
	 * Views and clicks over the last 30 days (UTC, including today) for every page, cached in a
	 * transient that the rollup clears (and that expires after 15 minutes so today's numbers move).
	 *
	 * @return array<int, array{views:int, clicks:int}>
	 */
	public static function page_totals(): array {
		$cached = get_transient( self::TOTALS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$totals = [];
		foreach ( self::rows( null, gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ), gmdate( 'Y-m-d' ) ) as $r ) {
			$totals[ $r['page_id'] ] ??= [
				'views'  => 0,
				'clicks' => 0,
			];
			if ( 'view' === $r['type'] ) {
				$totals[ $r['page_id'] ]['views'] += $r['count'];
			} else {
				$totals[ $r['page_id'] ]['clicks'] += $r['count'];
			}
		}
		set_transient( self::TOTALS_TRANSIENT, $totals, 15 * MINUTE_IN_SECONDS );
		return $totals;
	}

	/**
	 * Clears the cached per-page totals (called by the rollup).
	 */
	public static function flush_page_totals(): void {
		delete_transient( self::TOTALS_TRANSIENT );
	}

	/**
	 * Summary for a page over the last N days (including today), UTC.
	 *
	 * @param int $page_id Page id.
	 * @param int $days    Days.
	 * @return array<string, mixed>
	 */
	public static function for_page( int $page_id, int $days ): array {
		global $wpdb;
		$days  = max( 1, min( 3650, $days ) );
		$today = gmdate( 'Y-m-d' );
		$from  = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rolled = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT block_id, day, type, count, uniques FROM {$wpdb->prefix}crazylinks_daily WHERE page_id = %d AND day >= %s AND day < %s",
				$page_id,
				$from,
				$today
			),
			ARRAY_A
		);
		$live   = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(block_id, '') AS block_id, DATE(ts) AS day, type, COUNT(*) AS count, COUNT(DISTINCT visitor_hash) AS uniques
				FROM {$wpdb->prefix}crazylinks_events WHERE page_id = %d AND ts >= %s GROUP BY COALESCE(block_id, ''), DATE(ts), type",
				$page_id,
				$today . ' 00:00:00'
			),
			ARRAY_A
		);
		// phpcs:enable

		$totals = [
			'view'  => 0,
			'click' => 0,
		];
		$uniq   = 0;
		$daily  = [];
		$blocks = [];
		foreach ( array_merge( $rolled, $live ) as $row ) {
			$type  = (string) $row['type'];
			$count = (int) $row['count'];
			$day   = (string) $row['day'];
			if ( ! isset( $daily[ $day ] ) ) {
				$daily[ $day ] = [
					'day'    => $day,
					'views'  => 0,
					'clicks' => 0,
				];
			}
			if ( 'view' === $type ) {
				$totals['view']         += $count;
				$uniq                   += (int) $row['uniques'];
				$daily[ $day ]['views'] += $count;
			} elseif ( 'click' === $type ) {
				$totals['click']         += $count;
				$daily[ $day ]['clicks'] += $count;
				$bid                      = (string) $row['block_id'];
				$blocks[ $bid ]           = ( $blocks[ $bid ] ?? 0 ) + $count;
			}
		}
		ksort( $daily );
		arsort( $blocks );

		$doc        = PageStore::get_document( $page_id ) ?? [];
		$block_rows = [];
		foreach ( $blocks as $bid => $count ) {
			$block        = PageStore::find_block( $doc, (string) $bid );
			$block_rows[] = [
				'block_id' => (string) $bid,
				'title'    => (string) ( $block['props']['title'] ?? $block['props']['alt'] ?? '' ),
				'clicks'   => $count,
				'ctr'      => $totals['view'] > 0 ? round( $count / $totals['view'], 4 ) : null,
			];
		}

		return [
			'page_id' => $page_id,
			'range'   => [
				'from' => $from,
				'to'   => $today,
				'days' => $days,
			],
			'views'   => $totals['view'],
			'uniques' => $uniq,
			'clicks'  => $totals['click'],
			'ctr'     => $totals['view'] > 0 ? round( $totals['click'] / $totals['view'], 4 ) : null,
			'blocks'  => $block_rows,
			'daily'   => array_values( $daily ),
		];
	}
}
