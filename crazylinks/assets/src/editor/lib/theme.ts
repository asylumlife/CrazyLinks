/**
 * Theme preset helpers: effective token values and swatch CSS.
 */
import type { JsonObject, ThemePreset } from '../types';
import { getPath } from './paths';
import type { ColorTokens } from './contrast';

/**
 * The value a design token resolves to: page override, else preset, else undefined.
 *
 * @param design Page design tokens.
 * @param preset Theme preset.
 * @param path   Token path.
 */
export function effective(
	design: JsonObject,
	preset: ThemePreset | undefined,
	path: string[]
): unknown {
	const own = getPath( design, path );
	if ( own !== undefined && own !== '' ) {
		return own;
	}
	return preset ? getPath( preset, path ) : undefined;
}

/**
 * CSS background for a background token set (swatches and previews).
 *
 * @param bg Background tokens.
 */
export function backgroundCss( bg: JsonObject | undefined ): string {
	if ( ! bg ) {
		return '#f5f5f4';
	}
	const color = typeof bg.color === 'string' ? bg.color : '#f5f5f4';
	if ( bg.type === 'gradient' ) {
		const from = typeof bg.from === 'string' ? bg.from : color;
		const to = typeof bg.to === 'string' ? bg.to : color;
		if ( bg.radial ) {
			return `radial-gradient(circle, ${ from }, ${ to })`;
		}
		const angle = typeof bg.angle === 'number' ? bg.angle : 180;
		return `linear-gradient(${ angle }deg, ${ from }, ${ to })`;
	}
	return color;
}

const hexOr = ( v: unknown, fallback: string ): string =>
	typeof v === 'string' && /^#[0-9a-f]{3,8}$/i.test( v ) ? v : fallback;

/**
 * The colour tokens a page actually renders with, resolved like Tokens.php: page overrides over the
 * theme preset, over the built-in defaults. `dark` layers the preset's dark-mode tokens on top.
 *
 * @param design Page design tokens.
 * @param preset Theme preset.
 * @param dark   Resolve the dark-mode variant.
 */
export function resolveColorTokens(
	design: JsonObject,
	preset: ThemePreset | undefined,
	dark = false
): ColorTokens {
	const get = ( path: string[] ): unknown => {
		if ( dark ) {
			const d = preset?.dark ? getPath( preset.dark, path ) : undefined;
			if ( d !== undefined && d !== '' ) {
				return d;
			}
		}
		return effective( design, preset, path );
	};
	const type = get( [ 'background', 'type' ] );
	const text = hexOr( get( [ 'text', 'color' ] ), '#111111' );
	const bgColor = hexOr( get( [ 'background', 'color' ] ), '#ffffff' );
	const gradient = type === 'gradient';
	return {
		bg: gradient
			? hexOr( get( [ 'background', 'from' ] ), bgColor )
			: bgColor,
		bgTo: gradient
			? hexOr( get( [ 'background', 'to' ] ), bgColor )
			: undefined,
		text,
		muted: hexOr( get( [ 'text', 'muted' ] ), text ),
		btn: hexOr( get( [ 'button', 'color' ] ), '#111111' ),
		btnText: hexOr( get( [ 'button', 'text_color' ] ), '#ffffff' ),
		btnFill:
			typeof get( [ 'button', 'fill' ] ) === 'string'
				? String( get( [ 'button', 'fill' ] ) )
				: 'solid',
		accent: hexOr( get( [ 'accent' ] ), '#4f46e5' ),
	};
}
