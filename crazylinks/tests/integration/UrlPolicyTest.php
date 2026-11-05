<?php
/**
 * URL policy.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Schema\PageValidator;
use CrazyLinks\Schema\UrlPolicy;

/**
 * Only http, https, mailto, tel and sms are allowed.
 */
class UrlPolicyTest extends TestCase {

	public function allowed_urls(): array {
		return [
			[ 'https://example.com' ],
			[ 'http://example.com/path?q=1#frag' ],
			[ 'HTTPS://EXAMPLE.COM/' ],
			[ 'mailto:hello@example.com' ],
			[ 'tel:+15125550100' ],
			[ 'sms:+15125550100' ],
			[ 'https://例え.jp/パス' ],
		];
	}

	public function rejected_urls(): array {
		return [
			[ 'javascript:alert(1)' ],
			[ 'JaVaScRiPt:alert(1)' ],
			[ ' javascript:alert(1)' ],
			[ "java\tscript:alert(1)" ],
			[ 'data:text/html;base64,PHNjcmlwdD4=' ],
			[ 'vbscript:msgbox(1)' ],
			[ 'file:///etc/passwd' ],
			[ 'ftp://example.com' ],
			[ '//example.com/protocol-relative' ],
			[ '/relative/path' ],
			[ 'example.com' ],
			[ 'https:/example.com' ],
			[ 'https://' ],
			[ 'mailto:' ],
			[ "https://exa\nmple.com" ],
			[ '' ],
		];
	}

	/**
	 * @dataProvider allowed_urls
	 */
	public function test_allows( string $url ): void {
		$this->assertTrue( UrlPolicy::is_allowed( $url ), $url );
	}

	/**
	 * @dataProvider rejected_urls
	 */
	public function test_rejects( string $url ): void {
		$this->assertFalse( UrlPolicy::is_allowed( $url ), $url );
	}

	public function test_web_only_fields_reject_mailto(): void {
		$this->assertFalse( UrlPolicy::is_allowed( 'mailto:a@b.co', UrlPolicy::WEB_SCHEMES ) );
		$this->assertTrue( UrlPolicy::is_allowed( 'https://youtu.be/x', UrlPolicy::WEB_SCHEMES ) );
	}

	public function test_validator_applies_policy_to_every_url_field(): void {
		$bad = 'javascript:alert(1)';
		$doc = $this->doc(
			[
				[
					'type'  => 'link',
					'props' => [
						'title' => 't',
						'url'   => $bad,
					],
				],
				[
					'type'  => 'image',
					'props' => [
						'image' => 1,
						'url'   => $bad,
					],
				],
				[
					'type'  => 'socials',
					'props' => [
						'items' => [
							[
								'network' => 'x',
								'url'     => $bad,
							],
						],
					],
				],
				[
					'type'  => 'embed',
					'props' => [ 'url' => 'mailto:a@b.co' ],
				],
				[
					'type'  => 'link',
					'props' => [
						'title'     => 't',
						'url'       => 'https://ok.example',
						'deep_link' => 'javascript:alert(1)',
					],
				],
			]
		);
		$user  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$paths = array_column( ( new PageValidator() )->validate( $doc, $user )->errors(), 'path' );
		$this->assertContains( '/blocks/0/props/url', $paths );
		$this->assertContains( '/blocks/1/props/url', $paths );
		$this->assertContains( '/blocks/2/props/items/0/url', $paths );
		$this->assertContains( '/blocks/3/props/url', $paths );
		$this->assertContains( '/blocks/4/props/deep_link', $paths );
	}

	/**
	 * Deceptive URLs from the security review (F4).
	 *
	 * @return array<string, array{string}>
	 */
	public function deceptive_urls(): array {
		return [
			'userinfo host spoof'   => [ 'https://paypal.com@evil.com/' ],
			'user and password'     => [ 'https://user:pass@example.com/' ],
			'empty userinfo'        => [ 'https://@example.com/' ],
			'backslash authority'   => [ 'https://evil.com\\@good.com' ],
			'backslash in path'     => [ 'https://example.com\\evil' ],
			'RTL override'          => [ "https://example.com/\u{202E}gpj.exe" ],
			'zero-width space'      => [ "https://exa\u{200B}mple.com/" ],
			'no-break space'        => [ "https://example.com/a\u{00A0}b" ],
			'line separator'        => [ "https://example.com/a\u{2028}b" ],
			'ideographic space'     => [ "https://example.com/\u{3000}" ],
			'C1 control'            => [ "https://example.com/\u{0085}" ],
			'invalid UTF-8'         => [ "https://example.com/\xC3\x28" ],
			'cleaning changes host' => [ 'https://exa"mple.com/' ],
			'angle bracket host'    => [ 'https://exa<mple.com/' ],
		];
	}

	/**
	 * F4: deceptive or ambiguous URLs are rejected.
	 *
	 * @dataProvider deceptive_urls
	 */
	public function test_f4_rejects_deceptive_urls( string $url ): void {
		$this->assertSame( '', UrlPolicy::clean( $url ), $url );
	}

	/**
	 * F4: intranet links stay allowed unless the filter blocks private hosts.
	 */
	public function test_f4_private_hosts_allowed_by_default_and_blockable(): void {
		$private = [ 'http://127.0.0.1/', 'http://10.0.0.5/wiki', 'http://192.168.1.10:8080/', 'http://localhost/', 'http://printer.local/', 'http://[::1]/' ];
		foreach ( $private as $url ) {
			$this->assertNotSame( '', UrlPolicy::clean( $url ), $url );
		}
		add_filter( 'crazylinks_url_block_private_hosts', '__return_true' );
		foreach ( $private as $url ) {
			$this->assertSame( '', UrlPolicy::clean( $url ), $url );
		}
		$this->assertSame( 'https://example.com/', UrlPolicy::clean( 'https://example.com/' ) );
		$this->assertSame( 'http://8.8.8.8/', UrlPolicy::clean( 'http://8.8.8.8/' ) );
	}

	/**
	 * F4: ordinary URLs with @ outside the authority, and IDNs, still pass unchanged.
	 */
	public function test_f4_legitimate_urls_survive(): void {
		foreach ( [ 'https://example.com/?q=a@b', 'https://example.com/@handle', 'https://例え.jp/パス', 'mailto:hi@example.com' ] as $url ) {
			$this->assertSame( $url, UrlPolicy::clean( $url ), $url );
		}
	}
}
