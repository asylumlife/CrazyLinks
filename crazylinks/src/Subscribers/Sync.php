<?php
/**
 * Sending sign-ups to an email service: push, retries, backfill, webhook updates.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers;

use CrazyLinks\PageStore;
use CrazyLinks\Subscribers\Adapters\Mailchimp;

defined( 'ABSPATH' ) || exit;

/**
 * A sign-up on a block routed to Mailchimp is stored locally (`destination` mailchimp, `status`
 * pending, `sync_status` queued) and pushed inline during the sign-up request with a 5-second
 * timeout. A failed push becomes `failed` and is retried by a single WP-Cron event
 * (`crazylinks_subscriber_sync`, args `[id]`) with exponential backoff (1, 4, 16, 64 minutes) up to
 * 5 attempts in total. Errors that another attempt cannot fix (the member is in a state Mailchimp
 * will not change, the audience is gone) stop at once; the admin can still use "Retry sync".
 *
 * Backfill sends every locally confirmed row that was never sent, in batches of 25 through the
 * `crazylinks_subscriber_backfill` event, with `status_if_new: subscribed` (those people completed
 * this site's double opt-in) and the block's tags.
 */
final class Sync {

	public const HOOK_PUSH       = 'crazylinks_subscriber_sync';
	public const HOOK_BACKFILL   = 'crazylinks_subscriber_backfill';
	public const BACKFILL_OPTION = 'crazylinks_sync_backfill';
	public const MAX_ATTEMPTS    = 5;
	public const BATCH           = 25;

	/**
	 * Error codes another attempt cannot fix.
	 */
	private const PERMANENT = [ 'crazylinks_mc_member_exists', 'crazylinks_mc_member_state', 'crazylinks_mc_rejected', 'crazylinks_mc_invalid', 'crazylinks_mc_audience_not_found' ];

	/**
	 * Cron callbacks (registered on every request by Bootstrap).
	 */
	public static function register_hooks(): void {
		add_action( self::HOOK_PUSH, [ self::class, 'run_scheduled' ], 10, 1 );
		add_action( self::HOOK_BACKFILL, [ self::class, 'backfill_cron' ] );
	}

	/**
	 * Removes every scheduled sync event (deactivation, uninstall).
	 */
	public static function unschedule(): void {
		wp_unschedule_hook( self::HOOK_PUSH );
		wp_unschedule_hook( self::HOOK_BACKFILL );
	}

	/**
	 * Whether a block sends its sign-ups to Mailchimp.
	 *
	 * @param array<string, mixed> $block Block.
	 */
	public static function is_mailchimp_block( array $block ): bool {
		return Mailchimp::ID === ( $block['props']['destination'] ?? '' );
	}

	/**
	 * The audience a block sends to: its own `audience_id`, else the site default ('' when none).
	 *
	 * @param array<string, mixed>|null $block Block.
	 */
	public static function audience_for( ?array $block ): string {
		$own = is_array( $block ) ? (string) ( $block['props']['audience_id'] ?? '' ) : '';
		return Mailchimp::valid_audience( $own ) ? $own : Integrations::default_audience();
	}

	/**
	 * A block's tags, cleaned.
	 *
	 * @param array<string, mixed>|null $block Block.
	 * @return string[]
	 */
	public static function tags_for( ?array $block ): array {
		return is_array( $block ) ? Mailchimp::clean_tags( (array) ( $block['props']['tags'] ?? [] ) ) : [];
	}

	/**
	 * The block a row was signed up through (from the saved page; null when gone).
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>|null
	 */
	private static function block_for( array $row ): ?array {
		$post = get_post( (int) $row['page_id'] );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		return PageStore::find_block( PageStore::get_document( $post ) ?? [], (string) $row['block_id'] );
	}

	/**
	 * Seconds before retry number $attempts + 1.
	 *
	 * @param int $attempts Attempts made so far (1 or more).
	 */
	public static function retry_delay( int $attempts ): int {
		$delay = (int) min( 6 * HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 4 ** max( 0, $attempts - 1 ) ) );
		/**
		 * Filters the wait before the next push attempt.
		 *
		 * @param int $delay    Seconds (default 60, 240, 960, 3840).
		 * @param int $attempts Attempts made so far.
		 */
		return max( 1, (int) apply_filters( 'crazylinks_sync_retry_delay', $delay, $attempts ) );
	}

	/**
	 * Pushes one row now and records the outcome. On failure, schedules a retry when one can help.
	 *
	 * @param int    $id   Row id.
	 * @param string $mode signup (inline, short timeout) | retry | backfill.
	 * @return true|\WP_Error
	 */
	public static function push( int $id, string $mode = 'retry' ) {
		$row = Store::get( $id );
		if ( ! $row ) {
			return new \WP_Error( 'crazylinks_not_found', __( 'Subscriber not found.', 'crazylinks' ) );
		}
		$service = 'local' !== $row['destination'] && '' !== (string) $row['destination'] ? (string) $row['destination'] : Integrations::adapter_id();
		$adapter = Adapters::get( $service );
		if ( ! $adapter || ! $adapter->is_configured() ) {
			$err = new \WP_Error( 'crazylinks_sync_not_connected', __( 'No email service is connected (CrazyLinks → Settings → Integrations).', 'crazylinks' ) );
			Store::update(
				$id,
				[
					'sync_status' => 'skipped',
					'sync_error'  => $err->get_error_message(),
				]
			);
			return $err;
		}
		$block    = self::block_for( $row );
		$audience = '' !== (string) $row['audience_id'] ? (string) $row['audience_id'] : ( $block && self::is_mailchimp_block( $block ) ? self::audience_for( $block ) : Integrations::default_audience() );
		if ( '' === $audience ) {
			$err = new \WP_Error( 'crazylinks_sync_no_audience', __( 'No Mailchimp audience is chosen for this form or as the site default.', 'crazylinks' ) );
			Store::update(
				$id,
				[
					'sync_status' => 'skipped',
					'sync_error'  => $err->get_error_message(),
				]
			);
			return $err;
		}
		if ( 'signup' === $mode && method_exists( $adapter, 'set_timeout' ) ) {
			/**
			 * Filters the timeout of the push made while the visitor waits for the sign-up answer.
			 *
			 * @param int $seconds Default 5. A slower push falls back to the retry queue.
			 */
			$adapter->set_timeout( (int) apply_filters( 'crazylinks_sync_inline_timeout', 5 ) );
		}
		$confirmed = 'confirmed' === $row['status'];
		$result    = $adapter->push(
			[
				'email'         => (string) $row['email'],
				'status_if_new' => $confirmed ? 'subscribed' : 'pending',
				'consent_at'    => (string) $row['consent_at'],
				'resubscribe'   => ! $confirmed && 'backfill' !== $mode,
			],
			$audience,
			self::tags_for( $block )
		);
		$attempts  = (int) $row['sync_attempts'] + 1;
		if ( true === $result ) {
			$member  = $adapter->last_result();
			$update  = [
				'sync_status'   => 'sent',
				'sync_attempts' => $attempts,
				'sync_error'    => '',
				'synced_at'     => Store::now(),
				'external_id'   => mb_substr( $member['id'], 0, 64 ),
				'audience_id'   => $audience,
			];
			$confirm = 'pending' === $row['status'] && 'subscribed' === $member['status'];
			if ( $confirm ) {
				// Already a subscribed member: Mailchimp sends no confirmation, so nothing to wait for.
				$update['status']       = 'confirmed';
				$update['confirmed_at'] = Store::now();
			}
			Store::update( $id, $update );
			wp_clear_scheduled_hook( self::HOOK_PUSH, [ $id ] );
			if ( $confirm ) {
				$fresh = Store::get( $id );
				if ( $fresh ) {
					Service::announce( 'crazylinks_subscriber_confirmed', $fresh );
				}
			}
			return true;
		}
		$final = $attempts >= self::MAX_ATTEMPTS || in_array( $result->get_error_code(), self::PERMANENT, true );
		Store::update(
			$id,
			[
				'sync_status'   => 'failed',
				'sync_attempts' => $attempts,
				'sync_error'    => $result->get_error_message(),
				'audience_id'   => $audience,
			]
		);
		if ( ! $final && ! wp_next_scheduled( self::HOOK_PUSH, [ $id ] ) ) {
			wp_schedule_single_event( time() + self::retry_delay( $attempts ), self::HOOK_PUSH, [ $id ] );
		}
		return $result;
	}

	/**
	 * Cron: one scheduled retry.
	 *
	 * @param mixed $id Row id.
	 */
	public static function run_scheduled( $id ): void {
		$row = Store::get( (int) $id );
		if ( $row && 'sent' !== $row['sync_status'] ) {
			self::push( (int) $id, 'retry' );
		}
	}

	/**
	 * Admin "Retry sync": starts the attempt count again and pushes now.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows sent.
	 */
	public static function retry( array $ids ): int {
		$sent = 0;
		foreach ( array_values( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			$row = Store::get( $id );
			if ( ! $row || 'sent' === $row['sync_status'] ) {
				continue;
			}
			if ( 'mailchimp' !== $row['destination'] && 'confirmed' !== $row['status'] ) {
				continue; // A local-list row is only sent once it is confirmed here (this site's opt-in owns it).
			}
			wp_clear_scheduled_hook( self::HOOK_PUSH, [ $id ] );
			Store::update(
				$id,
				[
					'sync_status'   => 'queued',
					'sync_attempts' => 0,
					'sync_error'    => '',
				]
			);
			$sent += true === self::push( $id, 'retry' ) ? 1 : 0;
		}
		return $sent;
	}

	/**
	 * Starts a backfill through cron.
	 *
	 * @return true|\WP_Error
	 */
	public static function backfill_start() {
		if ( ! Adapters::active() ) {
			return new \WP_Error( 'crazylinks_sync_not_connected', __( 'Connect Mailchimp under CrazyLinks → Settings → Integrations first.', 'crazylinks' ) );
		}
		if ( '' === Integrations::default_audience() ) {
			return new \WP_Error( 'crazylinks_sync_no_audience', __( 'Choose a default Mailchimp audience under CrazyLinks → Settings → Integrations first.', 'crazylinks' ) );
		}
		update_option(
			self::BACKFILL_OPTION,
			[
				'running'  => true,
				'last_id'  => 0,
				'total'    => Store::backfill_count(),
				'sent'     => 0,
				'failed'   => 0,
				'started'  => time(),
				'finished' => 0,
			],
			false
		);
		wp_unschedule_hook( self::HOOK_BACKFILL );
		wp_schedule_single_event( time(), self::HOOK_BACKFILL );
		return true;
	}

	/**
	 * Backfill progress, or null when none ever ran.
	 *
	 * @return array{running:bool, last_id:int, total:int, sent:int, failed:int, started:int, finished:int}|null
	 */
	public static function backfill_state(): ?array {
		$s = get_option( self::BACKFILL_OPTION );
		if ( ! is_array( $s ) ) {
			return null;
		}
		return [
			'running'  => ! empty( $s['running'] ),
			'last_id'  => (int) ( $s['last_id'] ?? 0 ),
			'total'    => (int) ( $s['total'] ?? 0 ),
			'sent'     => (int) ( $s['sent'] ?? 0 ),
			'failed'   => (int) ( $s['failed'] ?? 0 ),
			'started'  => (int) ( $s['started'] ?? 0 ),
			'finished' => (int) ( $s['finished'] ?? 0 ),
		];
	}

	/**
	 * Cron callback for a backfill batch.
	 */
	public static function backfill_cron(): void {
		self::backfill_batch();
	}

	/**
	 * One backfill batch; from cron it schedules the next one while rows remain.
	 *
	 * @return bool Whether more batches remain.
	 */
	public static function backfill_batch(): bool {
		$state = self::backfill_state();
		if ( ! $state || ! $state['running'] ) {
			return false;
		}
		$rows = Store::backfill_batch( $state['last_id'], self::BATCH );
		foreach ( $rows as $row ) {
			$state['last_id'] = (int) $row['id'];
			if ( true === self::push( (int) $row['id'], 'backfill' ) ) {
				++$state['sent'];
			} else {
				++$state['failed'];
			}
		}
		$more = self::BATCH === count( $rows );
		if ( ! $more ) {
			$state['running']  = false;
			$state['finished'] = time();
		}
		update_option( self::BACKFILL_OPTION, $state, false );
		if ( $more && ! wp_doing_cron() ) {
			return true; // A caller looping in the foreground (WP-CLI) runs the next batch itself.
		}
		if ( $more ) {
			wp_schedule_single_event( time() + 5, self::HOOK_BACKFILL );
		}
		return $more;
	}

	/**
	 * Applies a parsed webhook event to the matching local rows. Unknown addresses change nothing.
	 *
	 * @param array{type:string, email:string, audience_id:string} $event Event.
	 * @return int Rows changed.
	 */
	public static function apply_webhook( array $event ): int {
		if ( ! in_array( $event['type'], [ 'subscribe', 'unsubscribe' ], true ) ) {
			return 0;
		}
		$changed = 0;
		foreach ( Store::find_in_audience( $event['email'], $event['audience_id'] ) as $row ) {
			$id = (int) $row['id'];
			if ( 'subscribe' === $event['type'] && 'confirmed' !== $row['status'] ) {
				Store::update(
					$id,
					[
						'status'       => 'confirmed',
						'confirmed_at' => Store::now(),
					]
				);
				$hook = 'crazylinks_subscriber_confirmed';
			} elseif ( 'unsubscribe' === $event['type'] && 'unsubscribed' !== $row['status'] ) {
				Store::update( $id, [ 'status' => 'unsubscribed' ] );
				$hook = 'crazylinks_subscriber_unsubscribed';
			} else {
				continue;
			}
			++$changed;
			$fresh = Store::get( $id );
			if ( $fresh ) {
				Service::announce( $hook, $fresh );
			}
		}
		return $changed;
	}
}
