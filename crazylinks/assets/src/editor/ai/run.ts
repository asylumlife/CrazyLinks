/**
 * AI requests: propose, apply, suggest. They read the editor store and write the result back
 * through its normal actions.
 */
import { __ } from '@wordpress/i18n';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '../store';
import type { EditorSelectors } from '../hooks';
import { coerceDoc } from '../store/doc';
import { metaFrom } from '../lib/save';
import { errorsFromResponse } from '../lib/errors';
import type { ServerError } from '../types';
import { aiApi } from './api';
import { aiStore, aiSelectors } from './store';
import type { AiState, HistoryEntry } from './types';

const ed = () => select( editorStore ) as unknown as EditorSelectors;
const edAct = () => dispatch( editorStore );
const ai = () => dispatch( aiStore );
const aiState = (): AiState =>
	( select( aiStore ) as unknown as { getState: () => AiState } ).getState();

/**
 * A readable message and the field errors from a failed request.
 *
 * @param e Thrown apiFetch error.
 */
export function describeError( e: unknown ): {
	message: string;
	errors: ServerError[];
} {
	const err = e as { message?: string; code?: string } | null;
	const errors = errorsFromResponse( e ).filter( ( x ) => x.path !== '/' );
	if ( err?.code === 'fetch_error' ) {
		return {
			message: __(
				'Could not reach your site. Check your connection.',
				'crazylinks'
			),
			errors,
		};
	}
	return {
		message: err?.message || __( 'The AI request failed.', 'crazylinks' ),
		errors,
	};
}

let seq = 0;

/**
 * Sends the instruction (with any answers to the current proposal's questions).
 */
export async function propose(): Promise< void > {
	const state = aiState();
	const id = ed().getMeta().id;
	const instruction = state.instruction.trim();
	if ( ! instruction || id <= 0 || state.status !== 'idle' ) {
		return;
	}
	const answers = aiSelectors.getAnswers( state );
	ai().start( 'proposing' );
	try {
		const proposal = await aiApi.propose( id, {
			instruction,
			mode: state.mode,
			page: ed().getDoc(),
			...( answers.length ? { questions_answers: answers } : {} ),
		} );
		seq += 1;
		ai().proposed(
			`p${ Date.now() }-${ seq }`,
			instruction,
			state.mode,
			proposal
		);
	} catch ( e ) {
		const { message, errors } = describeError( e );
		ai().failed( message, errors );
	}
}

/**
 * Applies a proposal: replaces the working copy (one undo step, marks the page dirty), then saves
 * it through the apply endpoint so the server re-validates, logs and creates the revision.
 *
 * @param entry History entry.
 */
export async function applyProposal( entry: HistoryEntry ): Promise< boolean > {
	const id = ed().getMeta().id;
	const proposalId = entry.proposal.proposal_id;
	if ( ! proposalId || entry.state !== 'open' ) {
		return false;
	}
	const base = ed().getDoc();
	const next = coerceDoc( entry.proposal.preview_document );
	ai().start( 'applying' );
	edAct().replaceDoc( next );
	const sent = ed().getDoc();
	edAct().setSave( {
		status: 'saving',
		message: __( 'Saving AI changes…', 'crazylinks' ),
	} );
	try {
		const res = await aiApi.apply( id, {
			proposal_id: proposalId,
			page: base,
		} );
		edAct().saved( metaFrom( res.page ), coerceDoc( res.page.page ), sent );
		edAct().setSave( {
			status: 'idle',
			savedAt: res.page.modified,
			autosavedDoc: '',
			message: __( 'AI changes saved. Undo is available.', 'crazylinks' ),
		} );
		ai().applied( entry.key, res.revision_id );
		return true;
	} catch ( e ) {
		// Nothing was saved: take the change back out of the working copy if nothing else touched it.
		if ( JSON.stringify( ed().getDoc() ) === JSON.stringify( sent ) ) {
			edAct().undo();
		}
		const { message, errors } = describeError( e );
		edAct().setSave( { status: 'error', message } );
		ai().failed( message, errors );
		return false;
	}
}

/**
 * Title suggestions for a link, or bio rewrites for the profile.
 *
 * @param kind    titles | bio.
 * @param blockId Link block id for titles.
 */
export async function suggest(
	kind: 'titles' | 'bio',
	blockId: string | null = null
): Promise< void > {
	let context: Record< string, unknown >;
	if ( kind === 'titles' ) {
		const block = blockId ? ed().getBlock( blockId ) : undefined;
		if ( ! block ) {
			return;
		}
		context = {
			title: block.props.title ?? '',
			url: block.props.url ?? '',
			subtitle: block.props.subtitle ?? '',
			other_titles: ed()
				.getDoc()
				.blocks.filter(
					( b ) => b.type === 'link' && b.id !== block.id
				)
				.map( ( b ) => String( b.props.title ?? '' ) ),
		};
	} else {
		const doc = ed().getDoc();
		context = {
			name: doc.profile.name ?? '',
			bio: doc.profile.bio ?? '',
			link_titles: doc.blocks
				.filter( ( b ) => b.type === 'link' )
				.map( ( b ) => String( b.props.title ?? '' ) ),
		};
	}
	ai().start( 'suggesting' );
	try {
		const res = await aiApi.suggest( ed().getMeta().id, kind, context );
		ai().setSuggestions( { kind, blockId, items: res.suggestions } );
	} catch ( e ) {
		const { message, errors } = describeError( e );
		ai().failed( message, errors );
	}
}

/**
 * Inserts a suggestion into the page (an ordinary, undoable edit).
 *
 * @param text Suggestion.
 */
export function insertSuggestion( text: string ): void {
	const s = aiState().suggestions;
	if ( ! s ) {
		return;
	}
	if ( s.kind === 'titles' && s.blockId ) {
		const block = ed().getBlock( s.blockId );
		if ( block ) {
			edAct().setBlockProps( s.blockId, { ...block.props, title: text } );
		}
	} else if ( s.kind === 'bio' ) {
		edAct().setSection( 'profile', [ 'bio' ], text );
	}
	ai().setSuggestions( null );
}
