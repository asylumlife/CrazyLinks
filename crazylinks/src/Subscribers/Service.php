<?php
/**
 * Subscriber flow: sign-up, confirmation, unsubscribe.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers;

use CrazyLinks\Analytics\RateLimiter;
use CrazyLinks\Analytics\Visitor;
use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Business rules for the local subscriber list.
 */
final class Service {

	/**
	 * Seconds a confirmation link stays valid.
	 */
	public static function confirm_ttl(): int {
		/**
		 * Filters how long a confirmation link works.
		 *
		 * @param int $seconds Default 7 days.
		 */
		return max( HOUR_IN_SECONDS, (int) apply_filters( 'crazylinks_confirm_ttl', 7 * DAY_IN_SECONDS ) );
	}

	/**
	 * Visitor-facing messages by key. The same keys are rendered into the form (see
	 * templates/blocks/email-capture.php) and returned by the REST route.
	 *
	 * @return array<string, string>
	 */
	public static function messages(): array {
		return [
			'pending'   => __( 'Check your inbox and click the link we sent to confirm your email.', 'crazylinks' ),
			'confirmed' => __( 'You are in. Thanks for subscribing!', 'crazylinks' ),
			'done'      => __( 'Your subscription is confirmed. Thank you!', 'crazylinks' ),
			'unsub'     => __( 'You have been unsubscribed.', 'crazylinks' ),
			'expired'   => __( 'That confirmation link has expired. Please sign up again.', 'crazylinks' ),
			'invalid'   => __( 'Please enter a valid email address.', 'crazylinks' ),
			'consent'   => __( 'Please tick the consent box to subscribe.', 'crazylinks' ),
			'limited'   => __( 'Too many attempts. Please try again later.', 'crazylinks' ),
			'error'     => __( 'Sorry, that did not work. Please try again.', 'crazylinks' ),
		];
	}

	/**
	 * Messages for one block: a block routed to Mailchimp says Mailchimp sends the confirmation.
	 *
	 * @param array<string, mixed> $props Block props.
	 * @return array<string, string>
	 */
	public static function messages_for( array $props ): array {
		$out = self::messages();
		if ( Adapters\Mailchimp::ID === ( $props['destination'] ?? '' ) ) {
			$out['pending'] = __( 'Check your email to confirm your subscription.', 'crazylinks' );
		}
		return $out;
	}

	/**
	 * Where to send the visitor back: the page (or the home page when it is gone), with a
	 * status in the query and a fragment that reveals the message next to the form.
	 *
	 * @param int                  $page_id  Page id.
	 * @param string               $block_id Block id.
	 * @param string               $key      Message key.
	 * @param array<string, mixed> $query    Extra query args.
	 */
	public static function return_url( int $page_id, string $block_id, string $key, array $query = [] ): string {
		$url = $page_id > 0 ? get_permalink( $page_id ) : false;
		if ( ! is_string( $url ) || '' === $url ) {
			return home_url( '/' );
		}
		$url = add_query_arg( $query ? $query : [ 'cl_sub' => $key ], $url );
		return '' !== $block_id ? $url . '#cl-ec-' . $block_id . '-m-' . $key : $url;
	}

	/**
	 * Handles a validated sign-up. Never reveals whether the address was already on the list: the
	 * returned status depends on the double opt-in setting only.
	 *
	 * @param \WP_Post             $page  Public page.
	 * @param array<string, mixed> $block Reachable email_capture block.
	 * @param string               $email Normalized email.
	 * @return string|\WP_Error pending | confirmed.
	 */
	public static function subscribe( \WP_Post $page, array $block, string $email ) {
		$doi      = Settings::double_opt_in();
		$block_id = (string) $block['id'];

		/**
		 * Lets an integration (MailPoet, FluentCRM, ...) take over a sign-up instead of the local
		 * table. Return null to continue locally. Return true, or an array with an optional
		 * `status` (`pending` or `confirmed`), when the sign-up was handled; nothing is stored
		 * locally and no email is sent. Return a WP_Error to reject it. The address is lowercased
		 * and valid; the page and block are publicly reachable and consent was given.
		 *
		 * @param null|bool|array|\WP_Error $handled  Null by default.
		 * @param string                    $email    Subscriber email.
		 * @param int                       $page_id  Page id.
		 * @param string                    $block_id Block id.
		 * @param array<string, mixed>      $block    The email_capture block.
		 */
		$handled = apply_filters( 'crazylinks_subscribe_handle', null, $email, $page->ID, $block_id, $block );
		if ( is_wp_error( $handled ) ) {
			return $handled;
		}
		if ( null !== $handled && false !== $handled ) {
			$status = is_array( $handled ) && 'pending' === ( $handled['status'] ?? '' ) ? 'pending' : 'confirmed';
			return $status;
		}

		$consent = mb_substr( (string) ( $block['props']['consent_text'] ?? '' ), 0, 500 );
		$skip    = '';
		if ( Sync::is_mailchimp_block( $block ) ) {
			if ( Integrations::mailchimp_connected() && '' !== Sync::audience_for( $block ) ) {
				return self::subscribe_mailchimp( $page, $block, $email, $consent );
			}
			// Not connected (yet): keep the sign-up on the local list with this site's own double
			// opt-in, so nobody is lost; backfill sends it once Mailchimp is set up.
			$skip = Integrations::mailchimp_connected()
				? __( 'No Mailchimp audience is chosen; kept on the local list.', 'crazylinks' )
				: __( 'Mailchimp is not connected; kept on the local list.', 'crazylinks' );
		}
		$existing = Store::find( $page->ID, $email );
		$response = $doi ? 'pending' : 'confirmed';

		if ( null === $existing ) {
			$token = Store::new_token();
			$id    = Store::insert(
				[
					'page_id'      => $page->ID,
					'block_id'     => $block_id,
					'email'        => $email,
					'status'       => $response,
					'consent_text' => $consent,
					'token'        => $token,
					'sync_status'  => '' !== $skip ? 'skipped' : '',
					'sync_error'   => $skip,
				]
			);
			$row   = $id > 0 ? Store::get( $id ) : null;
			if ( $row ) {
				self::announce( 'crazylinks_subscriber_added', $row );
				self::after_signup( $row, $token, $doi );
			}
			return $response;
		}

		if ( 'confirmed' === $existing['status'] ) {
			return $response; // Already on the list: nothing to do, nothing to reveal.
		}
		if ( 'pending' === $existing['status'] && $doi && time() - (int) strtotime( (string) $existing['updated_at'] . ' UTC' ) < 5 * MINUTE_IN_SECONDS ) {
			return $response; // A confirmation went out moments ago.
		}
		// Pending (and the owner turned double opt-in off) or unsubscribed and signing up again: fresh consent and token.
		$token = Store::new_token();
		Store::update(
			(int) $existing['id'],
			[
				'status'       => $response,
				'block_id'     => $block_id,
				'consent_text' => $consent,
				'consent_at'   => Store::now(),
				'confirmed_at' => $doi ? null : Store::now(),
				'token'        => $token,
			]
		);
		$row = Store::get( (int) $existing['id'] );
		if ( $row ) {
			self::after_signup( $row, $token, $doi );
		}
		return $response;
	}

	/**
	 * A sign-up on a block routed to Mailchimp: stored locally as pending (Mailchimp owns the
	 * confirmation and its webhook confirms the row), pushed at once, no email from this site.
	 * Always answers `pending`, so the response never reveals whether the address was known.
	 *
	 * @param \WP_Post             $page    Page.
	 * @param array<string, mixed> $block   Block.
	 * @param string               $email   Normalized email.
	 * @param string               $consent Consent text snapshot.
	 */
	private static function subscribe_mailchimp( \WP_Post $page, array $block, string $email, string $consent ): string {
		$block_id = (string) $block['id'];
		$audience = Sync::audience_for( $block );
		$existing = Store::find( $page->ID, $email );
		if ( null === $existing ) {
			$id  = Store::insert(
				[
					'page_id'      => $page->ID,
					'block_id'     => $block_id,
					'email'        => $email,
					'status'       => 'pending',
					'consent_text' => $consent,
					'token'        => Store::new_token(),
					'destination'  => Adapters\Mailchimp::ID,
					'audience_id'  => $audience,
					'sync_status'  => 'queued',
				]
			);
			$row = $id > 0 ? Store::get( $id ) : null;
			if ( $row ) {
				self::announce( 'crazylinks_subscriber_added', $row );
				self::announce( 'crazylinks_subscriber_pending', $row );
				Sync::push( $id, 'signup' );
			}
			return 'pending';
		}
		$id = (int) $existing['id'];
		if ( 'confirmed' === $existing['status'] ) {
			// Already confirmed here: make sure Mailchimp has them (as subscribed), nothing else.
			if ( 'sent' !== $existing['sync_status'] || $audience !== $existing['audience_id'] ) {
				Store::update(
					$id,
					[
						'destination'   => Adapters\Mailchimp::ID,
						'audience_id'   => $audience,
						'sync_status'   => 'queued',
						'sync_attempts' => 0,
						'sync_error'    => '',
					]
				);
				Sync::push( $id, 'signup' );
			}
			return 'pending';
		}
		if ( 'pending' === $existing['status'] && Adapters\Mailchimp::ID === $existing['destination'] && time() - (int) strtotime( (string) $existing['updated_at'] . ' UTC' ) < 5 * MINUTE_IN_SECONDS ) {
			return 'pending'; // Pushed moments ago; Mailchimp's confirmation is on its way.
		}
		Store::update(
			$id,
			[
				'status'        => 'pending',
				'block_id'      => $block_id,
				'consent_text'  => $consent,
				'consent_at'    => Store::now(),
				'confirmed_at'  => null,
				'token'         => Store::new_token(),
				'destination'   => Adapters\Mailchimp::ID,
				'audience_id'   => $audience,
				'sync_status'   => 'queued',
				'sync_attempts' => 0,
				'sync_error'    => '',
			]
		);
		wp_clear_scheduled_hook( Sync::HOOK_PUSH, [ $id ] );
		$row = Store::get( $id );
		if ( $row ) {
			self::announce( 'crazylinks_subscriber_pending', $row );
			Sync::push( $id, 'signup' );
		}
		return 'pending';
	}

	/**
	 * Hooks and email after a row became pending or confirmed. At most 3 emails per address per hour.
	 *
	 * @param array<string, mixed> $row   Row.
	 * @param string               $token Token.
	 * @param bool                 $doi   Double opt-in.
	 */
	private static function after_signup( array $row, string $token, bool $doi ): void {
		self::announce( $doi ? 'crazylinks_subscriber_pending' : 'crazylinks_subscriber_confirmed', $row );
		$key = hash_hmac( 'sha256', (string) $row['email'], Visitor::daily_salt() );
		if ( ! RateLimiter::hit( 'subscribe_to', $key, 3, HOUR_IN_SECONDS ) ) {
			return;
		}
		if ( $doi ) {
			Mailer::send_confirmation( $row, $token );
		} elseif ( (bool) apply_filters( 'crazylinks_send_welcome_email', true, $row ) ) {
			Mailer::send_welcome( $row, $token );
		}
	}

	/**
	 * Fires a subscriber action.
	 *
	 * @param string               $hook Hook name.
	 * @param array<string, mixed> $row  Row (no token hash).
	 */
	public static function announce( string $hook, array $row ): void {
		/**
		 * Fires for subscriber lifecycle changes: `crazylinks_subscriber_added` (new row),
		 * `crazylinks_subscriber_pending`, `crazylinks_subscriber_confirmed`,
		 * `crazylinks_subscriber_unsubscribed`.
		 *
		 * @param array<string, mixed> $subscriber id, page_id, block_id, email, status, consent_text, consent_at, confirmed_at, created_at, updated_at.
		 * @param int                  $page_id    Page id.
		 * @param string               $block_id   Block id.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Only our crazylinks_subscriber_* names are passed in.
		do_action( $hook, $row, (int) $row['page_id'], (string) $row['block_id'] );
	}

	/**
	 * Confirms the row behind an emailed token.
	 *
	 * @param string $token Token.
	 * @return array{result:string, row:?array<string, mixed>} result: confirmed | already | expired | invalid.
	 */
	public static function confirm( string $token ): array {
		$row = Store::find_by_token( $token );
		if ( ! $row || 'unsubscribed' === $row['status'] ) {
			return [
				'result' => 'invalid',
				'row'    => null,
			];
		}
		if ( 'confirmed' === $row['status'] ) {
			return [
				'result' => 'already',
				'row'    => $row,
			];
		}
		if ( time() - (int) strtotime( (string) $row['updated_at'] . ' UTC' ) > self::confirm_ttl() ) {
			return [
				'result' => 'expired',
				'row'    => $row,
			];
		}
		Store::update(
			(int) $row['id'],
			[
				'status'       => 'confirmed',
				'confirmed_at' => Store::now(),
			]
		);
		$row = Store::get( (int) $row['id'] );
		if ( $row ) {
			self::announce( 'crazylinks_subscriber_confirmed', $row );
		}
		return [
			'result' => 'confirmed',
			'row'    => $row,
		];
	}

	/**
	 * Unsubscribes the row behind an emailed token (any status, never expires).
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null The row, or null for an unknown token.
	 */
	public static function unsubscribe( string $token ): ?array {
		$row = Store::find_by_token( $token );
		if ( ! $row ) {
			return null;
		}
		if ( 'unsubscribed' !== $row['status'] ) {
			Store::update( (int) $row['id'], [ 'status' => 'unsubscribed' ] );
			$row = Store::get( (int) $row['id'] ) ?? $row;
			self::announce( 'crazylinks_subscriber_unsubscribed', $row );
		}
		return $row;
	}

	/**
	 * Sends a new confirmation email to a pending subscriber (admin action).
	 *
	 * @param int $id Subscriber id.
	 */
	public static function resend( int $id ): bool {
		$row = Store::get( $id );
		if ( ! $row || 'pending' !== $row['status'] ) {
			return false;
		}
		$token = Store::new_token();
		Store::update( $id, [ 'token' => $token ] ); // Also restarts the 7 day window.
		return Mailer::send_confirmation( $row, $token );
	}
}
