/**
 * Keyboard shortcut hints that match the person's platform.
 */

/**
 * Whether a platform string names an Apple device, where the primary modifier is Command.
 *
 * @param platform `navigator.platform`.
 */
export function isApplePlatform( platform: string ): boolean {
	return /Mac|iPhone|iPad|iPod/i.test( platform );
}

/**
 * Display text for "primary modifier + key": "⌘Z" on Apple devices, "Ctrl+Z" elsewhere.
 *
 * @param key      Key name, e.g. "Z".
 * @param shift    Whether Shift is part of the shortcut.
 * @param platform Platform string; defaults to the current device.
 */
export function primaryShortcut(
	key: string,
	shift = false,
	platform: string = typeof navigator !== 'undefined'
		? navigator.platform
		: ''
): string {
	if ( isApplePlatform( platform ) ) {
		return `${ shift ? '⇧' : '' }⌘${ key }`;
	}
	return `Ctrl+${ shift ? 'Shift+' : '' }${ key }`;
}
