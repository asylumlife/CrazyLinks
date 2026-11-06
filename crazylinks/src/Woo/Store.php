<?php
/**
 * Product data for the Product block: a separate store through WooCommerce's public Store API, or
 * WooCommerce on this site.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Woo;

use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that talks to a store. Every request URL is the `store_url` setting plus a fixed
 * path and a fixed set of query keys: block props and request input only ever supply values inside
 * the query string (an integer id, a search term, a category slug), never the host or the path
 * (SSRF guard, SPEC §12). Redirects are not followed, so a store cannot bounce the request to
 * another host.
 *
 * Results are normalized to one shape, cached for 15 minutes and also kept without expiry, so a
 * slow or unreachable store serves its last good answer instead of nothing. Nothing from the store
 * is trusted: names lose their tags, URLs are validated and pinned to the store's host, and images
 * must be https.
 *
 * Normalized product: `id` int, `name` string (plain text), `url` string, `image` string ('' if
 * none), `price` string (formatted with the store's own symbol), `amount` string (number only),
 * `currency` string (code), `regular_price` string ('' unless on sale), `on_sale` bool,
 * `price_from` bool (a variable product with a price range shows its lowest price).
 */
final class Store {

	public const API_PATH     = '/wp-json/wc/store/v1/products';
	public const ROOT_PATH    = '/wp-json/';
	public const STALE_OPTION = 'crazylinks_store_last';
	public const GEN_OPTION   = 'crazylinks_store_gen';
	public const TTL          = 15 * MINUTE_IN_SECONDS;
	public const BACKOFF      = 2 * MINUTE_IN_SECONDS;
	private const MAX_STALE   = 60;

	/**
	 * Sanitizes the store address setting. Returns '' when it is not usable.
	 *
	 * @param mixed $url Raw value.
	 */
	public static function sanitize_url( $url ): string {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url || strlen( $url ) > 200 ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && self::allow_http() ) ) {
			return '';
		}
		$host    = strtolower( (string) $parts['host'] );
		$dotless = false === strpos( $host, '.' );
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) || ( $dotless && ! self::allow_http() ) ) {
			return '';
		}
		$path = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';
		if ( '' !== $path && ! preg_match( '#^(/[A-Za-z0-9._~-]+)+$#', $path ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		return $scheme . '://' . $host . ( $port > 0 ? ':' . $port : '' ) . $path;
	}

	/**
	 * Whether plain http store addresses are allowed (local environments only).
	 */
	public static function allow_http(): bool {
		/**
		 * Filters whether an http (not https) store address is accepted. Default: only when
		 * wp_get_environment_type() is 'local'.
		 *
		 * @param bool $allow Whether http is accepted.
		 */
		return (bool) apply_filters( 'crazylinks_store_allow_http', 'local' === wp_get_environment_type() );
	}

	/**
	 * The configured store address, re-validated on every read, or ''.
	 */
	public static function base_url(): string {
		return self::sanitize_url( (string) Settings::get( 'store_url' ) );
	}

	/**
	 * Host (and port) of the configured store, or ''.
	 */
	public static function host(): string {
		$base = self::base_url();
		if ( '' === $base ) {
			return '';
		}
		$parts = (array) wp_parse_url( $base );
		return strtolower( (string) ( $parts['host'] ?? '' ) ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * Where products come from: 'remote' (store_url set), 'local' (WooCommerce on this site and no
	 * store_url) or 'none'.
	 */
	public static function mode(): string {
		if ( '' !== self::base_url() ) {
			return 'remote';
		}
		return self::local_available() ? 'local' : 'none';
	}

	/**
	 * Whether any product source is available.
	 */
	public static function is_available(): bool {
		return 'none' !== self::mode();
	}

	/**
	 * WooCommerce on this site.
	 */
	private static function local_available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) && function_exists( 'wc_get_products' );
	}

	/**
	 * One product by id.
	 *
	 * @param int $id Product id.
	 * @return array<string, mixed>|null
	 */
	public static function product( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		switch ( self::mode() ) {
			case 'local':
				return self::local_product( $id );
			case 'remote':
				$list = self::remote_cached( 'product', [ 'include' => (string) $id ] );
				foreach ( $list as $product ) {
					if ( $id === $product['id'] ) {
						return $product;
					}
				}
				return null;
		}
		return null;
	}

	/**
	 * The newest products, optionally in one category (slug).
	 *
	 * @param int    $count    How many (1 to 12).
	 * @param string $category Category slug or ''.
	 * @return array<int, array<string, mixed>>
	 */
	public static function latest( int $count, string $category = '' ): array {
		$count    = max( 1, min( 12, $count ) );
		$category = self::clean_slug( $category );
		switch ( self::mode() ) {
			case 'local':
				return self::local_query(
					[
						'limit'   => $count,
						'orderby' => 'date',
						'order'   => 'DESC',
						'status'  => 'publish',
					] + ( '' !== $category ? [ 'category' => [ $category ] ] : [] )
				);
			case 'remote':
				$query = [
					'per_page' => (string) $count,
					'orderby'  => 'date',
					'order'    => 'desc',
				];
				if ( '' !== $category ) {
					$query['category'] = $category;
				}
				return self::remote_cached( 'latest', $query );
		}
		return [];
	}

	/**
	 * Products matching a search term (editor picker, ability). Cached briefly, never kept as stale.
	 *
	 * @param string $term  Search term ('' lists the newest).
	 * @param int    $limit How many (1 to 50).
	 * @return array<int, array<string, mixed>>
	 */
	public static function search( string $term, int $limit = 10 ): array {
		$limit = max( 1, min( 50, $limit ) );
		$term  = trim( wp_strip_all_tags( $term ) );
		$term  = function_exists( 'mb_substr' ) ? mb_substr( $term, 0, 100 ) : substr( $term, 0, 100 );
		switch ( self::mode() ) {
			case 'local':
				return self::local_query(
					[
						'limit'   => $limit,
						'orderby' => 'date',
						'order'   => 'DESC',
						'status'  => 'publish',
					] + ( '' !== $term ? [ 's' => $term ] : [] )
				);
			case 'remote':
				$query = [
					'per_page' => (string) $limit,
					'orderby'  => 'date',
					'order'    => 'desc',
				];
				if ( '' !== $term ) {
					$query['search'] = $term;
				}
				return self::remote_cached( 'search', $query, false );
		}
		return [];
	}

	/**
	 * A product the render cache and /go/ may rely on without a network request: the cached or
	 * last-good copy (remote), or the live product (local). Null when there is none.
	 *
	 * @param int    $id       Product id.
	 * @param string $mode     'single' or 'latest'.
	 * @param int    $count    Count of the block (latest mode).
	 * @param string $category Category slug of the block (latest mode).
	 * @return array<string, mixed>|null
	 */
	public static function known_product( int $id, string $mode = 'single', int $count = 3, string $category = '' ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		switch ( self::mode() ) {
			case 'local':
				if ( 'latest' === $mode ) {
					foreach ( self::latest( $count, $category ) as $product ) {
						if ( $id === $product['id'] ) {
							return $product;
						}
					}
					return null;
				}
				return self::local_product( $id );
			case 'remote':
				if ( 'latest' === $mode ) {
					$query = [
						'per_page' => (string) max( 1, min( 12, $count ) ),
						'orderby'  => 'date',
						'order'    => 'desc',
					];
					$slug  = self::clean_slug( $category );
					if ( '' !== $slug ) {
						$query['category'] = $slug;
					}
					$list = self::stored( 'latest', $query );
				} else {
					$list = self::stored( 'product', [ 'include' => (string) $id ] );
				}
				foreach ( $list as $product ) {
					if ( $id === $product['id'] && self::on_store_host( (string) $product['url'] ) ) {
						return $product;
					}
				}
		}
		return null;
	}

	/**
	 * Whether a URL is https (http in local environments) and on the configured store's host.
	 *
	 * @param string $url URL.
	 */
	public static function on_store_host( string $url ): bool {
		$parts  = (array) wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		return ( 'https' === $scheme || ( 'http' === $scheme && self::allow_http() ) ) && '' !== self::host() && self::host() === $host;
	}

	/**
	 * The short form used by the picker and the ability.
	 *
	 * @param array<string, mixed> $p Normalized product.
	 * @return array{id:int, name:string, price:string, url:string, image:string, on_sale:bool}
	 */
	public static function brief( array $p ): array {
		return [
			'id'      => (int) $p['id'],
			'name'    => (string) $p['name'],
			'price'   => (string) $p['price'],
			'url'     => (string) $p['url'],
			'image'   => (string) $p['image'],
			'on_sale' => (bool) $p['on_sale'],
		];
	}

	/**
	 * Whether the store answered, and its name. Cached for ten minutes; `$refresh` forces a check.
	 *
	 * @param bool $refresh Check now.
	 * @return array{mode:string, host:string, ok:bool, name:string, checked:int}
	 */
	public static function status( bool $refresh = false ): array {
		$mode = self::mode();
		$out  = [
			'mode'    => $mode,
			'host'    => self::host(),
			'ok'      => false,
			'name'    => '',
			'checked' => 0,
		];
		if ( 'local' === $mode ) {
			$out['ok']      = true;
			$out['name']    = (string) get_bloginfo( 'name' );
			$out['checked'] = time();
			return $out;
		}
		if ( 'remote' !== $mode ) {
			return $out;
		}
		$key    = 'crazylinks_store_status_' . md5( self::generation() . self::base_url() );
		$cached = $refresh ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			return array_merge( $out, array_intersect_key( $cached, $out ) );
		}
		$products       = self::request( self::API_PATH, [ 'per_page' => '1' ] );
		$root           = null !== $products ? self::request( self::ROOT_PATH, [ '_fields' => 'name' ] ) : null;
		$out['ok']      = null !== $products;
		$out['name']    = is_array( $root ) && isset( $root['name'] ) ? self::text( $root['name'], 80 ) : '';
		$out['checked'] = time();
		set_transient( $key, $out, $out['ok'] ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Cache TTL in seconds.
	 *
	 * @param string $kind product | latest | search.
	 */
	public static function ttl( string $kind ): int {
		/**
		 * Filters how long fetched store data is served without asking the store again.
		 *
		 * @param int    $ttl  Seconds (default 900).
		 * @param string $kind product, latest or search.
		 */
		return max( 0, (int) apply_filters( 'crazylinks_store_cache_ttl', self::TTL, $kind ) );
	}

	/**
	 * Clears cached answers and the last-good copies (a new generation number orphans every
	 * transient, which then expires on its own; this works with or without an object cache).
	 */
	public static function flush(): void {
		delete_option( self::STALE_OPTION );
		update_option( self::GEN_OPTION, self::generation() + 1, true );
	}

	/**
	 * Cache generation, part of every cache key.
	 */
	private static function generation(): int {
		return (int) get_option( self::GEN_OPTION, 0 );
	}

	// ---------------------------------------------------------------------------------------------
	// Remote.

	/**
	 * Cached remote query with a last-good fallback.
	 *
	 * @param string               $kind   product | latest | search.
	 * @param array<string,string> $query  Query values (keys are fixed by the callers above).
	 * @param bool                 $keep   Keep a last-good copy.
	 * @return array<int, array<string, mixed>>
	 */
	private static function remote_cached( string $kind, array $query, bool $keep = true ): array {
		$key = self::cache_key( $kind, $query );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$rows = null;
		if ( ! get_transient( self::backoff_key() ) ) {
			$rows = self::request( self::API_PATH, $query );
			if ( null === $rows ) {
				// A slow or down store is not asked again for a couple of minutes.
				set_transient( self::backoff_key(), 1, self::BACKOFF );
			}
		}
		if ( null === $rows ) {
			// Serve the last good answer instead of nothing.
			return $keep ? self::stored( $kind, $query ) : [];
		}
		$list = self::normalize_list( $rows );
		set_transient( $key, $list, max( 1, self::ttl( $kind ) ) );
		if ( $keep && $list ) {
			self::remember( $key, $list );
		}
		return $list;
	}

	/**
	 * The last-good copy for a query.
	 *
	 * @param string               $kind  Kind.
	 * @param array<string,string> $query Query.
	 * @return array<int, array<string, mixed>>
	 */
	private static function stored( string $kind, array $query ): array {
		$key = self::cache_key( $kind, $query );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$all = get_option( self::STALE_OPTION, [] );
		return is_array( $all ) && isset( $all[ $key ]['rows'] ) && is_array( $all[ $key ]['rows'] ) ? array_values( $all[ $key ]['rows'] ) : [];
	}

	/**
	 * Stores a last-good copy (bounded, not autoloaded).
	 *
	 * @param string                           $key  Cache key.
	 * @param array<int, array<string, mixed>> $rows Rows.
	 */
	private static function remember( string $key, array $rows ): void {
		$all = get_option( self::STALE_OPTION, [] );
		$all = is_array( $all ) ? $all : [];
		if ( isset( $all[ $key ]['rows'] ) && $all[ $key ]['rows'] === $rows ) {
			return;
		}
		$all[ $key ] = [
			'rows' => $rows,
			'at'   => time(),
		];
		if ( count( $all ) > self::MAX_STALE ) {
			uasort( $all, static fn( $a, $b ) => (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ) );
			$all = array_slice( $all, 0, self::MAX_STALE, true );
		}
		update_option( self::STALE_OPTION, $all, false );
	}

	/**
	 * Cache key: the host is part of it, so changing the store never serves the old store's products.
	 *
	 * @param string               $kind  Kind.
	 * @param array<string,string> $query Query.
	 */
	private static function cache_key( string $kind, array $query ): string {
		ksort( $query );
		return 'crazylinks_store_' . substr( md5( self::generation() . '|' . self::base_url() . '|' . $kind . '|' . http_build_query( $query ) ), 0, 24 );
	}

	/**
	 * Transient that marks a recently failed store.
	 */
	private static function backoff_key(): string {
		return 'crazylinks_store_down_' . substr( md5( self::generation() . self::base_url() ), 0, 16 );
	}

	/**
	 * GET on the store. The URL is the stored base plus a constant path; the query only carries
	 * values. Returns the decoded JSON array, or null on any failure.
	 *
	 * @param string               $path  Constant path (API_PATH or ROOT_PATH).
	 * @param array<string,string> $query Query values.
	 * @return array<mixed>|null
	 */
	private static function request( string $path, array $query ): ?array {
		$base = self::base_url();
		if ( '' === $base || ! in_array( $path, [ self::API_PATH, self::ROOT_PATH ], true ) ) {
			return null;
		}
		$url      = $base . $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		$response = wp_remote_get(
			$url,
			[
				'timeout'             => 5,
				'redirection'         => 0,
				'user-agent'          => 'CrazyLinks/' . ( defined( 'CRAZYLINKS_VERSION' ) ? CRAZYLINKS_VERSION : '1' ),
				'headers'             => [ 'Accept' => 'application/json' ],
				'reject_unsafe_urls'  => ! self::allow_http(),
				'limit_response_size' => 2 * MB_IN_BYTES,
			]
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true, 32 );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Normalizes a Store API list.
	 *
	 * @param array<mixed> $rows Decoded response.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_list( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$product = is_array( $row ) ? self::normalize( $row ) : null;
			if ( null !== $product ) {
				$out[] = $product;
			}
		}
		return $out;
	}

	/**
	 * One Store API product into the plugin's shape; null when it lacks an id, a name or a valid
	 * product page on the store's own host.
	 *
	 * @param array<mixed> $p Store API product.
	 * @return array<string, mixed>|null
	 */
	public static function normalize( array $p ): ?array {
		$id   = isset( $p['id'] ) && is_numeric( $p['id'] ) ? (int) $p['id'] : 0;
		$name = self::text( $p['name'] ?? '', 200 );
		$url  = self::store_url_for( (string) ( $p['permalink'] ?? '' ) );
		if ( $id <= 0 || '' === $name || '' === $url ) {
			return null;
		}
		$image  = '';
		$images = isset( $p['images'] ) && is_array( $p['images'] ) ? $p['images'] : [];
		if ( isset( $images[0]['src'] ) && is_string( $images[0]['src'] ) ) {
			$image = self::https_url( $images[0]['src'] );
		}
		$prices = isset( $p['prices'] ) && is_array( $p['prices'] ) ? $p['prices'] : [];
		$minor  = isset( $prices['currency_minor_unit'] ) && is_numeric( $prices['currency_minor_unit'] ) ? max( 0, min( 4, (int) $prices['currency_minor_unit'] ) ) : 2;
		$fmt    = static function ( $value ) use ( $prices, $minor ): array {
			if ( ( ! is_string( $value ) && ! is_int( $value ) ) || ! preg_match( '/^\d{1,12}$/', (string) $value ) ) {
				return [ '', '' ];
			}
			$number = number_format( (int) $value / ( 10 ** $minor ), $minor, self::symbol( $prices['currency_decimal_separator'] ?? '.', '.' ), self::symbol( $prices['currency_thousand_separator'] ?? ',', ',' ) );
			return [ $number, self::text( $prices['currency_prefix'] ?? '', 8, false ) . $number . self::text( $prices['currency_suffix'] ?? '', 8, false ) ];
		};
		$range  = isset( $prices['price_range'] ) && is_array( $prices['price_range'] ) ? $prices['price_range'] : [];
		$from   = isset( $range['min_amount'], $range['max_amount'] ) && (string) $range['min_amount'] !== (string) $range['max_amount'] && preg_match( '/^\d{1,12}$/', (string) $range['min_amount'] );
		$price  = $fmt( $from ? $range['min_amount'] : ( $prices['price'] ?? null ) );
		$on     = ! empty( $p['on_sale'] ) && ! $from;
		$reg    = $on ? $fmt( $prices['regular_price'] ?? null ) : [ '', '' ];
		$on     = $on && '' !== $reg[1] && $reg[1] !== $price[1];
		return [
			'id'            => $id,
			'name'          => $name,
			'url'           => $url,
			'image'         => $image,
			'price'         => $price[1],
			'amount'        => $price[0],
			'currency'      => strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $prices['currency_code'] ?? '' ) ) ?? '' ),
			'regular_price' => $on ? $reg[1] : '',
			'on_sale'       => $on,
			'price_from'    => (bool) $from,
		];
	}

	/**
	 * Plain text from store data: no tags, entities decoded (templates escape on output), trimmed.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum characters.
	 * @param bool  $trim  Trim the ends (currency affixes keep their space).
	 */
	private static function text( $value, int $max, bool $trim = true ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$text = preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ) ) ?? '';
		$text = trim( $text );
		if ( ! $trim && '' !== $text ) {
			// Currency affixes keep one space on the side the store gave it (wp_strip_all_tags trims).
			$text = ( preg_match( '/^\s/u', $value ) ? ' ' : '' ) . $text . ( preg_match( '/\s$/u', $value ) ? ' ' : '' );
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * A one-character separator, with a fallback.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Fallback.
	 */
	private static function symbol( $value, string $fallback ): string {
		$value = is_string( $value ) ? html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ) : '';
		return '' !== $value && mb_strlen( $value ) <= 2 ? $value : $fallback;
	}

	/**
	 * A product page URL, only if it is on the configured store's host.
	 *
	 * @param string $url Raw URL.
	 */
	private static function store_url_for( string $url ): string {
		$clean = esc_url_raw( $url, self::allow_http() ? [ 'https', 'http' ] : [ 'https' ] );
		return '' !== $clean && self::on_store_host( $clean ) && self::is_safe( $clean ) ? $clean : '';
	}

	/**
	 * An https image URL, or ''.
	 *
	 * @param string $url Raw URL.
	 */
	private static function https_url( string $url ): string {
		$clean = esc_url_raw( $url, [ 'https' ] );
		return '' !== $clean && self::is_safe( $clean ) ? $clean : '';
	}

	/**
	 * Runs wp_http_validate_url() (no credentials, a public host, a normal port), remembered per host for
	 * the request so a list of twelve products costs one DNS lookup per host.
	 *
	 * @param string $url URL.
	 */
	private static function is_safe( string $url ): bool {
		static $hosts = [];
		$parts        = (array) wp_parse_url( $url );
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$key = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		if ( ! isset( $hosts[ $key ] ) ) {
			$hosts[ $key ] = false !== wp_http_validate_url( $key . '/' );
		}
		return $hosts[ $key ];
	}

	/**
	 * A category slug, or ''.
	 *
	 * @param string $slug Raw slug.
	 */
	public static function clean_slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		return preg_match( '/^[a-z0-9_-]{1,60}$/', $slug ) ? $slug : '';
	}

	// ---------------------------------------------------------------------------------------------
	// Local WooCommerce.

	/**
	 * A published, visible product on this site.
	 *
	 * @param int $id Product id.
	 * @return array<string, mixed>|null
	 */
	private static function local_product( int $id ): ?array {
		$product = wc_get_product( $id );
		return $product instanceof \WC_Product && 'publish' === $product->get_status() && 'hidden' !== $product->get_catalog_visibility() ? self::from_wc( $product ) : null;
	}

	/**
	 * Local products from wc_get_products().
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, array<string, mixed>>
	 */
	private static function local_query( array $args ): array {
		$args['visibility'] = 'catalog';
		$out                = [];
		foreach ( (array) wc_get_products( $args ) as $product ) {
			if ( $product instanceof \WC_Product ) {
				$out[] = self::from_wc( $product );
			}
		}
		return $out;
	}

	/**
	 * Normalizes a WooCommerce product object.
	 *
	 * @param \WC_Product $p Product.
	 * @return array<string, mixed>
	 */
	private static function from_wc( \WC_Product $p ): array {
		$image = (int) $p->get_image_id() > 0 ? (string) wp_get_attachment_image_url( (int) $p->get_image_id(), 'large' ) : '';
		$plain = static fn( string $html ): string => trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
		$on    = $p->is_on_sale() && '' !== (string) $p->get_regular_price();
		return [
			'id'            => (int) $p->get_id(),
			'name'          => self::text( $p->get_name(), 200 ),
			'url'           => esc_url_raw( (string) get_permalink( $p->get_id() ) ),
			'image'         => esc_url_raw( $image ),
			'price'         => $plain( wc_price( (float) $p->get_price() ) ),
			'amount'        => (string) $p->get_price(),
			'currency'      => (string) get_woocommerce_currency(),
			'regular_price' => $on ? $plain( wc_price( (float) $p->get_regular_price() ) ) : '',
			'on_sale'       => $on,
			'price_from'    => $p->is_type( 'variable' ),
		];
	}
}
