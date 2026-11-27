<?php
/**
 * Post lock.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\PostLock;

/**
 * A second editor sees who holds the page and can take over through core's post lock.
 */
class PostLockTest extends TestCase {

	public function test_second_user_sees_a_lock_and_a_core_takeover_url(): void {
		$post = $this->make_page( $this->doc() );
		$a    = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Alice Editor' ] );
		$b    = self::factory()->user->create( [ 'role' => 'administrator' ] );

		wp_set_current_user( $a );
		$mine = PostLock::state( $post );
		$this->assertFalse( $mine['locked'] );
		$this->assertMatchesRegularExpression( '/^\d+:' . $a . '$/', $mine['activeLock'] );

		wp_set_current_user( $b );
		$theirs = PostLock::state( $post );
		$this->assertTrue( $theirs['locked'] );
		$this->assertSame( 'Alice Editor', $theirs['user']['name'] );
		$this->assertStringContainsString( 'get-post-lock=1', $theirs['takeOverUrl'] );
		$this->assertStringContainsString( '_wpnonce=', $theirs['takeOverUrl'] );
		$this->assertSame( $a, (int) wp_check_post_lock( $post->ID ), 'Opening the editor as B did not steal the lock' );

		// Core's takeover: B sets the lock, A is now the one locked out.
		wp_set_post_lock( $post->ID );
		$this->assertFalse( PostLock::state( $post )['locked'] );
		wp_set_current_user( $a );
		$this->assertTrue( PostLock::state( $post )['locked'] );
	}

	public function test_lock_state_is_added_to_the_editor_boot_data(): void {
		$post = $this->make_page( $this->doc() );
		$data = apply_filters( 'crazylinks_editor_data', [ 'x' => 1 ], $post );
		$this->assertArrayHasKey( 'lock', $data );
		$this->assertFalse( $data['lock']['locked'] );
		$this->assertTrue( wp_script_is( 'heartbeat', 'enqueued' ) );
	}
}
