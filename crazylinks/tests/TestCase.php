<?php
/**
 * Shared helpers.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Frontend\TemplateLoader;
use CrazyLinks\PageStore;
use CrazyLinks\PostType;
use CrazyLinks\Render\RenderContext;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Schema\PageValidator;

/**
 * Base test case.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Block ids used by the default fixture document.
	 */
	protected const LINK_A = '01HZZZZZZZZZZZZZZZZZZZZZZA';
	protected const LINK_B = '01HZZZZZZZZZZZZZZZZZZZZZZB';

	/**
	 * Request superglobals as they were before the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved_superglobals = [];

	public function set_up(): void {
		parent::set_up();
		$this->saved_superglobals = [
			'_SERVER' => $_SERVER,
			'_GET'    => $_GET,
			'_POST'   => $_POST,
			'_COOKIE' => $_COOKIE,
		];
	}

	public function tear_down(): void {
		$_SERVER = $this->saved_superglobals['_SERVER'];
		$_GET    = $this->saved_superglobals['_GET'];
		$_POST   = $this->saved_superglobals['_POST'];
		$_COOKIE = $this->saved_superglobals['_COOKIE'];
		parent::tear_down();
	}

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
					'id'    => self::LINK_A,
					'type'  => 'link',
					'props' => [
						'title' => 'One',
						'url'   => 'https://example.com/one',
					],
				],
				[
					'id'    => self::LINK_B,
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

	/**
	 * Dispatches a REST request to crazylinks/v1.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below /crazylinks/v1.
	 * @param array<string, mixed> $body   JSON body.
	 */
	protected function request( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/crazylinks/v1' . $route );
		if ( $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Creates an administrator and returns its id.
	 */
	protected function admin(): int {
		return self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * Uploads a sample image and returns the attachment id.
	 */
	protected function image(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	/**
	 * Date string (UTC) for a number of days ago.
	 */
	protected static function day( int $ago ): string {
		return gmdate( 'Y-m-d', time() - $ago * DAY_IN_SECONDS );
	}

	/**
	 * Fails when rendered markup contains an injected script, image or event-handler attribute.
	 */
	protected function assert_no_injection( string $html ): void {
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		// Attribute values are entity-escaped, so drop them and look for real handler attributes.
		$tags = (string) preg_replace( '/"[^"]*"/', '""', $html );
		if ( preg_match( '/<[a-z]+[^>]*\son[a-z]+\s*=[^>]*>/i', $tags, $m ) ) {
			$this->fail( 'Injected handler: ' . $m[0] );
		}
	}

	/**
	 * Validates a document as a freshly created administrator.
	 *
	 * @param array<string, mixed>      $doc      Document.
	 * @param array<string, mixed>|null $previous Previously stored document.
	 */
	protected function validate_as_admin( array $doc, ?array $previous = null ): \CrazyLinks\Schema\ValidationResult {
		return ( new PageValidator() )->validate( $doc, $this->admin(), $previous );
	}

	/**
	 * Validates (asserting success) and renders a document as an administrator.
	 *
	 * @param array<string, mixed> $doc   Document.
	 * @param string               $mode  Render mode.
	 * @param bool                 $track Track clicks.
	 */
	protected function render_as_admin( array $doc, string $mode = 'standalone', bool $track = false ): string {
		$result = $this->validate_as_admin( $doc );
		$this->assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		$ctx = new RenderContext( 0, 'p', $this->admin(), $mode, $track, (array) $result->document()['settings'], time() );
		return ( new Renderer() )->render( $result->document(), $ctx );
	}

	/**
	 * Requests a link page URL the way WordPress would and returns the full HTML document.
	 *
	 * @param string $url URL.
	 */
	protected function request_page( string $url ): string {
		$this->go_to( $url );
		$loader = new TemplateLoader();
		$loader->template_redirect();
		$template = $loader->template_include( '' );
		$this->assertStringStartsWith( CRAZYLINKS_DIR . 'templates/', $template );
		ob_start();
		include $template;
		return (string) ob_get_clean();
	}
}
