<?php
/**
 * Privacy policy guide text.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Adds suggested privacy-policy text to Settings → Privacy → Policy Guide (SPEC §12).
 */
final class Privacy {

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'add_policy_content' ] );
	}

	/**
	 * Registers the text with core.
	 */
	public function add_policy_content(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'CrazyLinks', self::content() );
		}
	}

	/**
	 * Suggested policy text (HTML).
	 */
	public static function content(): string {
		$paragraphs = [
			'<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'crazylinks' ) . '</strong> '
				. esc_html__( 'Our link pages count visits and link clicks without cookies. For each view or click we store the page and link, the time, a device class (mobile, tablet or desktop), the operating system family, whether the page was opened inside an app such as Instagram or TikTok, the website that referred you (host name only) and any campaign tags in the link.', 'crazylinks' ),
			esc_html__( 'We do not store your IP address. To count unique visitors we store a one-way hash of your IP address and browser user agent combined with a secret that changes every day (UTC) and is then discarded, so visits cannot be linked from one day to the next. A shorter-lived keyed hash of your IP address alone is used to limit abuse; it expires within an hour and is then deleted.', 'crazylinks' ),
			esc_html__( 'Limitation: someone with access to both our database and our site configuration on the same day could test candidate IP addresses against that day\'s hashes. After the daily secret is discarded this is no longer possible.', 'crazylinks' ),
			esc_html__( 'Raw visit records are kept for a limited period set by the site owner and then reduced to daily totals that contain no visitor hashes.', 'crazylinks' ),
			esc_html__( 'If a link page offers an email sign-up form and you submit it, we store your email address, the consent text you agreed to, the time you agreed, which link page and form you used, and whether you confirmed. We send you one email asking you to confirm (double opt-in) unless the site owner turned that off, and every email includes an unsubscribe link. We do not store your IP address or browser details with your sign-up; a keyed hash of your IP address is used to limit sign-up attempts and expires within an hour. Sign-ups that are never confirmed, and addresses that unsubscribed, are deleted after the period set by the site owner (confirmed addresses stay until you unsubscribe or ask us to erase them). You can ask us to export or erase this data, and WordPress\'s personal data tools include it.', 'crazylinks' ),
			esc_html__( 'Embedded media (for example YouTube or Spotify players) load from the provider only after you tap them; the provider\'s own privacy policy then applies.', 'crazylinks' ),
			esc_html__( 'If the site owner turns on AI editing, staff who edit link pages can ask an AI service (the provider the owner connected in WordPress, for example Anthropic, Google or OpenAI) to suggest changes. The request, the content of the link page being edited and, for some requests, public facts about this site (title, tagline, recent post titles, menu links) or click totals per link are sent to that provider. Visitor data, email subscribers, IP addresses and account passwords or keys are never sent. We keep a record of these AI requests (who asked, when, what was asked and the proposed changes) for the period set by the site owner, 90 days by default; WordPress\'s personal data export and erase tools include it.', 'crazylinks' ),
		];
		return '<p>' . implode( '</p><p>', $paragraphs ) . '</p>';
	}
}
