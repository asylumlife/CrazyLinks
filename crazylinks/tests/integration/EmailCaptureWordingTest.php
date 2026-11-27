<?php
/**
 * Email capture wording.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

/**
 * The email capture block prefers its own success and pending text over the defaults.
 */
class EmailCaptureWordingTest extends TestCase {

	public function test_email_capture_prefers_the_blocks_wording(): void {
		$doc  = $this->doc(
			[
				[
					'type'  => 'email_capture',
					'props' => [
						'success_text' => 'Welcome <aboard>',
						'pending_text' => 'Tap the link in your inbox.',
					],
				],
			]
		);
		$html = $this->render_as_admin( $doc );
		$this->assertStringContainsString( 'Tap the link in your inbox.', $html );
		$this->assertStringContainsString( 'Welcome &lt;aboard&gt;', $html );
		$this->assertStringNotContainsString( 'Check your inbox and click the link', $html );
		$this->assertStringNotContainsString( 'You are in. Thanks', $html );
		$this->assertStringContainsString( 'Please enter a valid email address.', $html, 'Other messages keep the defaults' );

		$plain = $this->render_as_admin( $this->doc( [ [ 'type' => 'email_capture', 'props' => [] ] ] ) );
		$this->assertStringContainsString( 'Check your inbox and click the link', $plain );
	}
}
