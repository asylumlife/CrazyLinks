/**
 * Joins class names, skipping empty and falsy parts.
 *
 * @param parts Class names, or falsy values for classes that do not apply.
 */
export function classes(
	...parts: Array< string | false | null | undefined >
): string {
	return parts.filter( Boolean ).join( ' ' );
}
