<?php
/**
 * Payment button presets.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Render;

use CrazyLinks\Schema\UrlPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Presets for the `payment` block. A preset decides the icon, the default label, which hosts the URL may point at
 * and how a bare handle becomes a URL. No money passes through the plugin: the button opens the service itself.
 */
final class Payments {

	/**
	 * Preset definitions.
	 *
	 * @return array<string, array{label:string, icon:string, hosts:string[], template:string}>
	 */
	public static function presets(): array {
		return [
			'buymeacoffee' => [
				'label'    => __( 'Buy me a coffee', 'crazylinks' ),
				'icon'     => 'buymeacoffee',
				'hosts'    => [ 'buymeacoffee.com' ],
				'template' => 'https://www.buymeacoffee.com/%s',
			],
			'kofi'         => [
				'label'    => __( 'Support me on Ko-fi', 'crazylinks' ),
				'icon'     => 'kofi',
				'hosts'    => [ 'ko-fi.com' ],
				'template' => 'https://ko-fi.com/%s',
			],
			'paypal'       => [
				'label'    => __( 'Pay with PayPal', 'crazylinks' ),
				'icon'     => 'paypal',
				'hosts'    => [ 'paypal.me', 'paypal.com' ],
				'template' => 'https://paypal.me/%s',
			],
			'venmo'        => [
				'label'    => __( 'Pay with Venmo', 'crazylinks' ),
				'icon'     => 'venmo',
				'hosts'    => [ 'venmo.com' ],
				'template' => 'https://venmo.com/u/%s',
			],
			'cashapp'      => [
				'label'    => __( 'Pay with Cash App', 'crazylinks' ),
				'icon'     => 'cashapp',
				'hosts'    => [ 'cash.app' ],
				'template' => 'https://cash.app/$%s',
			],
			'stripe_link'  => [
				'label'    => __( 'Pay securely', 'crazylinks' ),
				'icon'     => 'stripe',
				'hosts'    => [ 'buy.stripe.com', 'donate.stripe.com', 'checkout.stripe.com' ],
				'template' => '',
			],
			'patreon'      => [
				'label'    => __( 'Become a patron', 'crazylinks' ),
				'icon'     => 'patreon',
				'hosts'    => [ 'patreon.com' ],
				'template' => 'https://www.patreon.com/%s',
			],
			'gofundme'     => [
				'label'    => __( 'Donate on GoFundMe', 'crazylinks' ),
				'icon'     => 'gofundme',
				'hosts'    => [ 'gofundme.com' ],
				'template' => 'https://www.gofundme.com/f/%s',
			],
			'custom'       => [
				'label'    => __( 'Pay', 'crazylinks' ),
				'icon'     => 'website',
				'hosts'    => [],
				'template' => '',
			],
		];
	}

	/**
	 * One preset (custom when unknown).
	 *
	 * @param string $service Service slug.
	 * @return array{label:string, icon:string, hosts:string[], template:string}
	 */
	public static function get( string $service ): array {
		$all = self::presets();
		return $all[ $service ] ?? $all['custom'];
	}

	/**
	 * Strips a leading @ or $ and checks the handle characters. Returns '' when invalid.
	 *
	 * @param string $handle Raw handle.
	 */
	public static function clean_handle( string $handle ): string {
		$handle = ltrim( trim( $handle ), '@$' );
		return preg_match( '/^[A-Za-z0-9._-]{1,80}$/', $handle ) ? $handle : '';
	}

	/**
	 * Whether a URL is https and on one of the preset's hosts (any https/http URL for custom).
	 *
	 * @param string $service Service slug.
	 * @param string $url     URL.
	 */
	public static function url_matches( string $service, string $url ): bool {
		if ( ! UrlPolicy::is_allowed( $url, UrlPolicy::WEB_SCHEMES ) ) {
			return false;
		}
		$hosts = self::get( $service )['hosts'];
		if ( ! $hosts ) {
			return true;
		}
		if ( ! str_starts_with( strtolower( $url ), 'https://' ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		foreach ( $hosts as $allowed ) {
			if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The final URL for a payment block's props: the explicit URL, or one built from the handle. '' when neither works.
	 *
	 * @param array<string, mixed> $props Block props.
	 */
	public static function resolve( array $props ): string {
		$service = (string) ( $props['service'] ?? 'custom' );
		$url     = trim( (string) ( $props['url'] ?? '' ) );
		if ( '' !== $url ) {
			return $url;
		}
		$template = self::get( $service )['template'];
		$handle   = self::clean_handle( (string) ( $props['handle'] ?? '' ) );
		if ( '' === $template || '' === $handle ) {
			return '';
		}
		return sprintf( $template, rawurlencode( $handle ) );
	}
}
