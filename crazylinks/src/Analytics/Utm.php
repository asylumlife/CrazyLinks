<?php
/**
 * UTM auto-tagging.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

use CrazyLinks\Schema\UrlPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Appends a page's UTM parameters to outbound web links, never overriding existing ones.
 */
final class Utm {

	/**
	 * Tags a URL.
	 *
	 * @param string               $url      Destination.
	 * @param array<string, mixed> $settings Page settings.
	 * @param string               $slug     Page slug (for the {slug} placeholder).
	 */
	public static function apply( string $url, array $settings, string $slug ): string {
		$utm = (array) ( $settings['utm'] ?? [] );
		if ( empty( $utm['enabled'] ) || ! UrlPolicy::is_web( $url ) ) {
			return $url;
		}
		$existing = [];
		$query    = (string) wp_parse_url( $url, PHP_URL_QUERY );
		wp_parse_str( $query, $existing );
		$add = [];
		foreach ( [ 'source', 'medium', 'campaign' ] as $key ) {
			$value = str_replace( '{slug}', $slug, (string) ( $utm[ $key ] ?? '' ) );
			if ( '' !== $value && ! isset( $existing[ 'utm_' . $key ] ) ) {
				$add[ 'utm_' . $key ] = rawurlencode( $value );
			}
		}
		return $add ? add_query_arg( $add, $url ) : $url;
	}
}
