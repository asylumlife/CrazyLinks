<?php
/**
 * "Page JSON" meta box: the no-JS fallback of the React editor (EditorPage, `?crazylinks_classic=1`).
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Admin;

use CrazyLinks\PageStore;
use CrazyLinks\PostType;
use CrazyLinks\Schema\PageValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Textarea editor for the page document. Validation runs in PostType::filter_insert_data();
 * on failure the stored page is unchanged and the submitted text is shown again with errors.
 */
final class JsonMetaBox {

	public const FIELD = 'crazylinks_json';
	public const NONCE = 'crazylinks_json_nonce';

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostType::NAME, [ $this, 'add' ] );
		add_filter( 'wp_insert_post_data', [ $this, 'inject' ], 5, 2 );
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	/**
	 * Registers the boxes.
	 */
	public function add(): void {
		add_meta_box( 'crazylinks_json', __( 'Page JSON', 'crazylinks' ), [ $this, 'render' ], PostType::NAME, 'normal', 'high' );
		add_meta_box( 'crazylinks_blocks', __( 'Blocks and click URLs', 'crazylinks' ), [ $this, 'render_blocks' ], PostType::NAME, 'side' );
	}

	/**
	 * Copies the submitted JSON into post_content (slashed, as wp_insert_post_data expects).
	 *
	 * @param array<string, mixed> $data    Slashed post data.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public function inject( $data, $postarr ) {
		// Only autosaves (previews) take the submitted JSON; ordinary revisions copy the saved page.
		$parent = is_array( $data ) && str_contains( (string) ( $data['post_name'] ?? '' ), '-autosave-' ) ? PostType::revision_parent( $data ) : 0;
		if ( ( PostType::NAME !== ( $data['post_type'] ?? '' ) && 0 === $parent ) || ! isset( $_POST[ self::NONCE ], $_POST[ self::FIELD ] ) ) {
			return $data;
		}
		// For a preview autosave the nonce and capability belong to the parent page.
		$post_id = $parent > 0 ? $parent : (int) ( $postarr['ID'] ?? 0 );
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), 'crazylinks_json_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $data;
		}
		// Raw JSON is validated and sanitized by PostType::filter_insert_data() at priority 10.
		$raw = wp_unslash( $_POST[ self::FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated against the page schema next.
		$raw = is_string( $raw ) ? $raw : '';
		if ( 0 === $parent ) {
			set_transient( 'crazylinks_draft_' . get_current_user_id() . '_' . $post_id, $raw, 5 * MINUTE_IN_SECONDS );
		}
		$data['post_content'] = wp_slash( $raw );
		return $data;
	}

	/**
	 * Textarea.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render( \WP_Post $post ): void {
		$errors = PageStore::pop_errors( (int) $post->ID );
		$key    = 'crazylinks_draft_' . get_current_user_id() . '_' . $post->ID;
		$draft  = get_transient( $key );
		delete_transient( $key );
		if ( $errors && is_string( $draft ) ) {
			$text = $draft;
		} else {
			$doc  = PageStore::get_document( $post ) ?? PageValidator::empty_document();
			$text = (string) wp_json_encode( PageValidator::to_json_shape( $doc ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		wp_nonce_field( 'crazylinks_json_' . $post->ID, self::NONCE );
		if ( $errors ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'The page JSON was not saved. Fix these problems and update again:', 'crazylinks' ) . '</strong></p><ul style="list-style:disc;margin-left:2em">';
			foreach ( $errors as $error ) {
				echo '<li><code>' . esc_html( $error['path'] ) . '</code> ' . esc_html( $error['message'] ) . '</li>';
			}
			echo '</ul></div>';
		}
		printf(
			'<p>%s</p>',
			esc_html__( 'Edit the page document directly. It is validated against schemas/page.v1.json when you save. Block ids are generated automatically when omitted.', 'crazylinks' )
		);
		if ( 'auto-draft' !== $post->post_status && is_readable( CRAZYLINKS_DIR . 'assets/build/editor/index.asset.php' ) ) {
			printf(
				'<p class="hide-if-no-js"><a href="%s">%s</a></p>',
				esc_url( (string) get_edit_post_link( $post->ID, 'url' ) ),
				esc_html__( 'Open the visual editor', 'crazylinks' )
			);
		}
		printf(
			'<textarea name="%s" id="crazylinks-json" rows="28" style="width:100%%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px" spellcheck="false" aria-label="%s">%s</textarea>',
			esc_attr( self::FIELD ),
			esc_attr__( 'Page JSON', 'crazylinks' ),
			esc_textarea( $text )
		);
	}

	/**
	 * Side box listing block ids and their click URLs.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_blocks( \WP_Post $post ): void {
		$doc = PageStore::get_document( $post ) ?? [];
		if ( empty( $doc['blocks'] ) ) {
			echo '<p>' . esc_html__( 'No blocks yet.', 'crazylinks' ) . '</p>';
			return;
		}
		echo '<ol style="margin-left:1.2em">';
		foreach ( (array) $doc['blocks'] as $block ) {
			$label = (string) ( $block['props']['title'] ?? $block['props']['text'] ?? '' );
			echo '<li><strong>' . esc_html( (string) $block['type'] ) . '</strong> ' . esc_html( $label ) . '<br><code style="font-size:11px">' . esc_html( (string) $block['id'] ) . '</code>';
			if ( 'link' === $block['type'] && 'publish' === $post->post_status ) {
				echo '<br><a href="' . esc_url( \CrazyLinks\Frontend\Router::go_url( $post->post_name, (string) $block['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'click URL', 'crazylinks' ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ol>';
	}

	/**
	 * Top-of-screen notice when the last save had JSON errors.
	 */
	public function notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || PostType::NAME !== $screen->id ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state.
		if ( $post_id && false !== get_transient( 'crazylinks_errors_' . get_current_user_id() . '_' . $post_id ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'CrazyLinks: the page JSON had errors and was not saved. Details are in the Page JSON box below.', 'crazylinks' ) . '</p></div>';
		}
	}
}
