<?php
/**
 * Privacy policy text.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Privacy;

/**
 * The suggested privacy-policy text is registered and covers what the plugin stores.
 */
class PrivacyPolicyTest extends TestCase {

	public function test_privacy_policy_text_is_registered(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		$text = Privacy::content();
		foreach ( [ 'cookies', 'hash', 'every day', 'device class', 'inside an app', 'referred you', 'IP address', 'Limitation', 'email address' ] as $needle ) {
			$this->assertStringContainsString( $needle, $text, $needle );
		}
		set_current_screen( 'dashboard' );
		$GLOBALS['wp_current_filter'][] = 'admin_init';
		( new Privacy() )->add_policy_content();
		array_pop( $GLOBALS['wp_current_filter'] );
		$GLOBALS['current_screen'] = null;
		$names = array_column( \WP_Privacy_Policy_Content::get_suggested_policy_text(), 'plugin_name' );
		$this->assertContains( 'CrazyLinks', $names );
	}
}
