/**
 * Social URL detection.
 */
import {
	detectSocial,
	normalizeUrl,
	parseSocialList,
} from '../../assets/src/editor/lib/social';

describe( 'detectSocial', () => {
	it.each( [
		[ 'https://www.instagram.com/someone', 'instagram' ],
		[ 'instagram.com/someone', 'instagram' ],
		[ 'https://www.tiktok.com/@someone', 'tiktok' ],
		[ 'https://youtu.be/abc', 'youtube' ],
		[ 'https://m.youtube.com/@chan', 'youtube' ],
		[ 'https://twitter.com/someone', 'x' ],
		[ 'https://x.com/someone', 'x' ],
		[ 'https://www.facebook.com/page', 'facebook' ],
		[ 'https://www.threads.net/@me', 'threads' ],
		[ 'https://bsky.app/profile/me.bsky.social', 'bluesky' ],
		[ 'https://mastodon.social/@me', 'mastodon' ],
		[ 'https://example.social/@me', 'mastodon' ],
		[ 'https://www.linkedin.com/in/me', 'linkedin' ],
		[ 'https://open.spotify.com/artist/1', 'spotify' ],
		[ 'https://music.apple.com/us/artist/1', 'apple_music' ],
		[ 'https://soundcloud.com/me', 'soundcloud' ],
		[ 'https://me.bandcamp.com', 'bandcamp' ],
		[ 'https://github.com/me', 'github' ],
		[ 'https://www.patreon.com/me', 'patreon' ],
		[ 'https://ko-fi.com/me', 'kofi' ],
		[ 'https://www.twitch.tv/me', 'twitch' ],
		[ 'https://discord.gg/abc', 'discord' ],
		[ 'https://wa.me/15125550100', 'whatsapp' ],
		[ 'https://t.me/me', 'telegram' ],
		[ 'https://www.pinterest.co.uk/me', 'pinterest' ],
		[ 'https://www.amazon.de/shop/me', 'amazon' ],
		[ 'https://me.substack.com', 'substack' ],
		[ 'https://example.com/feed', 'rss' ],
		[ 'https://example.com', 'website' ],
		[ 'hello@example.com', 'email' ],
		[ 'mailto:hello@example.com', 'email' ],
		[ '+1 512 555 0100', 'phone' ],
	] )( '%s → %s', ( input, network ) => {
		expect( detectSocial( input )?.network ).toBe( network );
	} );

	it( 'rejects things that are not URLs', () => {
		expect( detectSocial( '' ) ).toBeNull();
		expect( detectSocial( '@someone' ) ).toBeNull();
		expect( detectSocial( 'javascript:alert(1)' ) ).toBeNull();
		expect( detectSocial( 'ftp://example.com' ) ).toBeNull();
	} );
} );

describe( 'normalizeUrl', () => {
	it( 'adds schemes', () => {
		expect( normalizeUrl( 'example.com/me' ) ).toBe(
			'https://example.com/me'
		);
		expect( normalizeUrl( 'me@example.com' ) ).toBe(
			'mailto:me@example.com'
		);
		expect( normalizeUrl( '+1 (512) 555-0100' ) ).toBe(
			'tel:+15125550100'
		);
		expect( normalizeUrl( 'https://x.test' ) ).toBe( 'https://x.test' );
	} );
} );

describe( 'parseSocialList', () => {
	it( 'splits, de-duplicates and reports rejects', () => {
		const out = parseSocialList(
			'instagram.com/me\nhttps://instagram.com/me, tiktok.com/@me  @nope'
		);
		expect( out.items.map( ( i ) => i.network ) ).toEqual( [
			'instagram',
			'tiktok',
		] );
		expect( out.rejected ).toEqual( [ '@nope' ] );
	} );
} );
