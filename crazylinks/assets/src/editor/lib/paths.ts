/**
 * Immutable get/set on nested JSON objects by key path.
 */
import type { JsonObject, JsonValue } from '../types';

/**
 * Value at a path, or undefined.
 *
 * @param obj  Object.
 * @param path Keys.
 */
export function getPath(
	obj: unknown,
	path: ReadonlyArray< string | number >
): unknown {
	let cur: unknown = obj;
	for ( const key of path ) {
		if ( cur === null || typeof cur !== 'object' ) {
			return undefined;
		}
		cur = ( cur as Record< string | number, unknown > )[ key ];
	}
	return cur;
}

/**
 * Copy of `obj` with `path` set to `value`. `undefined` removes the key (used for "reset to theme").
 *
 * @param obj   Object.
 * @param path  Keys (string keys only: arrays are replaced whole).
 * @param value New value.
 */
export function setPath(
	obj: JsonObject,
	path: ReadonlyArray< string >,
	value: JsonValue | undefined
): JsonObject {
	if ( path.length === 0 ) {
		return obj;
	}
	const [ head, ...rest ] = path;
	const next: JsonObject = { ...obj };
	if ( rest.length === 0 ) {
		if ( value === undefined ) {
			delete next[ head ];
		} else {
			next[ head ] = value;
		}
		return next;
	}
	const child = obj[ head ];
	const childObj: JsonObject =
		child && typeof child === 'object' && ! Array.isArray( child )
			? ( child as JsonObject )
			: {};
	next[ head ] = setPath( childObj, rest, value );
	return next;
}
