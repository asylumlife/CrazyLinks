/**
 * Detects the social network of a pasted URL from its host (onboarding wizard and the socials block).
 */
import type { SocialNetwork } from '../registry/blocks';

const HOSTS: Array< [ RegExp, SocialNetwork ] > = [
	[ /(^|\.)instagram\.com$|(^|\.)instagr\.am$/, 'instagram' ],
	[ /(^|\.)tiktok\.com$/, 'tiktok' ],
	[ /(^|\.)youtube\.com$|(^|\.)youtu\.be$/, 'youtube' ],
	[ /(^|\.)(x|twitter)\.com$/, 'x' ],
	[ /(^|\.)facebook\.com$|(^|\.)fb\.(com|me)$/, 'facebook' ],
	[ /(^|\.)threads\.(net|com)$/, 'threads' ],
	[ /(^|\.)bsky\.(app|social)$/, 'bluesky' ],
	[ /(^|\.)linkedin\.com$|(^|\.)lnkd\.in$/, 'linkedin' ],
	[ /(^|\.)pinterest\.[a-z.]+$|(^|\.)pin\.it$/, 'pinterest' ],
	[ /(^|\.)snapchat\.com$/, 'snapchat' ],
	[ /(^|\.)twitch\.tv$/, 'twitch' ],
	[ /(^|\.)discord\.(gg|com)$/, 'discord' ],
	[ /(^|\.)wa\.me$|(^|\.)whatsapp\.com$/, 'whatsapp' ],
	[ /(^|\.)t\.me$|(^|\.)telegram\.(me|org)$/, 'telegram' ],
	[ /(^|\.)signal\.(me|group)$/, 'signal' ],
	[ /(^|\.)spotify\.com$|(^|\.)spotify\.link$/, 'spotify' ],
	[ /^music\.apple\.com$/, 'apple_music' ],
	[ /(^|\.)soundcloud\.com$/, 'soundcloud' ],
	[ /(^|\.)bandcamp\.com$/, 'bandcamp' ],
	[ /(^|\.)github\.com$/, 'github' ],
	[ /(^|\.)dribbble\.com$/, 'dribbble' ],
	[ /(^|\.)behance\.net$/, 'behance' ],
	[ /(^|\.)medium\.com$/, 'medium' ],
	[ /(^|\.)substack\.com$/, 'substack' ],
	[ /(^|\.)patreon\.com$/, 'patreon' ],
	[ /(^|\.)ko-fi\.com$/, 'kofi' ],
	[ /(^|\.)reddit\.com$|(^|\.)redd\.it$/, 'reddit' ],
	[ /(^|\.)tumblr\.com$/, 'tumblr' ],
	[ /(^|\.)vimeo\.com$/, 'vimeo' ],
	[ /(^|\.)etsy\.com$/, 'etsy' ],
	[ /(^|\.)amazon\.[a-z.]+$|(^|\.)amzn\.to$/, 'amazon' ],
];

/** Mastodon has no single host: match the common instance URL shape `/@user`. */
const MASTODON_HOSTS =
	/(^|\.)(mastodon\.[a-z]+|mstdn\.[a-z]+|fosstodon\.org|hachyderm\.io|mas\.to|techhub\.social)$/;

export interface DetectedSocial {
	network: SocialNetwork;
	url: string;
}

/**
 * Normalizes user input into a URL (adds https:// to bare hosts, mailto: to emails).
 *
 * @param input Raw text.
 */
export function normalizeUrl( input: string ): string {
	const v = input.trim();
	if ( v === '' ) {
		return '';
	}
	if ( /^(https?:|mailto:|tel:|sms:)/i.test( v ) ) {
		return v;
	}
	if ( /^[^\s@/]+@[^\s@/]+\.[a-z]{2,}$/i.test( v ) ) {
		return 'mailto:' + v;
	}
	if ( /^\+?[0-9][0-9 ().-]{5,}$/.test( v ) ) {
		return 'tel:' + v.replace( /[^0-9+]/g, '' );
	}
	if ( /^[a-z0-9-]+(\.[a-z0-9-]+)+(\/|$)/i.test( v ) ) {
		return 'https://' + v;
	}
	return v;
}

/**
 * Network for one URL, or null when it is not a usable URL.
 *
 * @param input URL or bare host/path.
 */
export function detectSocial( input: string ): DetectedSocial | null {
	const url = normalizeUrl( input );
	if ( url === '' ) {
		return null;
	}
	if ( /^mailto:/i.test( url ) ) {
		return { network: 'email', url };
	}
	if ( /^(tel|sms):/i.test( url ) ) {
		return { network: 'phone', url };
	}
	let host: string;
	let path: string;
	try {
		const parsed = new URL( url );
		if (
			! /^https?:$/.test( parsed.protocol ) ||
			! parsed.hostname.includes( '.' )
		) {
			return null;
		}
		host = parsed.hostname.toLowerCase().replace( /^(www|m|mobile)\./, '' );
		path = parsed.pathname;
	} catch {
		return null;
	}
	for ( const [ re, network ] of HOSTS ) {
		if ( re.test( host ) ) {
			return { network, url };
		}
	}
	if ( MASTODON_HOSTS.test( host ) || /^\/@[^/]+\/?$/.test( path ) ) {
		return { network: 'mastodon', url };
	}
	if ( /(^|\/)(feed|rss)(\.xml)?\/?$/.test( path ) ) {
		return { network: 'rss', url };
	}
	return { network: 'website', url };
}

/**
 * Parses pasted text (one URL per line, or separated by spaces/commas) into social items.
 *
 * @param text Pasted text.
 * @return Detected items (duplicates removed) and the lines that could not be used.
 */
export function parseSocialList( text: string ): {
	items: DetectedSocial[];
	rejected: string[];
} {
	const items: DetectedSocial[] = [];
	const rejected: string[] = [];
	const seen = new Set< string >();
	for ( const raw of text.split( /[\s,]+/ ) ) {
		const line = raw.trim();
		if ( line === '' ) {
			continue;
		}
		const found = detectSocial( line );
		if ( ! found ) {
			rejected.push( line );
			continue;
		}
		if ( ! seen.has( found.url ) ) {
			seen.add( found.url );
			items.push( found );
		}
	}
	return { items, rejected };
}
