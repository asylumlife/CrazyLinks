<?php
/**
 * What the model sees: a trimmed page document plus optional site facts and analytics totals.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\Analytics\Summary;
use CrazyLinks\Render\Themes;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC §10.1 principle 5: the model never sees secrets. The context is built only from the page
 * document (minus custom HTML and custom CSS, which the AI may not touch), public site facts (title,
 * tagline, published posts, menus, the user's own public profile URLs, published products) and
 * aggregate click counts. No options, keys, emails, subscriber data or raw visitor events.
 */
final class Context {

	/**
	 * Trimmed page document.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return array<string, mixed>
	 */
	public static function page( array $doc ): array {
		$blocks = [];
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$out = [
				'id'        => (string) ( $block['id'] ?? '' ),
				'type'      => (string) ( $block['type'] ?? '' ),
				'parent_id' => $block['parent_id'] ?? null,
				'order'     => (int) ( $block['order'] ?? 0 ),
			];
			if ( 'html' === $out['type'] ) {
				$out['note'] = 'Custom HTML block. Not editable by AI; leave it alone.';
				$blocks[]    = $out;
				continue;
			}
			if ( isset( $block['visible'] ) && ! $block['visible'] ) {
				$out['visible'] = false;
			}
			if ( ! empty( $block['pinned'] ) ) {
				$out['pinned'] = true;
			}
			if ( ! empty( $block['animation'] ) && 'none' !== $block['animation'] ) {
				$out['animation'] = $block['animation'];
			}
			if ( isset( $block['tracking'] ) && ! $block['tracking'] ) {
				$out['tracking'] = false;
			}
			$schedule = array_filter( (array) ( $block['schedule'] ?? [] ) );
			if ( $schedule ) {
				$out['schedule'] = $schedule;
			}
			$out['props'] = (array) ( $block['props'] ?? [] );
			$blocks[]     = $out;
		}
		$design = (array) ( $doc['design'] ?? [] );
		if ( isset( $design['custom_css'] ) ) {
			$design['custom_css'] = '(custom CSS present; not editable by AI)';
		}
		return [
			'profile' => (array) ( $doc['profile'] ?? [] ),
			'design'  => $design,
			'blocks'  => $blocks,
		];
	}

	/**
	 * Media library items the page references (id → title, type), so the model can talk about them.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return array<int, array{title:string, mime:string}>
	 */
	public static function media( array $doc ): array {
		$ids = [];
		foreach ( [ 'avatar', 'banner' ] as $key ) {
			$ids[] = (int) ( $doc['profile'][ $key ] ?? 0 );
		}
		$ids[] = (int) ( $doc['design']['background']['image'] ?? 0 );
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $block ) {
			$props = (array) ( $block['props'] ?? [] );
			foreach ( [ 'thumbnail', 'image', 'poster', 'video' ] as $key ) {
				$ids[] = (int) ( $props[ $key ] ?? 0 );
			}
			foreach ( (array) ( $props['items'] ?? [] ) as $item ) {
				$ids[] = (int) ( is_array( $item ) ? ( $item['image'] ?? 0 ) : 0 );
			}
		}
		$out = [];
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			if ( 'attachment' === get_post_type( $id ) ) {
				$out[ $id ] = [
					'title' => (string) get_the_title( $id ),
					'mime'  => (string) get_post_mime_type( $id ),
				];
			}
		}
		return $out;
	}

	/**
	 * Facts about the site and the requesting user for "build from this site". Public data only.
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>
	 */
	public static function site_facts( int $user_id ): array {
		$facts = [
			'site_name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'tagline'   => wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES ),
			'home_url'  => home_url( '/' ),
			'posts'     => [],
			'menus'     => [],
			'profile'   => [],
			'products'  => [],
		];
		$posts = get_posts(
			[
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => 10,
				'has_password'     => false,
				'suppress_filters' => false,
			]
		);
		foreach ( $posts as $post ) {
			$facts['posts'][] = [
				'title' => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
				'url'   => (string) get_permalink( $post ),
			];
		}
		foreach ( array_slice( wp_get_nav_menus(), 0, 3 ) as $menu ) {
			$items = [];
			foreach ( array_slice( (array) wp_get_nav_menu_items( $menu->term_id ), 0, 20 ) as $item ) {
				if ( is_object( $item ) && isset( $item->url, $item->title ) ) {
					$items[] = [
						'title' => wp_strip_all_tags( (string) $item->title ),
						'url'   => (string) $item->url,
					];
				}
			}
			$facts['menus'][] = [
				'name'  => (string) $menu->name,
				'items' => $items,
			];
		}
		$user = get_userdata( $user_id );
		if ( $user instanceof \WP_User ) {
			$facts['profile'] = [
				'display_name' => $user->display_name,
				'website'      => (string) $user->user_url,
				'bio'          => wp_strip_all_tags( (string) get_user_meta( $user_id, 'description', true ) ),
				'social_urls'  => [],
			];
			// Social profile fields added by common SEO plugins (URLs only).
			foreach ( [ 'facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'tiktok', 'pinterest', 'soundcloud', 'mastodon', 'bluesky', 'threads' ] as $key ) {
				$url = (string) get_user_meta( $user_id, $key, true );
				if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
					$facts['profile']['social_urls'][ $key ] = esc_url_raw( $url );
				}
			}
		}
		if ( class_exists( 'WooCommerce' ) ) {
			foreach ( get_posts(
				[
					'post_type'   => 'product',
					'post_status' => 'publish',
					'numberposts' => 10,
				]
			) as $product ) {
				$facts['products'][] = [
					'id'    => (int) $product->ID,
					'title' => wp_specialchars_decode( get_the_title( $product ), ENT_QUOTES ),
					'url'   => (string) get_permalink( $product ),
				];
			}
		}
		if ( \CrazyLinks\Subscribers\Integrations::mailchimp_connected() ) {
			// Audience ids and names only (from the cache, no request), for email_capture audience_id.
			$facts['audiences'] = array_map(
				static fn( array $a ): array => [
					'id'   => $a['id'],
					'name' => $a['name'],
				],
				\CrazyLinks\Subscribers\Integrations::cached_audiences()
			);
		}
		/**
		 * Filters the public site facts sent to the AI for "build from this site".
		 * Never add secrets, emails or visitor data here.
		 *
		 * @param array<string, mixed> $facts   Facts.
		 * @param int                  $user_id Requesting user.
		 */
		return (array) apply_filters( 'crazylinks_ai_site_facts', $facts, $user_id );
	}

	/**
	 * Aggregate click counts for the last 30 days (daily rollups plus today's totals; no visitor data).
	 *
	 * @param int $page_id Page.
	 * @return array<string, mixed>
	 */
	public static function analytics( int $page_id ): array {
		$summary = Summary::for_page( $page_id, 30 );
		return [
			'range'  => $summary['range'],
			'views'  => $summary['views'],
			'clicks' => $summary['clicks'],
			'ctr'    => $summary['ctr'],
			'blocks' => array_map(
				static fn( $b ) => [
					'block_id' => $b['block_id'],
					'title'    => $b['title'],
					'clicks'   => $b['clicks'],
				],
				array_slice( (array) $summary['blocks'], 0, 50 )
			),
		];
	}

	/**
	 * Theme preset slugs and names the model may choose from.
	 *
	 * @return array<string, string>
	 */
	public static function themes(): array {
		$out = [];
		foreach ( Themes::all() as $slug => $theme ) {
			$out[ (string) $slug ] = (string) ( is_array( $theme ) ? ( $theme['label'] ?? $slug ) : $slug );
		}
		return $out;
	}
}
