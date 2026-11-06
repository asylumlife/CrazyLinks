<?php
/**
 * Email service adapter contract.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * One email service (Mailchimp now; Kit, beehiiv or a generic webhook later). Register an
 * implementation with the `crazylinks_subscriber_adapters` filter (see Registry).
 *
 * Every method that talks to the service returns a WP_Error with a plain-language message the
 * site owner can act on. The error data carries `status` (HTTP status, 0 for a transport failure)
 * and `retry` (bool: a later attempt may succeed, for example after a rate limit).
 */
interface Adapter {

	/**
	 * Stable id stored with subscriber rows and settings (`mailchimp`).
	 */
	public function id(): string;

	/**
	 * Name shown to the site owner.
	 */
	public function label(): string;

	/**
	 * Whether the adapter has what it needs to make requests (for example an API key).
	 */
	public function is_configured(): bool;

	/**
	 * Checks the credentials with one cheap request.
	 *
	 * @return true|\WP_Error
	 */
	public function test();

	/**
	 * Lists the audiences (lists) the credentials can reach.
	 *
	 * @return array<int, array{id:string, name:string, member_count:int}>|\WP_Error
	 */
	public function audiences();

	/**
	 * Idempotent upsert of one subscriber into an audience. Never downgrades an existing member's
	 * status. After a successful call, last_result() describes the member.
	 *
	 * @param array<string, mixed> $subscriber `email` (required), `status_if_new` (`pending` or
	 *                                         `subscribed`), `consent_at` (UTC MySQL datetime),
	 *                                         `resubscribe` (bool: the person just asked again, so a
	 *                                         member who unsubscribed may be asked to confirm again).
	 * @param string               $audience_id Audience id.
	 * @param string[]             $tags        Tags to add.
	 * @return true|\WP_Error
	 */
	public function push( array $subscriber, string $audience_id, array $tags );

	/**
	 * What the last successful push() learned: `id` (external member id) and `status` (the
	 * member's status at the service, for example `pending` or `subscribed`).
	 *
	 * @return array{id:string, status:string}
	 */
	public function last_result(): array;

	/**
	 * Removes an address from an audience (personal-data eraser). An address that is not there
	 * counts as removed.
	 *
	 * @param string $email       Email.
	 * @param string $audience_id Audience id.
	 * @return true|\WP_Error
	 */
	public function remove( string $email, string $audience_id );

	/**
	 * Parses an incoming webhook delivery (the caller has already checked the shared secret).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array{type:string, email:string, audience_id:string}|\WP_Error `type` is
	 *         `subscribe`, `unsubscribe` or `ignored`.
	 */
	public function webhook( \WP_REST_Request $r );
}
