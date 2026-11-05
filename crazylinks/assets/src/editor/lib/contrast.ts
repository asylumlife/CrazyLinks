/**
 * WCAG 2.1 contrast ratio for hex colours (editor warnings below 4.5:1, SPEC §5).
 */

/**
 * RGB channels of a #rgb, #rgba, #rrggbb or #rrggbbaa colour, or null.
 *
 * @param hex Colour.
 */
export function parseHex( hex: string ): [ number, number, number ] | null {
	const m = /^#([0-9a-f]{3,8})$/i.exec( hex.trim() );
	if ( ! m ) {
		return null;
	}
	let h = m[ 1 ];
	if ( h.length === 3 || h.length === 4 ) {
		h = h
			.slice( 0, 3 )
			.split( '' )
			.map( ( c ) => c + c )
			.join( '' );
	} else if ( h.length === 8 ) {
		h = h.slice( 0, 6 );
	} else if ( h.length !== 6 ) {
		return null;
	}
	return [ 0, 2, 4 ].map( ( i ) => parseInt( h.slice( i, i + 2 ), 16 ) ) as [
		number,
		number,
		number,
	];
}

function luminance( [ r, g, b ]: [ number, number, number ] ): number {
	const f = ( c: number ) => {
		const s = c / 255;
		return s <= 0.03928
			? s / 12.92
			: Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
	};
	return 0.2126 * f( r ) + 0.7152 * f( g ) + 0.0722 * f( b );
}

/**
 * Contrast ratio between two colours (1–21), or null when either is not a hex colour.
 *
 * @param a Colour.
 * @param b Colour.
 */
export function contrast( a: string, b: string ): number | null {
	const ca = parseHex( a );
	const cb = parseHex( b );
	if ( ! ca || ! cb ) {
		return null;
	}
	const la = luminance( ca );
	const lb = luminance( cb );
	return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
}

export const AA_BODY = 4.5;
export const AA_LARGE = 3;

function hex2( n: number ): string {
	return Math.round( Math.max( 0, Math.min( 255, n ) ) )
		.toString( 16 )
		.padStart( 2, '0' );
}

/**
 * `top` painted over `bottom` at an opacity, as #rrggbb (mirrors Contrast::blend()).
 *
 * @param top     Foreground colour.
 * @param bottom  Background colour.
 * @param opacity Foreground opacity 0..1.
 */
export function blend( top: string, bottom: string, opacity: number ): string {
	const t = parseHex( top ) ?? [ 0, 0, 0 ];
	const b = parseHex( bottom ) ?? [ 255, 255, 255 ];
	return (
		'#' +
		[ 0, 1, 2 ]
			.map( ( i ) => hex2( t[ i ] * opacity + b[ i ] * ( 1 - opacity ) ) )
			.join( '' )
	);
}

/**
 * Black or white, whichever reads better on `bg` (mirrors Contrast::ink_for()).
 *
 * @param bg Background colour.
 */
export function inkFor( bg: string ): string {
	return ( contrast( '#000000', bg ) ?? 1 ) >=
		( contrast( '#ffffff', bg ) ?? 1 )
		? '#000000'
		: '#ffffff';
}

/** Resolved colour tokens of a design (hex strings). */
export interface ColorTokens {
	bg: string;
	/** Gradient end, when the background is a gradient. */
	bgTo?: string;
	text: string;
	muted: string;
	btn: string;
	btnText: string;
	btnFill: string;
	accent: string;
}

export interface ContrastIssue {
	/** Stable key, e.g. `muted`. */
	id: string;
	/** Human label of the failing pair. */
	label: string;
	fg: string;
	bg: string;
	ratio: number;
	/** The ratio the pair must reach. */
	min: number;
}

/**
 * The text/background pairs of a resolved design that fall below their WCAG minimum: body text,
 * headings (large text, 3:1), muted text on the page and on cards, button text on the button, and
 * the badge. A gradient background is sampled along its length, as the server-side check does.
 *
 * @param t      Resolved colour tokens.
 * @param labels Translated labels keyed by pair id.
 */
export function contrastIssues(
	t: ColorTokens,
	labels: Record< string, string >
): ContrastIssue[] {
	const out: ContrastIssue[] = [];
	const seen = new Set< string >();
	const check = (
		id: string,
		fg: string,
		bg: string,
		min: number = AA_BODY
	) => {
		const ratio = contrast( fg, bg );
		if ( ratio === null || ratio >= min || seen.has( id ) ) {
			return;
		}
		seen.add( id );
		out.push( {
			id,
			label: labels[ id ] ?? id,
			fg,
			bg,
			ratio,
			min,
		} );
	};
	const samples = t.bgTo
		? [ 0, 0.25, 0.5, 0.75, 1 ].map( ( o ) => blend( t.bgTo!, t.bg, o ) )
		: [ t.bg ];
	for ( const s of samples ) {
		check( 'text', t.text, s );
		check( 'headings', t.text, s, AA_LARGE );
		check( 'muted', t.muted, s );
		check( 'muted-card', t.muted, blend( t.text, s, 0.14 ) );
		check( 'accent', t.accent, s );
		switch ( t.btnFill ) {
			case 'outline':
				check( 'button', t.btn, s );
				break;
			case 'glass':
				check( 'button', t.text, blend( '#ffffff', s, 0.18 ) );
				break;
			case 'soft':
				check( 'button', t.btnText, blend( t.btn, s, 0.88 ) );
				break;
		}
	}
	// The solid colours are used by the sign-up form's button whatever the link fill.
	check(
		t.btnFill === 'solid' ? 'button' : 'button-solid',
		t.btnText,
		t.btn
	);
	check( 'badge', inkFor( t.accent ), t.accent );
	return out;
}

/**
 * Link badges with a custom colour: their text is always white.
 *
 * @param color Badge colour.
 */
export function badgeIssue( color: string ): number | null {
	const ratio = contrast( '#ffffff', color );
	return ratio !== null && ratio < AA_BODY ? ratio : null;
}

type Bg = Record< string, unknown >;

const isHex = ( v: unknown ): v is string =>
	typeof v === 'string' && parseHex( v ) !== null;

/**
 * Colours a section background can take (mirrors Contrast::section_samples()).
 *
 * @param bg Section background with a type.
 */
export function sectionSamples( bg: Bg ): string[] {
	switch ( bg.type ) {
		case 'gradient': {
			const from = isHex( bg.from ) ? bg.from : '#ffffff';
			const to = isHex( bg.to ) ? bg.to : '#eeeeee';
			return [ 0, 0.25, 0.5, 0.75, 1 ].map( ( o ) =>
				blend( to, from, o )
			);
		}
		case 'image':
			if ( isHex( bg.overlay ) ) {
				return [ bg.overlay ];
			}
			return [ isHex( bg.color ) ? bg.color : '#111111' ];
		default:
			return [ isHex( bg.color ) ? bg.color : '#ffffff' ];
	}
}

function sectionBg( props: Record< string, unknown > ): Bg | null {
	const bg = props.background;
	return bg &&
		typeof bg === 'object' &&
		! Array.isArray( bg ) &&
		typeof ( bg as Bg ).type === 'string'
		? ( bg as Bg )
		: null;
}

/**
 * The text colour a section's children use, or '' when it inherits the page (mirrors
 * Contrast::section_text()): the author's text colour, else for a section with its own background
 * the page text colour if it reads well there, otherwise black or white.
 *
 * @param props    Section props.
 * @param pageText Resolved page text colour.
 */
export function sectionText(
	props: Record< string, unknown >,
	pageText: string
): string {
	if ( isHex( props.text_color ) ) {
		return props.text_color;
	}
	const bg = sectionBg( props );
	if ( ! bg ) {
		return '';
	}
	const samples = sectionSamples( bg );
	const worst = ( fg: string ) =>
		Math.min( ...samples.map( ( s ) => contrast( fg, s ) ?? 1 ) );
	if ( isHex( pageText ) && worst( pageText ) >= AA_BODY ) {
		return pageText;
	}
	return worst( '#000000' ) >= worst( '#ffffff' ) ? '#000000' : '#ffffff';
}

/**
 * The section's text on its background (or on the page background when only the text colour is
 * overridden) when it falls below 4.5:1, or null. Mirrors Contrast::section_pairs().
 *
 * @param props       Section props.
 * @param pageText    Resolved page text colour.
 * @param pageSamples Page background colours (solid, or gradient samples).
 */
export function sectionIssue(
	props: Record< string, unknown >,
	pageText: string,
	pageSamples: string[]
): { fg: string; bg: string; ratio: number } | null {
	const fg = sectionText( props, pageText );
	if ( ! fg ) {
		return null;
	}
	const bg = sectionBg( props );
	const samples = bg ? sectionSamples( bg ) : pageSamples;
	let worst: { fg: string; bg: string; ratio: number } | null = null;
	for ( const s of samples ) {
		const ratio = contrast( fg, s );
		if ( ratio !== null && ( ! worst || ratio < worst.ratio ) ) {
			worst = { fg, bg: s, ratio };
		}
	}
	return worst && worst.ratio < AA_BODY ? worst : null;
}
