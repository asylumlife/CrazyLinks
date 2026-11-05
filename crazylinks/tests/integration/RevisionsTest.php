<?php
/**
 * Autosave and revision routes.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\PageStore;
use CrazyLinks\PostType;

/**
 * /crazylinks/v1/pages/{id}/autosave and /revisions.
 */
class RevisionsTest extends TestCase {

	private function request( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/crazylinks/v1' . $route );
		if ( $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $req );
	}

	private function editor(): int {
		$id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * A page with two saved versions (so it has revisions), owned by the current user.
	 */
	private function page_with_history(): \WP_Post {
		$post = $this->make_page( $this->doc(), 'history' );
		$user = $this->editor();
		wp_update_post(
			[
				'ID'          => $post->ID,
				'post_author' => $user,
			]
		);
		$this->assertSame( 200, $this->request( 'PATCH', "/pages/{$post->ID}", [ 'page' => $this->titled( 'Version two' ) ] )->get_status() );
		$this->assertSame( 200, $this->request( 'PATCH', "/pages/{$post->ID}", [ 'page' => $this->titled( 'Version three' ) ] )->get_status() );
		return get_post( $post->ID );
	}

	private function titled( string $title ): array {
		$doc                                   = $this->doc();
		$doc['blocks'][0]['props']['title']    = $title;
		return $doc;
	}

	private function first_title( $post ): string {
		return (string) ( PageStore::get_document( $post )['blocks'][0]['props']['title'] ?? '' );
	}

	public function test_routes_require_authentication(): void {
		$post = $this->make_page( $this->doc() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', "/pages/{$post->ID}/autosave", [ 'page' => $this->doc() ] )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', "/pages/{$post->ID}/revisions" )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', "/pages/{$post->ID}/revisions/1" )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', "/pages/{$post->ID}/revisions/1/restore" )->get_status() );
	}

	public function test_roles_without_page_caps_are_forbidden(): void {
		$post = $this->make_page( $this->doc() );
		foreach ( [ 'subscriber', 'author', 'contributor' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			$this->assertSame( 403, $this->request( 'POST', "/pages/{$post->ID}/autosave", [ 'page' => $this->doc() ] )->get_status(), $role );
			$this->assertSame( 403, $this->request( 'GET', "/pages/{$post->ID}/revisions" )->get_status(), $role );
		}
	}

	public function test_user_who_cannot_edit_others_pages_is_forbidden(): void {
		$post = $this->make_page( $this->doc() );
		add_role(
			'cl_writer',
			'CL Writer',
			[
				'read'                  => true,
				'edit_crazylinks_pages' => true,
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'cl_writer' ] ) );
		$this->assertSame( 403, $this->request( 'POST', "/pages/{$post->ID}/autosave", [ 'page' => $this->doc() ] )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', "/pages/{$post->ID}/revisions" )->get_status() );
		remove_role( 'cl_writer' );
	}

	public function test_unknown_page_is_404_for_editors(): void {
		$this->editor();
		$this->assertSame( 404, $this->request( 'GET', '/pages/999999/revisions' )->get_status() );
		$other = self::factory()->post->create();
		$this->assertSame( 404, $this->request( 'POST', "/pages/{$other}/autosave", [ 'page' => $this->doc() ] )->get_status() );
	}

	public function test_autosave_stores_a_revision_and_leaves_the_page_alone(): void {
		$post = $this->make_page( $this->doc() );
		$user = $this->editor();
		$res  = $this->request(
			'POST',
			"/pages/{$post->ID}/autosave",
			[
				'page'  => $this->titled( 'Work in progress' ),
				'title' => 'WIP',
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'autosave', $res->get_data()['mode'] );
		$autosave = wp_get_post_autosave( $post->ID, $user );
		$this->assertInstanceOf( \WP_Post::class, $autosave );
		$this->assertSame( $autosave->ID, $res->get_data()['autosave_id'] );
		$this->assertSame( 'Work in progress', $this->first_title( $autosave ) );
		$this->assertSame( 'WIP', $autosave->post_title );
		$this->assertSame( 'One', $this->first_title( $post->ID ), 'The page itself is unchanged' );

		// A second autosave by the same user updates the same revision.
		$again = $this->request( 'POST', "/pages/{$post->ID}/autosave", [ 'page' => $this->titled( 'Still working' ) ] );
		$this->assertSame( $autosave->ID, $again->get_data()['autosave_id'] );
		$this->assertSame( 'Still working', $this->first_title( wp_get_post_autosave( $post->ID, $user ) ) );
	}

	public function test_autosave_validates_like_a_save(): void {
		$post = $this->make_page( $this->doc() );
		$user = $this->editor();
		$doc  = $this->doc(
			[
				[
					'type'  => 'link',
					'props' => [
						'title' => 'Bad',
						'url'   => 'javascript:alert(1)',
					],
				],
			]
		);
		$res  = $this->request( 'POST', "/pages/{$post->ID}/autosave", [ 'page' => $doc ] );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'crazylinks_invalid_page', $res->get_data()['code'] );
		$this->assertSame( '/blocks/0/props/url', $res->get_data()['data']['errors'][0]['path'] );
		$this->assertFalse( wp_get_post_autosave( $post->ID, $user ) );

		$missing = $this->request( 'POST', "/pages/{$post->ID}/autosave", [ 'page' => $this->doc( [ [ 'type' => 'link', 'props' => [ 'title' => 'No URL' ] ] ] ) ] );
		$this->assertSame( 400, $missing->get_status() );
		$this->assertStringStartsWith( '/blocks/0/props', $missing->get_data()['data']['errors'][0]['path'] );
	}

	public function test_autosave_respects_the_unfiltered_html_gate(): void {
		$post = $this->make_page( $this->doc() );
		add_role(
			'cl_writer',
			'CL Writer',
			[
				'read'                         => true,
				'edit_crazylinks_pages'        => true,
				'edit_others_crazylinks_pages' => true,
				'edit_published_crazylinks_pages' => true,
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'cl_writer' ] ) );
		$doc = $this->doc( [ [ 'type' => 'html', 'props' => [ 'html' => '<script>x</script>' ] ] ] );
		$res = $this->request( 'POST', "/pages/{$post->ID}/autosave", [ 'page' => $doc ] );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( '/blocks/0', $res->get_data()['data']['errors'][0]['path'] );
		remove_role( 'cl_writer' );
	}

	public function test_unchanged_autosave_is_not_stored(): void {
		$post = $this->make_page( $this->doc() );
		$user = $this->editor();
		$this->request( 'POST', "/pages/{$post->ID}/autosave", [ 'page' => $this->titled( 'Changed' ) ] );
		$this->assertInstanceOf( \WP_Post::class, wp_get_post_autosave( $post->ID, $user ) );
		$same = $this->request(
			'POST',
			"/pages/{$post->ID}/autosave",
			[
				'page'  => PageStore::get_document( $post->ID ),
				'title' => get_post( $post->ID )->post_title,
			]
		);
		$this->assertSame( 'unchanged', $same->get_data()['mode'] );
		$this->assertFalse( wp_get_post_autosave( $post->ID, $user ), 'A stale autosave is removed' );
	}

	public function test_autosave_of_a_new_page_saves_it_as_a_draft(): void {
		$user = $this->editor();
		$id   = self::factory()->post->create(
			[
				'post_type'   => PostType::NAME,
				'post_status' => 'auto-draft',
				'post_author' => $user,
			]
		);
		$res  = $this->request(
			'POST',
			"/pages/{$id}/autosave",
			[
				'page'  => $this->doc(),
				'title' => 'My page',
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'draft', $res->get_data()['mode'] );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'My page', get_post( $id )->post_title );
		$this->assertSame( 'One', $this->first_title( $id ) );
	}

	public function test_revisions_list_and_read(): void {
		$post = $this->page_with_history();
		$this->request( 'POST', "/pages/{$post->ID}/autosave", [ 'page' => $this->titled( 'Draft idea' ) ] );

		$list = $this->request( 'GET', "/pages/{$post->ID}/revisions" );
		$this->assertSame( 200, $list->get_status() );
		$items = $list->get_data();
		$this->assertGreaterThanOrEqual( 3, count( $items ) );
		$this->assertCount( 1, array_filter( $items, static fn( $i ) => $i['autosave'] ) );
		foreach ( [ 'id', 'date', 'author', 'autosave', 'title', 'blocks' ] as $key ) {
			$this->assertArrayHasKey( $key, $items[0] );
		}

		$plain = array_values( array_filter( $items, static fn( $i ) => ! $i['autosave'] ) );
		$one   = $this->request( 'GET', "/pages/{$post->ID}/revisions/{$plain[0]['id']}" );
		$this->assertSame( 200, $one->get_status() );
		$this->assertSame( $post->ID, $one->get_data()['parent'] );
		$this->assertSame( 'Version three', $one->get_data()['page']['blocks'][0]['props']['title'] );

		$other = $this->make_page( $this->doc(), 'other' );
		$this->editor();
		$this->assertSame( 404, $this->request( 'GET', "/pages/{$other->ID}/revisions/{$plain[0]['id']}" )->get_status(), 'Revision of another page' );
	}

	public function test_other_users_autosaves_are_hidden_from_writers(): void {
		$post = $this->make_page( $this->doc() );
		$this->editor();
		$this->request( 'POST', "/pages/{$post->ID}/autosave", [ 'page' => $this->titled( 'Editor draft' ) ] );
		$autosave = wp_get_post_autosave( $post->ID, get_current_user_id() );

		add_role(
			'cl_writer',
			'CL Writer',
			[
				'read'                            => true,
				'edit_crazylinks_pages'           => true,
				'edit_published_crazylinks_pages' => true,
			]
		);
		$writer = self::factory()->user->create( [ 'role' => 'cl_writer' ] );
		wp_update_post(
			[
				'ID'          => $post->ID,
				'post_author' => $writer,
			]
		);
		wp_set_current_user( $writer );
		$items = $this->request( 'GET', "/pages/{$post->ID}/revisions" )->get_data();
		$this->assertSame( [], array_filter( $items, static fn( $i ) => $i['autosave'] ) );
		$this->assertSame( 403, $this->request( 'GET', "/pages/{$post->ID}/revisions/{$autosave->ID}" )->get_status() );
		remove_role( 'cl_writer' );
	}

	public function test_restore_creates_a_revision_and_returns_the_page(): void {
		$post   = $this->page_with_history();
		$before = count( wp_get_post_revisions( $post->ID ) );
		$target = null;
		foreach ( wp_get_post_revisions( $post->ID ) as $rev ) {
			if ( 'Version two' === $this->first_title( $rev ) ) {
				$target = $rev;
			}
		}
		$this->assertInstanceOf( \WP_Post::class, $target );

		$res = $this->request( 'POST', "/pages/{$post->ID}/revisions/{$target->ID}/restore" );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $target->ID, $res->get_data()['restored_from'] );
		$this->assertSame( 'Version two', $res->get_data()['page']['blocks'][0]['props']['title'] );
		$this->assertSame( 'Version two', $this->first_title( $post->ID ) );
		$this->assertSame( $before + 1, count( wp_get_post_revisions( $post->ID ) ), 'Restoring keeps history' );
		$this->assertSame( 'publish', get_post_status( $post->ID ), 'Status is kept' );
	}

	public function test_restore_is_refused_when_the_revision_fails_the_capability_gates(): void {
		// An administrator saved custom CSS; a user without unfiltered_html removed it later.
		$post = $this->make_page( $this->doc() );
		$doc  = $this->doc();
		$doc['design']['custom_css'] = '.cl-page{color:red}';
		wp_update_post(
			wp_slash(
				[
					'ID'           => $post->ID,
					'post_content' => wp_json_encode( $doc ),
				]
			)
		);
		$with_css = null;
		foreach ( wp_get_post_revisions( $post->ID ) as $rev ) {
			if ( str_contains( $rev->post_content, 'custom_css' ) ) {
				$with_css = $rev;
			}
		}
		$this->assertInstanceOf( \WP_Post::class, $with_css );
		wp_update_post(
			wp_slash(
				[
					'ID'           => $post->ID,
					'post_content' => wp_json_encode( $this->doc() ),
				]
			)
		);

		add_role(
			'cl_writer',
			'CL Writer',
			[
				'read'                            => true,
				'edit_crazylinks_pages'           => true,
				'edit_others_crazylinks_pages'    => true,
				'edit_published_crazylinks_pages' => true,
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'cl_writer' ] ) );
		$res = $this->request( 'POST', "/pages/{$post->ID}/revisions/{$with_css->ID}/restore" );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( '/design/custom_css', $res->get_data()['data']['errors'][0]['path'] );
		$this->assertStringNotContainsString( 'custom_css', get_post( $post->ID )->post_content );
		remove_role( 'cl_writer' );
	}

	public function test_responses_are_not_cacheable(): void {
		$post = $this->make_page( $this->doc() );
		$this->editor();
		$req = new \WP_REST_Request( 'GET', "/crazylinks/v1/pages/{$post->ID}/revisions" );
		$res = apply_filters( 'rest_post_dispatch', rest_get_server()->dispatch( $req ), rest_get_server(), $req );
		$this->assertSame( 'no-store', $res->get_headers()['Cache-Control'] ?? '' );
	}
}
