<?php
/**
 * Global settings access.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the single `crazylinks_settings` option.
 */
final class Settings {

	public const OPTION = 'crazylinks_settings';

	/**
	 * URL bases that would collide with core routes.
	 */
	public const RESERVED_BASES = [ 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'comments', 'page', 'author', 'search', 'category', 'tag', 'embed', 'trackback', 'attachment', 'type', 'date', 'xmlrpc', 'login', 'admin' ];

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'base'                      => 'l',
			'analytics'                 => true,
			'delete_on_uninstall'       => false,
			'render_mode'               => 'standalone',
			'retention_days'            => 90,
			'double_opt_in'             => true,
			'subscriber_retention_days' => 90,
			'clicks_per_minute'         => 60,
			'fetch_oembed'              => true,
			// AI editing (SPEC §10). Off until the owner opts in.
			'ai_enabled'                => false,
			'ai_consent'                => false,
			'ai_model_edit'             => '',
			'ai_model_suggest'          => '',
			'ai_user_hourly'            => 30,
			'ai_site_daily'             => 300,
			'ai_monthly_tokens'         => 2000000,
			'ai_log'                    => true,
			'ai_log_retention_days'     => 90,
			'ai_timeout'                => 60,
			'ai_abilities'              => [],
			'ai_mcp_confirm'            => false,
		];
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * The URL base for link pages (default `l`), after the `crazylinks_base_path` filter.
	 */
	public static function base(): string {
		$base = (string) self::get( 'base' );
		/**
		 * Filters the URL base for link pages (`/{base}/{slug}`).
		 *
		 * @param string $base Base path without slashes.
		 */
		$base     = (string) apply_filters( 'crazylinks_base_path', $base );
		$filtered = self::sanitize_base( $base );
		return '' === $filtered ? 'l' : $filtered;
	}

	/**
	 * Days raw analytics events are kept (0 = forever). A site that saved the older
	 * `retention_months` setting and never touched the new one keeps its months.
	 */
	public static function event_retention_days(): int {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		if ( ! isset( $stored['retention_days'] ) && isset( $stored['retention_months'] ) ) {
			return max( 1, (int) $stored['retention_months'] ) * 30;
		}
		return max( 0, (int) self::get( 'retention_days' ) );
	}

	/**
	 * Whether sign-ups need email confirmation (default on).
	 */
	public static function double_opt_in(): bool {
		/**
		 * Filters whether new subscribers must confirm by email before they count as confirmed.
		 *
		 * @param bool $enabled Setting value (default true).
		 */
		return (bool) apply_filters( 'crazylinks_double_opt_in', (bool) self::get( 'double_opt_in' ) );
	}

	/**
	 * Whether first-party analytics are on.
	 */
	public static function analytics_enabled(): bool {
		/**
		 * Filters whether CrazyLinks records views and clicks.
		 *
		 * @param bool $enabled Setting value.
		 */
		return (bool) apply_filters( 'crazylinks_analytics_enabled', (bool) self::get( 'analytics' ) );
	}

	/**
	 * Normalizes a URL base. Returns '' when unusable.
	 *
	 * @param string $base Raw base.
	 */
	public static function sanitize_base( string $base ): string {
		$base = strtolower( trim( $base, " /\t\n\r\0\x0B" ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $base ) || in_array( $base, self::RESERVED_BASES, true ) ) {
			return '';
		}
		return $base;
	}

	/**
	 * AI settings (only when the form carried the AI section, so partial updates leave them alone).
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param array<string, mixed> $out   Sanitized settings so far.
	 * @return array<string, mixed>
	 */
	private static function sanitize_ai( array $input, array $out ): array {
		// One opt-in: the enable checkbox carries the consent statement (wordpress.org guideline 7), so
		// consent is recorded whenever AI is turned on. A separate consent box let people tick "on" and
		// silently lose it on save (AsylumLife.com, 2026-11-05).
		$out['ai_enabled'] = ! empty( $input['ai_enabled'] );
		$out['ai_consent'] = $out['ai_enabled'];
		foreach ( [ 'ai_model_edit', 'ai_model_suggest' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$models      = array_filter( array_map( 'trim', explode( ',', (string) $input[ $key ] ) ) );
				$models      = array_filter( $models, static fn( $m ) => (bool) preg_match( '/^[A-Za-z0-9._:\/@-]{1,100}$/', $m ) );
				$out[ $key ] = implode( ',', array_slice( array_values( $models ), 0, 5 ) );
			}
		}
		$ints = [
			'ai_user_hourly'        => 1000,
			'ai_site_daily'         => 100000,
			'ai_monthly_tokens'     => 1000000000,
			'ai_log_retention_days' => 3650,
			'ai_timeout'            => 600,
		];
		foreach ( $ints as $key => $max ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = min( $max, absint( $input[ $key ] ) );
			}
		}
		$out['ai_timeout']     = max( 10, (int) $out['ai_timeout'] );
		$out['ai_log']         = ! empty( $input['ai_log'] );
		$out['ai_mcp_confirm'] = ! empty( $input['ai_mcp_confirm'] );
		$out['ai_abilities']   = array_values( array_intersect( array_map( 'strval', (array) ( $input['ai_abilities'] ?? [] ) ), Ai\Abilities::NAMES ) );
		return $out;
	}

	/**
	 * Sanitizes the whole option (register_setting callback).
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$old   = self::all();
		$out   = $old;

		if ( isset( $input['base'] ) ) {
			$base = self::sanitize_base( (string) $input['base'] );
			if ( '' === $base ) {
				add_settings_error( self::OPTION, 'crazylinks_base', __( 'The URL base must be 1-32 lowercase letters, numbers or hyphens and must not be a reserved WordPress path. The previous value was kept.', 'crazylinks' ) );
			} else {
				$out['base'] = $base;
			}
		}
		$out['analytics']           = ! empty( $input['analytics'] );
		$out['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );
		$out['fetch_oembed']        = ! empty( $input['fetch_oembed'] );
		$mode                       = isset( $input['render_mode'] ) ? (string) $input['render_mode'] : $old['render_mode'];
		$out['render_mode']         = in_array( $mode, [ 'standalone', 'theme' ], true ) ? $mode : 'standalone';
		if ( isset( $input['retention_days'] ) ) {
			$out['retention_days'] = min( 3650, absint( $input['retention_days'] ) );
		}
		$out['double_opt_in'] = ! empty( $input['double_opt_in'] );
		if ( isset( $input['subscriber_retention_days'] ) ) {
			$out['subscriber_retention_days'] = min( 3650, absint( $input['subscriber_retention_days'] ) );
		}
		unset( $out['retention_months'] );
		// The form marks the AI section with a hidden field. WordPress runs this sanitizer twice when the
		// option is written for the first time (update_option, then add_option), and the second pass
		// receives our own output, which has no marker but does carry `ai_consent` (never posted by the
		// form). Treat either as "the AI section is present" so a first save cannot drop AI settings
		// (AsylumLife.com, 2026-11-05).
		if ( isset( $input['ai_section'] ) || array_key_exists( 'ai_consent', $input ) ) {
			$out = self::sanitize_ai( $input, $out );
		}

		if ( $out['base'] !== $old['base'] ) {
			update_option( 'crazylinks_flush_rewrite', 1 );
		}
		Render\Cache::bump();
		return $out;
	}
}
