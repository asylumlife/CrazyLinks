<?php
/**
 * Shared helpers for the AI tests.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests\Fixtures;

use CrazyLinks\Ai\Status;
use CrazyLinks\Settings;

require_once __DIR__ . '/FakeProvider.php';

/**
 * Settings, the fake provider and REST helpers.
 */
trait AiHelpers {

	/**
	 * The musician document from the eval set.
	 *
	 * @return array<string, mixed>
	 */
	protected function musician(): array {
		return $this->eval_set()['documents']['musician'];
	}

	/**
	 * The eval set.
	 *
	 * @return array<string, mixed>
	 */
	protected function eval_set(): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/evals.json' ), true );
	}

	/**
	 * Merges AI settings.
	 *
	 * @param array<string, mixed> $patch Settings.
	 */
	protected function ai_settings( array $patch ): void {
		update_option( Settings::OPTION, array_merge( Settings::all(), $patch ) );
		Status::reset();
	}

	/**
	 * AI on, consented, fake provider configured.
	 */
	protected function ai_on(): void {
		FakeProvider::register();
		FakeProvider::reset( true );
		$this->ai_settings(
			[
				'ai_enabled' => true,
				'ai_consent' => true,
			]
		);
	}

	/**
	 * Queues canned model responses.
	 *
	 * @param array<int, array<string, mixed>|string> $responses Decoded or raw responses.
	 */
	protected function respond( array $responses ): void {
		foreach ( $responses as $r ) {
			FakeProvider::$responses[] = is_string( $r ) ? $r : (string) wp_json_encode( $r );
		}
	}

	/**
	 * Dispatches a REST request.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route (with namespace).
	 * @param array<string, mixed> $body   JSON body.
	 */
	protected function rest( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, $route );
		if ( $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * A user with a role, set as current.
	 *
	 * @param string $role Role.
	 */
	protected function as_role( string $role ): int {
		$id = self::factory()->user->create( [ 'role' => $role ] );
		wp_set_current_user( $id );
		return $id;
	}
}
