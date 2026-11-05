<?php
/**
 * REST API.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

/**
 * Permissions and validation on crazylinks/v1.
 */
class RestPagesTest extends TestCase {

	private function request( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/crazylinks/v1' . $route );
		if ( $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $req );
	}

	public function test_anonymous_cannot_list_or_create(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '/pages' )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', '/pages', [ 'title' => 'x' ] )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', '/render-preview', [ 'page' => $this->doc() ] )->get_status() );
	}

	public function test_subscriber_and_author_are_forbidden_by_default(): void {
		foreach ( [ 'subscriber', 'author' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			$this->assertSame( 403, $this->request( 'GET', '/pages' )->get_status(), $role );
		}
	}

	public function test_editor_crud_roundtrip(): void {
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$res = $this->request(
			'POST',
			'/pages',
			[
				'title'  => 'Mine',
				'slug'   => 'mine',
				'status' => 'publish',
				'page'   => $this->doc(),
			]
		);
		$this->assertSame( 201, $res->get_status() );
		$id = $res->get_data()['id'];
		$this->assertSame( home_url( '/l/mine/' ), $res->get_data()['link'] );
		$this->assertSame( 'One', $res->get_data()['page']['blocks'][0]['props']['title'] );

		$bad = $this->request( 'PATCH', "/pages/$id", [ 'page' => [ 'blocks' => [ [ 'type' => 'link', 'props' => [ 'title' => 'x', 'url' => 'javascript:alert(1)' ] ] ] ] ] );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'crazylinks_invalid_page', $bad->get_data()['code'] );
		$this->assertSame( '/blocks/0/props/url', $bad->get_data()['data']['errors'][0]['path'] );

		$ok = $this->request( 'PATCH', "/pages/$id", [ 'title' => 'Renamed' ] );
		$this->assertSame( 'Renamed', $ok->get_data()['title'] );
		$this->assertSame( 'One', $ok->get_data()['page']['blocks'][0]['props']['title'], 'Page JSON untouched when not sent' );

		$this->assertSame( 200, $this->request( 'DELETE', "/pages/$id" )->get_status() );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	public function test_contributor_style_user_cannot_publish(): void {
		$role = add_role( 'cl_writer', 'CL Writer', [ 'read' => true, 'edit_crazylinks_pages' => true ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'cl_writer' ] ) );
		$this->assertSame( 403, $this->request( 'POST', '/pages', [ 'status' => 'publish', 'page' => $this->doc() ] )->get_status() );
		$this->assertSame( 201, $this->request( 'POST', '/pages', [ 'status' => 'draft', 'page' => $this->doc() ] )->get_status() );
		remove_role( 'cl_writer' );
		unset( $role );
	}

	public function test_render_preview_returns_html_without_saving(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$before = wp_count_posts( 'crazylinks_page' );
		$res    = $this->request( 'POST', '/render-preview', [ 'page' => $this->doc() ] );
		$this->assertSame( 200, $res->get_status() );
		$this->assertStringContainsString( 'class="cl-page ', $res->get_data()['html'] );
		$this->assertStringContainsString( 'href="https://example.com/one"', $res->get_data()['html'], 'Preview links are direct, never logged' );
		$this->assertEquals( $before, wp_count_posts( 'crazylinks_page' ) );
	}

	public function test_render_preview_returns_a_standalone_document(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$res = $this->request( 'POST', '/render-preview', [ 'page' => $this->doc() ] );
		$doc = $res->get_data()['document'];
		$this->assertStringStartsWith( '<!DOCTYPE html>', $doc );
		$this->assertStringContainsString( '<body class="cl-body cl-preview"><main id="cl-main" class="cl-main">', $doc );
		$this->assertStringContainsString( '<style id="crazylinks-css">', $doc );
		$this->assertStringContainsString( 'id="crazylinks-js"', $doc );
		$this->assertStringContainsString( 'https://example.com/one', $doc );
		$this->assertStringNotContainsString( '<link ', $doc, 'No external stylesheets' );

		$bad = $this->request( 'POST', '/render-preview', [ 'page' => $this->doc( [ [ 'type' => 'heading', 'props' => [ 'text' => '' ] ] ] ) ] );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertStringStartsWith( '/blocks/0/props', $bad->get_data()['data']['errors'][0]['path'] );
	}

	public function test_analytics_summary_permissions(): void {
		$post = $this->make_page( $this->doc() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$req = new \WP_REST_Request( 'GET', '/crazylinks/v1/analytics/summary' );
		$req->set_query_params( [ 'page_id' => $post->ID ] );
		$this->assertSame( 403, rest_get_server()->dispatch( $req )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$req = new \WP_REST_Request( 'GET', '/crazylinks/v1/analytics/summary' );
		$req->set_query_params( [ 'page_id' => $post->ID ] );
		$res = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 0, $res->get_data()['views'] );
		$res = apply_filters( 'rest_post_dispatch', $res, rest_get_server(), $req );
		$this->assertSame( 'no-store', $res->get_headers()['Cache-Control'] ?? '' );
	}

	public function test_subscribe_endpoint(): void {
		$doc  = $this->doc(
			[
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZE',
					'type'  => 'email_capture',
					'props' => [ 'heading' => 'Join' ],
				],
			]
		);
		$post = $this->make_page( $doc );
		wp_set_current_user( 0 );
		$base = [
			'page_id'  => $post->ID,
			'block_id' => '01HZZZZZZZZZZZZZZZZZZZZZZE',
			'email'    => 'fan@example.com',
		];
		$this->assertSame( 200, $this->request( 'POST', '/subscribe', $base + [ 'website' => 'spam' ] )->get_status(), 'Honeypot fakes success' );
		$this->assertSame( 200, $this->request( 'POST', '/subscribe', $base )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/subscribe', [ 'block_id' => 'nope' ] + $base )->get_status() );
	}
}
