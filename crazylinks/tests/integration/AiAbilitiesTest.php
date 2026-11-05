<?php
/**
 * Abilities through core's abilities/v1 REST routes.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Ai\Abilities;
use CrazyLinks\Ai\AiLog;
use CrazyLinks\Ai\Proposals;
use CrazyLinks\Ai\Status;
use CrazyLinks\PageStore;
use CrazyLinks\Tests\Fixtures\AiHelpers;
use CrazyLinks\Tests\Fixtures\FakeProvider;

require_once dirname( __DIR__ ) . '/fixtures/ai/AiHelpers.php';

/**
 * SPEC §10.5.
 */
class AiAbilitiesTest extends TestCase {

	use AiHelpers;

	private const A3 = '01JM00000000000000000000A3';

	private \WP_Post $post;

	public function set_up(): void {
		parent::set_up();
		$this->ai_on();
		$this->ai_settings( [ 'ai_abilities' => Abilities::NAMES ] );
		$this->post = $this->make_page( $this->musician(), 'mcp-page' );
	}

	public function tear_down(): void {
		FakeProvider::reset( false );
		Status::reset();
		parent::tear_down();
	}

	/**
	 * Runs an ability with the method its annotations require.
	 *
	 * @param string               $name   Ability.
	 * @param array<string, mixed> $input  Input.
	 * @param string               $method GET | POST | DELETE.
	 */
	private function ability( string $name, array $input, string $method = 'GET' ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/wp-abilities/v1/abilities/' . $name . '/run' );
		if ( 'POST' === $method ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( (string) wp_json_encode( [ 'input' => $input ] ) );
		} else {
			$req->set_query_params( [ 'input' => $input ] );
		}
		return rest_do_request( $req );
	}

	public function test_registered_with_category_annotations_and_rest_flag(): void {
		$this->assertTrue( wp_has_ability_category( 'crazylinks' ) );
		$expect = [
			'crazylinks/list-pages'            => [ true, false, true ],
			'crazylinks/get-page'              => [ true, false, true ],
			'crazylinks/propose-changes'       => [ true, false, true ],
			'crazylinks/apply-changes'         => [ false, true, true ],
			'crazylinks/get-analytics-summary' => [ true, false, true ],
			'crazylinks/suggest-titles'        => [ true, false, true ],
		];
		foreach ( $expect as $name => [ $readonly, $destructive, $idempotent ] ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name );
			$this->assertSame( 'crazylinks', $ability->get_category() );
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
			$a = $ability->get_meta_item( 'annotations' );
			$this->assertSame( [ $readonly, $destructive, $idempotent ], [ $a['readonly'], $a['destructive'], $a['idempotent'] ], $name );
			$this->assertNotEmpty( $ability->get_input_schema() );
			$this->assertNotEmpty( $ability->get_output_schema() );
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$list = rest_do_request( new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' ) );
		$this->assertContains( 'crazylinks/propose-changes', array_column( $list->get_data(), 'name' ) );
	}

	public function test_list_and_get_page(): void {
		$res = $this->ability( 'crazylinks/list-pages', [ 'per_page' => 10 ] );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertContains( $this->post->ID, array_column( $res->get_data(), 'id' ) );

		$res = $this->ability( 'crazylinks/get-page', [ 'id' => $this->post->ID ] );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( self::A3, $res->get_data()['page']['blocks'][2]['id'] );
	}

	public function test_propose_and_apply_through_mcp_are_logged_and_badged(): void {
		$this->respond(
			[
				[
					'summary'    => 'Renamed.',
					'operations' => [
						[
							'op'    => 'update_block',
							'id'    => self::A3,
							'props' => [ 'title' => 'Via MCP' ],
						],
					],
					'questions'  => [],
				],
			]
		);
		$res = $this->ability(
			'crazylinks/propose-changes',
			[
				'id'          => $this->post->ID,
				'instruction' => 'Rename the single to Via MCP',
			]
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$proposal = $res->get_data();
		$this->assertSame( 'Via MCP', $proposal['preview_document']['blocks'][2]['props']['title'] );
		$this->assertNotSame( 'Via MCP', PageStore::get_document( $this->post->ID )['blocks'][2]['props']['title'], 'Proposing saves nothing' );

		$this->assertSame( 405, $this->ability( 'crazylinks/apply-changes', [ 'id' => $this->post->ID, 'proposal_id' => $proposal['proposal_id'] ], 'POST' )->get_status(), 'Destructive + idempotent needs DELETE' );
		$res = $this->ability(
			'crazylinks/apply-changes',
			[
				'id'          => $this->post->ID,
				'proposal_id' => $proposal['proposal_id'],
			],
			'DELETE'
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data = $res->get_data();
		$this->assertTrue( $data['applied'] );
		$this->assertSame( 'mcp', $data['source'] );
		$this->assertArrayNotHasKey( 'page', $data );
		$this->assertSame( 'Via MCP', PageStore::get_document( $this->post->ID )['blocks'][2]['props']['title'] );

		global $wpdb;
		$row = AiLog::get( (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}crazylinks_ai_log" ) );
		$this->assertSame( 'mcp', $row['source'] );
		$this->assertSame( '1', (string) $row['applied'] );

		$revs  = rest_do_request( new \WP_REST_Request( 'GET', "/crazylinks/v1/pages/{$this->post->ID}/revisions" ) )->get_data();
		$badge = array_column( $revs, 'ai', 'id' )[ $data['revision_id'] ];
		$this->assertSame(
			[
				'source' => 'mcp',
				'label'  => 'AI (external)',
			],
			$badge
		);

		// Idempotent.
		$again = $this->ability(
			'crazylinks/apply-changes',
			[
				'id'          => $this->post->ID,
				'proposal_id' => $proposal['proposal_id'],
			],
			'DELETE'
		)->get_data();
		$this->assertTrue( $again['already_applied'] );
	}

	public function test_agent_written_operations_need_no_site_provider(): void {
		FakeProvider::$configured = false;
		Status::reset();
		$res = $this->ability(
			'crazylinks/propose-changes',
			[
				'id'         => $this->post->ID,
				'summary'    => 'Pin the album.',
				'operations' => [
					[
						'op'     => 'update_block',
						'id'     => '01JM00000000000000000000A2',
						'pinned' => true,
					],
				],
			]
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'Pin link “Listen to Low Tide (album)” to the top', $res->get_data()['change_list'][0]['text'] );
		$this->assertSame( [], FakeProvider::$calls );

		$bad = $this->ability(
			'crazylinks/propose-changes',
			[
				'id'         => $this->post->ID,
				'operations' => [
					[
						'op'       => 'add_block',
						'after_id' => null,
						'block'    => [
							'type'  => 'html',
							'props' => [ 'html' => '<script>1</script>' ],
						],
					],
				],
			]
		);
		$this->assertSame( 400, $bad->get_status() );
	}

	public function test_require_confirmation_blocks_external_apply(): void {
		update_post_meta( $this->post->ID, Proposals::CONFIRM_META, '1' );
		$proposal = $this->ability(
			'crazylinks/propose-changes',
			[
				'id'         => $this->post->ID,
				'operations' => [
					[
						'op'      => 'update_block',
						'id'      => self::A3,
						'visible' => false,
					],
				],
			]
		)->get_data();
		$res      = $this->ability(
			'crazylinks/apply-changes',
			[
				'id'          => $this->post->ID,
				'proposal_id' => $proposal['proposal_id'],
			],
			'DELETE'
		);
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'crazylinks_ai_confirmation_required', $res->get_data()['code'] );

		// The person can still confirm it in the editor (admin source).
		$ok = rest_do_request(
			( function () use ( $proposal ) {
				$r = new \WP_REST_Request( 'POST', "/crazylinks/v1/pages/{$this->post->ID}/ai/apply" );
				$r->set_param( 'proposal_id', $proposal['proposal_id'] );
				return $r;
			} )()
		);
		$this->assertSame( 200, $ok->get_status() );
	}

	public function test_analytics_and_suggest_titles(): void {
		$res = $this->ability(
			'crazylinks/get-analytics-summary',
			[
				'id'   => $this->post->ID,
				'days' => 7,
			]
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 7, $res->get_data()['range']['days'] );

		$this->respond( [ [ 'suggestions' => [ 'One', 'Two' ] ] ] );
		$res = $this->ability( 'crazylinks/suggest-titles', [ 'title' => 'Merch store' ] );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( [ 'One', 'Two' ], $res->get_data()['suggestions'] );
	}

	public function test_disabled_abilities_and_permissions(): void {
		$this->ai_settings( [ 'ai_abilities' => [ 'crazylinks/list-pages' ] ] );
		$res = $this->ability( 'crazylinks/get-page', [ 'id' => $this->post->ID ] );
		$this->assertContains( $res->get_status(), [ 401, 403 ] );
		$this->assertSame( 200, $this->ability( 'crazylinks/list-pages', [] )->get_status() );

		$this->ai_settings( [ 'ai_abilities' => Abilities::NAMES ] );
		foreach ( [ 'subscriber', 'author' ] as $role ) {
			$this->as_role( $role );
			$this->assertSame( 403, $this->ability( 'crazylinks/get-page', [ 'id' => $this->post->ID ] )->get_status(), $role );
			$this->assertSame( 403, $this->ability( 'crazylinks/propose-changes', [ 'id' => $this->post->ID, 'instruction' => 'x' ] )->get_status(), $role );
			$this->assertSame( 403, $this->ability( 'crazylinks/get-analytics-summary', [ 'id' => $this->post->ID ] )->get_status(), $role );
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->ability( 'crazylinks/list-pages', [] )->get_status() );
	}
}
