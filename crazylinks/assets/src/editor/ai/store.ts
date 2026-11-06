/**
 * The `crazylinks/ai` store: composer state, this session's proposals and suggestions.
 *
 * Kept apart from `crazylinks/editor`; a proposal reaches the page only through the editor's
 * `replaceDoc` action, so undo and the dirty state work as for any other edit.
 */
import { createReduxStore, register } from '@wordpress/data';
import type { ServerError } from '../types';
import type {
	AiMode,
	AiState,
	HistoryEntry,
	Proposal,
	Suggestions,
} from './types';

export const AI_STORE = 'crazylinks/ai';
export const HISTORY_SIZE = 10;

export type AiAction =
	| { type: 'SET_MODE'; mode: AiMode }
	| { type: 'SET_INSTRUCTION'; instruction: string }
	| { type: 'START'; status: 'proposing' | 'applying' | 'suggesting' }
	| { type: 'FAILED'; message: string; errors: ServerError[] }
	| {
			type: 'PROPOSED';
			key: string;
			instruction: string;
			mode: AiMode;
			proposal: Proposal;
			at: number;
	  }
	| { type: 'EXTERNAL'; proposals: Proposal[]; at: number }
	| { type: 'SET_ANSWER'; index: number; value: string }
	| { type: 'SET_VIEW'; view: 'after' | 'before' }
	| { type: 'APPLIED'; key: string; revisionId: number | null }
	| { type: 'DISCARD'; key: string }
	| { type: 'SELECT_ENTRY'; key: string | null }
	| { type: 'SUGGESTIONS'; suggestions: Suggestions | null }
	| { type: 'DONE' };

export function initialAiState(): AiState {
	return {
		mode: 'edit',
		instruction: '',
		status: 'idle',
		error: null,
		errors: [],
		history: [],
		current: null,
		answers: {},
		view: 'after',
		suggestions: null,
	};
}

function patchEntry(
	history: HistoryEntry[],
	key: string,
	patch: Partial< HistoryEntry >
): HistoryEntry[] {
	return history.map( ( e ) => ( e.key === key ? { ...e, ...patch } : e ) );
}

/**
 * History key of a pending change from an external agent.
 *
 * @param p Proposal.
 */
export function externalKey( p: Proposal ): string {
	return `ext-${ p.proposal_id ?? '' }`;
}

export function aiReducer(
	state: AiState = initialAiState(),
	action: AiAction
): AiState {
	switch ( action.type ) {
		case 'SET_MODE':
			return { ...state, mode: action.mode };
		case 'SET_INSTRUCTION':
			return { ...state, instruction: action.instruction };
		case 'START':
			return { ...state, status: action.status, error: null, errors: [] };
		case 'FAILED':
			return {
				...state,
				status: 'idle',
				error: action.message,
				errors: action.errors,
			};
		case 'DONE':
			return { ...state, status: 'idle' };
		case 'PROPOSED': {
			const entry: HistoryEntry = {
				key: action.key,
				instruction: action.instruction,
				mode: action.mode,
				proposal: action.proposal,
				state: 'open',
				createdAt: action.at,
			};
			// A new proposal supersedes open ones that were never applied, except changes from external
			// agents, which wait for the person's decision.
			const older = state.history.map( ( e ) =>
				e.state === 'open' && ! e.external
					? { ...e, state: 'discarded' as const }
					: e
			);
			return {
				...state,
				status: 'idle',
				error: null,
				errors: [],
				history: [ entry, ...older ].slice( 0, HISTORY_SIZE ),
				current: action.key,
				answers: {},
				view: 'after',
			};
		}
		case 'EXTERNAL': {
			const known = new Set( state.history.map( ( e ) => e.key ) );
			const added: HistoryEntry[] = action.proposals
				.filter(
					( p ) => !! p.proposal_id && ! known.has( externalKey( p ) )
				)
				.map( ( p ) => ( {
					key: externalKey( p ),
					instruction: p.summary,
					mode: p.mode,
					proposal: p,
					state: 'open' as const,
					createdAt: p.created_at
						? Date.parse( p.created_at )
						: action.at,
					external: true,
				} ) );
			if ( ! added.length ) {
				return state;
			}
			return {
				...state,
				history: [ ...added, ...state.history ].slice(
					0,
					HISTORY_SIZE + added.length
				),
				current: state.current ?? added[ 0 ].key,
			};
		}
		case 'SET_ANSWER':
			return {
				...state,
				answers: { ...state.answers, [ action.index ]: action.value },
			};
		case 'SET_VIEW':
			return { ...state, view: action.view };
		case 'APPLIED':
			return {
				...state,
				status: 'idle',
				history: patchEntry( state.history, action.key, {
					state: 'applied',
					revisionId: action.revisionId,
				} ),
			};
		case 'DISCARD':
			return {
				...state,
				history: patchEntry( state.history, action.key, {
					state: 'discarded',
				} ),
				current: state.current === action.key ? null : state.current,
			};
		case 'SELECT_ENTRY':
			return { ...state, current: action.key, view: 'after' };
		case 'SUGGESTIONS':
			return {
				...state,
				status: 'idle',
				suggestions: action.suggestions,
			};
	}
	return state;
}

export const aiActions = {
	setMode: ( mode: AiMode ): AiAction => ( { type: 'SET_MODE', mode } ),
	setInstruction: ( instruction: string ): AiAction => ( {
		type: 'SET_INSTRUCTION',
		instruction,
	} ),
	start: ( status: 'proposing' | 'applying' | 'suggesting' ): AiAction => ( {
		type: 'START',
		status,
	} ),
	failed: ( message: string, errors: ServerError[] = [] ): AiAction => ( {
		type: 'FAILED',
		message,
		errors,
	} ),
	done: (): AiAction => ( { type: 'DONE' } ),
	proposed: (
		key: string,
		instruction: string,
		mode: AiMode,
		proposal: Proposal,
		at: number = Date.now()
	): AiAction => ( {
		type: 'PROPOSED',
		key,
		instruction,
		mode,
		proposal,
		at,
	} ),
	external: (
		proposals: Proposal[],
		at: number = Date.now()
	): AiAction => ( {
		type: 'EXTERNAL',
		proposals,
		at,
	} ),
	setAnswer: ( index: number, value: string ): AiAction => ( {
		type: 'SET_ANSWER',
		index,
		value,
	} ),
	setView: ( view: 'after' | 'before' ): AiAction => ( {
		type: 'SET_VIEW',
		view,
	} ),
	applied: ( key: string, revisionId: number | null ): AiAction => ( {
		type: 'APPLIED',
		key,
		revisionId,
	} ),
	discard: ( key: string ): AiAction => ( { type: 'DISCARD', key } ),
	selectEntry: ( key: string | null ): AiAction => ( {
		type: 'SELECT_ENTRY',
		key,
	} ),
	setSuggestions: ( suggestions: Suggestions | null ): AiAction => ( {
		type: 'SUGGESTIONS',
		suggestions,
	} ),
};

export const aiSelectors = {
	getState: ( state: AiState ) => state,
	getCurrent: ( state: AiState ): HistoryEntry | null =>
		state.history.find( ( e ) => e.key === state.current ) ?? null,
	isBusy: ( state: AiState ) => state.status !== 'idle',
	/** Changes from external agents still waiting for a decision. */
	getExternalOpen: ( state: AiState ): HistoryEntry[] =>
		state.history.filter( ( e ) => e.external && e.state === 'open' ),
	/** Questions paired with the answers typed so far (only answered ones). */
	getAnswers: ( state: AiState ) => {
		const entry = aiSelectors.getCurrent( state );
		if ( ! entry ) {
			return [];
		}
		return entry.proposal.questions
			.map( ( question, i ) => ( {
				question,
				answer: ( state.answers[ i ] ?? '' ).trim(),
			} ) )
			.filter( ( qa ) => qa.answer !== '' );
	},
	canApply: ( state: AiState ): boolean => {
		const entry = aiSelectors.getCurrent( state );
		return (
			!! entry &&
			entry.state === 'open' &&
			!! entry.proposal.proposal_id &&
			entry.proposal.operations.length > 0 &&
			state.status === 'idle'
		);
	},
};

export const aiStore = createReduxStore( AI_STORE, {
	reducer: aiReducer,
	actions: aiActions,
	selectors: aiSelectors,
} );

let registered = false;

/** Registers the store once. */
export function registerAiStore(): void {
	if ( ! registered ) {
		register( aiStore );
		registered = true;
	}
}
