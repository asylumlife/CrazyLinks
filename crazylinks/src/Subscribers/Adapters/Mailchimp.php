<?php
/**
 * Mailchimp Marketing API adapter.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers\Adapters;

use CrazyLinks\Subscribers\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Mailchimp Marketing API v3 (https://mailchimp.com/developer/marketing/api/).
 *
 * The host is always `https://{dc}.api.mailchimp.com/3.0`, where `{dc}` is the data-center suffix of
 * the API key (`…-us6`); nothing from a request, a block or a response can change the host or the
 * path (audience ids and member hashes are validated before they go into the path). HTTP Basic
 * auth with the key as the password, 10-second timeout, no redirects, every response decoded and
 * checked. The key never appears in a returned message.
 *
 * Endpoints used: `GET /ping`, `GET /lists`, `PUT /lists/{id}/members/{hash}` (add or update,
 * `status_if_new` only, so an existing member's status is never changed), `POST
 * /lists/{id}/members/{hash}/tags`, `POST /lists/{id}/members/{hash}/actions/delete-permanent`,
 * `GET` and `POST /lists/{id}/webhooks`.
 */
final class Mailchimp implements Adapter {

	public const ID = 'mailchimp';

	/**
	 * Audience (list) id pattern, shared with schemas/page.v1.json.
	 */
	public const AUDIENCE_PATTERN = '/^[0-9a-z]{6,20}$/';

	/**
	 * API key pattern: hex or alphanumeric secret, a dash, the data center.
	 */
	public const KEY_PATTERN = '/^[0-9A-Za-z]{8,64}-([a-z]{2,6}[0-9]{1,3})$/';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout = 10;

	/**
	 * Result of the last successful push.
	 *
	 * @var array{id:string, status:string}
	 */
	private array $last = [
		'id'     => '',
		'status' => '',
	];

	/**
	 * Constructor.
	 *
	 * @param string|null $key API key; null reads the saved setting.
	 */
	public function __construct( ?string $key = null ) {
		$this->key = null === $key ? Integrations::api_key() : trim( $key );
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return 'Mailchimp';
	}

	/**
	 * Whether a well-formed key is set.
	 */
	public function is_configured(): bool {
		return self::valid_key( $this->key );
	}

	/**
	 * Whether a string looks like a Mailchimp API key.
	 *
	 * @param string $key Key.
	 */
	public static function valid_key( string $key ): bool {
		return (bool) preg_match( self::KEY_PATTERN, $key );
	}

	/**
	 * Whether a string is a well-formed audience id.
	 *
	 * @param string $id Id.
	 */
	public static function valid_audience( string $id ): bool {
		return (bool) preg_match( self::AUDIENCE_PATTERN, $id );
	}

	/**
	 * API root for the key's data center, or '' without a valid key.
	 */
	public function base_url(): string {
		if ( ! preg_match( self::KEY_PATTERN, $this->key, $m ) ) {
			return '';
		}
		return 'https://' . $m[1] . '.api.mailchimp.com/3.0';
	}

	/**
	 * Mailchimp's member id: MD5 of the lowercased address.
	 *
	 * @param string $email Email.
	 */
	public static function subscriber_hash( string $email ): string {
		return md5( strtolower( trim( $email ) ) );
	}

	/**
	 * Sets the request timeout (the sign-up request uses a shorter one).
	 *
	 * @param int $seconds Seconds (1 to 30).
	 */
	public function set_timeout( int $seconds ): void {
		$this->timeout = max( 1, min( 30, $seconds ) );
	}

	/**
	 * GET /ping.
	 *
	 * @return true|\WP_Error
	 */
	public function test() {
		$res = $this->request( 'GET', '/ping' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! isset( $res['data']['health_status'] ) ) {
			return self::bad_response();
		}
		return true;
	}

	/**
	 * GET /lists (up to 1000 audiences, only the fields we show).
	 *
	 * @return array<int, array{id:string, name:string, member_count:int}>|\WP_Error
	 */
	public function audiences() {
		$res = $this->request(
			'GET',
			'/lists',
			null,
			[
				'count'  => '1000',
				'fields' => 'lists.id,lists.name,lists.stats.member_count,total_items',
			]
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! isset( $res['data']['lists'] ) || ! is_array( $res['data']['lists'] ) ) {
			return self::bad_response();
		}
		$out = [];
		foreach ( $res['data']['lists'] as $list ) {
			if ( ! is_array( $list ) || ! is_string( $list['id'] ?? null ) || ! self::valid_audience( $list['id'] ) ) {
				continue;
			}
			$out[] = [
				'id'           => $list['id'],
				'name'         => mb_substr( wp_strip_all_tags( (string) ( $list['name'] ?? '' ) ), 0, 100 ),
				'member_count' => max( 0, (int) ( $list['stats']['member_count'] ?? 0 ) ),
			];
		}
		return $out;
	}

	/**
	 * PUT /lists/{id}/members/{hash} with `status_if_new`, then POST …/tags when there are tags.
	 *
	 * @param array<string, mixed> $subscriber Subscriber (see Adapter::push()).
	 * @param string               $audience_id Audience id.
	 * @param string[]             $tags        Tags.
	 * @return true|\WP_Error
	 */
	public function push( array $subscriber, string $audience_id, array $tags ) {
		$email = strtolower( trim( (string) ( $subscriber['email'] ?? '' ) ) );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'crazylinks_mc_invalid', __( 'The address is not a valid email address.', 'crazylinks' ), self::data( 400, false ) );
		}
		if ( ! self::valid_audience( $audience_id ) ) {
			return self::audience_missing();
		}
		$status = 'subscribed' === ( $subscriber['status_if_new'] ?? '' ) ? 'subscribed' : 'pending';
		$hash   = self::subscriber_hash( $email );
		$path   = '/lists/' . $audience_id . '/members/' . $hash;
		$body   = [
			'email_address' => $email,
			'status_if_new' => $status,
		];
		$signup = (string) ( $subscriber['consent_at'] ?? '' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $signup ) ) {
			$body['timestamp_signup'] = gmdate( 'c', (int) strtotime( $signup . ' UTC' ) );
		}
		$tags = self::clean_tags( $tags );
		if ( $tags ) {
			$body['tags'] = $tags; // Applied to new members; existing members get them through /tags below.
		}
		$res = $this->request( 'PUT', $path, $body, [ 'skip_merge_validation' => 'true' ] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$member = self::member( $res['data'] );
		if ( null === $member ) {
			return self::bad_response();
		}
		// Someone who unsubscribed earlier and just signed up again is asked to confirm again
		// (pending), never subscribed directly. Cleaned and archived members are left alone.
		if ( ! empty( $subscriber['resubscribe'] ) && 'unsubscribed' === $member['status'] ) {
			$again = $this->request( 'PUT', $path, [ 'status' => 'pending' ] );
			if ( is_wp_error( $again ) ) {
				return $again;
			}
			$member = self::member( $again['data'] ) ?? $member;
		}
		if ( $tags ) {
			$tagged = $this->request(
				'POST',
				$path . '/tags',
				[
					'tags'       => array_map(
						static fn( string $t ): array => [
							'name'   => $t,
							'status' => 'active',
						],
						$tags
					),
					'is_syncing' => false,
				]
			);
			if ( is_wp_error( $tagged ) ) {
				return $tagged;
			}
		}
		$this->last = $member;
		return true;
	}

	/**
	 * Last push result.
	 *
	 * @return array{id:string, status:string}
	 */
	public function last_result(): array {
		return $this->last;
	}

	/**
	 * POST /lists/{id}/members/{hash}/actions/delete-permanent (404 counts as removed).
	 *
	 * @param string $email       Email.
	 * @param string $audience_id Audience id.
	 * @return true|\WP_Error
	 */
	public function remove( string $email, string $audience_id ) {
		if ( ! self::valid_audience( $audience_id ) ) {
			return self::audience_missing();
		}
		$res = $this->request( 'POST', '/lists/' . $audience_id . '/members/' . self::subscriber_hash( $email ) . '/actions/delete-permanent' );
		if ( is_wp_error( $res ) && 404 === ( $res->get_error_data()['status'] ?? 0 ) ) {
			return true;
		}
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Parses Mailchimp's form-encoded delivery: `type`, `data[email]`, `data[list_id]`.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array{type:string, email:string, audience_id:string}|\WP_Error
	 */
	public function webhook( \WP_REST_Request $r ) {
		$type = sanitize_key( (string) $r->get_param( 'type' ) );
		$data = $r->get_param( 'data' );
		$data = is_array( $data ) ? $data : [];
		$list = is_string( $data['list_id'] ?? null ) ? strtolower( trim( $data['list_id'] ) ) : '';
		$mail = is_string( $data['email'] ?? null ) ? \CrazyLinks\Subscribers\Store::normalize_email( $data['email'] ) : '';
		if ( '' === $mail || ! self::valid_audience( $list ) ) {
			return new \WP_Error( 'crazylinks_webhook_invalid', __( 'Not a Mailchimp audience event.', 'crazylinks' ), [ 'status' => 400 ] );
		}
		$map = [
			'subscribe'   => 'subscribe',
			'unsubscribe' => 'unsubscribe',
			'cleaned'     => 'unsubscribe',
		];
		return [
			'type'        => $map[ $type ] ?? 'ignored',
			'email'       => $mail,
			'audience_id' => $list,
		];
	}

	/**
	 * Creates the audience webhook (events subscribe, unsubscribe, cleaned; every source), unless
	 * one with the same URL exists.
	 *
	 * @param string $audience_id Audience id.
	 * @param string $url         Webhook URL.
	 * @return string|\WP_Error `created` or `exists`.
	 */
	public function register_webhook( string $audience_id, string $url ) {
		if ( ! self::valid_audience( $audience_id ) ) {
			return self::audience_missing();
		}
		$list = $this->request( 'GET', '/lists/' . $audience_id . '/webhooks' );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		foreach ( (array) ( $list['data']['webhooks'] ?? [] ) as $hook ) {
			if ( is_array( $hook ) && ( $hook['url'] ?? '' ) === $url ) {
				return 'exists';
			}
		}
		$res = $this->request(
			'POST',
			'/lists/' . $audience_id . '/webhooks',
			[
				'url'     => $url,
				'events'  => [
					'subscribe'   => true,
					'unsubscribe' => true,
					'cleaned'     => true,
					'profile'     => false,
					'upemail'     => false,
					'campaign'    => false,
				],
				'sources' => [
					'user'  => true,
					'admin' => true,
					'api'   => true,
				],
			]
		);
		if ( is_wp_error( $res ) ) {
			if ( 400 === ( $res->get_error_data()['status'] ?? 0 ) ) {
				return new \WP_Error( 'crazylinks_mc_webhook_unreachable', __( 'Mailchimp could not reach the webhook address. It must be a public https address that Mailchimp can open from the internet (a local development site will not work).', 'crazylinks' ), $res->get_error_data() );
			}
			return $res;
		}
		return isset( $res['data']['id'] ) ? 'created' : self::bad_response();
	}

	/**
	 * The member fields we keep, or null when the response is not a member.
	 *
	 * @param mixed $data Decoded body.
	 * @return array{id:string, status:string}|null
	 */
	private static function member( $data ): ?array {
		if ( ! is_array( $data ) || ! is_string( $data['id'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/', $data['id'] ) ) {
			return null;
		}
		$status = is_string( $data['status'] ?? null ) ? $data['status'] : '';
		if ( ! in_array( $status, [ 'subscribed', 'unsubscribed', 'cleaned', 'pending', 'transactional', 'archived' ], true ) ) {
			return null;
		}
		return [
			'id'     => $data['id'],
			'status' => $status,
		];
	}

	/**
	 * Tags: trimmed, plain text, at most 100 characters (Mailchimp's limit), unique, at most 10.
	 *
	 * @param array<int, mixed> $tags Tags.
	 * @return string[]
	 */
	public static function clean_tags( array $tags ): array {
		$out = [];
		foreach ( $tags as $tag ) {
			$tag = is_string( $tag ) ? trim( wp_strip_all_tags( $tag ) ) : '';
			if ( '' !== $tag ) {
				$out[] = mb_substr( $tag, 0, 100 );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 10 );
	}

	/**
	 * One request. Returns the status code and decoded body, or a mapped WP_Error.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path below /3.0 (built from constants and validated ids).
	 * @param array<string, mixed>|null $body   JSON body.
	 * @param array<string, string>     $query  Query values.
	 * @return array{code:int, data:array<mixed>}|\WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null, array $query = [] ) {
		$base = $this->base_url();
		if ( '' === $base ) {
			return new \WP_Error( 'crazylinks_mc_no_key', __( 'No Mailchimp API key is saved. Add it under CrazyLinks → Settings → Integrations.', 'crazylinks' ), self::data( 0, false ) );
		}
		if ( ! preg_match( '#^(/[A-Za-z0-9_-]+)+$#', $path ) ) {
			return self::bad_response();
		}
		$url  = $base . $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! preg_match( '/^[a-z]{2,6}[0-9]{1,3}\.api\.mailchimp\.com$/', $host ) ) {
			return self::bad_response();
		}
		$args = [
			'method'              => $method,
			'timeout'             => $this->timeout,
			'redirection'         => 0,
			'user-agent'          => 'CrazyLinks/' . ( defined( 'CRAZYLINKS_VERSION' ) ? CRAZYLINKS_VERSION : '1' ),
			'headers'             => [
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth header.
				'Authorization' => 'Basic ' . base64_encode( 'crazylinks:' . $this->key ),
				'Accept'        => 'application/json',
			],
			'limit_response_size' => MB_IN_BYTES,
		];
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'crazylinks_mc_unreachable', __( 'Could not reach Mailchimp. Check that this site can make outgoing connections, then try again.', 'crazylinks' ), self::data( 0, true ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = '' === $raw ? [] : json_decode( $raw, true, 32 );
		if ( $code >= 200 && $code < 300 ) {
			if ( ! is_array( $data ) ) {
				return self::bad_response();
			}
			return [
				'code' => $code,
				'data' => $data,
			];
		}
		return self::error_for( $code, is_array( $data ) ? $data : [] );
	}

	/**
	 * Maps an error response to a plain-language WP_Error.
	 *
	 * @param int                  $code HTTP status.
	 * @param array<string, mixed> $data Decoded problem document (type, title, status, detail, instance).
	 */
	public static function error_for( int $code, array $data ): \WP_Error {
		$title  = is_string( $data['title'] ?? null ) ? $data['title'] : '';
		$detail = is_string( $data['detail'] ?? null ) ? self::redact( $data['detail'] ) : '';
		if ( 401 === $code || 'API Key Invalid' === $title ) {
			return new \WP_Error( 'crazylinks_mc_bad_key', __( 'Mailchimp did not accept the API key. Copy the whole key again from Mailchimp (Profile → Extras → API keys; it ends in something like -us6) and check that it is not disabled.', 'crazylinks' ), self::data( $code, false ) );
		}
		if ( 403 === $code ) {
			return new \WP_Error( 'crazylinks_mc_forbidden', __( 'Mailchimp refused access for this API key (the account or key may be disabled, or the key lacks permission).', 'crazylinks' ), self::data( $code, false ) );
		}
		if ( 404 === $code ) {
			return self::audience_missing( $code );
		}
		if ( 429 === $code ) {
			return new \WP_Error( 'crazylinks_mc_rate_limited', __( 'Mailchimp is rate limiting this account (too many requests at once). CrazyLinks will try again later.', 'crazylinks' ), self::data( $code, true ) );
		}
		if ( 'Member Exists' === $title ) {
			return new \WP_Error( 'crazylinks_mc_member_exists', __( 'This address is already in the Mailchimp audience with a different status, so it was left as it is.', 'crazylinks' ), self::data( $code, false ) );
		}
		if ( in_array( $title, [ 'Member In Compliance State', 'Forgotten Email Not Subscribed' ], true ) ) {
			return new \WP_Error( 'crazylinks_mc_member_state', __( 'This address unsubscribed from or was removed from the Mailchimp audience earlier, so Mailchimp will not add it back automatically. The person can re-subscribe through a Mailchimp sign-up form.', 'crazylinks' ), self::data( $code, false ) );
		}
		if ( $code >= 500 ) {
			/* translators: %d: HTTP status code. */
			return new \WP_Error( 'crazylinks_mc_server', sprintf( __( 'Mailchimp had a temporary problem (HTTP %d). CrazyLinks will try again later.', 'crazylinks' ), $code ), self::data( $code, true ) );
		}
		/* translators: 1: HTTP status code, 2: Mailchimp's explanation. */
		return new \WP_Error( 'crazylinks_mc_rejected', trim( sprintf( __( 'Mailchimp refused the request (HTTP %1$d). %2$s', 'crazylinks' ), $code, $detail ) ), self::data( $code, false ) );
	}

	/**
	 * Mailchimp's explanation without email addresses, trimmed.
	 *
	 * @param string $text Text.
	 */
	private static function redact( string $text ): string {
		$text = (string) preg_replace( '/[^\s@]+@[^\s@]+/', '(address)', wp_strip_all_tags( $text ) );
		return mb_substr( trim( $text ), 0, 200 );
	}

	/**
	 * Error data.
	 *
	 * @param int  $status HTTP status (0 for none).
	 * @param bool $retry  A later attempt may work.
	 * @return array{status:int, retry:bool}
	 */
	private static function data( int $status, bool $retry ): array {
		return [
			'status' => $status,
			'retry'  => $retry,
		];
	}

	/**
	 * Audience not found.
	 *
	 * @param int $code HTTP status.
	 */
	private static function audience_missing( int $code = 404 ): \WP_Error {
		return new \WP_Error( 'crazylinks_mc_audience_not_found', __( 'Mailchimp could not find that audience. Choose the audience again under CrazyLinks → Settings → Integrations, or on the sign-up block.', 'crazylinks' ), self::data( $code, false ) );
	}

	/**
	 * Unexpected answer.
	 */
	private static function bad_response(): \WP_Error {
		return new \WP_Error( 'crazylinks_mc_bad_response', __( 'Mailchimp sent an answer CrazyLinks did not understand. Try again in a few minutes.', 'crazylinks' ), self::data( 0, true ) );
	}
}
