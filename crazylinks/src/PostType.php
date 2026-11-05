<?php
/**
 * The crazylinks_page post type.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks;

use CrazyLinks\Schema\PageValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type and keeps post_content a valid canonical page JSON document.
 */
final class PostType {

	public const NAME = 'crazylinks_page';

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_filter( 'use_block_editor_for_post_type', [ $this, 'disable_block_editor' ], 10, 2 );
		add_filter( 'wp_insert_post_data', [ $this, 'filter_insert_data' ], 10, 4 );
		add_action( 'save_post_' . self::NAME, [ $this, 'after_save' ], 10, 2 );
		add_action( 'transition_post_status', [ Render\Cache::class, 'on_transition' ], 10, 3 );
		add_filter( 'post_type_link', [ $this, 'permalink' ], 10, 2 );
		add_filter( 'et_builder_post_types', [ $this, 'exclude_from_builders' ] );
		add_filter( 'et_builder_third_party_post_types', [ $this, 'exclude_from_builders' ] );
		( new PostLock() )->register_hooks();
	}

	/**
	 * Registers the CPT. Rewrites are handled by Frontend\Router, not by the CPT itself.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::NAME,
			[
				'labels'              => [
					'name'               => __( 'Link Pages', 'crazylinks' ),
					'singular_name'      => __( 'Link Page', 'crazylinks' ),
					'add_new'            => __( 'Add Link Page', 'crazylinks' ),
					'add_new_item'       => __( 'Add Link Page', 'crazylinks' ),
					'edit_item'          => __( 'Edit Link Page', 'crazylinks' ),
					'new_item'           => __( 'New Link Page', 'crazylinks' ),
					'view_item'          => __( 'View Link Page', 'crazylinks' ),
					'search_items'       => __( 'Search Link Pages', 'crazylinks' ),
					'not_found'          => __( 'No link pages found.', 'crazylinks' ),
					'not_found_in_trash' => __( 'No link pages found in Trash.', 'crazylinks' ),
					'all_items'          => __( 'Link Pages', 'crazylinks' ),
					'menu_name'          => __( 'CrazyLinks', 'crazylinks' ),
				],
				'public'              => true,
				'publicly_queryable'  => true,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-admin-links',
				'menu_position'       => 26,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => true,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capability_type'     => [ 'crazylinks_page', 'crazylinks_pages' ],
				'capabilities'        => Capabilities::post_type_caps(),
				'map_meta_cap'        => true,
				'supports'            => [ 'title', 'author', 'revisions', 'thumbnail' ],
				'delete_with_user'    => false,
			]
		);
	}

	/**
	 * The page JSON is not block content; never open it in Gutenberg.
	 *
	 * @param bool   $use_editor Default.
	 * @param string $post_type Post type.
	 */
	public function disable_block_editor( $use_editor, $post_type ): bool {
		return self::NAME === $post_type ? false : (bool) $use_editor;
	}

	/**
	 * Removes the post type from page-builder post type lists.
	 *
	 * @param mixed $types Post types.
	 * @return mixed
	 */
	public function exclude_from_builders( $types ) {
		if ( is_array( $types ) ) {
			$types = array_values( array_diff( $types, [ self::NAME ] ) );
		}
		return $types;
	}

	/**
	 * Validates and normalizes post_content on every write path (REST, meta box, CLI, revisions).
	 *
	 * Invalid content never reaches the database: the previous document is kept (or an empty
	 * page for new posts) and the errors are stored for the meta box to display.
	 *
	 * @param array<string, mixed> $data                Slashed post data.
	 * @param array<string, mixed> $postarr             Raw post array.
	 * @param array<string, mixed> $unsanitized_postarr Unsanitized post array.
	 * @param bool                 $update              Whether this is an update.
	 * @return array<string, mixed>
	 */
	public function filter_insert_data( $data, $postarr, $unsanitized_postarr = [], $update = false ) {
		unset( $unsanitized_postarr );
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$parent = self::revision_parent( $data );
		if ( self::NAME !== ( $data['post_type'] ?? '' ) && 0 === $parent ) {
			return $data;
		}
		if ( $parent > 0 ) {
			// Revision or autosave (preview) of a link page: validated like the page itself, with the
			// parent's current document as the fallback, so previews never render unvalidated JSON.
			$post_id  = $parent;
			$previous = PageStore::get_document( $parent, false );
		} else {
			$post_id  = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
			$previous = $update && $post_id ? PageStore::get_document( $post_id, false ) : null;
		}
		$raw = wp_unslash( (string) ( $data['post_content'] ?? '' ) );

		if ( '' === trim( $raw ) ) {
			$doc = $previous ?? PageValidator::empty_document();
		} else {
			$result = ( new PageValidator() )->validate( $raw, get_current_user_id(), $previous );
			if ( $result->is_valid() ) {
				$doc = $result->document();
			} else {
				PageStore::remember_errors( $post_id, $result->errors() );
				$doc = $previous ?? PageValidator::empty_document();
			}
		}
		/**
		 * Filters the normalized page document right before it is written.
		 *
		 * @param array<string, mixed> $doc     Page document.
		 * @param int                  $post_id Post id (0 for new posts).
		 */
		$doc                  = (array) apply_filters( 'crazylinks_before_save', $doc, $post_id );
		$data['post_content'] = wp_slash( PageStore::encode( $doc ) );
		return $data;
	}

	/**
	 * The parent link page id when the post data is a revision or autosave of one, else 0.
	 *
	 * @param array<string, mixed> $data Post data.
	 */
	public static function revision_parent( array $data ): int {
		if ( 'revision' !== ( $data['post_type'] ?? '' ) || empty( $data['post_parent'] ) ) {
			return 0;
		}
		$parent = (int) $data['post_parent'];
		return self::NAME === get_post_type( $parent ) ? $parent : 0;
	}

	/**
	 * Stores the schema version meta and invalidates the render cache.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function after_save( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		$doc = PageStore::get_document( $post );
		update_post_meta( $post_id, '_cl_schema_version', (int) ( $doc['schema'] ?? 1 ) );
		Render\Cache::forget( (int) $post_id );
		Frontend\Embeds::prime( (int) $post_id, $doc );
	}

	/**
	 * Pretty permalink `/{base}/{slug}/` for published pages.
	 *
	 * @param string   $link Default link.
	 * @param \WP_Post $post Post.
	 */
	public function permalink( $link, $post ): string {
		if ( ! $post instanceof \WP_Post || self::NAME !== $post->post_type ) {
			return (string) $link;
		}
		if ( ! in_array( $post->post_status, [ 'publish', 'private' ], true ) || '' === $post->post_name ) {
			return (string) $link;
		}
		return Frontend\Router::page_url( $post->post_name );
	}
}
