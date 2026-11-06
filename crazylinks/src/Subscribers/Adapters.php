<?php
/**
 * Email service adapter registry.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers;

use CrazyLinks\Subscribers\Adapters\Adapter;
use CrazyLinks\Subscribers\Adapters\Mailchimp;

defined( 'ABSPATH' ) || exit;

/**
 * Adapters by id. Built in: `mailchimp`. Others plug in with `crazylinks_subscriber_adapters`.
 */
final class Adapters {

	/**
	 * Every registered adapter, keyed by id.
	 *
	 * @return array<string, Adapter>
	 */
	public static function all(): array {
		/**
		 * Filters the email service adapters. Add an object implementing
		 * CrazyLinks\Subscribers\Adapters\Adapter under its id().
		 *
		 * @param array<string, Adapter> $adapters Default: [ 'mailchimp' => Mailchimp ].
		 */
		$list = (array) apply_filters( 'crazylinks_subscriber_adapters', [ Mailchimp::ID => new Mailchimp() ] );
		$out  = [];
		foreach ( $list as $id => $adapter ) {
			if ( $adapter instanceof Adapter && $adapter->id() === (string) $id && preg_match( '/^[a-z0-9_-]{1,16}$/', (string) $id ) ) {
				$out[ (string) $id ] = $adapter;
			}
		}
		return $out;
	}

	/**
	 * One adapter by id, or null.
	 *
	 * @param string $id Adapter id.
	 */
	public static function get( string $id ): ?Adapter {
		if ( '' === $id || 'local' === $id ) {
			return null;
		}
		return self::all()[ $id ] ?? null;
	}

	/**
	 * The adapter chosen in Settings → Integrations, when it is configured; otherwise null.
	 */
	public static function active(): ?Adapter {
		$adapter = self::get( Integrations::adapter_id() );
		return $adapter && $adapter->is_configured() ? $adapter : null;
	}
}
