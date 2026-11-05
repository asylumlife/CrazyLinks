<?php
/**
 * Rate limits and the monthly token budget for AI requests.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\Analytics\RateLimiter;
use CrazyLinks\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC §10.6: per-user (default 30/hour) and per-site (default 300/day) request limits through the
 * shared RateLimiter, and a monthly token budget per site with a soft warning at 80% and a hard stop
 * at 100% (core's AI client has no cost cap). Usage is counted from the provider's reported tokens
 * when available, else estimated at four characters per token.
 */
final class Limits {

	public const USAGE_OPTION = 'crazylinks_ai_usage';

	public const SOFT_SHARE = 0.8;

	/**
	 * Counts one request against the rate limits. WP_Error (429) when a limit is exceeded.
	 *
	 * @param int $user_id User.
	 * @return true|WP_Error
	 */
	public static function hit( int $user_id ) {
		/**
		 * Filters the AI request limits.
		 *
		 * @param array{user_hourly:int, site_daily:int} $limits  Limits (0 = unlimited).
		 * @param int                                    $user_id User.
		 */
		$limits = (array) apply_filters(
			'crazylinks_ai_rate_limits',
			[
				'user_hourly' => (int) Settings::get( 'ai_user_hourly' ),
				'site_daily'  => (int) Settings::get( 'ai_site_daily' ),
			],
			$user_id
		);
		$user   = (int) ( $limits['user_hourly'] ?? 0 );
		$site   = (int) ( $limits['site_daily'] ?? 0 );
		if ( $user > 0 && ! RateLimiter::hit( 'ai_user', 'u' . $user_id, $user, HOUR_IN_SECONDS ) ) {
			return new WP_Error(
				'crazylinks_ai_rate_limited',
				/* translators: %d: requests per hour. */
				sprintf( __( 'You have reached the limit of %d AI requests per hour. Please try again later.', 'crazylinks' ), $user ),
				[ 'status' => 429 ]
			);
		}
		if ( $site > 0 && ! RateLimiter::hit( 'ai_site', 'site-' . get_current_blog_id(), $site, DAY_IN_SECONDS ) ) {
			return new WP_Error(
				'crazylinks_ai_rate_limited',
				/* translators: %d: requests per day. */
				sprintf( __( 'This site has reached its limit of %d AI requests today. Please try again tomorrow.', 'crazylinks' ), $site ),
				[ 'status' => 429 ]
			);
		}
		return true;
	}

	/**
	 * Monthly budget in tokens (0 = unlimited).
	 */
	public static function budget(): int {
		/**
		 * Filters the monthly AI token budget for the site (0 = unlimited).
		 *
		 * @param int $tokens Setting value.
		 */
		return max( 0, (int) apply_filters( 'crazylinks_ai_monthly_tokens', (int) Settings::get( 'ai_monthly_tokens' ) ) );
	}

	/**
	 * Tokens used this calendar month (UTC).
	 */
	public static function used(): int {
		$usage = get_option( self::USAGE_OPTION, [] );
		if ( ! is_array( $usage ) || ( $usage['month'] ?? '' ) !== gmdate( 'Y-m' ) ) {
			return 0;
		}
		return (int) ( $usage['tokens'] ?? 0 );
	}

	/**
	 * Adds tokens to this month's usage.
	 *
	 * @param int $tokens Tokens.
	 */
	public static function record( int $tokens ): void {
		$month = gmdate( 'Y-m' );
		$used  = self::used() + max( 0, $tokens );
		update_option(
			self::USAGE_OPTION,
			[
				'month'  => $month,
				'tokens' => $used,
			],
			false
		);
	}

	/**
	 * WP_Error (429) when the budget is spent.
	 *
	 * @return true|WP_Error
	 */
	public static function check_budget() {
		$budget = self::budget();
		if ( $budget > 0 && self::used() >= $budget ) {
			return new WP_Error(
				'crazylinks_ai_budget_exhausted',
				__( 'This site has used its AI budget for this month. An administrator can raise it under CrazyLinks → Settings.', 'crazylinks' ),
				[ 'status' => 429 ]
			);
		}
		return true;
	}

	/**
	 * Budget state for the status endpoint and editor.
	 *
	 * @return array{budget:int, used:int, share:float, warning:bool, exhausted:bool}
	 */
	public static function budget_state(): array {
		$budget = self::budget();
		$used   = self::used();
		$share  = $budget > 0 ? min( 1.0, $used / $budget ) : 0.0;
		return [
			'budget'    => $budget,
			'used'      => $used,
			'share'     => round( $share, 4 ),
			'warning'   => $budget > 0 && $share >= self::SOFT_SHARE,
			'exhausted' => $budget > 0 && $used >= $budget,
		];
	}

	/**
	 * Rough token estimate for providers that do not report usage.
	 *
	 * @param string $text Text sent or received.
	 */
	public static function estimate( string $text ): int {
		return (int) ceil( mb_strlen( $text ) / 4 );
	}
}
