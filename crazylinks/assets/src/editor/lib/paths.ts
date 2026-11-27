/**
 * Immutable get/set on nested JSON objects by key path.
 */
import type { JsonObject, JsonValue } from '../types';

/**
 * Whether a value is a plain (non-array, non-null) object.
 *
 * @param value Anything.
 */
export function isPlainObject(
	value: unknown
): value is Record< string, unknown > {
	return !! value && typeof value === 'object' && ! Array.isArray( value );
}

/**
 * The value as a JSON object, or an empty object when it is anything else.
 *
 * @param value Anything.
 */
export function asObject( value: unknown ): JsonObject {
	return isPlainObject( value ) ? ( value as JsonObject ) : {};
}

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
	next[ head ] = setPath( asObject( obj[ head ] ), rest, value );
	return next;
}
