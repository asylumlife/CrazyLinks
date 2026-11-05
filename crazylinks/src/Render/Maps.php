<?php
/**
 * Map links.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Builds plain outbound map links from an address. Nothing is embedded or fetched.
 */
final class Maps {

	/**
	 * Google Maps search URL.
	 *
	 * @param string $address Address.
	 */
	public static function google( string $address ): string {
		return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address );
	}

	/**
	 * Apple Maps search URL.
	 *
	 * @param string $address Address.
	 */
	public static function apple( string $address ): string {
		return 'https://maps.apple.com/?q=' . rawurlencode( $address );
	}
}
