/**
 * crazylinks/ai store reducer and selectors.
 */
import {
	aiReducer,
	aiActions as A,
	aiSelectors as S,
	initialAiState,
	HISTORY_SIZE,
} from '../../assets/src/editor/ai/store';
import type { AiState, Proposal } from '../../assets/src/editor/ai/types';
import { emptyDoc } from '../../assets/src/editor/store/doc';

function proposal( over: Partial< Proposal > = {} ): Proposal {
	return {
		proposal_id: 'abcdefghijklmnopqrstuvwx',
		summary: 'Renamed the single.',
		operations: [
			{ op: 'update_block', id: 'X', props: { title: 'New' } },
		],
		questions: [],
		preview_document: emptyDoc(),
		change_list: [
			{ kind: 'update', text: 'Change link “A”: title', block_id: 'X' },
		],
		mode: 'edit',
		expires_at: null,
		model: 'claude-sonnet-5-5',
		...over,
	};
}

const run = ( state: AiState, ...actions: ReturnType< typeof A.done >[] ) =>
	actions.reduce( aiReducer, state );

describe( 'ai reducer', () => {
	it( 'starts idle in edit mode', () => {
		const s = initialAiState();
		expect( s.mode ).toBe( 'edit' );
		expect( s.status ).toBe( 'idle' );
		expect( S.getCurrent( s ) ).toBeNull();
		expect( S.canApply( s ) ).toBe( false );
	} );

	it( 'tracks mode, instruction and request status', () => {
		let s = run(
			initialAiState(),
			A.setMode( 'restyle' ),
			A.setInstruction( 'Moody' )
		);
		expect( s.mode ).toBe( 'restyle' );
		expect( s.instruction ).toBe( 'Moody' );
		s = aiReducer( s, A.start( 'proposing' ) );
		expect( S.isBusy( s ) ).toBe( true );
		s = aiReducer(
			s,
			A.failed( 'Too many requests', [ { path: '/x', message: 'bad' } ] )
		);
		expect( s.status ).toBe( 'idle' );
		expect( s.error ).toBe( 'Too many requests' );
		expect( s.errors ).toHaveLength( 1 );
		s = aiReducer( s, A.start( 'proposing' ) );
		expect( s.error ).toBeNull();
		expect( s.errors ).toEqual( [] );
	} );

	it( 'adds proposals to the session history, newest first, superseding open ones', () => {
		let s = run(
			initialAiState(),
			A.proposed( 'a', 'one', 'edit', proposal(), 1 ),
			A.proposed( 'b', 'two', 'edit', proposal(), 2 )
		);
		expect( s.history.map( ( e ) => e.key ) ).toEqual( [ 'b', 'a' ] );
		expect( s.history[ 1 ].state ).toBe( 'discarded' );
		expect( S.getCurrent( s )?.key ).toBe( 'b' );
		expect( S.canApply( s ) ).toBe( true );

		for ( let i = 0; i < HISTORY_SIZE + 3; i++ ) {
			s = aiReducer(
				s,
				A.proposed( `k${ i }`, 'x', 'edit', proposal() )
			);
		}
		expect( s.history ).toHaveLength( HISTORY_SIZE );
	} );

	it( 'applies, discards and selects entries', () => {
		let s = aiReducer(
			initialAiState(),
			A.proposed( 'a', 'one', 'edit', proposal() )
		);
		s = run( s, A.start( 'applying' ), A.applied( 'a', 42 ) );
		expect( s.status ).toBe( 'idle' );
		expect( s.history[ 0 ] ).toMatchObject( {
			state: 'applied',
			revisionId: 42,
		} );
		expect( S.canApply( s ) ).toBe( false );

		s = aiReducer( s, A.proposed( 'b', 'two', 'edit', proposal() ) );
		s = aiReducer( s, A.discard( 'b' ) );
		expect( s.current ).toBeNull();
		expect( s.history[ 0 ].state ).toBe( 'discarded' );
		s = aiReducer( s, A.selectEntry( 'a' ) );
		expect( S.getCurrent( s )?.instruction ).toBe( 'one' );
		expect( s.history.find( ( e ) => e.key === 'a' )?.state ).toBe(
			'applied'
		);
	} );

	it( 'cannot apply questions-only answers', () => {
		const s = aiReducer(
			initialAiState(),
			A.proposed(
				'q',
				'Change the Spotify link',
				'edit',
				proposal( {
					proposal_id: null,
					operations: [],
					questions: [ 'Album or single?', 'New URL?' ],
				} )
			)
		);
		expect( S.canApply( s ) ).toBe( false );
	} );

	it( 'pairs answers with questions, skipping blank ones, and resets them on a new proposal', () => {
		let s = aiReducer(
			initialAiState(),
			A.proposed(
				'q',
				'x',
				'edit',
				proposal( { questions: [ 'Album or single?', 'New URL?' ] } )
			)
		);
		s = run( s, A.setAnswer( 0, ' The single ' ), A.setAnswer( 1, '   ' ) );
		expect( S.getAnswers( s ) ).toEqual( [
			{ question: 'Album or single?', answer: 'The single' },
		] );
		s = aiReducer( s, A.proposed( 'r', 'x', 'edit', proposal() ) );
		expect( s.answers ).toEqual( {} );
	} );

	it( 'switches the before/after view and resets it to after for new proposals', () => {
		let s = aiReducer(
			initialAiState(),
			A.proposed( 'a', 'x', 'edit', proposal() )
		);
		s = aiReducer( s, A.setView( 'before' ) );
		expect( s.view ).toBe( 'before' );
		s = aiReducer( s, A.proposed( 'b', 'x', 'edit', proposal() ) );
		expect( s.view ).toBe( 'after' );
	} );

	it( 'stores and clears suggestions', () => {
		let s = run(
			initialAiState(),
			A.start( 'suggesting' ),
			A.setSuggestions( {
				kind: 'titles',
				blockId: 'X',
				items: [ 'One', 'Two' ],
			} )
		);
		expect( s.status ).toBe( 'idle' );
		expect( s.suggestions?.items ).toEqual( [ 'One', 'Two' ] );
		s = aiReducer( s, A.setSuggestions( null ) );
		expect( s.suggestions ).toBeNull();
	} );

	it( 'ignores unknown actions', () => {
		const s = initialAiState();
		expect( aiReducer( s, { type: 'NOPE' } as never ) ).toBe( s );
	} );
} );
