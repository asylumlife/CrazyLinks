<?php
/**
 * The MCP route (SPEC §10.7): exposure meta, the editing guide, preview and apply of agent-written
 * operations, pending confirmations, and the CrazyLinks MCP server.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Admin\SettingsPage;
use CrazyLinks\Ai\Abilities;
use CrazyLinks\Ai\AiLog;
use CrazyLinks\Ai\EditingGuide;
use CrazyLinks\Ai\ExternalOps;
use CrazyLinks\Ai\Limits;
use CrazyLinks\Ai\McpServer;
use CrazyLinks\Ai\McpStatus;
use CrazyLinks\Ai\OpsSchema;
use CrazyLinks\Ai\OpsValidator;
use CrazyLinks\Ai\Proposals;
use CrazyLinks\PageStore;
use CrazyLinks\Settings;
use CrazyLinks\Tests\Fixtures\AiHelpers;
use WP\MCP\Core\McpAdapter;

require_once dirname( __DIR__ ) . '/fixtures/ai/AiHelpers.php';
require_once dirname( __DIR__ ) . '/fixtures/mcp/FakeMcpAdapter.php';

/**
 * SPEC §10.7.
 */
class AiMcpTest extends TestCase {

	use AiHelpers;

	private const A3 = '01JM00000000000000000000A3';

	private \WP_Post $post;

	private int $editor;

	public function set_up(): void {
		parent::set_up();
		$this->ai_settings( [ 'ai_abilities' => Abilities::NAMES ] );
		$this->post   = $this->make_page( $this->musician(), 'mcp-route' );
		$this->editor = $this->as_role( 'editor' );
		if ( property_exists( McpAdapter::class, 'calls' ) ) {
			McpAdapter::reset();
		}
	}

	public function tear_down(): void {
		$this->ai_settings( [ 'ai_abilities' => Abilities::NAMES ] );
		$this->reregister();
		parent::tear_down();
	}

	/**
	 * Registers the CrazyLinks abilities again with the current settings (they are registered once per
	 * request in production; the test process is one long request).
	 */
	private function reregister(): void {
		global $wp_current_filter;
		foreach ( Abilities::NAMES as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			( new Abilities() )->register_abilities();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Runs an ability through core's REST route.
	 *
	 * @param string               $name   Ability.
	 * @param array<string, mixed> $input  Input.
	 * @param string               $method GET | POST.
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

	/**
	 * Rename the single.
	 *
	 * @param string $title New title.
	 * @return array<int, array<string, mixed>>
	 */
	private function rename( string $title = 'Via Claude' ): array {
		return [
			[
				'op'    => 'update_block',
				'id'    => self::A3,
				'props' => [ 'title' => $title ],
			],
		];
	}

	private function title(): string {
		return (string) PageStore::get_document( get_post( $this->post->ID ) )['blocks'][2]['props']['title'];
	}

	private function revisions(): int {
		return count( wp_get_post_revisions( $this->post->ID ) );
	}

	private function proposal_transients(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_crazylinks\\_aip\\_%'" );
	}

	private function log_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}crazylinks_ai_log" );
	}

	// ---------------------------------------------------------------------------------------------
	// Registration and exposure.

	public function test_new_abilities_are_registered_in_order_with_annotations(): void {
		$this->assertSame(
			[ 'crazylinks/list-pages', 'crazylinks/get-page', 'crazylinks/get-editing-guide', 'crazylinks/preview-operations', 'crazylinks/apply-operations', 'crazylinks/propose-changes', 'crazylinks/apply-changes', 'crazylinks/get-analytics-summary', 'crazylinks/suggest-titles' ],
			Abilities::NAMES
		);
		$expect = [
			'crazylinks/get-editing-guide'  => [ true, false, true ],
			'crazylinks/preview-operations' => [ true, false, true ],
			'crazylinks/apply-operations'   => [ false, true, false ],
		];
		foreach ( $expect as $name => [ $readonly, $destructive, $idempotent ] ) {
			$a = wp_get_ability( $name )->get_meta_item( 'annotations' );
			$this->assertSame( [ $readonly, $destructive, $idempotent ], [ $a['readonly'], $a['destructive'], $a['idempotent'] ], $name );
			$this->assertNotSame( '', wp_get_ability( $name )->get_description() );
		}
		$ops = wp_get_ability( 'crazylinks/preview-operations' )->get_input_schema()['properties']['operations'];
		$this->assertSame( 'array', $ops['type'] );
		$this->assertSame( 'object', $ops['items']['type'] );
	}

	public function test_exposure_meta_follows_the_enable_switch_and_rest_flag_stays(): void {
		$this->ai_settings( [ 'ai_abilities' => [ 'crazylinks/get-page', 'crazylinks/apply-operations' ] ] );
		$this->reregister();
		foreach ( Abilities::NAMES as $name ) {
			$on      = in_array( $name, [ 'crazylinks/get-page', 'crazylinks/apply-operations' ], true );
			$ability = wp_get_ability( $name );
			$this->assertSame( $on, $ability->get_meta_item( 'public' ), $name );
			$this->assertSame(
				[
					'public' => $on,
					'type'   => 'tool',
				],
				$ability->get_meta_item( 'mcp' ),
				$name
			);
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ), $name );
		}
		$list = rest_do_request( new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' ) );
		$this->assertContains( 'crazylinks/list-pages', array_column( $list->get_data(), 'name' ), 'Disabled abilities stay listed in REST (the permission callback gates them)' );
	}

	public function test_settings_sanitizer_keeps_the_mcp_switch_idempotent(): void {
		$this->assertTrue( Settings::defaults()['ai_mcp_server'] );
		$off = Settings::sanitize( [ 'ai_section' => '1' ] );
		$this->assertFalse( $off['ai_mcp_server'] );
		$this->assertSame( $off, Settings::sanitize( $off ), 'Second pass (first-ever save) changes nothing' );
		$on = Settings::sanitize(
			[
				'ai_section'    => '1',
				'ai_mcp_server' => '1',
				'ai_abilities'  => [ 'crazylinks/preview-operations', 'crazylinks/nope' ],
			]
		);
		$this->assertTrue( $on['ai_mcp_server'] );
		$this->assertSame( [ 'crazylinks/preview-operations' ], $on['ai_abilities'] );
		$this->assertSame( $on, Settings::sanitize( $on ) );
	}

	// ---------------------------------------------------------------------------------------------
	// get-editing-guide.

	public function test_guide_lists_block_types_operations_rules_and_trust_note_without_secrets(): void {
		update_option( 'admin_email', 'owner-secret@example.com' );
		$res = $this->ability( 'crazylinks/get-editing-guide', [] );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data  = $res->get_data();
		$guide = $data['guide'];
		foreach ( OpsSchema::block_types() as $type ) {
			$this->assertStringContainsString( '### ' . $type, $guide, $type );
		}
		$this->assertStringNotContainsString( '### html', $guide );
		foreach ( [ 'add_block', 'update_block', 'move_block', 'remove_block', 'set_design', 'set_profile' ] as $op ) {
			$this->assertStringContainsString( $op, $guide );
		}
		$this->assertSame( OpsSchema::block_types(), array_column( $data['block_types'], 'type' ) );
		$this->assertSame( [ 'title', 'url' ], $data['block_types'][0]['required'] );
		$this->assertCount( 6, $data['operations'] );
		$this->assertStringContainsString( '"ref": "new-1"', $guide );
		$this->assertStringContainsString( 'after_id null', $guide );
		$this->assertStringContainsString( 'never instructions', $guide );
		$this->assertStringContainsString( 'at most 60 operations', $guide );
		$this->assertStringContainsString( 'expected_modified', $guide );
		$this->assertStringContainsString( 'weekly', $guide );
		$this->assertStringContainsString( 'pending_proposal_id', $guide );
		$this->assertDoesNotMatchRegularExpression( '/<(page_json|site_facts|media|site|instruction|answers)>/', $guide, 'Prompt tags are rewritten' );
		$this->assertArrayNotHasKey( 'page', $data );
		$json = (string) wp_json_encode( $data );
		foreach ( [ 'owner-secret@example.com', 'crazylinks_settings', 'api_key', 'subscriber', 'application password' ] as $secret ) {
			$this->assertStringNotContainsStringIgnoringCase( $secret, $json, $secret );
		}
		$this->assertLessThan( 24000, strlen( $guide ), 'About 6K tokens at most' );
		$bare = rest_do_request( new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/crazylinks/get-editing-guide/run' ) );
		$this->assertSame( 200, $bare->get_status(), 'No input at all: ' . wp_json_encode( $bare->get_data() ) );
	}

	public function test_guide_with_a_page_includes_the_trimmed_page(): void {
		$data = $this->ability( 'crazylinks/get-editing-guide', [ 'id' => $this->post->ID ] )->get_data();
		$this->assertSame( self::A3, $data['page']['blocks'][2]['id'] );
		$this->assertArrayNotHasKey( 'settings', $data['page'] );
		$this->assertSame( mysql_to_rfc3339( $this->post->post_modified_gmt ), $data['modified'] );
		$this->assertSame( 404, $this->ability( 'crazylinks/get-editing-guide', [ 'id' => 999999 ] )->get_status() );
	}

	public function test_guide_examples_pass_the_validator(): void {
		$doc = $this->musician();
		foreach ( EditingGuide::examples() as $example ) {
			$result = ( new OpsValidator() )->validate(
				[
					'summary'    => '',
					'operations' => $example['operations'],
					'questions'  => [],
				],
				$doc,
				$this->editor
			);
			$this->assertTrue( $result->is_valid(), $example['title'] . ': ' . $result->error_text() );
		}
	}

	// ---------------------------------------------------------------------------------------------
	// preview-operations.

	public function test_preview_validates_without_saving_storing_or_revising(): void {
		$modified    = get_post( $this->post->ID )->post_modified_gmt;
		$revisions   = $this->revisions();
		$transients  = $this->proposal_transients();
		$logs        = $this->log_rows();
		$this->assertSame( 405, $this->ability( 'crazylinks/preview-operations', [ 'id' => $this->post->ID, 'operations' => $this->rename() ], 'POST' )->get_status(), 'Read-only abilities run with GET' );
		$res = $this->ability(
			'crazylinks/preview-operations',
			[
				'id'         => $this->post->ID,
				'operations' => $this->rename(),
			]
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data = $res->get_data();
		$this->assertTrue( $data['valid'] );
		$this->assertSame( [], $data['errors'] );
		$this->assertSame( 'Via Claude', $data['preview_document']['blocks'][2]['props']['title'] );
		$this->assertSame( 'update', $data['change_list'][0]['kind'] );
		$this->assertNotSame( '', $data['summary'] );
		$this->assertSame( 'New single: Harbor Lights', $this->title() );
		$this->assertSame( $modified, get_post( $this->post->ID )->post_modified_gmt );
		$this->assertSame( $revisions, $this->revisions() );
		$this->assertSame( $transients, $this->proposal_transients() );
		$this->assertSame( $logs, $this->log_rows() );
	}

	public function test_preview_of_invalid_operations_is_a_normal_result(): void {
		$res = $this->ability(
			'crazylinks/preview-operations',
			[
				'id'         => $this->post->ID,
				'operations' => [
					[
						'op'    => 'update_block',
						'id'    => self::A3,
						'props' => [ 'url' => 'javascript:alert(1)' ],
					],
					[
						'op' => 'remove_block',
						'id' => '01JM0000000000000000000ZZZ',
					],
				],
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertFalse( $data['valid'] );
		$this->assertGreaterThanOrEqual( 2, count( $data['errors'] ) );
		$this->assertSame( '/operations/0/props/url', $data['errors'][0]['path'] );
		$this->assertSame( 'New single: Harbor Lights', $data['preview_document']['blocks'][2]['props']['title'], 'Unchanged page when invalid' );
	}

	public function test_preview_permissions_and_missing_page(): void {
		$this->assertSame( 404, $this->ability( 'crazylinks/preview-operations', [ 'id' => 999999, 'operations' => [] ] )->get_status() );
		foreach ( [ 'subscriber', 'author' ] as $role ) {
			$this->as_role( $role );
			$this->assertSame( 403, $this->ability( 'crazylinks/preview-operations', [ 'id' => $this->post->ID, 'operations' => $this->rename() ] )->get_status(), $role );
			$this->assertFalse( wp_get_ability( 'crazylinks/preview-operations' )->check_permissions( [ 'id' => $this->post->ID, 'operations' => [] ] ), $role );
			$this->assertSame( 403, $this->ability( 'crazylinks/get-editing-guide', [] )->get_status(), $role );
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->ability( 'crazylinks/preview-operations', [ 'id' => $this->post->ID, 'operations' => $this->rename() ] )->get_status() );
	}

	// ---------------------------------------------------------------------------------------------
	// apply-operations.

	public function test_apply_saves_with_revision_badge_log_and_limit_count(): void {
		$this->ai_settings( [ 'ai_user_hourly' => 2 ] );
		$revisions = $this->revisions();
		$this->assertSame( 405, $this->ability( 'crazylinks/apply-operations', [ 'id' => $this->post->ID, 'operations' => $this->rename() ], 'DELETE' )->get_status(), 'Not idempotent: POST' );
		$res = $this->ability(
			'crazylinks/apply-operations',
			[
				'id'                => $this->post->ID,
				'operations'        => $this->rename(),
				'summary'           => 'Renamed the single for Claude.',
				'expected_modified' => mysql_to_rfc3339( $this->post->post_modified_gmt ),
			],
			'POST'
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data = $res->get_data();
		$this->assertTrue( $data['applied'] );
		$this->assertIsInt( $data['revision_id'] );
		$this->assertNull( $data['pending_proposal_id'] );
		$this->assertSame( 'mcp', $data['source'] );
		$this->assertSame( 'Renamed the single for Claude.', $data['summary'] );
		$this->assertSame( get_permalink( $this->post->ID ), $data['link'] );
		$this->assertSame( mysql_to_rfc3339( get_post( $this->post->ID )->post_modified_gmt ), $data['modified'] );
		$this->assertSame( 'Via Claude', $this->title() );
		$this->assertSame( $revisions + 1, $this->revisions() );

		$revs  = rest_do_request( new \WP_REST_Request( 'GET', "/crazylinks/v1/pages/{$this->post->ID}/revisions" ) )->get_data();
		$badge = array_column( $revs, 'ai', 'id' )[ $data['revision_id'] ];
		$this->assertSame( 'AI (external)', $badge['label'] );

		global $wpdb;
		$row = AiLog::get( (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}crazylinks_ai_log" ) );
		$this->assertSame( 'mcp', $row['source'] );
		$this->assertSame( 'external', $row['kind'] );
		$this->assertSame( 'external', $row['model'] );
		$this->assertSame( '0', (string) $row['prompt_tokens'] );
		$this->assertSame( '1', (string) $row['applied'] );
		$this->assertSame( (string) $data['revision_id'], (string) $row['revision_id'] );
		$this->assertSame( 0, Limits::used(), 'No site tokens' );

		// The apply used one of the two requests this hour, shared with API proposals.
		$this->assertTrue( Limits::hit( $this->editor ) );
		$this->assertWPError( Limits::hit( $this->editor ) );
		$over = $this->ability( 'crazylinks/apply-operations', [ 'id' => $this->post->ID, 'operations' => $this->rename( 'Again' ) ], 'POST' );
		$this->assertSame( 429, $over->get_status() );
		$this->assertSame( 'crazylinks_ai_rate_limited', $over->get_data()['code'] );
		$this->assertSame( 'Via Claude', $this->title() );
	}

	public function test_apply_refuses_a_stale_expected_modified(): void {
		$res = $this->ability(
			'crazylinks/apply-operations',
			[
				'id'                => $this->post->ID,
				'operations'        => $this->rename(),
				'expected_modified' => '2001-01-01T00:00:00',
			],
			'POST'
		);
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'crazylinks_conflict', $res->get_data()['code'] );
		$this->assertSame( mysql_to_rfc3339( $this->post->post_modified_gmt ), $res->get_data()['data']['modified'] );
		$this->assertStringContainsString( mysql_to_rfc3339( $this->post->post_modified_gmt ), $res->get_data()['message'], 'MCP clients only see the message' );
		$this->assertSame( 'New single: Harbor Lights', $this->title() );
	}

	public function test_apply_with_an_idempotency_key_applies_once(): void {
		$revisions = $this->revisions();
		$input     = [
			'id'              => $this->post->ID,
			'operations'      => $this->rename(),
			'idempotency_key' => 'rename-single_1',
		];
		$first     = $this->ability( 'crazylinks/apply-operations', $input, 'POST' )->get_data();
		$again     = $this->ability( 'crazylinks/apply-operations', $input, 'POST' );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( $first['revision_id'], $again->get_data()['revision_id'] );
		$this->assertTrue( $again->get_data()['replayed'] );
		$this->assertSame( $revisions + 1, $this->revisions() );
		$this->assertSame( 400, $this->ability( 'crazylinks/apply-operations', array_merge( $input, [ 'idempotency_key' => 'bad key!' ] ), 'POST' )->get_status() );
	}

	public function test_invalid_operations_are_rejected_with_the_errors_in_the_message(): void {
		$res = $this->ability(
			'crazylinks/apply-operations',
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
			],
			'POST'
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'crazylinks_ai_invalid_ops', $res->get_data()['code'] );
		$this->assertStringContainsString( '/operations/0/block/type', $res->get_data()['message'] );
		$this->assertNotEmpty( $res->get_data()['data']['errors'] );
	}

	public function test_require_confirmation_stores_a_pending_change_for_the_editor(): void {
		update_post_meta( $this->post->ID, Proposals::CONFIRM_META, '1' );
		$agent     = $this->as_role( 'editor' );
		$revisions = $this->revisions();
		$res       = $this->ability(
			'crazylinks/apply-operations',
			[
				'id'         => $this->post->ID,
				'operations' => $this->rename(),
				'summary'    => 'Rename for the tour.',
			],
			'POST'
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data = $res->get_data();
		$this->assertFalse( $data['applied'] );
		$this->assertNull( $data['revision_id'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{24}$/', $data['pending_proposal_id'] );
		$this->assertNotSame( '', $data['message'] );
		$this->assertSame( 'New single: Harbor Lights', $this->title() );
		$this->assertSame( $revisions, $this->revisions() );

		// The agent cannot confirm its own change.
		$self = $this->ability(
			'crazylinks/apply-changes',
			[
				'id'          => $this->post->ID,
				'proposal_id' => $data['pending_proposal_id'],
			],
			'DELETE'
		);
		$this->assertSame( 409, $self->get_status() );

		// Another editor sees it in the editor's AI tab and applies it.
		wp_set_current_user( $this->editor );
		$list = $this->rest( 'GET', "/crazylinks/v1/pages/{$this->post->ID}/ai/pending" );
		$this->assertSame( 200, $list->get_status(), wp_json_encode( $list->get_data() ) );
		$this->assertCount( 1, $list->get_data() );
		$pending = $list->get_data()[0];
		$this->assertSame( $data['pending_proposal_id'], $pending['proposal_id'] );
		$this->assertTrue( $pending['external'] );
		$this->assertSame( 'Rename for the tour.', $pending['summary'] );
		$this->assertSame( get_userdata( $agent )->display_name, $pending['author'] );
		$this->assertSame( 'Via Claude', $pending['preview_document']['blocks'][2]['props']['title'] );

		$ok = $this->rest( 'POST', "/crazylinks/v1/pages/{$this->post->ID}/ai/apply", [ 'proposal_id' => $pending['proposal_id'] ] );
		$this->assertSame( 200, $ok->get_status(), wp_json_encode( $ok->get_data() ) );
		$this->assertSame( 'Via Claude', $this->title() );
		$this->assertSame( 'mcp', get_metadata( 'post', (int) $ok->get_data()['revision_id'], Proposals::REVISION_META, true ), 'Badged as external' );
		$this->assertSame( [], $this->rest( 'GET', "/crazylinks/v1/pages/{$this->post->ID}/ai/pending" )->get_data() );
	}

	public function test_pending_changes_can_be_discarded_and_are_page_scoped(): void {
		update_post_meta( $this->post->ID, Proposals::CONFIRM_META, '1' );
		$id = $this->ability( 'crazylinks/apply-operations', [ 'id' => $this->post->ID, 'operations' => $this->rename() ], 'POST' )->get_data()['pending_proposal_id'];

		$other = $this->make_page( $this->musician(), 'other-page' );
		wp_set_current_user( $this->editor );
		$this->assertSame( [], $this->rest( 'GET', "/crazylinks/v1/pages/{$other->ID}/ai/pending" )->get_data() );
		$this->assertFalse( $this->rest( 'POST', "/crazylinks/v1/pages/{$other->ID}/ai/discard", [ 'proposal_id' => $id ] )->get_data()['discarded'] );

		$this->as_role( 'subscriber' );
		$this->assertSame( 403, $this->rest( 'GET', "/crazylinks/v1/pages/{$this->post->ID}/ai/pending" )->get_status() );

		wp_set_current_user( $this->editor );
		$this->assertTrue( $this->rest( 'POST', "/crazylinks/v1/pages/{$this->post->ID}/ai/discard", [ 'proposal_id' => $id ] )->get_data()['discarded'] );
		$this->assertSame( [], $this->rest( 'GET', "/crazylinks/v1/pages/{$this->post->ID}/ai/pending" )->get_data() );
		$this->assertSame( 410, $this->rest( 'POST', "/crazylinks/v1/pages/{$this->post->ID}/ai/apply", [ 'proposal_id' => $id ] )->get_status() );
	}

	public function test_apply_is_refused_while_someone_else_holds_the_editor_lock(): void {
		$other = self::factory()->user->create( [ 'role' => 'editor' ] );
		update_post_meta( $this->post->ID, '_edit_lock', time() . ':' . $other );
		$res = $this->ability( 'crazylinks/apply-operations', [ 'id' => $this->post->ID, 'operations' => $this->rename() ], 'POST' );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'crazylinks_page_locked', $res->get_data()['code'] );
		$this->assertSame( 'New single: Harbor Lights', $this->title() );

		// The agent's own lock (same account) does not block it.
		update_post_meta( $this->post->ID, '_edit_lock', time() . ':' . $this->editor );
		$this->assertSame( 200, $this->ability( 'crazylinks/apply-operations', [ 'id' => $this->post->ID, 'operations' => $this->rename() ], 'POST' )->get_status() );
	}

	public function test_disabled_or_forbidden_apply(): void {
		$this->ai_settings( [ 'ai_abilities' => [ 'crazylinks/preview-operations' ] ] );
		$res = $this->ability( 'crazylinks/apply-operations', [ 'id' => $this->post->ID, 'operations' => $this->rename() ], 'POST' );
		$this->assertContains( $res->get_status(), [ 401, 403 ] );
		$this->assertSame( 'crazylinks_ability_disabled', $res->get_data()['code'] );
		$this->assertWPError( wp_get_ability( 'crazylinks/apply-operations' )->check_permissions( [ 'id' => $this->post->ID ] ) );

		$this->ai_settings( [ 'ai_abilities' => Abilities::NAMES ] );
		$this->as_role( 'author' );
		$this->assertSame( 403, $this->ability( 'crazylinks/apply-operations', [ 'id' => $this->post->ID, 'operations' => $this->rename() ], 'POST' )->get_status() );
		$this->assertSame( 'New single: Harbor Lights', $this->title() );
	}

	public function test_external_ops_need_no_site_provider(): void {
		$this->ai_settings(
			[
				'ai_enabled' => false,
				'ai_consent' => false,
			]
		);
		$data = ExternalOps::apply( get_post( $this->post->ID ), [ 'operations' => $this->rename() ] );
		$this->assertIsArray( $data );
		$this->assertTrue( $data['applied'] );
	}

	// ---------------------------------------------------------------------------------------------
	// The MCP server.

	public function test_mcp_server_is_created_with_enabled_tools_only(): void {
		if ( ! property_exists( McpAdapter::class, 'calls' ) ) {
			$this->markTestSkipped( 'The real MCP Adapter is loaded.' );
		}
		$this->ai_settings( [ 'ai_abilities' => [ 'crazylinks/apply-operations', 'crazylinks/list-pages', 'crazylinks/get-editing-guide' ] ] );
		do_action( 'mcp_adapter_init', new McpAdapter() );
		$this->assertCount( 1, McpAdapter::$calls );
		$call = McpAdapter::$calls[0];
		$this->assertSame( 'crazylinks', $call['server_id'] );
		$this->assertSame( 'crazylinks', $call['server_route_namespace'] );
		$this->assertSame( 'mcp', $call['server_route'] );
		$this->assertSame( 'CrazyLinks', $call['server_name'] );
		$this->assertStringContainsString( 'crazylinks-get-editing-guide', $call['server_description'] );
		$this->assertSame( CRAZYLINKS_VERSION, $call['server_version'] );
		$this->assertSame( [ 'WP\MCP\Transport\HttpTransport' ], $call['mcp_transports'] );
		$this->assertSame( 'WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler', $call['error_handler'] );
		$this->assertNull( $call['observability_handler'] );
		$this->assertSame( [ 'crazylinks/list-pages', 'crazylinks/get-editing-guide', 'crazylinks/apply-operations' ], $call['tools'] );
		$this->assertSame( [], $call['resources'] );
		$this->assertSame( [], $call['prompts'] );

		$permission = $call['transport_permission_callback'];
		$request    = new \WP_REST_Request( 'POST', '/crazylinks/mcp' );
		wp_set_current_user( 0 );
		$denied = $permission( $request );
		$this->assertWPError( $denied );
		$this->assertSame( 401, $denied->get_error_data()['status'] );
		$this->as_role( 'subscriber' );
		$denied = $permission( $request );
		$this->assertWPError( $denied );
		$this->assertSame( 403, $denied->get_error_data()['status'] );
		wp_set_current_user( $this->editor );
		$this->assertTrue( user_can( $this->editor, 'edit_crazylinks_pages' ) );
		$this->assertTrue( $permission( $request ) );

		$this->assertTrue( McpStatus::adapter_active() );
		$this->assertSame( rest_url( 'crazylinks/mcp' ), McpStatus::endpoint() );
		$this->assertSame( rest_url( 'mcp/mcp-adapter-default-server' ), McpStatus::default_endpoint() );
	}

	public function test_mcp_server_respects_the_setting_and_filter_and_survives_errors(): void {
		if ( ! property_exists( McpAdapter::class, 'calls' ) ) {
			$this->markTestSkipped( 'The real MCP Adapter is loaded.' );
		}
		$this->ai_settings( [ 'ai_mcp_server' => false ] );
		do_action( 'mcp_adapter_init', new McpAdapter() );
		$this->assertSame( [], McpAdapter::$calls );

		$this->ai_settings( [ 'ai_mcp_server' => true ] );
		add_filter( 'crazylinks_mcp_server', '__return_false' );
		do_action( 'mcp_adapter_init', new McpAdapter() );
		remove_filter( 'crazylinks_mcp_server', '__return_false' );
		$this->assertSame( [], McpAdapter::$calls );

		$log = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Keeps the debug log line out of the test output.
		try {
			McpAdapter::$result = new \WP_Error( 'duplicate_server_id', 'Server with ID "crazylinks" already exists.' );
			( new McpServer() )->create( new McpAdapter() );
			McpAdapter::$result = null;
			McpAdapter::$throw  = true;
			( new McpServer() )->create( new McpAdapter() );
			( new McpServer() )->create( 'not an adapter' );
		} finally {
			ini_set( 'error_log', (string) $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore.
		}
		$this->assertCount( 2, McpAdapter::$calls, 'Errors and exceptions are swallowed' );
	}

	public function test_settings_box_and_snippets_have_endpoints_and_placeholders_only(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		( new SettingsPage() )->ai_field( [ 'key' => 'ai_mcp' ] );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="crazylinks_settings[ai_mcp_server]"', $html );
		$this->assertStringContainsString( esc_html( rest_url( 'crazylinks/mcp' ) ), $html );
		$this->assertStringContainsString( esc_html( rest_url( 'mcp/mcp-adapter-default-server' ) ), $html );
		$this->assertStringContainsString( '@automattic/mcp-wordpress-remote@latest', $html );
		$this->assertStringContainsString( 'claude mcp add --transport http crazylinks', $html );
		$this->assertStringContainsString( 'Application Password', $html );

		$config = json_decode( McpStatus::desktop_config(), true );
		$env    = $config['mcpServers']['crazylinks']['env'];
		$this->assertSame( McpStatus::endpoint(), $env['WP_API_URL'] );
		$this->assertSame( 'YOUR-APPLICATION-PASSWORD', $env['WP_API_PASSWORD'] );
		$login = wp_get_current_user()->user_login;
		$this->assertSame( $login, $env['WP_API_USERNAME'], 'The login name is pre-filled so nobody pastes the Application Password name there' );
		$this->assertStringContainsString( '<base64 of ' . $login . ':application-password>', McpStatus::code_command() );
		$this->assertStringContainsString( 'login name', $html );

		wp_set_current_user( 0 );
		$this->assertSame( 'YOUR-WORDPRESS-USERNAME', json_decode( McpStatus::desktop_config(), true )['mcpServers']['crazylinks']['env']['WP_API_USERNAME'], 'Logged out (WP-CLI without --user) falls back to a placeholder' );
	}
}
