<?php
/**
 * Shared helpers.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\PageStore;
use CrazyLinks\PostType;
use CrazyLinks\Schema\PageValidator;

/**
 * Base test case.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * A valid document with a profile and two links.
	 *
	 * @return array<string, mixed>
	 */
	protected function doc( array $blocks = [], array $settings = [] ): array {
		return [
			'schema'   => 1,
			'profile'  => [ 'name' => 'Test' ],
			'design'   => [],
			'blocks'   => $blocks ? $blocks : [
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZA',
					'type'  => 'link',
					'props' => [
						'title' => 'One',
						'url'   => 'https://example.com/one',
					],
				],
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZB',
					'type'  => 'link',
					'props' => [
						'title' => 'Two',
						'url'   => 'https://example.com/two?a=1',
					],
				],
			],
			'settings' => $settings,
		];
	}

	/**
	 * Creates a published page from a document (validated as an administrator).
	 */
	protected function make_page( array $doc, string $slug = 'test-page', string $status = 'publish' ): \WP_Post {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$result = ( new PageValidator() )->validate( $doc, get_current_user_id() );
		$this->assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		$id = self::factory()->post->create(
			[
				'post_type'    => PostType::NAME,
				'post_name'    => $slug,
				'post_status'  => $status,
				'post_content' => wp_slash( PageStore::encode( $result->document() ) ),
			]
		);
		return get_post( $id );
	}

	/**
	 * Events table rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function events(): array {
		global $wpdb;
		return (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}crazylinks_events ORDER BY id", ARRAY_A );
	}
}
