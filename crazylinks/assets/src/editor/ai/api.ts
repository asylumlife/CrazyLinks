/**
 * REST client for the AI routes.
 */
import apiFetch from '@wordpress/api-fetch';
import type { PageDoc } from '../types';
import type { AiMode, ApplyResult, BudgetState, Proposal } from './types';

const NS = '/crazylinks/v1';

export interface ProposeInput {
	instruction: string;
	mode: AiMode;
	page: PageDoc;
	questions_answers?: Array< { question: string; answer: string } >;
}

export const aiApi = {
	propose( id: number, data: ProposeInput ) {
		return apiFetch< Proposal >( {
			path: `${ NS }/pages/${ id }/ai/propose`,
			method: 'POST',
			data,
		} );
	},
	apply( id: number, data: { proposal_id: string; page?: PageDoc } ) {
		return apiFetch< ApplyResult >( {
			path: `${ NS }/pages/${ id }/ai/apply`,
			method: 'POST',
			data,
		} );
	},
	suggest(
		id: number,
		kind: 'titles' | 'bio',
		context: Record< string, unknown >
	) {
		return apiFetch< { suggestions: string[] } >( {
			path: `${ NS }/pages/${ id }/ai/suggest`,
			method: 'POST',
			data: { kind, context },
		} );
	},
	pageSettings( id: number, requireConfirmation: boolean ) {
		return apiFetch< { require_confirmation: boolean } >( {
			path: `${ NS }/pages/${ id }/ai/settings`,
			method: 'POST',
			data: { require_confirmation: requireConfirmation },
		} );
	},
	status() {
		return apiFetch< {
			available: boolean;
			reason: string;
			message: string;
			budget: BudgetState;
		} >( { path: `${ NS }/ai/status` } );
	},
};
