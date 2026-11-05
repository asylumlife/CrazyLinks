<?php
/**
 * Link URL policy.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Allows http, https, mailto, tel and sms URLs only (SPEC §4), and rejects deceptive forms (SPEC §12).
 */
final class UrlPolicy {

	public const LINK_SCHEMES = [ 'http', 'https', 'mailto', 'tel', 'sms' ];
	public const WEB_SCHEMES  = [ 'http', 'https' ];

	/**
	 * Returns the cleaned URL, or '' if it violates the policy.
	 *
	 * @param string   $url     Raw URL.
	 * @param string[] $schemes Allowed schemes.
	 */
	public static function clean( string $url, array $schemes = self::LINK_SCHEMES ): string {
		$url = trim( $url, " \t\n\r" );
		// No control, format (bidi overrides, zero-width) or space characters anywhere (SPEC §12).
		// Invalid UTF-8 makes preg_match() fail, which is rejected too.
		if ( '' === $url || 1 === preg_match( '/[\x00-\x1F\x7F\s]/', $url ) || 1 !== preg_match( '/^[^\p{Cc}\p{Cf}\p{Z}]+$/u', $url ) ) {
			return '';
		}
		if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $m ) ) {
			return '';
		}
		$scheme = strtolower( $m[1] );
		if ( ! in_array( $scheme, $schemes, true ) ) {
			return '';
		}
		$host = '';
		if ( in_array( $scheme, self::WEB_SCHEMES, true ) ) {
			$host = self::web_host( $url, $scheme );
			if ( '' === $host ) {
				return '';
			}
		} elseif ( strlen( $url ) <= strlen( $scheme ) + 1 ) {
			return '';
		}
		$clean = esc_url_raw( $url, $schemes );
		if ( ! is_string( $clean ) || '' === $clean ) {
			return '';
		}
		// Re-validate what will actually be stored: cleaning must not change the scheme or host.
		if ( '' !== $host && self::web_host( $clean, $scheme ) !== $host ) {
			return '';
		}
		if ( '' === $host && ! str_starts_with( strtolower( $clean ), $scheme . ':' ) ) {
			return '';
		}
		return $clean;
	}

	/**
	 * Lower-cased host of an http(s) URL, or '' when the URL is unusable or deceptive:
	 * missing host, userinfo (`https://paypal.com@evil.com/`), backslashes (which browsers read as
	 * path separators, so `https://evil.com\@good.com` goes to evil.com), or a private host when
	 * the `crazylinks_url_block_private_hosts` filter asks for that.
	 *
	 * @param string $url    URL.
	 * @param string $scheme Expected scheme.
	 */
	private static function web_host( string $url, string $scheme ): string {
		if ( ! str_starts_with( strtolower( $url ), $scheme . '://' ) || str_contains( $url, '\\' ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$authority = (string) substr( $url, strlen( $scheme ) + 3 );
		$authority = (string) preg_replace( '#[/?\#].*$#s', '', $authority );
		if ( str_contains( $authority, '@' ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		/**
		 * Filters whether links to private or local hosts (127.0.0.1, 10.x, 192.168.x, localhost,
		 * *.local) are rejected. Off by default: owners may legitimately link to an intranet.
		 *
		 * @param bool   $block Default false.
		 * @param string $host  Host being checked.
		 */
		if ( apply_filters( 'crazylinks_url_block_private_hosts', false, $host ) && self::is_private_host( $host ) ) {
			return '';
		}
		return $host;
	}

	/**
	 * Whether a host is loopback, private, link-local, reserved or a local-only name.
	 *
	 * @param string $host Lower-cased host (IPv6 may be bracketed).
	 */
	public static function is_private_host( string $host ): bool {
		$host = trim( $host, '[]' );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return true;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false;
	}

	/**
	 * Whether a URL is allowed.
	 *
	 * @param string   $url     URL.
	 * @param string[] $schemes Allowed schemes.
	 */
	public static function is_allowed( string $url, array $schemes = self::LINK_SCHEMES ): bool {
		return '' !== self::clean( $url, $schemes );
	}

	/**
	 * Whether the URL uses http(s) (and so can go through the /go/ redirect).
	 *
	 * @param string $url URL.
	 */
	public static function is_web( string $url ): bool {
		return (bool) preg_match( '#^https?://#i', $url );
	}
}
