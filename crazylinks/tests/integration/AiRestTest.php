<?php
/**
 * REST: /pages/{id}/ai/propose, /apply, /suggest, /settings and /ai/status.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Ai\AiLog;
use CrazyLinks\Ai\Limits;
use CrazyLinks\Ai\Proposals;
use CrazyLinks\Ai\Status;
use CrazyLinks\PageStore;
use CrazyLinks\Tests\Fixtures\AiHelpers;
use CrazyLinks\Tests\Fixtures\FakeProvider;

require_once dirname( __DIR__ ) . '/fixtures/ai/AiHelpers.php';

/**
 * End to end with the real driver and a fake provider.
 */
class AiRestTest extends TestCase {

	use AiHelpers;

	private const A2 = '01JM00000000000000000000A2';
	private const A3 = '01JM00000000000000000000A3';
	private const A6 = '01JM00000000000000000000A6';

	public function set_up(): void {
		parent::set_up();
		$this->ai_on();
	}

	public function tear_down(): void {
		FakeProvider::reset( false );
		Status::reset();
		parent::tear_down();
	}

	/**
	 * The musician page, owned by a fresh editor who is the current user.
	 */
	private function page(): \WP_Post {
		$post   = $this->make_page( $this->musician(), 'ai-' . wp_rand() );
		$editor = $this->as_role( 'editor' );
		wp_update_post(
			[
				'ID'          => $post->ID,
				'post_author' => $editor,
			]
		);
		return get_post( $post->ID );
	}

	private function rename( string $title = 'Stream Harbor Lights' ): array {
		return [
			'summary'    => 'Renamed the single.',
			'operations' => [
				[
					'op'    => 'update_block',
					'id'    => self::A3,
					'props' => [ 'title' => $title ],
				],
			],
			'questions'  => [],
		];
	}

	private function propose( int $id, array $body = [] ): \WP_REST_Response {
		return $this->rest( 'POST', "/crazylinks/v1/pages/{$id}/ai/propose", $body + [ 'instruction' => 'Rename the single' ] );
	}

	public function test_propose_returns_preview_and_change_list_without_saving(): void {
		$post   = $this->page();
		$before = $post->post_content;
		$this->respond( [ $this->rename() ] );
		$res = $this->propose( $post->ID );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data = $res->get_data();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{24}$/', $data['proposal_id'] );
		$this->assertSame( 'Renamed the single.', $data['summary'] );
		$this->assertSame( 'update_block', $data['operations'][0]['op'] );
		$this->assertSame( 'Stream Harbor Lights', $data['preview_document']['blocks'][2]['props']['title'] );
		$this->assertSame( 'Change link “New single: Harbor Lights”: title', $data['change_list'][0]['text'] );
		$this->assertSame( [], $data['questions'] );
		$this->assertNotEmpty( $data['expires_at'] );
		$this->assertSame( $before, get_post( $post->ID )->post_content, 'Nothing saved' );
		$this->assertSame( 1500, Limits::used(), 'Tokens counted against the budget' );

		$row = AiLog::get( AiLog::count_since( 0 ) ? $this->last_log_id() : 0 );
		$this->assertSame( 'propose', $row['kind'] );
		$this->assertSame( '0', (string) $row['applied'] );
		$this->assertSame( 'Rename the single', $row['instruction'] );
		$this->assertSame( 'claude-sonnet-5-5', $row['model'] );
	}

	private function last_log_id(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}crazylinks_ai_log" );
	}

	public function test_apply_saves_through_the_normal_path_creates_a_revision_and_logs(): void {
		$post = $this->page();
		$this->respond( [ $this->rename() ] );
		$proposal = $this->propose( $post->ID )->get_data();

		$res = $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/apply", [ 'proposal_id' => $proposal['proposal_id'] ] );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data = $res->get_data();
		$this->assertTrue( $data['applied'] );
		$this->assertIsInt( $data['revision_id'] );
		$this->assertSame( 'admin', $data['source'] );
		$this->assertFalse( $data['rebased'] );
		$this->assertSame( 'Stream Harbor Lights', PageStore::get_document( $post->ID )['blocks'][2]['props']['title'] );
		$this->assertSame( 'Stream Harbor Lights', $data['page']['page']['blocks'][2]['props']['title'] );
		$this->assertSame( $post->ID, (int) wp_get_post_revision( $data['revision_id'] )->post_parent );

		$row = AiLog::get( $this->last_log_id() );
		$this->assertSame( '1', (string) $row['applied'] );
		$this->assertSame( (string) $data['revision_id'], (string) $row['revision_id'] );
		$this->assertSame( 'admin', $row['source'] );

		// Idempotent.
		$again = $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/apply", [ 'proposal_id' => $proposal['proposal_id'] ] )->get_data();
		$this->assertTrue( $again['already_applied'] );
		$this->assertSame( $data['revision_id'], $again['revision_id'] );

		// History badge.
		$revs  = $this->rest( 'GET', "/crazylinks/v1/pages/{$post->ID}/revisions" )->get_data();
		$badge = array_column( $revs, 'ai', 'id' )[ $data['revision_id'] ] ?? null;
		$this->assertNotNull( $badge, wp_json_encode( [ $revs, $data['revision_id'], get_metadata( 'post', $data['revision_id'], Proposals::REVISION_META, true ) ] ) );
		$this->assertSame( 'admin', $badge['source'] );
		$this->assertSame( 'AI', $badge['label'] );
	}

	public function test_apply_with_edited_operations_and_the_editor_working_copy(): void {
		$post    = $this->page();
		$working = PageStore::get_document( $post );
		$working['blocks'][3]['props']['title'] = 'Merch (unsaved edit)';
		$this->respond( [ $this->rename() ] );
		$proposal = $this->propose( $post->ID, [ 'page' => $working ] )->get_data();
		$this->assertSame( 'Merch (unsaved edit)', $proposal['preview_document']['blocks'][3]['props']['title'], 'Proposals apply to the working copy' );

		$edited = [
			[
				'op'    => 'update_block',
				'id'    => self::A3,
				'props' => [ 'title' => 'My own wording' ],
			],
		];
		$data   = $this->rest(
			'POST',
			"/crazylinks/v1/pages/{$post->ID}/ai/apply",
			[
				'proposal_id' => $proposal['proposal_id'],
				'operations'  => $edited,
				'page'        => $working,
			]
		)->get_data();
		$this->assertTrue( $data['applied'] );
		$this->assertTrue( $data['edited'] );
		$this->assertFalse( $data['rebased'] );
		$doc = PageStore::get_document( $post->ID );
		$this->assertSame( 'My own wording', $doc['blocks'][2]['props']['title'] );
		$this->assertSame( 'Merch (unsaved edit)', $doc['blocks'][3]['props']['title'] );
	}

	public function test_added_blocks_keep_the_preview_ids(): void {
		$post = $this->page();
		$this->respond(
			[
				[
					'summary'    => 'Added.',
					'operations' => [
						[
							'op'       => 'add_block',
							'after_id' => null,
							'block'    => [
								'type'  => 'heading',
								'props' => [ 'text' => 'Hello' ],
							],
						],
					],
					'questions'  => [],
				],
			]
		);
		$proposal = $this->propose( $post->ID )->get_data();
		$new_id   = $proposal['preview_document']['blocks'][0]['id'];
		$this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/apply", [ 'proposal_id' => $proposal['proposal_id'] ] );
		$this->assertSame( $new_id, PageStore::get_document( $post->ID )['blocks'][0]['id'] );
		$this->assertSame( $new_id, $proposal['change_list'][0]['block_id'] );
	}

	public function test_page_changed_since_proposal_rebases_or_conflicts(): void {
		$post = $this->page();
		$this->respond( [ $this->rename(), $this->rename() ] );
		$first  = $this->propose( $post->ID )->get_data();
		$second = $this->propose( $post->ID )->get_data();

		// A human edits another block: the rename still fits, so it is applied on the current page.
		$doc                                   = PageStore::get_document( $post->ID );
		$doc['blocks'][1]['props']['title']    = 'Album (human edit)';
		$this->assertSame( 200, $this->rest( 'PATCH', "/crazylinks/v1/pages/{$post->ID}", [ 'page' => $doc ] )->get_status() );
		$data = $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/apply", [ 'proposal_id' => $first['proposal_id'] ] )->get_data();
		$this->assertTrue( $data['rebased'] );
		$saved = PageStore::get_document( $post->ID );
		$this->assertSame( 'Album (human edit)', $saved['blocks'][1]['props']['title'], 'Human edit kept' );
		$this->assertSame( 'Stream Harbor Lights', $saved['blocks'][2]['props']['title'] );

		// A human removes the block the second proposal changes: 409.
		$saved['blocks'] = array_values( array_filter( $saved['blocks'], static fn( $b ) => self::A3 !== $b['id'] ) );
		$this->rest( 'PATCH', "/crazylinks/v1/pages/{$post->ID}", [ 'page' => $saved ] );
		$res = $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/apply", [ 'proposal_id' => $second['proposal_id'] ] );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'crazylinks_ai_conflict', $res->get_data()['code'] );
	}

	public function test_expired_foreign_and_unknown_proposals_are_rejected(): void {
		$post = $this->page();
		$this->respond( [ $this->rename(), $this->rename() ] );
		$proposal = $this->propose( $post->ID )->get_data();
		$id       = $proposal['proposal_id'];

		// Another page.
		$other = $this->page();
		$this->assertSame( 410, $this->rest( 'POST', "/crazylinks/v1/pages/{$other->ID}/ai/apply", [ 'proposal_id' => $id ] )->get_status() );

		// Another user (an editor too).
		$this->as_role( 'administrator' );
		$this->assertSame( 410, $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/apply", [ 'proposal_id' => $id ] )->get_status() );

		// Expired.
		wp_set_current_user( (int) $post->post_author );
		$data            = get_transient( Proposals::TRANSIENT . $id );
		$data['expires'] = time() - 1;
		set_transient( Proposals::TRANSIENT . $id, $data, 60 );
		$res = $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/apply", [ 'proposal_id' => $id ] );
		$this->assertSame( 410, $res->get_status() );
		$this->assertSame( 'crazylinks_ai_proposal_expired', $res->get_data()['code'] );

		$this->assertSame( 400, $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/apply", [ 'proposal_id' => 'short' ] )->get_status() );
		$this->assertSame( 410, $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/apply", [ 'proposal_id' => str_repeat( 'a', 24 ) ] )->get_status() );
		$this->assertSame( 30 * MINUTE_IN_SECONDS, Proposals::TTL );
	}

	public function test_edited_operations_are_validated_like_the_original(): void {
		$post = $this->page();
		$this->respond( [ $this->rename() ] );
		$proposal = $this->propose( $post->ID )->get_data();
		$res      = $this->rest(
			'POST',
			"/crazylinks/v1/pages/{$post->ID}/ai/apply",
			[
				'proposal_id' => $proposal['proposal_id'],
				'operations'  => [
					[
						'op'    => 'update_block',
						'id'    => self::A3,
						'props' => [ 'url' => 'javascript:alert(1)' ],
					],
				],
			]
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'crazylinks_ai_invalid_ops', $res->get_data()['code'] );
	}

	public function test_permissions_per_role(): void {
		$post = $this->page();
		$this->respond( [ $this->rename() ] );
		$this->assertSame( 200, $this->propose( $post->ID )->get_status(), 'Editor who owns the page' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->propose( $post->ID )->get_status() );
		$this->assertSame( 401, $this->rest( 'GET', '/crazylinks/v1/ai/status' )->get_status() );

		foreach ( [ 'subscriber', 'contributor', 'author' ] as $role ) {
			$this->as_role( $role );
			$this->assertSame( 403, $this->propose( $post->ID )->get_status(), $role );
			$this->assertSame( 403, $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/apply", [ 'proposal_id' => str_repeat( 'a', 24 ) ] )->get_status(), $role );
			$this->assertSame( 403, $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/suggest", [ 'kind' => 'titles' ] )->get_status(), $role );
			$this->assertSame( 403, $this->rest( 'GET', '/crazylinks/v1/ai/status' )->get_status(), $role );
		}

		// Can edit pages but lacks use_crazylinks_ai.
		add_role(
			'cl_pager',
			'Pager',
			[
				'read'                         => true,
				'edit_crazylinks_pages'        => true,
				'edit_others_crazylinks_pages' => true,
			]
		);
		$this->as_role( 'cl_pager' );
		$this->assertSame( 403, $this->propose( $post->ID )->get_status() );

		// Has use_crazylinks_ai but cannot edit this page.
		add_role(
			'cl_ai_only',
			'AI only',
			[
				'read'                  => true,
				'use_crazylinks_ai'     => true,
				'edit_crazylinks_pages' => true,
			]
		);
		$this->as_role( 'cl_ai_only' );
		$this->assertSame( 403, $this->propose( $post->ID )->get_status() );
		$this->assertSame( 404, $this->propose( 999999 )->get_status() );
		remove_role( 'cl_pager' );
		remove_role( 'cl_ai_only' );
	}

	public function test_rate_limits_per_user_and_site(): void {
		$post = $this->page();
		$this->ai_settings( [ 'ai_user_hourly' => 2 ] );
		$this->respond( [ $this->rename(), $this->rename(), $this->rename() ] );
		$this->assertSame( 200, $this->propose( $post->ID )->get_status() );
		$this->assertSame( 200, $this->propose( $post->ID )->get_status() );
		$res = $this->propose( $post->ID );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( 'crazylinks_ai_rate_limited', $res->get_data()['code'] );
		$this->assertStringContainsString( 'per hour', $res->get_data()['message'] );
		$this->assertCount( 2, FakeProvider::$calls, 'The limited request never reached the provider' );

		// The site-wide limit applies across users.
		$this->ai_settings(
			[
				'ai_user_hourly' => 0,
				'ai_site_daily'  => 3,
			]
		);
		$this->as_role( 'administrator' );
		$this->respond( [ $this->rename() ] );
		$this->assertSame( 200, $this->propose( $post->ID )->get_status() );
		$res = $this->propose( $post->ID );
		$this->assertSame( 429, $res->get_status() );
		$this->assertStringContainsString( 'today', $res->get_data()['message'] );
	}

	public function test_budget_warns_at_80_percent_and_stops_at_100(): void {
		$post = $this->page();
		$this->ai_settings( [ 'ai_monthly_tokens' => 2000 ] );
		$this->respond( [ $this->rename(), $this->rename() ] );
		$data = $this->propose( $post->ID )->get_data();
		$this->assertSame( 1500, $data['budget']['used'] );
		$this->assertFalse( $data['budget']['warning'] );

		Limits::record( 200 );
		$status = $this->rest( 'GET', '/crazylinks/v1/ai/status' )->get_data();
		$this->assertTrue( $status['budget']['warning'], '85% used' );
		$this->assertFalse( $status['budget']['exhausted'] );

		$this->assertSame( 200, $this->propose( $post->ID )->get_status(), 'Soft limit only warns' );
		$res = $this->propose( $post->ID );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( 'crazylinks_ai_budget_exhausted', $res->get_data()['code'] );
		$this->assertCount( 2, FakeProvider::$calls );

		// A new month starts fresh.
		update_option(
			Limits::USAGE_OPTION,
			[
				'month'  => '2001-01',
				'tokens' => 999999,
			]
		);
		$this->assertSame( 0, Limits::used() );
	}

	public function test_unavailable_paths(): void {
		$post = $this->page();
		$this->ai_settings( [ 'ai_enabled' => false ] );
		$res = $this->propose( $post->ID );
		$this->assertSame( 503, $res->get_status() );
		$this->assertSame( 'crazylinks_ai_unavailable', $res->get_data()['code'] );
		$status = $this->rest( 'GET', '/crazylinks/v1/ai/status' )->get_data();
		$this->assertFalse( $status['available'] );
		$this->assertSame( 'disabled', $status['reason'] );

		$this->ai_settings( [ 'ai_enabled' => true ] );
		FakeProvider::$configured = false;
		Status::reset();
		$status = $this->rest( 'GET', '/crazylinks/v1/ai/status' )->get_data();
		$this->assertSame( 'no_provider', $status['reason'] );
		$this->assertStringContainsString( 'Connectors', $status['message'] );
		$this->assertSame( 503, $this->propose( $post->ID )->get_status() );
		$this->assertSame( 503, $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/suggest", [ 'kind' => 'bio' ] )->get_status() );

		FakeProvider::$configured = true;
		Status::reset();
		add_filter( 'wp_ai_client_prevent_prompt', '__return_true' );
		Status::reset();
		$this->assertSame( 503, $this->propose( $post->ID )->get_status(), 'Prevented prompts never run' );
		remove_filter( 'wp_ai_client_prevent_prompt', '__return_true' );
		$this->assertSame( [], FakeProvider::$calls );
	}

	public function test_questions_come_back_without_a_proposal_id(): void {
		$post = $this->page();
		$this->respond(
			[
				[
					'summary'    => 'Which one?',
					'operations' => [],
					'questions'  => [ 'Album or single?' ],
				],
				$this->rename(),
			]
		);
		$data = $this->propose( $post->ID, [ 'instruction' => 'Change the Spotify link' ] )->get_data();
		$this->assertNull( $data['proposal_id'] );
		$this->assertSame( [ 'Album or single?' ], $data['questions'] );

		$this->propose(
			$post->ID,
			[
				'instruction'       => 'Change the Spotify link',
				'questions_answers' => [
					[
						'question' => 'Album or single?',
						'answer'   => 'The single',
					],
				],
			]
		);
		$this->assertStringContainsString( "Q: Album or single?\nA: The single", FakeProvider::$calls[1]['prompt'] );
	}

	public function test_restyle_and_analytics_modes(): void {
		$post = $this->page();
		$this->respond(
			[
				[
					'summary'    => 'Dark.',
					'operations' => [
						[
							'op'     => 'set_design',
							'design' => [ 'theme' => 'midnight' ],
						],
					],
					'questions'  => [],
				],
				[
					'summary'    => 'The album link did best.',
					'operations' => [
						[
							'op'     => 'update_block',
							'id'     => self::A2,
							'pinned' => true,
						],
					],
					'questions'  => [],
				],
			]
		);
		$this->assertSame( 'midnight', $this->propose( $post->ID, [ 'mode' => 'restyle' ] )->get_data()['preview_document']['design']['theme'] );
		$this->assertStringContainsString( 'MODE: restyle', FakeProvider::$calls[0]['system'] );

		$data = $this->propose(
			$post->ID,
			[
				'mode'        => 'analytics_qa',
				'instruction' => 'Which link did best?',
			]
		)->get_data();
		$this->assertSame( 'The album link did best.', $data['summary'] );
		$this->assertStringContainsString( '<analytics>', FakeProvider::$calls[1]['prompt'] );
		$this->assertSame( 400, $this->propose( $post->ID, [ 'mode' => 'nonsense' ] )->get_status() );
	}

	public function test_suggest(): void {
		$post = $this->page();
		$this->respond( [ [ 'suggestions' => [ 'Stream Harbor Lights', 'Hear the new single' ] ] ] );
		$res = $this->rest(
			'POST',
			"/crazylinks/v1/pages/{$post->ID}/ai/suggest",
			[
				'kind'    => 'titles',
				'context' => [
					'title' => 'New single: Harbor Lights',
					'url'   => 'https://open.spotify.com/track/harbor',
					'evil'  => [ 'nested' => 'ignored' ],
				],
			]
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( [ 'Stream Harbor Lights', 'Hear the new single' ], $res->get_data()['suggestions'] );
		$this->assertStringNotContainsString( 'ignored', FakeProvider::$calls[0]['prompt'] );
		$this->assertSame( 'suggest', AiLog::get( $this->last_log_id() )['kind'] );
		$this->assertSame( 400, $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/suggest", [ 'kind' => 'poem' ] )->get_status() );
	}

	public function test_log_privacy_and_retention(): void {
		$post = $this->page();
		$this->ai_settings( [ 'ai_log' => false ] );
		$this->respond( [ $this->rename() ] );
		$this->propose( $post->ID );
		$row = AiLog::get( $this->last_log_id() );
		$this->assertSame( '', $row['instruction'] );
		$this->assertSame( '[]', $row['operations'] );
		$this->assertSame( 'claude-sonnet-5-5', $row['model'], 'Usage is still recorded' );

		global $wpdb;
		$wpdb->update( AiLog::table(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ], [ 'id' => $row['id'] ] );
		do_action( 'crazylinks_daily_rollup' );
		$this->assertNull( AiLog::get( (int) $row['id'] ), 'Pruned after 90 days' );
	}

	public function test_page_settings_route_and_status_shape(): void {
		$post = $this->page();
		$res  = $this->rest( 'POST', "/crazylinks/v1/pages/{$post->ID}/ai/settings", [ 'require_confirmation' => true ] );
		$this->assertTrue( $res->get_data()['require_confirmation'] );
		$this->assertTrue( Proposals::requires_confirmation( $post->ID ) );

		$status = $this->rest( 'GET', '/crazylinks/v1/ai/status' )->get_data();
		$this->assertTrue( $status['available'] );
		$this->assertSame( [ 'Fake AI' ], $status['providers'] );
		$this->assertSame( 'claude-sonnet-5-5', $status['models']['edit'][0] );
		$this->assertSame( 'claude-haiku-5-5', $status['models']['suggest'][0] );
		$this->assertSame( 30, $status['limits']['user_hourly'] );
		$this->assertSame( 300, $status['limits']['site_daily'] );
		$this->assertSame( 30, $status['limits']['proposal_minutes'] );
	}

	public function test_editor_boot_data(): void {
		$post = $this->page();
		$data = ( new \CrazyLinks\Admin\EditorPage() )->boot_data( $post );
		$this->assertTrue( $data['ai']['available'] );
		$this->assertTrue( $data['ai']['ready'] );
		$this->assertTrue( $data['ai']['tab'] );
		$this->assertSame( '', $data['ai']['reason'] );
		$this->assertContains( 'restyle', $data['ai']['modes'] );

		FakeProvider::$configured = false;
		Status::reset();
		$data = ( new \CrazyLinks\Admin\EditorPage() )->boot_data( $post );
		$this->assertFalse( $data['ai']['available'] );
		$this->assertSame( 'no_provider', $data['ai']['reason'] );
		$this->assertNotSame( '', $data['ai']['message'] );
	}

	public function test_personal_data_export_and_erase(): void {
		$post = $this->page();
		$this->respond( [ $this->rename() ] );
		$this->propose( $post->ID );
		$email = wp_get_current_user()->user_email;
		$out   = \CrazyLinks\Ai\Bootstrap::export( $email );
		$this->assertCount( 1, $out['data'] );
		$this->assertSame( 'Rename the single', $out['data'][0]['data'][2]['value'] );
		$this->assertArrayHasKey( 'crazylinks-ai', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
		$this->assertTrue( \CrazyLinks\Ai\Bootstrap::erase( $email )['items_removed'] );
		$this->assertSame( [], \CrazyLinks\Ai\Bootstrap::export( $email )['data'] );
	}

	/**
	 * Regression: ticking "Turn on AI editing" alone must persist (the old separate consent box reset it).
	 */
	public function test_enabling_ai_in_settings_records_consent(): void {
		$saved = \CrazyLinks\Settings::sanitize( [ 'ai_section' => '1', 'ai_enabled' => '1' ] );
		$this->assertTrue( $saved['ai_enabled'] );
		$this->assertTrue( $saved['ai_consent'] );
		$off = \CrazyLinks\Settings::sanitize( [ 'ai_section' => '1' ] );
		$this->assertFalse( $off['ai_enabled'] );
		$this->assertFalse( $off['ai_consent'] );
	}

	/**
	 * Regression: WordPress sanitizes twice when an option is first created (update_option then
	 * add_option). The second pass must not drop the AI section (AsylumLife.com, 2026-11-05).
	 */
	public function test_first_ever_settings_save_keeps_ai_enabled(): void {
		$form = [ 'base' => 'linktree', 'analytics' => '1', 'render_mode' => 'standalone', 'ai_section' => '1', 'ai_enabled' => '1', 'ai_user_hourly' => '31' ];
		$twice = \CrazyLinks\Settings::sanitize( \CrazyLinks\Settings::sanitize( $form ) );
		$this->assertTrue( $twice['ai_enabled'] );
		$this->assertTrue( $twice['ai_consent'] );
		$this->assertSame( 31, $twice['ai_user_hourly'] );

		( new \CrazyLinks\Admin\SettingsPage() )->register();
		delete_option( \CrazyLinks\Settings::OPTION );
		update_option( \CrazyLinks\Settings::OPTION, $form );
		$stored = get_option( \CrazyLinks\Settings::OPTION );
		$this->assertTrue( $stored['ai_enabled'], 'First-ever save through update_option must keep AI on.' );
		$this->assertSame( 'linktree', $stored['base'] );
	}
}
