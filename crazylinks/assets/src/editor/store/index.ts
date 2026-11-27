/**
 * The `crazylinks/editor` data store.
 */
import { createReduxStore, register, select } from '@wordpress/data';
import { reducer } from './reducer';
import type { EditorState } from './reducer';
import * as actions from './actions';
import * as selectors from './selectors';

const STORE_NAME = 'crazylinks/editor';

export const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
} );

type Selectors = typeof selectors;

/** The store's selectors with the state argument already bound. */
export type EditorSelectors = {
	[ K in keyof Selectors ]: Selectors[ K ] extends (
		state: EditorState,
		...args: infer A
	) => infer R
		? ( ...args: A ) => R
		: never;
};

/**
 * The bound selectors of the editor store, for code that runs outside React.
 */
export function editorSelect(): EditorSelectors {
	return select( store ) as unknown as EditorSelectors;
}

let registered = false;

/**
 * Registers the store once.
 */
export function registerStore(): void {
	if ( ! registered ) {
		register( store );
		registered = true;
	}
}
