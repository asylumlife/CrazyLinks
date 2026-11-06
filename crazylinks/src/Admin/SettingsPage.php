<?php
/**
 * Settings screen.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Admin;

use CrazyLinks\Ai\Abilities;
use CrazyLinks\Ai\Limits;
use CrazyLinks\Ai\McpStatus;
use CrazyLinks\Ai\Status;
use CrazyLinks\PostType;
use CrazyLinks\Settings;
use CrazyLinks\Subscribers\Adapters;
use CrazyLinks\Subscribers\Adapters\Mailchimp;
use CrazyLinks\Subscribers\Integrations;
use CrazyLinks\Woo\Store;

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
		add_action( 'admin_post_crazylinks_mailchimp_test', [ $this, 'mailchimp_test' ] );
		add_action( 'admin_post_crazylinks_mailchimp_webhook', [ $this, 'mailchimp_webhook' ] );
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

		add_settings_section( 'crazylinks_store', __( 'Store', 'crazylinks' ), [ $this, 'store_intro' ], self::SLUG );
		add_settings_field(
			'crazylinks_store_url',
			__( 'Store address', 'crazylinks' ),
			[ $this, 'field' ],
			self::SLUG,
			'crazylinks_store',
			[
				'key'       => 'store_url',
				'label_for' => 'crazylinks_store_url',
			]
		);

		add_settings_section( 'crazylinks_integrations', __( 'Integrations', 'crazylinks' ), [ $this, 'integrations_intro' ], self::SLUG );
		$int_fields = [
			'subscriber_adapter' => __( 'Email service', 'crazylinks' ),
			'mailchimp_api_key'  => __( 'Mailchimp API key', 'crazylinks' ),
			'mailchimp_audience' => __( 'Default audience', 'crazylinks' ),
			'mailchimp_webhook'  => __( 'Mailchimp webhook', 'crazylinks' ),
		];
		foreach ( $int_fields as $key => $label ) {
			add_settings_field(
				'crazylinks_' . $key,
				$label,
				[ $this, 'integration_field' ],
				self::SLUG,
				'crazylinks_integrations',
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
			'ai_mcp'            => __( 'External agents (MCP)', 'crazylinks' ),
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
	 * Store section intro: what the Product block reads and where from.
	 */
	public function store_intro(): void {
		echo '<p>' . esc_html__( 'The Product block shows products from a WooCommerce store. Leave the address empty to use WooCommerce on this site (when it is active), or enter a separate store: CrazyLinks reads its public product list and never contacts any other address for products.', 'crazylinks' ) . '</p>';
	}

	/**
	 * Status line under the store address.
	 */
	private function store_status(): void {
		$status = Store::status();
		if ( 'local' === $status['mode'] ) {
			echo '<p class="description"><strong>' . esc_html__( 'Using WooCommerce on this site.', 'crazylinks' ) . '</strong></p>';
			return;
		}
		if ( 'remote' !== $status['mode'] ) {
			echo '<p class="description">' . esc_html__( 'No store connected. The Product block shows nothing until you add an address or activate WooCommerce.', 'crazylinks' ) . '</p>';
			return;
		}
		if ( $status['ok'] ) {
			$name = '' !== $status['name'] ? ' ' . sprintf(
				/* translators: %s: store name. */
				__( 'Store name: %s.', 'crazylinks' ),
				$status['name']
			) : '';
			echo '<p class="description"><strong>' . esc_html__( 'The store answered.', 'crazylinks' ) . '</strong>' . esc_html( $name ) . '</p>';
		} else {
			echo '<p class="description"><strong>' . esc_html__( 'The store did not answer.', 'crazylinks' ) . '</strong> ' . esc_html__( 'Check the address and that WooCommerce is installed there. Products already fetched keep showing.', 'crazylinks' ) . '</p>';
		}
	}

	/**
	 * Integrations section intro.
	 */
	public function integrations_intro(): void {
		// Pressing Enter in any field submits the first submit button in the form: make that "Save"
		// rather than the Test connection button below.
		echo '<div id="crazylinks-integrations"></div><input type="submit" name="submit" value="' . esc_attr__( 'Save Changes', 'crazylinks' ) . '" class="screen-reader-text" tabindex="-1" aria-hidden="true">';
		echo '<p>' . esc_html__( 'Send sign-ups from Email sign-up blocks to an email service. Each block chooses "This site\'s list" or the service in its settings; blocks that keep this site\'s list are not affected. Nothing is sent to Mailchimp until you save a key here and route a block to it.', 'crazylinks' ) . '</p>';
		$notice = get_transient( self::notice_key() );
		if ( is_array( $notice ) && isset( $notice['message'] ) ) {
			delete_transient( self::notice_key() );
			printf( '<div class="notice notice-%s inline"><p>%s</p></div>', ! empty( $notice['ok'] ) ? 'success' : 'error', esc_html( (string) $notice['message'] ) );
		}
	}

	/**
	 * Renders one Integrations field.
	 *
	 * @param array{key:string} $args Field args.
	 */
	public function integration_field( array $args ): void {
		$s   = Settings::all();
		$opt = Settings::OPTION;
		$id  = 'crazylinks_' . $args['key'];
		switch ( $args['key'] ) {
			case 'subscriber_adapter':
				printf( '<select id="%s" name="%s[subscriber_adapter]">', esc_attr( $id ), esc_attr( $opt ) );
				printf( '<option value=""%s>%s</option>', selected( (string) $s['subscriber_adapter'], '', false ), esc_html__( 'Local list only', 'crazylinks' ) );
				foreach ( Adapters::all() as $adapter_id => $adapter ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $adapter_id ), selected( (string) $s['subscriber_adapter'], $adapter_id, false ), esc_html( $adapter->label() ) );
				}
				echo '</select>';
				break;
			case 'mailchimp_api_key':
				$key = Integrations::api_key();
				printf( '<input type="text" id="%s" name="%s[mailchimp_api_key]" value="%s" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us6">', esc_attr( $id ), esc_attr( $opt ), esc_attr( Integrations::mask( $key ) ) );
				echo '<p class="description">' . esc_html__( 'In Mailchimp: Profile → Extras → API keys → Create A Key. Only the last four characters are shown after saving; leave the field as it is to keep the key, or clear it to remove the key.', 'crazylinks' ) . '</p>';
				$test = Integrations::last_test();
				if ( $test ) {
					printf(
						'<p class="description"><strong>%s</strong> %s</p>',
						esc_html( $test['ok'] ? __( 'Connection OK.', 'crazylinks' ) : __( 'Connection failed.', 'crazylinks' ) ),
						esc_html(
							$test['message'] . ' ' . sprintf(
								/* translators: %s: time since the test, for example "5 mins". */
								__( '(tested %s ago)', 'crazylinks' ),
								human_time_diff( $test['at'] )
							)
						)
					);
				}
				printf( '<p><button type="submit" class="button" name="action" value="crazylinks_mailchimp_test" formaction="%s" formnovalidate>%s</button></p>', esc_url( self::action_url( 'crazylinks_mailchimp_test' ) ), esc_html__( 'Test connection', 'crazylinks' ) );
				break;
			case 'mailchimp_audience':
				$current = Integrations::default_audience();
				$list    = '' !== Integrations::api_key() ? Integrations::audiences() : new \WP_Error( 'none', __( 'Save an API key to choose from your audiences.', 'crazylinks' ) );
				if ( is_wp_error( $list ) ) {
					printf( '<input type="text" id="%s" name="%s[mailchimp_audience]" value="%s" class="regular-text code" pattern="[0-9a-z]{6,20}" autocomplete="off">', esc_attr( $id ), esc_attr( $opt ), esc_attr( $current ) );
					echo '<p class="description">' . esc_html( $list->get_error_message() ) . ' ' . esc_html__( 'You can also paste the Audience ID (Mailchimp: Audience → Settings → Audience name and defaults).', 'crazylinks' ) . '</p>';
					break;
				}
				printf( '<select id="%s" name="%s[mailchimp_audience]"><option value="">%s</option>', esc_attr( $id ), esc_attr( $opt ), esc_html__( 'Choose an audience', 'crazylinks' ) );
				$known = false;
				foreach ( $list as $aud ) {
					$known = $known || $aud['id'] === $current;
					/* translators: 1: audience name, 2: member count. */
					printf( '<option value="%s"%s>%s</option>', esc_attr( $aud['id'] ), selected( $current, $aud['id'], false ), esc_html( sprintf( _n( '%1$s (%2$s member)', '%1$s (%2$s members)', $aud['member_count'], 'crazylinks' ), $aud['name'], number_format_i18n( $aud['member_count'] ) ) ) );
				}
				if ( '' !== $current && ! $known ) {
					/* translators: %s: audience id. */
					printf( '<option value="%1$s" selected>%2$s</option>', esc_attr( $current ), esc_html( sprintf( __( '%s (not found in this account)', 'crazylinks' ), $current ) ) );
				}
				echo '</select><p class="description">' . esc_html__( 'Used by sign-up blocks routed to Mailchimp that do not pick their own audience. The list is refreshed every 10 minutes.', 'crazylinks' ) . '</p>';
				break;
			case 'mailchimp_webhook':
				printf( '<input type="text" id="%s" value="%s" class="large-text code" readonly onfocus="this.select()">', esc_attr( $id ), esc_attr( Integrations::webhook_url() ) );
				echo '<p class="description">' . esc_html__( 'Mailchimp sends confirmations and unsubscribes here, so the Subscribers screen stays up to date. Register it with the button (for the default audience), or paste it in Mailchimp under Audience → Settings → Webhooks with the events subscribes, unsubscribes and cleaned address, for every audience a block uses. Keep the address private: the key in it is the password.', 'crazylinks' ) . '</p>';
				printf( '<p><button type="submit" class="button" name="action" value="crazylinks_mailchimp_webhook" formaction="%s" formnovalidate>%s</button></p>', esc_url( self::action_url( 'crazylinks_mailchimp_webhook' ) ), esc_html__( 'Register webhook in Mailchimp', 'crazylinks' ) );
				break;
		}
	}

	/**
	 * The admin-post URL for a button inside the settings form. The button also carries name="action"
	 * (after the form's hidden action=update, so it wins) and the nonce uses its own field name, so
	 * the settings form's _wpnonce cannot collide.
	 *
	 * @param string $action Action.
	 */
	private static function action_url( string $action ): string {
		return wp_nonce_url( add_query_arg( 'action', $action, admin_url( 'admin-post.php' ) ), $action, 'cl_nonce' );
	}

	/**
	 * Per-user transient for the result of a button.
	 */
	private static function notice_key(): string {
		return 'crazylinks_int_notice_' . get_current_user_id();
	}

	/**
	 * Remembers a result and returns to the Integrations section.
	 *
	 * @param bool   $ok      Success.
	 * @param string $message Message.
	 */
	private static function back( bool $ok, string $message ): void {
		set_transient(
			self::notice_key(),
			[
				'ok'      => $ok,
				'message' => $message,
			],
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . PostType::NAME . '&page=' . self::SLUG . '#crazylinks-integrations' ) );
		exit;
	}

	/**
	 * "Test connection": tests the key typed in the field when it is new (without saving it), else
	 * the saved key.
	 */
	public function mailchimp_test(): void {
		if ( ! current_user_can( 'manage_crazylinks' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'crazylinks' ), 403 );
		}
		check_admin_referer( 'crazylinks_mailchimp_test', 'cl_nonce' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above with the cl_nonce field.
		$posted = isset( $_POST[ Settings::OPTION ]['mailchimp_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ Settings::OPTION ]['mailchimp_api_key'] ) ) ) : '';
		$typed  = '' !== $posted && ! Integrations::is_mask( $posted ) && Integrations::api_key() !== $posted;
		$result = Integrations::test( $typed ? $posted : null );
		if ( true === $result ) {
			self::back( true, $typed ? __( 'Connected to Mailchimp with the key you typed. Save the settings to keep it.', 'crazylinks' ) : __( 'Connected to Mailchimp.', 'crazylinks' ) );
		}
		self::back( false, $result->get_error_message() );
	}

	/**
	 * "Register webhook in Mailchimp" for the default audience.
	 */
	public function mailchimp_webhook(): void {
		if ( ! current_user_can( 'manage_crazylinks' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'crazylinks' ), 403 );
		}
		check_admin_referer( 'crazylinks_mailchimp_webhook', 'cl_nonce' );
		$audience = Integrations::default_audience();
		if ( '' === $audience ) {
			self::back( false, __( 'Choose a default audience and save the settings first.', 'crazylinks' ) );
		}
		$result = ( new Mailchimp() )->register_webhook( $audience, Integrations::webhook_url() );
		if ( is_wp_error( $result ) ) {
			self::back( false, $result->get_error_message() );
		}
		self::back( true, 'exists' === $result ? __( 'The webhook was already registered in Mailchimp for the default audience.', 'crazylinks' ) : __( 'Webhook registered in Mailchimp for the default audience (subscribes, unsubscribes and cleaned addresses).', 'crazylinks' ) );
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
				printf(
					'<label><input type="checkbox" id="%s" name="%s[ai_enabled]" value="1"%s> <strong>%s</strong> %s</label>',
					esc_attr( $id ),
					esc_attr( $opt ),
					checked( ! empty( $s['ai_enabled'] ), true, false ),
					esc_html__( 'Turn on AI editing for people with the "Use CrazyLinks AI" capability (administrators and editors).', 'crazylinks' ),
					esc_html(
						sprintf(
							/* translators: %s: AI provider name(s). */
							__( 'By turning it on you agree that when someone uses AI editing, CrazyLinks sends to %s: what they typed, the page being edited (profile, design and blocks, without custom HTML or custom CSS) and, only for "Build from this site", the site title and tagline, recent post titles and links, menu links and the user\'s public profile links; for "Ask about analytics", click totals per link. Provider keys, emails, subscribers and visitor data are never sent. The provider\'s terms and privacy policy apply.', 'crazylinks' ),
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
					'crazylinks/get-editing-guide'     => __( 'Editing guide for assistants (read only)', 'crazylinks' ),
					'crazylinks/preview-operations'    => __( 'Preview changes written by the assistant (nothing saved)', 'crazylinks' ),
					'crazylinks/apply-operations'      => __( 'Save changes written by the assistant (saves the page)', 'crazylinks' ),
					'crazylinks/propose-changes'       => __( 'Propose changes (preview only, nothing saved)', 'crazylinks' ),
					'crazylinks/apply-changes'         => __( 'Apply proposed changes (saves the page)', 'crazylinks' ),
					'crazylinks/get-analytics-summary' => __( 'Read click totals (read only)', 'crazylinks' ),
					'crazylinks/suggest-titles'        => __( 'Suggest link titles', 'crazylinks' ),
					'crazylinks/search-products'       => __( 'Find store products (read only)', 'crazylinks' ),
				];
				foreach ( Abilities::NAMES as $i => $name ) {
					printf( '<label><input type="checkbox"%s name="%s[ai_abilities][]" value="%s"%s> %s <code>%s</code></label><br>', 0 === $i ? ' id="' . esc_attr( $id ) . '"' : '', esc_attr( $opt ), esc_attr( $name ), checked( in_array( $name, (array) $s['ai_abilities'], true ), true, false ), esc_html( $labels[ $name ] ), esc_html( $name ) );
				}
				printf( '<p><label><input type="checkbox" name="%s[ai_mcp_confirm]" value="1"%s> %s</label></p>', esc_attr( $opt ), checked( ! empty( $s['ai_mcp_confirm'] ), true, false ), esc_html__( 'Require changes from external agents to be confirmed in the editor (each page can override this in its AI tab).', 'crazylinks' ) );
				echo '<p class="description">' . esc_html__( 'For Claude Desktop or Claude Code, where the assistant writes the changes itself, enable at least "List pages", "Read a page", "Editing guide", "Preview changes" and "Save changes". No AI provider key is needed on this site for those.', 'crazylinks' ) . '</p>';
				break;
			case 'ai_mcp':
				$this->mcp_box( $s, $id );
				break;
		}
	}

	/**
	 * The "External agents (MCP)" box: adapter status, server switch, endpoints and connection snippets.
	 *
	 * @param array<string, mixed> $s  Settings.
	 * @param string               $id Field id.
	 */
	private function mcp_box( array $s, string $id ): void {
		$opt   = Settings::OPTION;
		$state = McpStatus::state();
		if ( McpStatus::adapter_active() ) {
			$version = McpStatus::adapter_version();
			/* translators: %s: MCP Adapter version. */
			echo '<p><strong>' . esc_html( '' === $version ? __( 'MCP Adapter is active.', 'crazylinks' ) : sprintf( __( 'MCP Adapter %s is active.', 'crazylinks' ), $version ) ) . '</strong> ' . esc_html( McpStatus::message( $state ) ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html( McpStatus::message( 'no_adapter' ) ) . '</strong></p>';
		}
		printf(
			'<p><label><input type="checkbox" id="%s" name="%s[ai_mcp_server]" value="1"%s> %s</label></p>',
			esc_attr( $id ),
			esc_attr( $opt ),
			checked( ! empty( $s['ai_mcp_server'] ), true, false ),
			esc_html__( 'Offer a CrazyLinks MCP server that lists only the abilities enabled above as tools.', 'crazylinks' )
		);
		printf(
			'<p><label><input type="checkbox" name="%s[ai_mcp_default_server_off]" value="1"%s> %s</label><br><span class="description">%s</span></p>',
			esc_attr( $opt ),
			checked( ! empty( $s['ai_mcp_default_server_off'] ), true, false ),
			esc_html__( "Turn off the MCP Adapter's default server. Only the CrazyLinks server stays reachable.", 'crazylinks' ),
			esc_html( McpStatus::other_public_sentence() )
		);
		if ( ! McpStatus::reachable() ) {
			echo '<p><strong>' . esc_html__( 'No MCP server is reachable: the CrazyLinks server and the default server are both off.', 'crazylinks' ) . '</strong></p>';
		}
		echo '<p>' . esc_html__( 'CrazyLinks server:', 'crazylinks' ) . ' <code>' . esc_html( McpStatus::endpoint() ) . '</code>';
		if ( ! McpStatus::default_server_off() ) {
			echo '<br>' . esc_html__( 'MCP Adapter default server (enabled abilities through its discover and execute tools):', 'crazylinks' ) . ' <code>' . esc_html( McpStatus::default_endpoint() ) . '</code>';
		}
		echo '</p>';
		echo '<p class="description">' . esc_html( McpStatus::user_advice() ) . '</p>';
		echo '<p><label for="crazylinks_mcp_desktop">' . esc_html__( 'Claude Desktop: add this to claude_desktop_config.json (Settings → Developer → Edit Config). Your WordPress login name is already filled in; replace only the password placeholder with the application password itself, not its name:', 'crazylinks' ) . '</label><br>';
		printf( '<textarea id="crazylinks_mcp_desktop" class="large-text code" rows="13" readonly>%s</textarea></p>', esc_textarea( McpStatus::desktop_config() ) );
		echo '<p><label for="crazylinks_mcp_code">' . esc_html__( 'Claude Code: run this in a terminal (base64 of "login name:application password", for example with: echo -n \'login:xxxx xxxx xxxx xxxx xxxx xxxx\' | base64):', 'crazylinks' ) . '</label><br>';
		printf( '<textarea id="crazylinks_mcp_code" class="large-text code" rows="3" readonly>%s</textarea></p>', esc_textarea( McpStatus::code_command() ) );
		echo '<p class="description">' . esc_html__( 'Never paste a real password into a shared document. claude.ai on the web cannot connect yet because it needs OAuth.', 'crazylinks' ) . '</p>';
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
				echo '<p class="description">' . esc_html__( 'Apart from a store or Mailchimp you connect below, this is the only outbound request CrazyLinks makes: when you save, WordPress asks the provider (YouTube, Vimeo, Spotify and similar) for the title, thumbnail and player of the URL you entered. Visitors never trigger it. Turn it off to make no requests; embeds then show a plain link.', 'crazylinks' ) . '</p>';
				break;
			case 'store_url':
				printf( '<input type="url" id="%s" name="%s" value="%s" class="regular-text code" placeholder="https://store.example.com" maxlength="200" autocomplete="off">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $s['store_url'] ) );
				echo '<p class="description">' . esc_html__( 'The home address of a WooCommerce site with the public Store API on (the default). Products are read from it; a visitor never sends anything to it from your page. Requests carry no personal data.', 'crazylinks' ) . '</p>';
				$this->store_status();
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
