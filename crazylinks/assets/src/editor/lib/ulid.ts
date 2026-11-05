/**
 * ULID generator (Crockford base32, 26 chars), matching src/Schema/Ulid.php.
 */
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function randomBytes( n: number ): Uint8Array {
	const out = new Uint8Array( n );
	const c = typeof globalThis !== 'undefined' ? globalThis.crypto : undefined;
	if ( c && typeof c.getRandomValues === 'function' ) {
		c.getRandomValues( out );
	} else {
		for ( let i = 0; i < n; i++ ) {
			out[ i ] = Math.floor( Math.random() * 256 );
		}
	}
	return out;
}

/**
 * A new ULID.
 *
 * @param now Milliseconds since the epoch.
 */
export function ulid( now: number = Date.now() ): string {
	let time = '';
	let t = now;
	for ( let i = 0; i < 10; i++ ) {
		time = ALPHABET[ t % 32 ] + time;
		t = Math.floor( t / 32 );
	}
	const bytes = randomBytes( 16 );
	let rand = '';
	for ( let i = 0; i < 16; i++ ) {
		rand += ALPHABET[ bytes[ i ] % 32 ];
	}
	return time + rand;
}

export const ULID_PATTERN = /^[0-9A-HJKMNP-TV-Z]{26}$/;
