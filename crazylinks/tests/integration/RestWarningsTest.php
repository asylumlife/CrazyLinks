<?php
/**
 * Validation warnings on REST write and preview responses.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

/**
 * Non-blocking warnings (missing alt text) travel with create, update and render-preview responses.
 */
class RestWarningsTest extends TestCase {

	private function request( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/crazylinks/v1' . $route );
		if ( $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $req );
	}

	private function doc_with_image_without_alt(): array {
		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		return $this->doc(
			[
				[
					'type'  => 'image',
					'props' => [ 'image' => $id ],
				],
			]
		);
	}

	public function test_create_update_and_preview_return_warnings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$doc = $this->doc_with_image_without_alt();

		$created = $this->request( 'POST', '/pages', [ 'title' => 'Warn', 'page' => $doc ] );
		$this->assertSame( 201, $created->get_status() );
		$this->assertArrayHasKey( 'warnings', $created->get_data() );
		$this->assertNotEmpty( $created->get_data()['warnings'], 'An image without alt text should warn on create.' );
		$this->assertArrayHasKey( 'path', $created->get_data()['warnings'][0] );

		$id      = (int) $created->get_data()['id'];
		$updated = $this->request( 'PATCH', '/pages/' . $id, [ 'page' => $doc ] );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertNotEmpty( $updated->get_data()['warnings'] );

		$preview = $this->request( 'POST', '/render-preview', [ 'page' => $doc ] );
		$this->assertSame( 200, $preview->get_status() );
		$this->assertNotEmpty( $preview->get_data()['warnings'] );

		$clean = $this->request( 'PATCH', '/pages/' . $id, [ 'page' => $this->doc() ] );
		$this->assertSame( [], $clean->get_data()['warnings'], 'A document without images has no warnings.' );

		$read = $this->request( 'GET', '/pages/' . $id );
		$this->assertArrayNotHasKey( 'warnings', $read->get_data(), 'Plain reads do not re-validate.' );
	}
}
