/**
 * useSelect bound to the AI store.
 */
import { useSelect } from '@wordpress/data';
import { aiStore } from './store';
import type { BoundAiSelectors } from './store';
import type { AiState } from './types';

/**
 * Selects from the AI store state.
 *
 * @param fn Mapper. Pass a stable function (module-level), since it is the hook's dependency.
 */
export function useAi< T >( fn: ( state: AiState ) => T ): T {
	return useSelect(
		( select ) =>
			fn(
				( select( aiStore ) as unknown as BoundAiSelectors ).getState()
			),
		[ fn ]
	);
}
