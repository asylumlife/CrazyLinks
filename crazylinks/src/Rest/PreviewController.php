<?php
/**
 * Render preview endpoint.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Rest;

use CrazyLinks\Frontend\Assets;
use CrazyLinks\PageStore;
use CrazyLinks\PostType;
use CrazyLinks\Render\RenderContext;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Render\Tokens;
use CrazyLinks\Schema\PageValidator;

defined( 'ABSPATH' ) || exit;

/**
 * POST /crazylinks/v1/render-preview: renders a page document without saving it.
 */
final class PreviewController extends \WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = RestRoutes::NS;
		$this->rest_base = 'render-preview';
	}

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'page' => [
							'type'        => 'object',
							'required'    => true,
							'description' => __( 'Page document to render.', 'crazylinks' ),
						],
						'id'   => [
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Existing page id (for slug, author and cached embeds).', 'crazylinks' ),
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * Permission: may edit pages, and the given page if any.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$id = (int) $request['id'];
		if ( ! current_user_can( 'edit_crazylinks_pages' ) || ( $id > 0 && ! current_user_can( 'edit_post', $id ) ) ) {
			return new \WP_Error( 'crazylinks_forbidden', __( 'Sorry, you are not allowed to do that.', 'crazylinks' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	/**
	 * Renders.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$id       = (int) $request['id'];
		$post     = $id > 0 ? get_post( $id ) : null;
		$post     = $post instanceof \WP_Post && PostType::NAME === $post->post_type ? $post : null;
		$previous = $post ? PageStore::get_document( $post, false ) : null;

		$result = ( new PageValidator() )->validate( (array) $request['page'], get_current_user_id(), $previous );
		if ( ! $result->is_valid() ) {
			return $result->to_wp_error();
		}
		$doc = $result->document();
		$ctx = new RenderContext(
			$post ? (int) $post->ID : 0,
			$post ? (string) $post->post_name : '',
			$post ? (int) $post->post_author : get_current_user_id(),
			'preview',
			false,
			(array) $doc['settings'],
			time()
		);
		Assets::reset();
		$html = ( new Renderer() )->render( $doc, $ctx );
		$css  = Assets::style_tag( ( new Tokens( (array) $doc['design'] ) )->custom_css() );
		$js   = Assets::script_tag();

		return rest_ensure_response(
			[
				'html'     => $css . $html,
				'document' => self::document( $css, $html, $js ),
				'warnings' => $result->warnings(),
			]
		);
	}

	/**
	 * A complete HTML document shaped like templates/standalone.php (same body class, main wrapper,
	 * inline CSS and front-end JS) for the editor's sandboxed preview iframe. No wp_head(): previews
	 * must not load theme or third-party assets.
	 *
	 * @param string $css  Style tags.
	 * @param string $html Rendered page.
	 * @param string $js   Front-end script tag.
	 */
	public static function document( string $css, string $html, string $js ): string {
		return '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"' . ( is_rtl() ? ' dir="rtl"' : '' ) . '>'
			. '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
			. '<title>' . esc_html__( 'Preview', 'crazylinks' ) . '</title>' . $css . '</head>'
			. '<body class="cl-body cl-preview"><main id="cl-main" class="cl-main">' . $html . '</main>' . $js . '</body></html>';
	}

	/**
	 * Schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'crazylinks_preview',
			'type'       => 'object',
			'properties' => [
				'html'     => [
					'type'        => 'string',
					'description' => __( 'Rendered page HTML including the plugin stylesheet.', 'crazylinks' ),
					'readonly'    => true,
				],
				'document' => [
					'type'        => 'string',
					'description' => __( 'Complete standalone HTML document for a sandboxed preview frame.', 'crazylinks' ),
					'readonly'    => true,
				],
				'warnings' => [
					'type'        => 'array',
					'description' => __( 'Non-blocking validation warnings (for example missing alt text), each with a JSON path and message.', 'crazylinks' ),
					'readonly'    => true,
					'items'       => [ 'type' => 'object' ],
				],
			],
		];
	}
}
