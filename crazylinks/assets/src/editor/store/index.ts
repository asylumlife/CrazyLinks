/**
 * The `crazylinks/editor` data store.
 */
import { createReduxStore, register } from '@wordpress/data';
import { reducer } from './reducer';
import * as actions from './actions';
import * as selectors from './selectors';

export const STORE_NAME = 'crazylinks/editor';

export const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
} );

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
