/**
 * Typed shortcuts for the editor store.
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { store } from './store';
import type { EditorSelectors } from './store';

/**
 * useSelect bound to the editor store.
 *
 * @param fn   Mapper receiving the bound selectors.
 * @param deps Dependencies.
 */
export function useEditor< T >(
	fn: ( s: EditorSelectors ) => T,
	deps: unknown[] = []
): T {
	return useSelect(
		( select ) => fn( select( store ) as unknown as EditorSelectors ),
		// eslint-disable-next-line react-hooks/exhaustive-deps -- callers pass their own dependency list, as with useSelect itself.
		deps
	);
}

export function useEditorDispatch() {
	return useDispatch( store );
}
