/**
 * Focal point maths: a point {x, y} (0 to 1) stored on a block and rendered as `object-position`.
 */
import type { JsonObject } from '../types';

export interface Focal {
	x: number;
	y: number;
}

const clamp01 = ( n: number ) => Math.max( 0, Math.min( 1, n ) );
const round3 = ( n: number ) => Math.round( n * 1000 ) / 1000;

/**
 * The stored focal point, or the centre when none is set or it is malformed.
 *
 * @param value Stored value.
 */
export function readFocal( value: unknown ): Focal {
	const v = ( value && typeof value === 'object' ? value : {} ) as Record<
		string,
		unknown
	>;
	return {
		x: typeof v.x === 'number' ? clamp01( v.x ) : 0.5,
		y: typeof v.y === 'number' ? clamp01( v.y ) : 0.5,
	};
}

/**
 * Focal point for a click inside a box, as the value to store. The centre is stored as nothing.
 *
 * @param px     Pointer X, in the same unit as `width`.
 * @param py     Pointer Y.
 * @param width  Box width.
 * @param height Box height.
 */
export function focalFromPoint(
	px: number,
	py: number,
	width: number,
	height: number
): JsonObject | undefined {
	if ( width <= 0 || height <= 0 ) {
		return undefined;
	}
	return normalizeFocal( {
		x: clamp01( px / width ),
		y: clamp01( py / height ),
	} );
}

/**
 * Rounds to three decimals and drops the default centre.
 *
 * @param f Focal point.
 */
export function normalizeFocal( f: Focal ): JsonObject | undefined {
	const x = round3( clamp01( f.x ) );
	const y = round3( clamp01( f.y ) );
	return x === 0.5 && y === 0.5 ? undefined : { x, y };
}

/**
 * CSS `object-position` for a focal point (empty for the centre).
 *
 * @param value Stored value.
 */
export function focalCss( value: unknown ): string {
	const f = readFocal( value );
	return f.x === 0.5 && f.y === 0.5
		? ''
		: `${ Math.round( f.x * 1000 ) / 10 }% ${ Math.round( f.y * 1000 ) / 10 }%`;
}
