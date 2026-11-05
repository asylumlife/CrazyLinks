<?php
/**
 * Rewrite layer (SPEC §8).
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Frontend;

use CrazyLinks\PostType;
use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers three narrowly scoped rules, `/{base}/{slug}`, `/{base}/{slug}/go/{block_id}` and
 * `/{base}/{slug}/vcard`, and falls back to normal WordPress resolution when no link page has that
 * slug, so the rules never shadow other content. Our query vars are honoured only when one of these
 * rules matched (or, for a bare `crazylinks_slug`, when permalinks are plain), so they cannot be
 * appended to other URLs (SPEC §12).
 */
final class Router {

	public const QV_SLUG  = 'crazylinks_slug';
	public const QV_GO    = 'crazylinks_go';
	public const QV_ITEM  = 'crazylinks_item';
	public const QV_VCARD = 'crazylinks_vcard';

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'add_rules' ], 20 );
		add_action( 'init', [ $this, 'maybe_flush' ], 99 );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_filter( 'request', [ $this, 'map_request' ] );
		add_filter( 'redirect_canonical', [ $this, 'no_canonical_redirect' ] );
		add_filter( 'wp_speculation_rules_href_exclude_paths', [ $this, 'exclude_from_speculation' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve_vcard' ], 1 );
	}

	/**
	 * The rule regexes for the current base.
	 *
	 * @return array<string, string> regex → query
	 */
	public static function rules(): array {
		$base = preg_quote( Settings::base(), '#' );
		return [
			'^' . $base . '/([^/]+)/go/([A-Za-z0-9]{1,64})(?:/([A-Za-z0-9]{1,64}))?/?$' => 'index.php?' . self::QV_SLUG . '=$matches[1]&' . self::QV_GO . '=$matches[2]&' . self::QV_ITEM . '=$matches[3]',
			'^' . $base . '/([^/]+)/vcard/?$' => 'index.php?' . self::QV_SLUG . '=$matches[1]&' . self::QV_VCARD . '=1',
			'^' . $base . '/([^/]+)/?$'       => 'index.php?' . self::QV_SLUG . '=$matches[1]',
		];
	}

	/**
	 * Adds rules at the top.
	 */
	public function add_rules(): void {
		foreach ( self::rules() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * Removes our rules from WP_Rewrite (deactivation).
	 */
	public static function remove_rules(): void {
		global $wp_rewrite;
		if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
			return;
		}
		foreach ( array_keys( self::rules() ) as $regex ) {
			unset( $wp_rewrite->extra_rules_top[ $regex ] );
		}
	}

	/**
	 * Flushes once after the base path changed.
	 */
	public function maybe_flush(): void {
		if ( get_option( 'crazylinks_flush_rewrite' ) || self::rules_outdated() ) {
			delete_option( 'crazylinks_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Whether the stored rewrite rules predate the current /go/ rule (the plugin was updated
	 * without being re-activated). Always false with plain permalinks, which store no rules.
	 */
	private static function rules_outdated(): bool {
		$stored = get_option( 'rewrite_rules' );
		return is_array( $stored ) && [] !== $stored && ! array_key_exists( (string) array_key_first( self::rules() ), $stored );
	}

	/**
	 * Registers query vars.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public function query_vars( $vars ): array {
		$vars[] = self::QV_SLUG;
		$vars[] = self::QV_GO;
		$vars[] = self::QV_ITEM;
		$vars[] = self::QV_VCARD;
		return $vars;
	}

	/**
	 * Maps our query vars onto a normal singular query, or hands the path back to WordPress
	 * when no link page has the slug (so a real page at /l/foo still works).
	 *
	 * @param array<string, mixed> $qv Query vars.
	 * @return array<string, mixed>
	 */
	public function map_request( $qv ): array {
		$qv   = (array) $qv;
		$vars = $this->our_vars( $qv );
		unset( $qv[ self::QV_SLUG ], $qv[ self::QV_GO ], $qv[ self::QV_ITEM ], $qv[ self::QV_VCARD ] );
		if ( null === $vars ) {
			return $qv;
		}
		$slug  = sanitize_title( urldecode( $vars[ self::QV_SLUG ] ) );
		$go    = $vars[ self::QV_GO ];
		$item  = '' !== $go ? $vars[ self::QV_ITEM ] : '';
		$vcard = '' !== $vars[ self::QV_VCARD ] && '' === $go;
		$page  = '' !== $slug ? get_page_by_path( $slug, OBJECT, PostType::NAME ) : null;

		if ( ! $page instanceof \WP_Post ) {
			$path = Settings::base() . '/' . $slug . ( '' !== $go ? '/go/' . $go . ( '' !== $item ? '/' . $item : '' ) : '' ) . ( $vcard ? '/vcard' : '' );
			return [ 'pagename' => $path ];
		}
		$out = [
			'post_type' => PostType::NAME,
			'name'      => $slug,
		];
		if ( '' !== $go ) {
			$out[ self::QV_GO ] = $go;
			if ( '' !== $item ) {
				$out[ self::QV_ITEM ] = $item;
			}
		} elseif ( $vcard ) {
			$out[ self::QV_VCARD ] = '1';
		}
		return $out;
	}

	/**
	 * The CrazyLinks query vars that may be honoured for this request, or null.
	 *
	 * With pretty permalinks they come only from one of our matched rewrite rules (never from the
	 * query string, so `?crazylinks_slug=` or `?crazylinks_go=` cannot be appended to other URLs);
	 * with plain permalinks query-string URLs are the only form and are honoured as-is.
	 *
	 * @param array<string, mixed> $qv Query vars.
	 * @return array{crazylinks_slug:string, crazylinks_go:string, crazylinks_item:string, crazylinks_vcard:string}|null
	 */
	private function our_vars( array $qv ): ?array {
		if ( '' !== (string) get_option( 'permalink_structure' ) ) {
			global $wp;
			if ( ! $wp instanceof \WP || ! is_string( $wp->matched_rule ) || ! array_key_exists( $wp->matched_rule, self::rules() ) ) {
				return null;
			}
			$qv = [];
			wp_parse_str( (string) $wp->matched_query, $qv );
		}
		$out = [];
		foreach ( [ self::QV_SLUG, self::QV_GO, self::QV_ITEM, self::QV_VCARD ] as $key ) {
			$out[ $key ] = isset( $qv[ $key ] ) && is_string( $qv[ $key ] ) ? $qv[ $key ] : '';
		}
		return '' === $out[ self::QV_SLUG ] ? null : $out;
	}

	/**
	 * Serves /{base}/{slug}/vcard/ for singular link pages, or turns it into a 404.
	 */
	public function maybe_serve_vcard(): void {
		if ( '' === (string) get_query_var( self::QV_VCARD ) || ! is_singular( PostType::NAME ) ) {
			return;
		}
		( new VCard() )->handle( get_queried_object() );
	}

	/**
	 * Prevents WordPress from "correcting" our URLs to the CPT's query-string permalink.
	 *
	 * @param string|false $redirect Redirect URL.
	 * @return string|false
	 */
	public function no_canonical_redirect( $redirect ) {
		if ( is_singular( PostType::NAME ) ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Keeps core speculative loading from prefetching click redirects (each would log a click).
	 *
	 * @param string[] $paths Excluded paths.
	 * @return string[]
	 */
	public function exclude_from_speculation( $paths ): array {
		$paths   = (array) $paths;
		$prefix  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$paths[] = rtrim( $prefix, '/' ) . '/' . Settings::base() . '/*/go/*';
		$paths[] = rtrim( $prefix, '/' ) . '/' . Settings::base() . '/*/vcard*';
		return $paths;
	}

	/**
	 * Public URL of a page.
	 *
	 * @param string $slug Slug.
	 */
	public static function page_url( string $slug ): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QV_SLUG, rawurlencode( $slug ), home_url( '/' ) );
		}
		return home_url( user_trailingslashit( Settings::base() . '/' . rawurlencode( $slug ) ) );
	}

	/**
	 * Click redirect URL for a block.
	 *
	 * @param string $slug     Page slug.
	 * @param string $block_id Block id.
	 * @param string $item_id  Item id inside the block (social icons), or ''.
	 */
	public static function go_url( string $slug, string $block_id, string $item_id = '' ): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg(
				array_filter(
					[
						self::QV_SLUG => rawurlencode( $slug ),
						self::QV_GO   => rawurlencode( $block_id ),
						self::QV_ITEM => rawurlencode( $item_id ),
					]
				),
				home_url( '/' )
			);
		}
		return home_url( user_trailingslashit( Settings::base() . '/' . rawurlencode( $slug ) . '/go/' . rawurlencode( $block_id ) . ( '' !== $item_id ? '/' . rawurlencode( $item_id ) : '' ) ) );
	}

	/**
	 * Root-relative click redirect path for a block, used in rendered (cached) HTML so the markup
	 * never depends on the request's host or scheme (SPEC §12).
	 *
	 * @param string $slug     Page slug.
	 * @param string $block_id Block id.
	 * @param string $item_id  Item id inside the block (social icons), or ''.
	 */
	public static function go_path( string $slug, string $block_id, string $item_id = '' ): string {
		return wp_make_link_relative( self::go_url( $slug, $block_id, $item_id ) );
	}

	/**
	 * Download URL of the vCard for a page's contact block.
	 *
	 * @param string $slug Page slug.
	 */
	public static function vcard_url( string $slug ): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg(
				[
					self::QV_SLUG  => rawurlencode( $slug ),
					self::QV_VCARD => '1',
				],
				home_url( '/' )
			);
		}
		return home_url( user_trailingslashit( Settings::base() . '/' . rawurlencode( $slug ) . '/vcard' ) );
	}

	/**
	 * Root-relative vCard download path (for rendered HTML).
	 *
	 * @param string $slug Page slug.
	 */
	public static function vcard_path( string $slug ): string {
		return wp_make_link_relative( self::vcard_url( $slug ) );
	}
}
