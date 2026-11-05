<?php
/**
 * Settings screen.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Admin;

use CrazyLinks\Ai\Abilities;
use CrazyLinks\Ai\Limits;
use CrazyLinks\Ai\Status;
use CrazyLinks\PostType;
use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * CrazyLinks → Settings (classic Settings API).
 */
final class SettingsPage {

	public const SLUG = 'crazylinks-settings';

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'admin_post_crazylinks_flush', [ $this, 'flush' ] );
		add_filter( 'option_page_capability_crazylinks', static fn() => 'manage_crazylinks' );
	}

	/**
	 * Adds the submenu under the CrazyLinks menu.
	 */
	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostType::NAME,
			__( 'CrazyLinks Settings', 'crazylinks' ),
			__( 'Settings', 'crazylinks' ),
			'manage_crazylinks',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Registers the option and fields.
	 */
	public function register(): void {
		register_setting(
			'crazylinks',
			Settings::OPTION,
			[
				'type'              => 'object',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			]
		);
		add_settings_section( 'crazylinks_main', '', '__return_false', self::SLUG );

		$fields = [
			'base'                      => __( 'URL base', 'crazylinks' ),
			'render_mode'               => __( 'Default display', 'crazylinks' ),
			'analytics'                 => __( 'Analytics', 'crazylinks' ),
			'retention_days'            => __( 'Keep raw events for', 'crazylinks' ),
			'double_opt_in'             => __( 'Email sign-ups', 'crazylinks' ),
			'subscriber_retention_days' => __( 'Keep unconfirmed and unsubscribed records for', 'crazylinks' ),
			'fetch_oembed'              => __( 'Embed previews', 'crazylinks' ),
			'delete_on_uninstall'       => __( 'On uninstall', 'crazylinks' ),
		];
		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'crazylinks_' . $key,
				$label,
				[ $this, 'field' ],
				self::SLUG,
				'crazylinks_main',
				[
					'key'       => $key,
					'label_for' => 'crazylinks_' . $key,
				]
			);
		}

		add_settings_section( 'crazylinks_ai', __( 'AI editing', 'crazylinks' ), [ $this, 'ai_intro' ], self::SLUG );
		$ai_fields = [
			'ai_enabled'        => __( 'AI editing', 'crazylinks' ),
			'ai_models'         => __( 'Models', 'crazylinks' ),
			'ai_limits'         => __( 'Request limits', 'crazylinks' ),
			'ai_monthly_tokens' => __( 'Monthly budget', 'crazylinks' ),
			'ai_log'            => __( 'AI history', 'crazylinks' ),
			'ai_abilities'      => __( 'External AI agents', 'crazylinks' ),
		];
		foreach ( $ai_fields as $key => $label ) {
			add_settings_field(
				'crazylinks_' . $key,
				$label,
				[ $this, 'ai_field' ],
				self::SLUG,
				'crazylinks_ai',
				[
					'key'       => $key,
					'label_for' => 'crazylinks_' . $key,
				]
			);
		}
	}

	/**
	 * AI section intro (anchor for the editor's "turn it on" link).
	 */
	public function ai_intro(): void {
		$providers = Status::providers();
		echo '<div id="crazylinks-ai"></div><input type="hidden" name="' . esc_attr( Settings::OPTION ) . '[ai_section]" value="1">';
		echo '<p>' . esc_html__( 'Edit link pages by describing the change. CrazyLinks uses the AI provider connected in Settings → Connectors; nothing is sent until you turn this on, and every change is previewed and confirmed before it is saved.', 'crazylinks' ) . '</p>';
		if ( ! Status::client_exists() ) {
			echo '<p><strong>' . esc_html( Status::message( 'no_client' ) ) . '</strong></p>';
		} elseif ( ! $providers ) {
			printf(
				'<p><strong>%s</strong> <a href="%s">%s</a></p>',
				esc_html__( 'No AI provider is connected yet.', 'crazylinks' ),
				esc_url( admin_url( 'options-connectors.php' ) ),
				esc_html__( 'Open Settings → Connectors', 'crazylinks' )
			);
		} else {
			/* translators: %s: comma-separated provider names. */
			echo '<p>' . esc_html( sprintf( __( 'Connected provider: %s.', 'crazylinks' ), implode( ', ', $providers ) ) ) . '</p>';
		}
	}

	/**
	 * Renders one AI field.
	 *
	 * @param array{key:string} $args Field args.
	 */
	public function ai_field( array $args ): void {
		$s   = Settings::all();
		$key = $args['key'];
		$opt = Settings::OPTION;
		$id  = 'crazylinks_' . $key;
		switch ( $key ) {
			case 'ai_enabled':
				$providers = Status::providers();
				$who       = $providers ? implode( ', ', $providers ) : __( 'the AI provider you connect in Settings → Connectors', 'crazylinks' );
				printf( '<label><input type="checkbox" id="%s" name="%s[ai_enabled]" value="1"%s> %s</label><br>', esc_attr( $id ), esc_attr( $opt ), checked( ! empty( $s['ai_enabled'] ), true, false ), esc_html__( 'Turn on AI editing for people with the "Use CrazyLinks AI" capability (administrators and editors).', 'crazylinks' ) );
				printf(
					'<label><input type="checkbox" name="%s[ai_consent]" value="1"%s> %s</label>',
					esc_attr( $opt ),
					checked( ! empty( $s['ai_consent'] ), true, false ),
					esc_html(
						sprintf(
							/* translators: %s: AI provider name(s). */
							__( 'I agree that when someone uses AI editing, CrazyLinks sends to %s: what they typed, the page being edited (profile, design and blocks, without custom HTML or custom CSS) and, only for "Build from this site", the site title and tagline, recent post titles and links, menu links and the user\'s public profile links; for "Ask about analytics", click totals per link. Provider keys, emails, subscribers and visitor data are never sent. The provider\'s terms and privacy policy apply.', 'crazylinks' ),
							$who
						)
					)
				);
				break;
			case 'ai_models':
				printf( '<p><label>%s<br><input type="text" id="%s" name="%s[ai_model_edit]" value="%s" class="regular-text code" placeholder="%s"></label></p>', esc_html__( 'Model for page edits (comma-separated preference)', 'crazylinks' ), esc_attr( $id ), esc_attr( $opt ), esc_attr( (string) $s['ai_model_edit'] ), esc_attr( implode( ',', Status::EDIT_MODELS ) ) );
				printf( '<p><label>%s<br><input type="text" name="%s[ai_model_suggest]" value="%s" class="regular-text code" placeholder="%s"></label></p>', esc_html__( 'Model for title and bio suggestions', 'crazylinks' ), esc_attr( $opt ), esc_attr( (string) $s['ai_model_suggest'] ), esc_attr( implode( ',', Status::FAST_MODELS ) ) );
				printf( '<p><label>%s <input type="number" name="%s[ai_timeout]" value="%d" min="10" max="600" class="small-text"></label></p>', esc_html__( 'Timeout in seconds', 'crazylinks' ), esc_attr( $opt ), (int) $s['ai_timeout'] );
				echo '<p class="description">' . esc_html__( 'Leave empty for the recommended models (Claude Sonnet for edits, Claude Haiku for suggestions). If none of the listed models is available, WordPress picks any capable model from your provider.', 'crazylinks' ) . '</p>';
				break;
			case 'ai_limits':
				printf( '<label><input type="number" id="%s" name="%s[ai_user_hourly]" value="%d" min="0" max="1000" class="small-text"> %s</label><br>', esc_attr( $id ), esc_attr( $opt ), (int) $s['ai_user_hourly'], esc_html__( 'requests per person per hour', 'crazylinks' ) );
				printf( '<label><input type="number" name="%s[ai_site_daily]" value="%d" min="0" max="100000" class="small-text"> %s</label>', esc_attr( $opt ), (int) $s['ai_site_daily'], esc_html__( 'requests per site per day (0 = no limit)', 'crazylinks' ) );
				break;
			case 'ai_monthly_tokens':
				$state = Limits::budget_state();
				printf( '<input type="number" id="%s" name="%s[ai_monthly_tokens]" value="%d" min="0" step="1000" class="regular-text"> %s', esc_attr( $id ), esc_attr( $opt ), (int) $s['ai_monthly_tokens'], esc_html__( 'tokens per month (0 = no limit)', 'crazylinks' ) );
				/* translators: 1: tokens used, 2: percentage. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Used this month: %1$s tokens (%2$s%%). A warning shows at 80%%; AI requests stop at 100%% until next month. An edit typically uses 5,000 to 10,000 tokens.', 'crazylinks' ), number_format_i18n( $state['used'] ), number_format_i18n( $state['share'] * 100, 0 ) ) ) . '</p>';
				break;
			case 'ai_log':
				printf( '<label><input type="checkbox" id="%s" name="%s[ai_log]" value="1"%s> %s</label><br>', esc_attr( $id ), esc_attr( $opt ), checked( ! empty( $s['ai_log'] ), true, false ), esc_html__( 'Keep what people asked the AI and the changes it proposed.', 'crazylinks' ) );
				printf( '<label>%s <input type="number" name="%s[ai_log_retention_days]" value="%d" min="0" max="3650" class="small-text"> %s</label>', esc_html__( 'Delete AI history after', 'crazylinks' ), esc_attr( $opt ), (int) $s['ai_log_retention_days'], esc_html__( 'days (0 keeps it).', 'crazylinks' ) );
				echo '<p class="description">' . esc_html__( 'With history off, CrazyLinks still records who used AI, when, the model and token counts (for limits and the budget), but not the request or the changes.', 'crazylinks' ) . '</p>';
				break;
			case 'ai_abilities':
				echo '<p class="description">' . esc_html__( 'Let AI assistants such as Claude or ChatGPT work on your link pages through the WordPress MCP Adapter plugin. They act as the WordPress user who connects them and can only do what that user can. Each ability is off until you enable it.', 'crazylinks' ) . '</p>';
				$labels = [
					'crazylinks/list-pages'            => __( 'List pages (read only)', 'crazylinks' ),
					'crazylinks/get-page'              => __( 'Read a page (read only)', 'crazylinks' ),
					'crazylinks/propose-changes'       => __( 'Propose changes (preview only, nothing saved)', 'crazylinks' ),
					'crazylinks/apply-changes'         => __( 'Apply proposed changes (saves the page)', 'crazylinks' ),
					'crazylinks/get-analytics-summary' => __( 'Read click totals (read only)', 'crazylinks' ),
					'crazylinks/suggest-titles'        => __( 'Suggest link titles', 'crazylinks' ),
				];
				foreach ( Abilities::NAMES as $i => $name ) {
					printf( '<label><input type="checkbox"%s name="%s[ai_abilities][]" value="%s"%s> %s <code>%s</code></label><br>', 0 === $i ? ' id="' . esc_attr( $id ) . '"' : '', esc_attr( $opt ), esc_attr( $name ), checked( in_array( $name, (array) $s['ai_abilities'], true ), true, false ), esc_html( $labels[ $name ] ), esc_html( $name ) );
				}
				printf( '<p><label><input type="checkbox" name="%s[ai_mcp_confirm]" value="1"%s> %s</label></p>', esc_attr( $opt ), checked( ! empty( $s['ai_mcp_confirm'] ), true, false ), esc_html__( 'Require changes from external agents to be confirmed in the editor (each page can override this in its AI tab).', 'crazylinks' ) );
				break;
		}
	}

	/**
	 * Renders one field.
	 *
	 * @param array{key:string} $args Field args.
	 */
	public function field( array $args ): void {
		$s    = Settings::all();
		$key  = $args['key'];
		$name = Settings::OPTION . '[' . $key . ']';
		$id   = 'crazylinks_' . $key;
		switch ( $key ) {
			case 'base':
				printf( '<code>%s/</code><input type="text" id="%s" name="%s" value="%s" class="regular-text code" pattern="[a-z0-9][a-z0-9\-]{0,31}" required><code>/your-page</code>', esc_html( untrailingslashit( home_url() ) ), esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $s['base'] ) );
				echo '<p class="description">' . esc_html__( 'Lowercase letters, numbers and hyphens. Changing it flushes rewrite rules; old links stop working.', 'crazylinks' ) . '</p>';
				break;
			case 'render_mode':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( [
					'standalone' => __( 'Standalone page (fastest, no theme)', 'crazylinks' ),
					'theme'      => __( 'Inside my theme (header and footer)', 'crazylinks' ),
				] as $value => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $s['render_mode'], $value, false ), esc_html( $label ) );
				}
				echo '</select><p class="description">' . esc_html__( 'Each page can override this in its settings.', 'crazylinks' ) . '</p>';
				break;
			case 'analytics':
				printf( '<label><input type="checkbox" id="%s" name="%s" value="1"%s> %s</label>', esc_attr( $id ), esc_attr( $name ), checked( ! empty( $s['analytics'] ), true, false ), esc_html__( 'Count page views and link clicks (first-party, no cookies, no raw IP addresses stored).', 'crazylinks' ) );
				break;
			case 'retention_days':
				printf( '<input type="number" id="%s" name="%s" value="%d" min="0" max="3650" class="small-text"> %s', esc_attr( $id ), esc_attr( $name ), absint( Settings::event_retention_days() ), esc_html__( 'days (0 keeps them forever). Daily totals are always kept.', 'crazylinks' ) );
				echo '<p class="description">' . esc_html__( 'Breakdowns (device, operating system, referrer) come from raw events, so they only cover this window. Unique visitors for older days come from the daily totals.', 'crazylinks' ) . '</p>';
				break;
			case 'double_opt_in':
				printf( '<label><input type="checkbox" id="%s" name="%s" value="1"%s> %s</label>', esc_attr( $id ), esc_attr( $name ), checked( ! empty( $s['double_opt_in'] ), true, false ), esc_html__( 'Ask new subscribers to confirm by email (double opt-in, recommended).', 'crazylinks' ) );
				echo '<p class="description">' . esc_html__( 'Turn it off and addresses are marked confirmed right away; the visitor still gets one welcome email with an unsubscribe link.', 'crazylinks' ) . '</p>';
				break;
			case 'subscriber_retention_days':
				printf( '<input type="number" id="%s" name="%s" value="%d" min="0" max="3650" class="small-text"> %s', esc_attr( $id ), esc_attr( $name ), (int) $s['subscriber_retention_days'], esc_html__( 'days (0 keeps them forever)', 'crazylinks' ) );
				echo '<p class="description">' . esc_html__( 'Sign-ups that were never confirmed, and people who unsubscribed, are deleted this many days after their last change. Confirmed subscribers are never deleted automatically.', 'crazylinks' ) . '</p>';
				break;
			case 'fetch_oembed':
				printf( '<label><input type="checkbox" id="%s" name="%s" value="1"%s> %s</label>', esc_attr( $id ), esc_attr( $name ), checked( ! empty( $s['fetch_oembed'] ), true, false ), esc_html__( 'Fetch embed details from the provider when a page with an Embed or Video block is saved.', 'crazylinks' ) );
				echo '<p class="description">' . esc_html__( 'This is the only outbound request CrazyLinks makes: when you save, WordPress asks the provider (YouTube, Vimeo, Spotify and similar) for the title, thumbnail and player of the URL you entered. Visitors never trigger it. Turn it off to make no requests; embeds then show a plain link.', 'crazylinks' ) . '</p>';
				break;
			case 'delete_on_uninstall':
				printf( '<label><input type="checkbox" id="%s" name="%s" value="1"%s> %s</label>', esc_attr( $id ), esc_attr( $name ), checked( ! empty( $s['delete_on_uninstall'] ), true, false ), esc_html__( 'Delete all link pages, analytics and settings when the plugin is deleted.', 'crazylinks' ) );
				break;
		}
	}

	/**
	 * Screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_crazylinks' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'CrazyLinks Settings', 'crazylinks' ) . '</h1>';
		foreach ( Notices::conflicts( Settings::base() ) as $conflict ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $conflict ) . '</p></div>';
		}
		settings_errors( Settings::OPTION );
		echo '<form method="post" action="options.php">';
		settings_fields( 'crazylinks' );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Link health', 'crazylinks' ) . '</h2>';
		echo '<p>' . esc_html__( 'If link pages return "not found", rebuild the rewrite rules.', 'crazylinks' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="crazylinks_flush">';
		wp_nonce_field( 'crazylinks_flush' );
		submit_button( __( 'Rebuild rewrite rules', 'crazylinks' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Flushes rewrite rules (admin-post handler).
	 */
	public function flush(): void {
		if ( ! current_user_can( 'manage_crazylinks' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'crazylinks' ), 403 );
		}
		check_admin_referer( 'crazylinks_flush' );
		flush_rewrite_rules( false );
		wp_safe_redirect( add_query_arg( 'crazylinks-flushed', '1', admin_url( 'edit.php?post_type=' . PostType::NAME . '&page=' . self::SLUG ) ) );
		exit;
	}
}
