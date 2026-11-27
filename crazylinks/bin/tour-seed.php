<?php
/**
 * Builds the "Feature tour" page used for the docs/FEATURES.md screenshots and fills it with
 * made-up analytics. For a local development site only: it replaces that page's events.
 *
 * Usage (from the WordPress root):
 *   wp crazylinks demo --slug=tour
 *   wp eval-file wp-content/plugins/crazylinks/bin/tour-seed.php
 *
 * Run it once per fresh demo page (a second run would add the extra blocks again).
 *
 * @package CrazyLinks
 */

use CrazyLinks\PageStore;
use CrazyLinks\Schema\PageValidator;

PHP_SAPI === 'cli' || exit; // Dev tool: never runs over HTTP.
defined( 'WP_CLI' ) || exit; // Needs WordPress loaded by WP-CLI (wp eval-file).

// phpcs:disable WordPress.WP.AlternativeFunctions.rand_mt_rand, WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- seeded on purpose: every run produces the same ids and the same made-up numbers, so the screenshots are reproducible.

( static function (): void {

	global $wpdb;
	$post = get_page_by_path( 'tour', OBJECT, 'crazylinks_page' );
	if ( ! $post instanceof WP_Post ) {
		WP_CLI::error( 'No link page with the slug "tour". Create it first: wp crazylinks demo --slug=tour' );
	}
	$doc    = PageStore::get_document( $post );
	$chars  = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	$n      = 0;
	$ulid   = static function () use ( $chars, &$n ) {
		$s = '01MTR9';
		mt_srand( 4200 + ( ++$n ) );
		for ( $i = 6; $i < 26; $i++ ) {
			$s .= $chars[ mt_rand( 0, 31 ) ];
		}
		return $s;
	};
	$mk     = static function ( string $type, array $props, ?string $parent_id = null, array $extra = [] ) use ( $ulid ) {
		return array_merge(
			[
				'id'        => $ulid(),
				'type'      => $type,
				'parent_id' => $parent_id,
				'order'     => 0,
				'visible'   => true,
				'schedule'  => [
					'start' => null,
					'end'   => null,
				],
				'pinned'    => false,
				'animation' => 'none',
				'tracking'  => true,
				'props'     => $props,
			],
			$extra
		);
	};
	$images = [];
	$gid    = null;
	foreach ( $doc['blocks'] as &$b ) {
		if ( 'group' === $b['type'] ) {
			$b['schedule'] = [
				'start' => null,
				'end'   => null,
			];
			$gid           = $b['id'];
		}
		if ( 'gallery' === $b['type'] ) {
			$images = array_column( $b['props']['items'], 'image' );
		}
		if ( 'countdown' === $b['type'] ) {
			$b['props']['target'] = gmdate( 'c', time() + 14 * DAY_IN_SECONDS + 5 * HOUR_IN_SECONDS + 1234 );
		}
	}
	unset( $b );
	$next_fri = new DateTime( 'next friday 19:00', wp_timezone() );
	$sec      = $ulid();
	$new      = [
		$mk(
			'text',
			[
				'content' => '<p>Text blocks take a short paragraph with <strong>bold</strong>, <em>italics</em> and <a href="https://example.com/about">links</a>.</p>',
				'align'   => 'center',
			]
		),
		$mk( 'divider', [ 'style' => 'dots' ] ),
		$mk(
			'image',
			[
				'image'   => $images[1] ?? $images[0],
				'alt'     => 'Stage lights',
				'caption' => 'An image block with a caption',
				'url'     => 'https://example.com/photo',
			]
		),
		$mk(
			'countdown',
			[
				'label'        => 'Friday live stream starts in',
				'target'       => $next_fri->format( 'c' ),
				'repeat'       => 'weekly',
				'repeat_days'  => [ 'fri' ],
				'live_minutes' => 90,
				'expired_text' => 'We are live!',
				'url'          => 'https://example.com/stream',
				'link_label'   => 'Watch now',
				'size'         => 'medium',
			]
		),
		$mk(
			'countdown',
			[
				'label'  => 'Early-bird tickets end in',
				'target' => gmdate( 'c', time() + 2 * DAY_IN_SECONDS + 7000 ),
				'size'   => 'small',
			]
		),
		$mk(
			'product',
			[
				'mode'        => 'latest',
				'count'       => 3,
				'display'     => 'card',
				'show_price'  => true,
				'button_text' => 'Buy',
			]
		),
		array_merge(
			$mk(
				'group',
				[
					'heading'     => 'More links',
					'description' => 'A collapsible Section with a two-column grid.',
					'collapsible' => true,
					'collapsed'   => false,
					'layout'      => 'grid',
					'padding'     => 'md',
					'radius'      => 'md',
				]
			),
			[ 'id' => $sec ]
		),
		$mk(
			'link',
			[
				'title' => 'Podcast',
				'url'   => 'https://example.com/podcast',
				'emoji' => '🎙️',
			],
			$sec
		),
		$mk(
			'link',
			[
				'title' => 'Newsletter',
				'url'   => 'https://example.com/news',
				'emoji' => '✉️',
			],
			$sec,
			[ 'order' => 1 ]
		),
		$mk(
			'link',
			[
				'title' => 'Press kit',
				'url'   => 'https://example.com/press',
				'emoji' => '📰',
			],
			$sec,
			[ 'order' => 2 ]
		),
		$mk(
			'link',
			[
				'title' => 'Bookings',
				'url'   => 'https://example.com/book',
				'emoji' => '📅',
			],
			$sec,
			[ 'order' => 3 ]
		),
	];
	$o        = 100;
	foreach ( $new as $b ) {
		if ( null === $b['parent_id'] ) {
			$b['order'] = $o++;
		}
		$doc['blocks'][] = $b;
	}
	wp_set_current_user( 1 );
	$r = ( new PageValidator() )->validate( $doc, 1 );
	if ( ! $r->is_valid() ) {
		WP_CLI::error( wp_json_encode( $r->errors() ) );
	}
	$doc = $r->document();
	wp_update_post(
		[
			'ID'           => $post->ID,
			'post_title'   => 'Feature tour',
			'post_content' => wp_slash( PageStore::encode( $doc ) ),
		]
	);

	// Made-up analytics for the last 14 days.
	$ev = $wpdb->prefix . 'crazylinks_events';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}crazylinks_events WHERE page_id = %d", $post->ID ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}crazylinks_daily WHERE page_id = %d", $post->ID ) );
	$links = [];
	$soc   = [];
	$prod  = [];
	foreach ( $doc['blocks'] as $b ) {
		if ( in_array( $b['type'], [ 'link', 'payment' ], true ) && 0 !== strpos( $b['props']['url'] ?? '', 'mailto' ) && 0 !== strpos( $b['props']['url'] ?? '', 'tel' ) ) {
			$links[] = $b['id'];
		}
		if ( 'socials' === $b['type'] ) {
			foreach ( $b['props']['items'] as $i ) {
				if ( 0 === strpos( $i['url'], 'http' ) ) {
					$soc[] = [ $b['id'], $i['id'] ];
				}
			}
		}
		if ( 'product' === $b['type'] && 'latest' === ( $b['props']['mode'] ?? '' ) ) {
			$prod = $b['id'];
		}
	}
	$pids = [];
	if ( $prod ) {
		foreach ( (array) \CrazyLinks\Woo\Store::latest( 3, '' ) as $p ) {
			$pids[] = (string) ( $p['id'] ?? '' );
		}
	}
	$sources = [
		[ 34, 'instagram', 'instagram', null, 'mobile', 'iOS' ],
		[ 22, null, 'tiktok', null, 'mobile', 'Android' ],
		[ 12, null, 'facebook', null, 'mobile', 'iOS' ],
		[ 9, 'newsletter', null, null, 'desktop', 'macOS' ],
		[ 8, null, null, 'www.google.com', 'desktop', 'Windows' ],
		[ 5, null, null, 'www.youtube.com', 'desktop', 'macOS' ],
		[ 10, null, null, null, 'mobile', 'iOS' ],
	];
	mt_srand( 77 );
	$pick = static function ( array $a, float $skew ) {
		return $a[ (int) floor( count( $a ) * pow( mt_rand() / mt_getrandmax(), $skew ) ) % count( $a ) ];
	};
	$rows = 0;
	for ( $d = 13; $d >= 0; $d-- ) {
		$day = gmdate( 'Y-m-d', time() - $d * DAY_IN_SECONDS );
		$mul = 0.6 + ( 13 - $d ) / 16 + ( mt_rand( 0, 30 ) / 100 );
		foreach ( $sources as $si => $s ) {
			$views = (int) round( $s[0] * $mul / 4 );
			for ( $v = 0; $v < $views; $v++ ) {
				$hash = md5( "$day-$si-$v" );
				$sec0 = mt_rand( 3600, $d ? 80000 : max( 3700, (int) ( time() % DAY_IN_SECONDS ) - 600 ) );
				$base = [
					'page_id'       => $post->ID,
					'visitor_hash'  => $hash,
					'device'        => $s[4],
					'os'            => $s[5],
					'country'       => $pick( [ 'US', 'US', 'US', 'CA', 'GB', 'MX', 'DE' ], 1.4 ),
					'in_app'        => $s[2],
					'referrer_host' => $s[3],
					'utm_source'    => $s[1],
					'utm_medium'    => $s[1] ? ( 'newsletter' === $s[1] ? 'email' : 'bio' ) : null,
				];
				$wpdb->insert(
					$ev,
					$base + [
						'type' => 'view',
						'ts'   => $day . ' ' . gmdate( 'H:i:s', $sec0 ),
					]
				);
				++$rows;
				$clicks = mt_rand( 0, 100 ) < 62 ? ( mt_rand( 0, 100 ) < 30 ? 2 : 1 ) : 0;
				for ( $c = 0; $c < $clicks; $c++ ) {
					$roll = mt_rand( 0, 100 );
					$blk  = null;
					$item = null;
					if ( $roll < 22 && $soc ) {
						[ $blk, $item ] = $pick( $soc, 1.8 );
					} elseif ( $roll < 36 && $pids ) {
						$blk  = $prod;
						$item = $pick( $pids, 1.5 );
					} else {
						$rot = array_merge( array_slice( $links, $si % 3 ), array_slice( $links, 0, $si % 3 ) );
						$blk = $pick( $rot, 2.0 );
					}
					$wpdb->insert(
						$ev,
						$base + [
							'type'     => 'click',
							'block_id' => $blk,
							'item_id'  => $item,
							'ts'       => $day . ' ' . gmdate( 'H:i:s', $sec0 + 20 + $c * 15 ),
						]
					);
					++$rows;
				}
			}
		}
	}
	WP_CLI::success( "page {$post->ID}, {$rows} events, products: " . implode( ',', $pids ) );
} )();
