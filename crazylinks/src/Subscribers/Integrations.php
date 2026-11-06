<?php
/**
 * Settings → Integrations: adapter choice, Mailchimp key, default audience, webhook secret.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers;

use CrazyLinks\Settings;
use CrazyLinks\Subscribers\Adapters\Mailchimp;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the integration settings. The API key lives in `crazylinks_settings`
 * (`mailchimp_api_key`); it is never sent to the browser except as a mask showing its last four
 * characters, and no REST route, page document or editor boot data includes it.
 */
final class Integrations {

	public const SECRET_OPTION   = 'crazylinks_mailchimp_webhook_secret';
	public const TEST_TRANSIENT  = 'crazylinks_mc_test';
	public const AUDIENCES_TTL   = 10 * MINUTE_IN_SECONDS;
	private const MASK_CHAR      = '•';
	private const AUDIENCES_PREF = 'crazylinks_mc_audiences_';

	/**
	 * Chosen adapter id ('' = local list only).
	 */
	public static function adapter_id(): string {
		return (string) Settings::get( 'subscriber_adapter' );
	}

	/**
	 * Saved Mailchimp API key ('' when none).
	 */
	public static function api_key(): string {
		return trim( (string) Settings::get( 'mailchimp_api_key' ) );
	}

	/**
	 * Site-wide default audience id ('' when none).
	 */
	public static function default_audience(): string {
		$id = (string) Settings::get( 'mailchimp_audience' );
		return Mailchimp::valid_audience( $id ) ? $id : '';
	}

	/**
	 * Whether Mailchimp is the chosen service and has a well-formed key.
	 */
	public static function mailchimp_connected(): bool {
		return Mailchimp::ID === self::adapter_id() && Mailchimp::valid_key( self::api_key() );
	}

	/**
	 * What the settings field shows for a key: bullets and the last four characters.
	 *
	 * @param string $key Key.
	 */
	public static function mask( string $key ): string {
		return '' === $key ? '' : str_repeat( self::MASK_CHAR, 12 ) . substr( $key, -4 );
	}

	/**
	 * Whether a submitted value is the mask (or any bulleted value), meaning "keep the saved key".
	 *
	 * @param string $value Submitted value.
	 */
	public static function is_mask( string $value ): bool {
		return str_starts_with( $value, self::MASK_CHAR ) || str_starts_with( $value, '*' );
	}

	/**
	 * The webhook secret, generated once and stored (not autoloaded).
	 */
	public static function webhook_secret(): string {
		$secret = (string) get_option( self::SECRET_OPTION, '' );
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $secret ) ) {
			$secret = wp_generate_password( 32, false, false );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	/**
	 * The address to paste into Mailchimp (Audience → Settings → Webhooks).
	 */
	public static function webhook_url(): string {
		return add_query_arg( 'key', self::webhook_secret(), rest_url( 'crazylinks/v1/integrations/mailchimp/webhook' ) );
	}

	/**
	 * Audiences for the saved key, cached for 10 minutes.
	 *
	 * @param bool $refresh Ask Mailchimp now.
	 * @return array<int, array{id:string, name:string, member_count:int}>|\WP_Error
	 */
	public static function audiences( bool $refresh = false ) {
		$adapter = Adapters::get( Mailchimp::ID );
		if ( ! $adapter || ! $adapter->is_configured() ) {
			return new \WP_Error( 'crazylinks_mc_no_key', __( 'No Mailchimp API key is saved. Add it under CrazyLinks → Settings → Integrations.', 'crazylinks' ), [ 'status' => 409 ] );
		}
		$key = self::audiences_key();
		$hit = $refresh ? false : get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$list = $adapter->audiences();
		if ( ! is_wp_error( $list ) ) {
			set_transient( $key, $list, self::AUDIENCES_TTL );
		}
		return $list;
	}

	/**
	 * Audiences from the cache only (never a request), [] when nothing is cached.
	 *
	 * @return array<int, array{id:string, name:string, member_count:int}>
	 */
	public static function cached_audiences(): array {
		if ( '' === self::api_key() ) {
			return [];
		}
		$hit = get_transient( self::audiences_key() );
		return is_array( $hit ) ? $hit : [];
	}

	/**
	 * Cache key (per key, so a new key never shows the old account's audiences).
	 *
	 * @param string|null $api_key Key (default: saved key).
	 */
	private static function audiences_key( ?string $api_key = null ): string {
		return self::AUDIENCES_PREF . substr( md5( null === $api_key ? self::api_key() : $api_key ), 0, 16 );
	}

	/**
	 * Runs and remembers a connection test.
	 *
	 * @param string|null $api_key Key to test (default: the saved key).
	 * @return true|\WP_Error
	 */
	public static function test( ?string $api_key = null ) {
		$adapter = new Mailchimp( $api_key );
		$result  = $adapter->is_configured() ? $adapter->test() : new \WP_Error( 'crazylinks_mc_no_key', __( 'No Mailchimp API key is saved. Add it under CrazyLinks → Settings → Integrations.', 'crazylinks' ) );
		if ( null === $api_key || self::api_key() === $api_key ) {
			set_transient(
				self::TEST_TRANSIENT,
				[
					'ok'      => true === $result,
					'message' => true === $result ? __( 'Connected to Mailchimp.', 'crazylinks' ) : $result->get_error_message(),
					'at'      => time(),
				],
				DAY_IN_SECONDS
			);
		}
		return $result;
	}

	/**
	 * Last test result for the saved key, or null.
	 *
	 * @return array{ok:bool, message:string, at:int}|null
	 */
	public static function last_test(): ?array {
		$hit = get_transient( self::TEST_TRANSIENT );
		return is_array( $hit ) && isset( $hit['ok'], $hit['message'], $hit['at'] ) ? [
			'ok'      => (bool) $hit['ok'],
			'message' => (string) $hit['message'],
			'at'      => (int) $hit['at'],
		] : null;
	}

	/**
	 * Sanitizes the Integrations section. Idempotent: WordPress runs the option sanitizer twice on
	 * the first-ever save, and the second pass receives our own output (the raw key, not the mask).
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param array<string, mixed> $out   Sanitized settings so far (starts as the stored values).
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, array $out ): array {
		$adapter                   = (string) ( $input['subscriber_adapter'] ?? '' );
		$out['subscriber_adapter'] = in_array( $adapter, array_merge( [ '' ], array_keys( Adapters::all() ) ), true ) ? $adapter : '';

		$stored = trim( (string) ( $out['mailchimp_api_key'] ?? '' ) );
		if ( array_key_exists( 'mailchimp_api_key', $input ) ) {
			$raw = trim( (string) $input['mailchimp_api_key'] );
			if ( '' === $raw ) {
				$out['mailchimp_api_key'] = '';
			} elseif ( $raw === $stored || self::is_mask( $raw ) ) {
				$out['mailchimp_api_key'] = $stored;
			} elseif ( Mailchimp::valid_key( $raw ) ) {
				$out['mailchimp_api_key'] = $raw;
			} else {
				add_settings_error( Settings::OPTION, 'crazylinks_mailchimp_api_key', __( 'That does not look like a Mailchimp API key (it should end with a dash and the data center, for example -us6). The previous key was kept.', 'crazylinks' ) );
				$out['mailchimp_api_key'] = $stored;
			}
		}
		if ( array_key_exists( 'mailchimp_audience', $input ) ) {
			$aud = strtolower( trim( (string) $input['mailchimp_audience'] ) );
			if ( '' === $aud || Mailchimp::valid_audience( $aud ) ) {
				$out['mailchimp_audience'] = $aud;
			} else {
				add_settings_error( Settings::OPTION, 'crazylinks_mailchimp_audience', __( 'The audience id should be letters and numbers only (Mailchimp: Audience → Settings → Audience name and defaults → Audience ID). The previous value was kept.', 'crazylinks' ) );
			}
		}
		if ( $out['mailchimp_api_key'] !== $stored ) {
			delete_transient( self::audiences_key( $stored ) );
			delete_transient( self::TEST_TRANSIENT );
		}
		return $out;
	}
}
