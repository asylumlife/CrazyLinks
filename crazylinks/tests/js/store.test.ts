/**
 * Store reducer and selectors.
 */
import {
	reducer,
	initialState,
	applyOp,
	HISTORY_LIMIT,
} from '../../assets/src/editor/store/reducer';
import type { EditorState } from '../../assets/src/editor/store/reducer';
import * as A from '../../assets/src/editor/store/actions';
import * as S from '../../assets/src/editor/store/selectors';
import { createBlock, emptyDoc } from '../../assets/src/editor/store/doc';
import type { Block, PageDoc, PageMeta } from '../../assets/src/editor/types';

const meta: PageMeta = {
	id: 5,
	title: 'Demo',
	slug: 'demo',
	status: 'publish',
	link: '',
	modified: '',
};

function link( title: string, url = 'https://example.com' ): Block {
	const b = createBlock( 'link' );
	b.props = { title, url };
	return b;
}

function start( blocks: Block[] = [] ): EditorState {
	const doc: PageDoc = {
		...emptyDoc(),
		blocks: blocks.map( ( b, i ) => ( { ...b, order: i } ) ),
	};
	return reducer( undefined, A.reset( doc, meta ) );
}

const run = ( state: EditorState, ...actions: ReturnType< typeof A.undo >[] ) =>
	actions.reduce( reducer, state );

describe( 'reducer', () => {
	it( 'starts clean after RESET', () => {
		const s = start( [ link( 'One' ) ] );
		expect( S.isDirty( s ) ).toBe( false );
		expect( S.canUndo( s ) ).toBe( false );
		expect( S.getMeta( s ).slug ).toBe( 'demo' );
	} );

	it( 'adds a block, selects it and records history', () => {
		const s = run( start(), A.addBlock( 'link' ) );
		expect( s.doc.blocks ).toHaveLength( 1 );
		expect( s.doc.blocks[ 0 ].type ).toBe( 'link' );
		expect( s.selected ).toBe( s.doc.blocks[ 0 ].id );
		expect( S.isDirty( s ) ).toBe( true );
		expect( S.canUndo( s ) ).toBe( true );
	} );

	it( 'inserts after a given block', () => {
		const a = link( 'A' );
		const b = link( 'B' );
		const s = run( start( [ a, b ] ), A.addBlock( 'heading', null, a.id ) );
		expect( s.doc.blocks.map( ( x ) => x.type ) ).toEqual( [
			'link',
			'heading',
			'link',
		] );
		expect( s.doc.blocks.map( ( x ) => x.order ) ).toEqual( [ 0, 1, 2 ] );
	} );

	it( 'undoes and redoes', () => {
		const a = link( 'A' );
		let s = run(
			start( [ a ] ),
			A.setBlockProps( a.id, { title: 'B', url: 'https://example.com' } )
		);
		expect( s.doc.blocks[ 0 ].props.title ).toBe( 'B' );
		s = reducer( s, A.undo() );
		expect( s.doc.blocks[ 0 ].props.title ).toBe( 'A' );
		expect( S.isDirty( s ) ).toBe( false );
		s = reducer( s, A.redo() );
		expect( s.doc.blocks[ 0 ].props.title ).toBe( 'B' );
		expect( S.canRedo( s ) ).toBe( false );
	} );

	it( 'collapses consecutive edits with the same key into one undo step', () => {
		const a = link( 'A' );
		let s = start( [ a ] );
		for ( const t of [ 'H', 'He', 'Hel', 'Hell', 'Hello' ] ) {
			s = reducer(
				s,
				A.setBlockProps(
					a.id,
					{ title: t, url: 'https://example.com' },
					`${ a.id }:title`
				)
			);
		}
		expect( s.past ).toHaveLength( 1 );
		s = reducer( s, A.undo() );
		expect( s.doc.blocks[ 0 ].props.title ).toBe( 'A' );
	} );

	it( 'clears redo after a new edit and caps history', () => {
		const a = link( 'A' );
		let s = run(
			start( [ a ] ),
			A.updateBlock( a.id, { visible: false } ),
			A.undo()
		);
		expect( S.canRedo( s ) ).toBe( true );
		s = reducer( s, A.updateBlock( a.id, { pinned: true } ) );
		expect( S.canRedo( s ) ).toBe( false );
		for ( let i = 0; i < HISTORY_LIMIT + 20; i++ ) {
			s = reducer( s, A.updateBlock( a.id, { visible: i % 2 === 0 } ) );
		}
		expect( s.past.length ).toBeLessThanOrEqual( HISTORY_LIMIT );
	} );

	it( 'ignores no-op edits', () => {
		const s = start( [ link( 'A' ) ] );
		expect( reducer( s, A.moveBlock( s.doc.blocks[ 0 ].id, -1 ) ) ).toBe(
			s
		);
	} );

	it( 'applies a theme and clears the overrides it owns', () => {
		let s = start();
		s = run(
			s,
			A.setSection( 'design', [ 'background', 'color' ], '#000000' ),
			A.setSection( 'design', [ 'layout' ], 'grid' ),
			A.setSection( 'design', [ 'button', 'color' ], '#ff0000' ),
			A.applyTheme( 'midnight' )
		);
		expect( s.doc.design ).toEqual( { layout: 'grid', theme: 'midnight' } );
	} );

	it( 'sets and removes section values by path', () => {
		let s = run(
			start(),
			A.setSection( 'settings', [ 'seo', 'title' ], 'Hi' )
		);
		expect( s.doc.settings ).toEqual( { seo: { title: 'Hi' } } );
		s = reducer(
			s,
			A.setSection( 'settings', [ 'seo', 'title' ], undefined )
		);
		expect( s.doc.settings ).toEqual( { seo: {} } );
	} );

	it( 'adopts the server document after a save only when nothing changed meanwhile', () => {
		const a = link( 'A' );
		const s = run(
			start( [ a ] ),
			A.setBlockProps( a.id, {
				title: 'B',
				url: 'https://example.com/x',
			} )
		);
		const sent = s.doc;
		const server = {
			...sent,
			blocks: [
				{
					...sent.blocks[ 0 ],
					props: { title: 'B', url: 'https://example.com/x?clean' },
				},
			],
		};
		const saved = reducer(
			s,
			A.saved( { ...meta, modified: 'now' }, server, sent )
		);
		expect( saved.doc.blocks[ 0 ].props.url ).toBe(
			'https://example.com/x?clean'
		);
		expect( S.isDirty( saved ) ).toBe( false );

		const moved = reducer(
			s,
			A.setBlockProps( a.id, { title: 'C', url: 'https://example.com' } )
		);
		const late = reducer( moved, A.saved( meta, server, sent ) );
		expect( late.doc.blocks[ 0 ].props.title ).toBe( 'C' );
		expect( S.isDirty( late ) ).toBe( true );
	} );

	it( 'tracks title and slug changes as dirty', () => {
		const s = run( start(), A.setMeta( { title: 'New name' } ) );
		expect( S.isDirty( s ) ).toBe( true );
		expect( S.isDocDirty( s ) ).toBe( false );
	} );

	it( 'needs an autosave only for unsaved, not-yet-autosaved changes', () => {
		let s = run( start(), A.addBlock( 'divider' ) );
		expect( S.needsAutosave( s ) ).toBe( true );
		s = reducer( s, A.setSave( { autosavedDoc: S.getDocJson( s ) } ) );
		expect( S.needsAutosave( s ) ).toBe( false );
	} );

	it( 'keeps the media cache across RESET', () => {
		let s = reducer(
			start(),
			A.addMedia( [
				{ id: 3, url: 'u', thumb: 't', alt: '', mime: 'image/png' },
			] )
		);
		s = reducer( s, A.reset( emptyDoc(), meta ) );
		expect( S.getMedia( s, 3 )?.thumb ).toBe( 't' );
	} );
} );

describe( 'selectors', () => {
	it( 'merges server and local errors without duplicates', () => {
		const empty = createBlock( 'link' );
		let s = start( [ empty ] );
		expect(
			S.getLocalErrors( s )
				.map( ( e ) => e.field )
				.sort()
		).toEqual( [ 'props/title', 'props/url' ] );
		s = reducer(
			s,
			A.setErrors( [
				{
					path: '/blocks/0/props/url',
					message: 'Bad URL',
					blockId: empty.id,
					field: 'props/url',
				},
			] )
		);
		const all = S.getAllErrors( s );
		expect( all ).toHaveLength( 2 );
		expect( all.find( ( e ) => e.field === 'props/url' )?.message ).toBe(
			'Bad URL'
		);
	} );

	it( 'returns children and groups', () => {
		const g = createBlock( 'group' );
		const c = { ...link( 'Child' ), parent_id: g.id };
		const s = start( [ g, c, link( 'Top' ) ] );
		expect( S.getGroups( s ).map( ( b ) => b.id ) ).toEqual( [ g.id ] );
		expect(
			S.getChildren( s, g.id ).map( ( b ) => b.props.title )
		).toEqual( [ 'Child' ] );
		expect( S.getChildren( s, null ) ).toHaveLength( 2 );
	} );
} );

describe( 'applyOp', () => {
	it( 'replaces the document normalized', () => {
		const doc = emptyDoc();
		const b = { ...link( 'x' ), order: 7 };
		const out = applyOp( doc, {
			kind: 'replace',
			doc: { ...doc, blocks: [ b ] },
		} );
		expect( out.doc.blocks[ 0 ].order ).toBe( 0 );
	} );

	it( 'selects the duplicate', () => {
		const a = link( 'A' );
		const s = run( start( [ a ] ), A.duplicateBlock( a.id ) );
		expect( s.doc.blocks ).toHaveLength( 2 );
		expect( s.selected ).toBe( s.doc.blocks[ 1 ].id );
		expect( s.doc.blocks[ 1 ].id ).not.toBe( a.id );
	} );
} );

describe( 'initialState', () => {
	it( 'is clean', () => {
		expect( S.isDirty( initialState() ) ).toBe( false );
	} );
} );
