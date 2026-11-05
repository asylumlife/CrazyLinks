<?php
/**
 * Request fingerprinting without cookies.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Derives the anonymous, daily-rotating visitor hash and coarse device facts from a request.
 */
final class Visitor {

	public const SALT_OPTION_PREFIX = 'crazylinks_salt_';

	/**
	 * Constructor.
	 *
	 * @param string $ip       Client IP (never stored).
	 * @param string $ua       User agent.
	 * @param string $referrer Referrer URL.
	 */
	public function __construct( public string $ip, public string $ua, public string $referrer ) {}

	/**
	 * From the current request. Only REMOTE_ADDR is trusted unless the filter says otherwise.
	 */
	public static function from_request(): self {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the client IP used for the visitor hash (e.g. to trust a proxy header).
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip  = (string) apply_filters( 'crazylinks_visitor_ip', $ip );
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		return new self( $ip, substr( $ua, 0, 512 ), $ref );
	}

	/**
	 * Uniques hash: sha256(ip + ua + daily salt), truncated to 32 hex chars. Used only to count
	 * unique visitors; never for rate limits or de-duplication (see abuse_key()).
	 */
	public function hash(): string {
		return substr( hash( 'sha256', $this->ip . '|' . $this->ua . '|' . self::daily_salt() ), 0, 32 );
	}

	/**
	 * Key for de-duplication and rate limits: HMAC of the IP alone under the daily salt, so
	 * rotating the user agent cannot mint new identities (SPEC §7.3).
	 */
	public function abuse_key(): string {
		return hash_hmac( 'sha256', $this->ip, self::daily_salt() );
	}

	/**
	 * Salt that rotates every UTC day. The previous day's random value is deleted, so hashes
	 * cannot be linked across days.
	 */
	public static function daily_salt(): string {
		return self::salt_for_day( gmdate( 'Y-m-d' ) );
	}

	/**
	 * Effective salt for a UTC day: HMAC of the stored random value with wp_salt(), so a database
	 * dump alone is not enough to recompute visitor hashes.
	 *
	 * The random value lives in a non-autoloaded option named after the day. It is created with
	 * INSERT IGNORE and then re-read, so two requests racing at midnight end up with the same value
	 * (core's add_option() uses ON DUPLICATE KEY UPDATE and would let the second writer win).
	 *
	 * @param string $day Day as Y-m-d.
	 */
	public static function salt_for_day( string $day ): string {
		$name = self::SALT_OPTION_PREFIX . str_replace( '-', '', $day );
		$raw  = get_option( $name );
		if ( ! is_string( $raw ) || '' === $raw ) {
			$raw = self::create_salt( $name );
		}
		return hash_hmac( 'sha256', $day . '|' . $raw, wp_salt() );
	}

	/**
	 * Creates the day's random value if no other request has, then returns whichever value won.
	 *
	 * @param string $name Option name.
	 */
	private static function create_salt( string $name ): string {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Race-safe create; caches are reset below.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, bin2hex( random_bytes( 32 ) ) ) );
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_set( $name, $raw, 'options' );

		// Discard every other day's value (and the pre-1.0 autoloaded `crazylinks_salt`).
		$old = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s", $wpdb->esc_like( self::SALT_OPTION_PREFIX ) . '%', $name ) );
		// phpcs:enable
		$old[] = 'crazylinks_salt';
		foreach ( $old as $option ) {
			delete_option( (string) $option );
		}
		return $raw;
	}

	/**
	 * Whether the UA looks like a bot, crawler, link previewer or scripted client.
	 */
	public function is_bot(): bool {
		if ( '' === trim( $this->ua ) ) {
			return true;
		}
		$pattern = '/bot\b|bot\/|crawl|spider|slurp|archiver|facebookexternalhit|facebookcatalog|embedly|preview|whatsapp\/|telegrambot|discordbot|slackbot|skypeuripreview|bingpreview|headless|lighthouse|pagespeed|pingdom|uptime|monitor|curl\/|wget\/|python-requests|python-urllib|go-http-client|okhttp\/|java\/|libwww|httpclient|axios\/|node-fetch|scrapy|feedfetcher|mediapartners/i';
		/**
		 * Filters the bot user-agent regex.
		 *
		 * @param string $pattern PCRE pattern.
		 */
		$pattern = (string) apply_filters( 'crazylinks_bot_pattern', $pattern );
		return (bool) preg_match( $pattern, $this->ua );
	}

	/**
	 * In-app browser token, or null.
	 */
	public function in_app(): ?string {
		$map = [
			'instagram' => '/Instagram/i',
			'facebook'  => '/FBAN|FBAV|FB_IAB/',
			'tiktok'    => '/BytedanceWebview|musical_ly|TikTok/i',
			'snapchat'  => '/Snapchat/i',
			'linkedin'  => '/LinkedInApp/i',
			'twitter'   => '/Twitter/i',
			'pinterest' => '/Pinterest/i',
		];
		foreach ( $map as $name => $re ) {
			if ( preg_match( $re, $this->ua ) ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * Device class: mobile, tablet or desktop.
	 */
	public function device(): string {
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $this->ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Mobi|iPhone|iPod|Android|Windows Phone/i', $this->ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Coarse OS name.
	 */
	public function os(): ?string {
		$map = [
			'ios'     => '/iPhone|iPad|iPod/',
			'android' => '/Android/',
			'windows' => '/Windows/',
			'macos'   => '/Mac OS X|Macintosh/',
			'linux'   => '/Linux/',
		];
		foreach ( $map as $name => $re ) {
			if ( preg_match( $re, $this->ua ) ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * Referrer host, ignoring same-site referrers.
	 */
	public function referrer_host(): ?string {
		$host = strtolower( (string) wp_parse_url( $this->referrer, PHP_URL_HOST ) );
		$own  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || $host === $own ) {
			return null;
		}
		return substr( $host, 0, 255 );
	}
}
