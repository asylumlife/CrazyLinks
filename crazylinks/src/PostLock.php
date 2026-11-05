<?php
/**
 * Edit lock for the React editor, built on core's post lock.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Core's classic editor screen sets `_edit_lock` and shows a "someone else is editing" dialog.
 * The React editor replaces that screen, so this class does the same job with core's own
 * functions and storage: it checks and sets the lock when the editor boots, hands the result to
 * the editor (`window.crazylinks.lock`), and relies on core's `wp-refresh-post-lock` heartbeat
 * handler to keep the lock alive and to report a takeover. "Take over" is core's own URL
 * (`get-post-lock` with a `lock-post_{id}` nonce), handled by post.php.
 */
final class PostLock {

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'crazylinks_editor_data', [ $this, 'editor_data' ], 5, 2 );
	}

	/**
	 * Adds the lock state to the editor boot data and loads core's heartbeat script.
	 *
	 * @param mixed $data Boot data.
	 * @param mixed $post Page being edited.
	 * @return mixed
	 */
	public function editor_data( $data, $post ) {
		if ( ! is_array( $data ) || ! $post instanceof \WP_Post ) {
			return $data;
		}
		$data['lock'] = self::state( $post );
		wp_enqueue_script( 'heartbeat' );
		return $data;
	}

	/**
	 * Checks the lock and, when nobody else holds it, takes it for the current user.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed> `locked`, `user` {name, avatar} when someone else holds the lock, else `activeLock`; `takeOverUrl` and `nonce` always.
	 */
	public static function state( \WP_Post $post ): array {
		$id     = (int) $post->ID;
		$holder = wp_check_post_lock( $id );
		$out    = [
			'locked'      => false,
			'postId'      => $id,
			'nonce'       => wp_create_nonce( 'update-post_' . $id ),
			'activeLock'  => '',
			'user'        => null,
			// Core's own takeover: post.php sets the lock for the current user and redirects back.
			'takeOverUrl' => add_query_arg(
				[
					'get-post-lock' => '1',
					'_wpnonce'      => wp_create_nonce( 'lock-post_' . $id ),
				],
				(string) get_edit_post_link( $id, 'url' )
			),
		];
		if ( $holder ) {
			$user = get_userdata( (int) $holder );
			return array_merge(
				$out,
				[
					'locked' => true,
					'user'   => [
						'name'   => $user ? $user->display_name : '',
						'avatar' => $user ? (string) get_avatar_url( $user->ID, [ 'size' => 64 ] ) : '',
					],
				]
			);
		}
		$lock = wp_set_post_lock( $id );
		if ( is_array( $lock ) ) {
			$out['activeLock'] = implode( ':', $lock );
		}
		return $out;
	}
}
