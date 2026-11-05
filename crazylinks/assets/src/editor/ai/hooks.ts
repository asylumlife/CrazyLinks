/**
 * useSelect bound to the AI store.
 */
import { useSelect } from '@wordpress/data';
import { aiStore } from './store';
import type { AiState } from './types';

/**
 * Selects from the AI store state.
 *
 * @param fn Mapper.
 */
export function useAi< T >( fn: ( state: AiState ) => T ): T {
	return useSelect(
		( select ) =>
			fn(
				(
					select( aiStore ) as unknown as { getState: () => AiState }
				 ).getState()
			),
		[ fn ]
	);
}
