/**
 * Typed shortcuts for the editor store.
 */
/* eslint-disable react-hooks/exhaustive-deps -- useEditor forwards the caller's dependency list, like useSelect. */
import { useDispatch, useSelect } from '@wordpress/data';
import { store } from './store';
import type { EditorState } from './store/reducer';
import * as selectors from './store/selectors';

type Selectors = typeof selectors;
type Curried = {
	[ K in keyof Selectors ]: Selectors[ K ] extends (
		state: EditorState,
		...args: infer A
	) => infer R
		? ( ...args: A ) => R
		: never;
};

/**
 * useSelect bound to the editor store.
 *
 * @param fn   Mapper receiving the curried selectors.
 * @param deps Dependencies.
 */
export function useEditor< T >(
	fn: ( s: Curried ) => T,
	deps: unknown[] = []
): T {
	// Callers pass their own dependency list, as with useSelect itself.

	return useSelect(
		( select ) => fn( select( store ) as unknown as Curried ),
		deps
	);
}

export function useEditorDispatch() {
	return useDispatch( store );
}

export type { Curried as EditorSelectors };
