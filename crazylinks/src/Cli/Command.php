<?php
/**
 * WP-CLI commands.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Cli;

use CrazyLinks\Analytics\Rollup;
use CrazyLinks\PageStore;
use CrazyLinks\PostType;
use CrazyLinks\Schema\PageValidator;
use CrazyLinks\Schema\Ulid;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage CrazyLinks link pages.
 */
final class Command {

	/**
	 * Creates (or replaces) a demo page that shows one of every block: links in each presentation, socials,
	 * gallery, email sign-up, payment, countdown, video, latest posts, contact and map.
	 *
	 * Placeholder images are generated with GD and imported with `wp media import` (reused on later runs).
	 *
	 * ## OPTIONS
	 *
	 * [--slug=<slug>]
	 * : Page slug. Default: demo.
	 *
	 * [--theme=<theme>]
	 * : Theme preset slug. Default: midnight.
	 *
	 * [--layout=<layout>]
	 * : Page layout: list, grid or featured. Default: list.
	 *
	 * [--porcelain]
	 * : Output only the page id.
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function demo( $args, $assoc_args ): void {
		unset( $args );
		$slug   = sanitize_title( $assoc_args['slug'] ?? 'demo' );
		$images = $this->demo_images();
		$this->demo_posts( $images );
		$doc    = $this->demo_document( $images, sanitize_key( $assoc_args['theme'] ?? 'midnight' ), sanitize_key( $assoc_args['layout'] ?? 'list' ) );
		$result = ( new PageValidator() )->validate( $doc, get_current_user_id() );
		if ( ! $result->is_valid() ) {
			WP_CLI::error( 'Demo document invalid: ' . wp_json_encode( $result->errors() ) );
		}
		$existing = get_page_by_path( $slug, OBJECT, PostType::NAME );
		$postarr  = [
			'post_type'    => PostType::NAME,
			'post_title'   => 'Demo link page',
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_content' => wp_slash( PageStore::encode( $result->document() ) ),
		];
		if ( $existing instanceof \WP_Post ) {
			$postarr['ID'] = $existing->ID;
		}
		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id );
		}
		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $id );
			return;
		}
		WP_CLI::success( sprintf( 'Demo page %d at %s', $id, get_permalink( $id ) ) );
		foreach ( (array) ( PageStore::get_document( $id )['blocks'] ?? [] ) as $block ) {
			if ( 'link' === $block['type'] ) {
				WP_CLI::line( sprintf( '  %s  %s  -> %s', $block['id'], $block['props']['title'], $block['props']['url'] ) );
			}
		}
	}

	/**
	 * Attachment ids of the three placeholder images, importing them on first use.
	 *
	 * @return int[]
	 */
	private function demo_images(): array {
		$existing = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => '_crazylinks_demo', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small CLI lookup.
				'posts_per_page' => 3,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			]
		);
		if ( 3 === count( $existing ) ) {
			return array_map( 'intval', $existing );
		}
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			WP_CLI::warning( 'PHP GD is not available; the demo gallery is skipped.' );
			return [];
		}
		$dir = trailingslashit( get_temp_dir() ) . 'crazylinks-demo/';
		wp_mkdir_p( $dir );
		$palette = [
			[ [ 79, 70, 229 ], [ 236, 72, 153 ] ],
			[ [ 16, 185, 129 ], [ 59, 130, 246 ] ],
			[ [ 245, 158, 11 ], [ 239, 68, 68 ] ],
		];
		$ids     = [];
		foreach ( $palette as $i => [ $from, $to ] ) {
			$file = $dir . 'crazylinks-demo-' . ( $i + 1 ) . '.png';
			$this->demo_png( $file, 1360, 765, $from, $to );
			$out = WP_CLI::runcommand(
				sprintf( 'media import %s --porcelain --title=%s --alt=%s', escapeshellarg( $file ), escapeshellarg( 'Demo image ' . ( $i + 1 ) ), escapeshellarg( 'Placeholder gradient ' . ( $i + 1 ) ) ),
				[
					'return'     => true,
					'exit_error' => false,
				]
			);
			$id  = (int) $out;
			if ( $id > 0 ) {
				update_post_meta( $id, '_crazylinks_demo', '1' );
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Writes a diagonal gradient PNG.
	 *
	 * @param string $file   Path.
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 * @param int[]  $from   RGB.
	 * @param int[]  $to     RGB.
	 */
	private function demo_png( string $file, int $width, int $height, array $from, array $to ): void {
		$img = imagecreatetruecolor( $width, $height );
		if ( false === $img ) {
			return;
		}
		for ( $y = 0; $y < $height; $y++ ) {
			$t   = $y / ( $height - 1 );
			$col = imagecolorallocate( $img, (int) ( $from[0] + ( $to[0] - $from[0] ) * $t ), (int) ( $from[1] + ( $to[1] - $from[1] ) * $t ), (int) ( $from[2] + ( $to[2] - $from[2] ) * $t ) );
			if ( false !== $col ) {
				imageline( $img, 0, $y, $width, $y, $col );
			}
		}
		$white = imagecolorallocatealpha( $img, 255, 255, 255, 90 );
		if ( false !== $white ) {
			imagefilledellipse( $img, (int) ( $width * 0.7 ), (int) ( $height * 0.35 ), (int) ( $height * 0.8 ), (int) ( $height * 0.8 ), $white );
		}
		imagepng( $img, $file );
	}

	/**
	 * Makes sure there are a few posts (with featured images) for the latest_posts block.
	 *
	 * @param int[] $images Placeholder attachment ids.
	 */
	private function demo_posts( array $images ): void {
		$have = (int) ( wp_count_posts( 'post' )->publish ?? 0 );
		for ( $i = $have; $i < 3; $i++ ) {
			$id = wp_insert_post(
				[
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_title'   => 'Demo post ' . ( $i + 1 ),
					'post_content' => 'Placeholder post created by wp crazylinks demo.',
				]
			);
			if ( $id && isset( $images[ $i ] ) ) {
				set_post_thumbnail( (int) $id, $images[ $i ] );
			}
		}
	}

	/**
	 * The demo document.
	 *
	 * @param int[]  $images Placeholder attachment ids (may be empty).
	 * @param string $theme  Theme preset.
	 * @param string $layout Page layout.
	 * @return array<string, mixed>
	 */
	private function demo_document( array $images, string $theme, string $layout ): array {
		$section = Ulid::generate();
		$blocks  = [
			[
				'type'  => 'heading',
				'props' => [ 'text' => 'Latest' ],
			],
			[
				'type'      => 'link',
				'pinned'    => true,
				'animation' => 'pulse',
				'props'     => [
					'title'     => 'Listen to the new single',
					'subtitle'  => 'Out now everywhere',
					'url'       => 'https://example.com/single',
					'new_until' => gmdate( 'c', time() + 7 * DAY_IN_SECONDS ),
				],
			],
		];
		if ( $images ) {
			$blocks[] = [
				'type'  => 'link',
				'props' => [
					'title'     => 'Watch the live session',
					'subtitle'  => 'Featured card',
					'url'       => 'https://example.com/live',
					'display'   => 'featured',
					'thumbnail' => $images[0],
					'badge'     => [ 'text' => 'Live' ],
				],
			];
			$blocks[] = [
				'type'  => 'link',
				'props' => [
					'title'      => 'Tour dates',
					'subtitle'   => 'Thumbnail on the right',
					'url'        => 'https://example.com/tour',
					'display'    => 'thumbnail',
					'thumb_side' => 'right',
					'thumbnail'  => $images[1] ?? $images[0],
				],
			];
		}
		$blocks = array_merge(
			$blocks,
			[
				[
					'type'  => 'link',
					'props' => [
						'title' => 'Merch store',
						'url'   => 'https://example.org/shop?ref=bio',
						'emoji' => '🛍️',
						'rel'   => [ 'sponsored' ],
					],
				],
				[
					'type'  => 'link',
					'props' => [
						'title' => 'Source on GitHub',
						'url'   => 'https://github.com/example',
						'icon'  => 'github',
					],
				],
				[
					'type'  => 'link',
					'props' => [
						'title' => 'Email me',
						'url'   => 'mailto:hello@example.com',
					],
				],
				[
					'id'       => $section,
					'type'     => 'group',
					// On air Monday to Thursday 13:00-14:00; the section shows from 12:30 (site time).
					'schedule' => [
						'start'  => null,
						'end'    => null,
						'weekly' => [
							'days'  => [ 1, 2, 3, 4 ],
							'start' => '12:30',
							'end'   => '14:00',
						],
					],
					'props'    => [
						'heading'     => 'On air now',
						'description' => 'The lunchtime show, Monday to Thursday 1-2 pm.',
						'background'  => [
							'type'  => 'gradient',
							'from'  => '#7c3aed',
							'to'    => '#db2777',
							'angle' => 135,
						],
						'padding'     => 'md',
						'radius'      => 'lg',
					],
				],
				[
					'type'      => 'link',
					'parent_id' => $section,
					'props'     => [
						'title' => 'Listen live',
						'url'   => 'https://example.com/live-radio',
						'icon'  => 'spotify',
					],
				],
				[
					'type'      => 'link',
					'parent_id' => $section,
					'props'     => [
						'title' => 'Call the show',
						'url'   => 'tel:+15125550100',
					],
				],
				[
					'type'  => 'heading',
					'props' => [
						'text'    => 'Release party',
						'subtext' => 'One night only',
						'size'    => 'lg',
						'weight'  => 'black',
						'divider' => 'line',
						'emoji'   => '🎉',
						'spacing' => 'loose',
					],
				],
				[
					'type'  => 'countdown',
					'props' => [
						'label'        => 'Doors open in',
						'target'       => gmdate( 'c', time() + 14 * DAY_IN_SECONDS + 3 * HOUR_IN_SECONDS ),
						'expired_text' => 'We are open!',
						'url'          => 'https://example.com/tickets',
						'link_label'   => 'Get tickets',
					],
				],
				[
					'type'  => 'map',
					'props' => [
						'label'   => 'The Venue',
						'address' => "1100 Congress Ave\nAustin, TX 78701",
					],
				],
				[
					'type'  => 'video',
					'props' => [
						'url'   => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
						'title' => 'Official video',
					],
				],
			]
		);
		if ( $images ) {
			$blocks[] = [
				'type'  => 'heading',
				'props' => [ 'text' => 'Gallery' ],
			];
			$blocks[] = [
				'type'  => 'gallery',
				'props' => [
					'columns' => 3,
					'items'   => array_map(
						static fn( int $id ): array => [
							'image'   => $id,
							'caption' => '',
							'url'     => 'https://example.com/photos',
						],
						$images
					),
				],
			];
		}
		$blocks  = array_merge(
			$blocks,
			[
				[
					'type'  => 'heading',
					'props' => [ 'text' => 'From the blog' ],
				],
				[
					'type'  => 'latest_posts',
					'props' => [
						'count'   => 3,
						'display' => 'classic',
					],
				],
				[
					'type'  => 'heading',
					'props' => [ 'text' => 'Support' ],
				],
				[
					'type'  => 'payment',
					'props' => [
						'service'     => 'kofi',
						'handle'      => 'example',
						'amount_hint' => 'Any amount helps',
					],
				],
				[
					'type'  => 'payment',
					'props' => [
						'service' => 'buymeacoffee',
						'handle'  => 'example',
					],
				],
				[
					'type'  => 'contact',
					'props' => [
						'phone'   => '+1 (512) 555-0100',
						'email'   => 'hello@example.com',
						'address' => '1100 Congress Ave, Austin, TX 78701',
						'vcard'   => true,
					],
				],
				[
					'type'  => 'email_capture',
					'props' => [
						'heading'      => 'Join the list',
						'button_text'  => 'Subscribe',
						'consent_text' => 'I agree to receive occasional emails. Unsubscribe any time.',
						'pending_text' => 'Almost there! Open the email we just sent and tap the link.',
						'success_text' => 'You are on the list. Thank you!',
						'destination'  => 'local',
					],
				],
				[
					'type'  => 'socials',
					'props' => [
						'items' => [
							[
								'network' => 'instagram',
								'url'     => 'https://instagram.com/example',
							],
							[
								'network' => 'tiktok',
								'url'     => 'https://www.tiktok.com/@example',
							],
							[
								'network' => 'youtube',
								'url'     => 'https://youtube.com/@example',
							],
							[
								'network' => 'spotify',
								'url'     => 'https://open.spotify.com/artist/example',
							],
							[
								'network' => 'bluesky',
								'url'     => 'https://bsky.app/profile/example',
							],
							[
								'network' => 'email',
								'url'     => 'mailto:hello@example.com',
							],
						],
					],
				],
			]
		);
		$profile = [
			'name'           => 'Crazy Demo',
			'bio'            => "Musician, maker, occasional podcaster.\nNew single out Friday.",
			'location'       => 'Austin, TX',
			'verified_badge' => true,
		];
		if ( $images ) {
			$profile['avatar'] = $images[2] ?? $images[0];
		}
		return [
			'schema'   => 1,
			'profile'  => $profile,
			'design'   => [
				'theme'  => $theme,
				'layout' => in_array( $layout, [ 'list', 'grid', 'featured' ], true ) ? $layout : 'list',
			],
			'blocks'   => $blocks,
			'settings' => [
				'in_app_hint' => true,
				'utm'         => [
					'enabled'  => true,
					'source'   => 'crazylinks',
					'medium'   => 'bio',
					'campaign' => '{slug}',
				],
			],
		];
	}

	/**
	 * Validates a JSON file against the page schema.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a JSON file, or - for STDIN.
	 *
	 * @param string[] $args Positional args.
	 */
	public function validate( $args ): void {
		$file   = $args[0];
		$json   = '-' === $file ? (string) stream_get_contents( STDIN ) : (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI input.
		$result = ( new PageValidator() )->validate( $json, get_current_user_id() );
		if ( $result->is_valid() ) {
			foreach ( $result->warnings() as $warning ) {
				WP_CLI::warning( 'Warning: ' . $warning['path'] . ' ' . $warning['message'] );
			}
			WP_CLI::success( 'Valid.' );
			return;
		}
		foreach ( $result->errors() as $error ) {
			WP_CLI::warning( $error['path'] . ' ' . $error['message'] );
		}
		WP_CLI::halt( 1 );
	}

	/**
	 * Runs the daily analytics rollup now.
	 *
	 * @subcommand rollup
	 */
	public function rollup(): void {
		( new Rollup() )->run();
		WP_CLI::success( 'Rollup complete through ' . get_option( 'crazylinks_rollup_through', '(nothing to roll up)' ) );
	}
}
