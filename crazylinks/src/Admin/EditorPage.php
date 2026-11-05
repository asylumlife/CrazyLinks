<?php
/**
 * React page editor screen.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Admin;

use CrazyLinks\Ai\WpAiClientDriver;
use CrazyLinks\PageStore;
use CrazyLinks\PostType;
use CrazyLinks\Render\Icons;
use CrazyLinks\Render\Payments;
use CrazyLinks\Render\Themes;
use CrazyLinks\Schema\PageValidator;
use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Takes over post.php / post-new.php for crazylinks_page through the core `replace_editor` filter and
 * mounts the React editor (SPEC §9). The list table, trash and core revisions stay core's.
 *
 * `?crazylinks_classic=1` (also the <noscript> link) shows the classic screen with the JSON meta box,
 * the no-JS fallback.
 */
final class EditorPage {

	public const HANDLE = 'crazylinks-editor';

	public const CLASSIC_ARG = 'crazylinks_classic';

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'replace_editor', [ $this, 'replace_editor' ], 10, 2 );
	}

	/**
	 * Whether the classic (JSON meta box) screen was requested.
	 */
	public static function wants_classic(): bool {
		return isset( $_GET[ self::CLASSIC_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen switch.
	}

	/**
	 * Classic screen URL for a page.
	 *
	 * @param int $post_id Post id.
	 */
	public static function classic_url( int $post_id ): string {
		return add_query_arg(
			[
				'post'            => $post_id,
				'action'          => 'edit',
				self::CLASSIC_ARG => 1,
			],
			admin_url( 'post.php' )
		);
	}

	/**
	 * `replace_editor` callback: prints the editor screen and tells core to skip its own.
	 *
	 * @param bool     $replace Whether another plugin already replaced the editor.
	 * @param \WP_Post $post    Post being edited.
	 * @return bool
	 */
	public function replace_editor( $replace, $post ) {
		if ( true === $replace || ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type || self::wants_classic() ) {
			return (bool) $replace;
		}
		// WP_Screen::get() also applies this filter while the screen is set up; only post.php and
		// post-new.php (after their load-* action) are the real "replace the editor now" calls.
		if ( ! did_action( 'load-post.php' ) && ! did_action( 'load-post-new.php' ) ) {
			return (bool) $replace;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) || ! is_readable( CRAZYLINKS_DIR . 'assets/build/editor/index.asset.php' ) ) {
			return (bool) $replace; // No build: the classic screen with the JSON box still works.
		}
		$this->enqueue( $post );
		$this->render( $post );
		return true;
	}

	/**
	 * Enqueues the bundle, media modal and boot data.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function enqueue( \WP_Post $post ): void {
		$asset = include CRAZYLINKS_DIR . 'assets/build/editor/index.asset.php';
		$asset = is_array( $asset ) ? $asset : [];
		$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
		$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : CRAZYLINKS_VERSION;

		wp_enqueue_script( self::HANDLE, CRAZYLINKS_URL . 'assets/build/editor/index.js', $deps, $ver, true );
		wp_set_script_translations( self::HANDLE, 'crazylinks', CRAZYLINKS_DIR . 'languages' );
		wp_enqueue_style( self::HANDLE, CRAZYLINKS_URL . 'assets/build/editor/style-index.css', [ 'wp-components' ], $ver );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		wp_enqueue_media( [ 'post' => $post->ID ] );

		wp_add_inline_script(
			self::HANDLE,
			'window.crazylinks = ' . wp_json_encode( $this->boot_data( $post ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ';',
			'before'
		);
	}

	/**
	 * Data the React app starts from (window.crazylinks).
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public function boot_data( \WP_Post $post ): array {
		$page = $this->page_payload( $post );
		$doc  = PageStore::get_document( $post ) ?? PageValidator::empty_document();
		if ( 'auto-draft' === $post->post_status ) {
			$page['title'] = ''; // Core names every new post "Auto Draft"; the wizard fills in the real name.
		}

		$data = [
			'post'         => array_merge(
				$page,
				[
					'isNew'      => 'auto-draft' === $post->post_status,
					'viewUrl'    => (string) get_permalink( $post ),
					'classicUrl' => self::classic_url( (int) $post->ID ),
					'listUrl'    => admin_url( 'edit.php?post_type=' . PostType::NAME ),
					'baseUrl'    => trailingslashit( home_url( '/' . Settings::base() ) ),
				]
			),
			'autosave'     => $this->autosave_payload( $post ),
			'schema'       => PageValidator::schema(),
			'themes'       => Themes::all(),
			'icons'        => Icons::registry(),
			'networks'     => Icons::networks(),
			'payments'     => Payments::presets(),
			'blockCatalog' => self::block_catalog(),
			'media'        => self::media_for( $doc ),
			'caps'         => [
				'unfiltered_html'           => current_user_can( 'unfiltered_html' ),
				'publish_crazylinks_pages'  => current_user_can( 'publish_crazylinks_pages' ),
				'view_crazylinks_analytics' => current_user_can( 'view_crazylinks_analytics' ),
				'use_crazylinks_ai'         => current_user_can( 'use_crazylinks_ai' ),
			],
			'ai'           => \CrazyLinks\Ai\Status::editor_data( (int) $post->ID, self::ai_available() ),
			'rest'         => [
				'root'      => esc_url_raw( rest_url() ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'namespace' => 'crazylinks/v1',
			],
			'settings'     => [
				'renderMode'       => (string) Settings::get( 'render_mode' ),
				'analytics'        => Settings::analytics_enabled(),
				/**
				 * Filters the editor's autosave interval in seconds (minimum 5).
				 *
				 * @param int $seconds Seconds of inactivity before an autosave. Default 10.
				 */
				'autosaveInterval' => max( 5, (int) apply_filters( 'crazylinks_editor_autosave_interval', 10 ) ),
			],
			'version'      => CRAZYLINKS_VERSION,
		];

		/**
		 * Filters the data the React page editor boots with (window.crazylinks).
		 *
		 * @param array<string, mixed> $data Boot data.
		 * @param \WP_Post             $post Page being edited.
		 */
		return (array) apply_filters( 'crazylinks_editor_data', $data, $post );
	}

	/**
	 * The page in the GET /crazylinks/v1/pages/{id} shape.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function page_payload( \WP_Post $post ): array {
		$request = new \WP_REST_Request( 'GET', '/crazylinks/v1/pages/' . $post->ID );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		if ( $response->is_error() || ! is_array( $data ) ) {
			$data = [
				'id'     => (int) $post->ID,
				'title'  => $post->post_title,
				'slug'   => $post->post_name,
				'status' => $post->post_status,
				'link'   => '',
				'page'   => PageValidator::to_json_shape( PageValidator::empty_document() ),
			];
		}
		return $data;
	}

	/**
	 * The current user's autosave when it is newer than the page, else null.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>|null
	 */
	private function autosave_payload( \WP_Post $post ): ?array {
		$autosave = wp_get_post_autosave( $post->ID, get_current_user_id() );
		if ( ! $autosave instanceof \WP_Post || mysql2date( 'U', $autosave->post_modified_gmt, false ) <= mysql2date( 'U', $post->post_modified_gmt, false ) ) {
			return null;
		}
		if ( $autosave->post_content === $post->post_content && $autosave->post_title === $post->post_title ) {
			return null;
		}
		$doc = PageStore::get_document( $autosave, false );
		if ( null === $doc ) {
			return null;
		}
		return [
			'id'       => (int) $autosave->ID,
			'modified' => mysql_to_rfc3339( $autosave->post_modified_gmt ),
			'title'    => $autosave->post_title,
			'page'     => PageValidator::to_json_shape( $doc ),
		];
	}

	/**
	 * Every block type in the schema with its availability on this site for this user.
	 *
	 * @return array<int, array{type:string, available:bool, reason:string}>
	 */
	public static function block_catalog(): array {
		$schema = PageValidator::schema();
		$types  = (array) ( $schema['$defs']['block']['properties']['type']['enum'] ?? [] );
		$out    = [];
		foreach ( $types as $type ) {
			$type      = (string) $type;
			$available = true;
			$reason    = '';
			if ( 'html' === $type && ! current_user_can( 'unfiltered_html' ) ) {
				$available = false;
				$reason    = __( 'Needs permission to post unfiltered HTML.', 'crazylinks' );
			} elseif ( 'woo_products' === $type && ! class_exists( 'WooCommerce' ) ) {
				$available = false;
				$reason    = __( 'Needs WooCommerce.', 'crazylinks' );
			}
			$out[] = [
				'type'      => $type,
				'available' => $available,
				'reason'    => $reason,
			];
		}
		/**
		 * Filters the block types offered by the editor's add-block drawer.
		 *
		 * @param array<int, array{type:string, available:bool, reason:string}> $catalog One entry per schema block type.
		 */
		return (array) apply_filters( 'crazylinks_editor_block_catalog', $out );
	}

	/**
	 * Thumbnail data for every attachment the document references.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return array<int, array<string, mixed>> Keyed by attachment id (never 0, so it encodes as a JSON object).
	 */
	public static function media_for( array $doc ): array {
		$ids = [];
		foreach ( [ $doc['profile']['avatar'] ?? 0, $doc['profile']['banner'] ?? 0, $doc['design']['background']['image'] ?? 0, $doc['settings']['seo']['image'] ?? 0 ] as $id ) {
			$ids[] = (int) $id;
		}
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $block ) {
			$props = is_array( $block ) ? (array) ( $block['props'] ?? [] ) : [];
			foreach ( [ 'thumbnail', 'image', 'poster', 'video' ] as $key ) {
				$ids[] = (int) ( $props[ $key ] ?? 0 );
			}
			foreach ( (array) ( $props['items'] ?? [] ) as $item ) {
				$ids[] = is_array( $item ) ? (int) ( $item['image'] ?? 0 ) : 0;
			}
		}
		$out = [];
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'read_post', $id ) ) {
				continue;
			}
			$thumb      = wp_get_attachment_image_src( $id, 'thumbnail' );
			$meta       = wp_get_attachment_metadata( $id );
			$out[ $id ] = [
				'id'     => $id,
				'url'    => (string) wp_get_attachment_url( $id ),
				'thumb'  => is_array( $thumb ) ? (string) $thumb[0] : (string) wp_get_attachment_url( $id ),
				'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'mime'   => (string) get_post_mime_type( $id ),
				'width'  => is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0,
				'height' => is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0,
			];
		}
		return $out;
	}

	/**
	 * Whether the AI tab should show (Phase 3 fills it).
	 */
	public static function ai_available(): bool {
		$available = false;
		if ( current_user_can( 'use_crazylinks_ai' ) ) {
			try {
				$available = ( new WpAiClientDriver() )->isAvailable();
			} catch ( \Throwable $e ) {
				$available = false;
			}
		}
		/**
		 * Filters whether the editor shows the AI tab (window.crazylinks.ai.available).
		 *
		 * @param bool $available Default: the user has use_crazylinks_ai and core's AI client has a text model.
		 */
		return (bool) apply_filters( 'crazylinks_editor_ai_available', $available );
	}

	/**
	 * Body class for the editor screen.
	 *
	 * @param string $classes Space-separated classes.
	 */
	public function body_class( $classes ): string {
		return trim( (string) $classes . ' crazylinks-editor-screen' );
	}

	/**
	 * Prints the admin screen: header, mount point, no-JS fallback.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function render( \WP_Post $post ): void {
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		require_once ABSPATH . 'wp-admin/admin-header.php';
		echo '<div class="wrap crazylinks-editor-wrap">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'Edit Link Page', 'crazylinks' ) . '</h1>';
		echo '<div id="crazylinks-editor"><p class="crazylinks-editor-loading hide-if-no-js">' . esc_html__( 'Loading the editor…', 'crazylinks' ) . '</p></div>';
		echo '<noscript><div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: URL of the JSON editing screen. */
			wp_kses( __( 'The visual editor needs JavaScript. <a href="%s">Edit the page JSON instead</a>.', 'crazylinks' ), [ 'a' => [ 'href' => true ] ] ),
			esc_url( self::classic_url( (int) $post->ID ) )
		);
		echo '</p></div></noscript>';
		echo '</div>';
	}
}
