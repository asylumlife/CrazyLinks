<?php
/**
 * Whether AI editing can run, and why not.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Availability is the conjunction of: core's AI client exists (WordPress 7.0+), `wp_supports_ai()`,
 * the CrazyLinks AI setting is on with the owner's consent, the user has `use_crazylinks_ai`, and a
 * configured provider has a text model that can return JSON. Each failure has a reason code the
 * editor turns into a one-line pointer.
 */
final class Status {

	public const EDIT_MODELS = [ 'claude-sonnet-5-5', 'claude-sonnet-5', 'claude-sonnet-4-6' ];

	public const FAST_MODELS = [ 'claude-haiku-5-5', 'claude-haiku-4-5' ];

	/**
	 * Per-request cache of the provider probe.
	 *
	 * @var bool|null
	 */
	private static ?bool $provider_ready = null;

	/**
	 * Clears the provider probe cache (tests, after a provider is registered).
	 */
	public static function reset(): void {
		self::$provider_ready = null;
	}

	/**
	 * Reason AI editing is unavailable for a user, or '' when it is ready.
	 *
	 * @param int|null $user_id User (default current).
	 * @return string '' | no_client | unsupported | disabled | no_capability | no_provider
	 */
	public static function reason( ?int $user_id = null ): string {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::client_exists() ) {
			return 'no_client';
		}
		if ( ! wp_supports_ai() ) {
			return 'unsupported';
		}
		if ( ! self::enabled() ) {
			return 'disabled';
		}
		if ( ! user_can( $user_id, 'use_crazylinks_ai' ) ) {
			return 'no_capability';
		}
		if ( ! self::provider_ready() ) {
			return 'no_provider';
		}
		return '';
	}

	/**
	 * Whether the owner turned AI editing on (and consented).
	 */
	public static function enabled(): bool {
		$on = (bool) Settings::get( 'ai_enabled' ) && (bool) Settings::get( 'ai_consent' );
		/**
		 * Filters whether CrazyLinks AI editing is switched on for the site.
		 *
		 * @param bool $on Setting value (enabled and consented).
		 */
		return (bool) apply_filters( 'crazylinks_ai_enabled', $on );
	}

	/**
	 * Whether WordPress's AI client is present (7.0+).
	 */
	public static function client_exists(): bool {
		return function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' );
	}

	/**
	 * Whether a configured provider offers a text model that returns JSON (cached per request).
	 */
	public static function provider_ready(): bool {
		if ( null !== self::$provider_ready ) {
			return self::$provider_ready;
		}
		$ready = false;
		if ( self::client_exists() && wp_supports_ai() ) {
			try {
				$ready = true === wp_ai_client_prompt( 'ping' )->using_system_instruction( 'ping' )->as_json_response()->is_supported_for_text_generation();
			} catch ( \Throwable $e ) {
				$ready = false;
			}
		}
		self::$provider_ready = $ready;
		return $ready;
	}

	/**
	 * Names of configured AI providers (for the settings screen, opt-in text and status).
	 *
	 * @return string[]
	 */
	public static function providers(): array {
		if ( ! self::client_exists() || ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return [];
		}
		$names = [];
		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			foreach ( $registry->getRegisteredProviderIds() as $id ) {
				if ( $registry->isProviderConfigured( $id ) ) {
					$class   = $registry->getProviderClassName( $id );
					$names[] = (string) $class::metadata()->getName();
				}
			}
		} catch ( \Throwable $e ) {
			return $names;
		}
		return $names;
	}

	/**
	 * Model preference list for edits or suggestions.
	 *
	 * @param string $purpose edit | suggest.
	 * @return string[]
	 */
	public static function models( string $purpose ): array {
		$setting = (string) Settings::get( 'suggest' === $purpose ? 'ai_model_suggest' : 'ai_model_edit' );
		$models  = array_values( array_filter( array_map( 'trim', explode( ',', $setting ) ) ) );
		$models  = array_values( array_unique( array_merge( $models, 'suggest' === $purpose ? self::FAST_MODELS : self::EDIT_MODELS ) ) );
		/**
		 * Filters the model preference list (first available wins; core falls back to any capable model).
		 *
		 * @param string[] $models  Model ids.
		 * @param string   $purpose edit | suggest | grade.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'crazylinks_ai_models', $models, $purpose ) ) );
	}

	/**
	 * A one-line explanation for a reason code.
	 *
	 * @param string $reason Reason code.
	 */
	public static function message( string $reason ): string {
		switch ( $reason ) {
			case 'no_client':
				return __( 'AI editing needs WordPress 7.0 or later.', 'crazylinks' );
			case 'unsupported':
				return __( 'AI features are turned off on this site.', 'crazylinks' );
			case 'disabled':
				return __( 'AI editing is off. An administrator can turn it on in CrazyLinks → Settings.', 'crazylinks' );
			case 'no_capability':
				return __( 'Your account is not allowed to use AI editing.', 'crazylinks' );
			case 'no_provider':
				return __( 'No AI provider is connected. Add one in Settings → Connectors.', 'crazylinks' );
		}
		return '';
	}

	/**
	 * The `ai` key of the editor boot data (window.crazylinks.ai).
	 *
	 * @param int  $page_id   Page being edited.
	 * @param bool $available Whether the editor should treat AI as available (EditorPage::ai_available()).
	 * @return array<string, mixed>
	 */
	public static function editor_data( int $page_id, bool $available ): array {
		$reason = self::reason();
		return [
			'available'           => $available,
			'ready'               => '' === $reason,
			'tab'                 => current_user_can( 'use_crazylinks_ai' ),
			'reason'              => $reason,
			'message'             => self::message( $reason ),
			'connectorsUrl'       => current_user_can( 'manage_options' ) ? admin_url( 'options-connectors.php' ) : '',
			'settingsUrl'         => current_user_can( 'manage_crazylinks' ) ? admin_url( 'edit.php?post_type=crazylinks_page&page=crazylinks-settings#crazylinks-ai' ) : '',
			'providers'           => '' === $reason ? self::providers() : [],
			'budget'              => Limits::budget_state(),
			'requireConfirmation' => Proposals::requires_confirmation( $page_id ),
			'modes'               => OpsValidator::MODES,
		];
	}
}
