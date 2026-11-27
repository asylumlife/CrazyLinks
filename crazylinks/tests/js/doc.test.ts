/**
 * Pure document operations.
 */
import {
	applyOp,
	initialState,
	reducer,
} from '../../assets/src/editor/store/reducer';
import { moveBlockTo } from '../../assets/src/editor/store/actions';
import * as D from '../../assets/src/editor/store/doc';
import { ULID_PATTERN } from '../../assets/src/editor/lib/ulid';
import type { Block, PageDoc } from '../../assets/src/editor/types';

function block( type: string, extra: Partial< Block > = {} ): Block {
	return { ...D.createBlock( type ), ...extra };
}

function docOf( blocks: Block[] ): PageDoc {
	return D.normalize( { ...D.emptyDoc(), blocks } );
}

const ids = ( d: PageDoc, parent: string | null ) =>
	D.siblings( d, parent ).map( ( b ) => b.id );

describe( 'document operations', () => {
	it( 'creates blocks with ULIDs and registry defaults', () => {
		const b = D.createBlock( 'spacer' );
		expect( b.id ).toMatch( ULID_PATTERN );
		expect( b.props ).toEqual( { height: 24 } );
		expect( b.schedule ).toEqual( { start: null, end: null } );
		expect(
			D.createBlock( 'group', 'X'.repeat( 26 ) ).parent_id
		).toBeNull();
	} );

	it( 'normalizes like PageValidator::reorder (groups followed by children)', () => {
		const g = block( 'group', { order: 1 } );
		const c2 = block( 'link', { parent_id: g.id, order: 5 } );
		const c1 = block( 'link', { parent_id: g.id, order: 2 } );
		const top = block( 'heading', { order: 0 } );
		const d = docOf( [ c2, g, c1, top ] );
		expect( d.blocks.map( ( b ) => b.id ) ).toEqual( [
			top.id,
			g.id,
			c1.id,
			c2.id,
		] );
		expect( d.blocks.map( ( b ) => b.order ) ).toEqual( [ 0, 1, 0, 1 ] );
	} );

	it( 'promotes orphans and refuses nested groups', () => {
		const orphan = block( 'link', { parent_id: 'Z'.repeat( 26 ) } );
		const g1 = block( 'group' );
		const g2 = block( 'group', { parent_id: g1.id } );
		const d = docOf( [ orphan, g1, g2 ] );
		expect( d.blocks.every( ( b ) => b.parent_id === null ) ).toBe( true );
	} );

	it( 'moves within siblings', () => {
		const a = block( 'link', { order: 0 } );
		const b = block( 'link', { order: 1 } );
		const c = block( 'link', { order: 2 } );
		const d = D.moveBlock( docOf( [ a, b, c ] ), c.id, -1 );
		expect( d.blocks.map( ( x ) => x.id ) ).toEqual( [ a.id, c.id, b.id ] );
		expect( D.moveBlock( d, a.id, -1 ) ).toEqual( d );
	} );

	it( 'reorders a list by ids', () => {
		const a = block( 'link', { order: 0 } );
		const b = block( 'link', { order: 1 } );
		const d = D.applyOrder( docOf( [ a, b ] ), null, [ b.id, a.id ] );
		expect( d.blocks.map( ( x ) => x.id ) ).toEqual( [ b.id, a.id ] );
	} );

	it( 'moves blocks into and out of groups', () => {
		const g = block( 'group', { order: 0 } );
		const a = block( 'link', { order: 1 } );
		let d = D.setParent( docOf( [ g, a ] ), a.id, g.id );
		expect( d.blocks[ 1 ] ).toMatchObject( {
			id: a.id,
			parent_id: g.id,
			order: 0,
		} );
		d = D.setParent( d, a.id, null );
		expect( d.blocks[ 1 ] ).toMatchObject( {
			id: a.id,
			parent_id: null,
			order: 1,
		} );
		expect( D.setParent( d, g.id, a.id ) ).toBe( d );
	} );

	it( 'removes a group with its children', () => {
		const g = block( 'group' );
		const c = block( 'link', { parent_id: g.id } );
		const keep = block( 'heading' );
		const d = D.removeBlock( docOf( [ g, c, keep ] ), g.id );
		expect( d.blocks.map( ( b ) => b.id ) ).toEqual( [ keep.id ] );
	} );

	it( 'duplicates a group with fresh ids for every child', () => {
		const g = block( 'group', { order: 0, props: { title: 'G' } } );
		const c = block( 'link', {
			parent_id: g.id,
			props: { title: 'C', url: 'https://x.test' },
		} );
		const { doc, id } = D.duplicateBlock( docOf( [ g, c ] ), g.id );
		expect( doc.blocks ).toHaveLength( 4 );
		const copyChildren = doc.blocks.filter( ( b ) => b.parent_id === id );
		expect( copyChildren ).toHaveLength( 1 );
		expect( copyChildren[ 0 ].id ).not.toBe( c.id );
		expect( new Set( doc.blocks.map( ( b ) => b.id ) ).size ).toBe( 4 );
	} );

	it( 'coerces loose input into the document shape', () => {
		const d = D.coerceDoc( {
			blocks: [
				{ type: 'link', props: { title: 'x', url: 'y' } },
				'junk',
			],
			profile: [],
		} );
		expect( d.schema ).toBe( 1 );
		expect( d.profile ).toEqual( {} );
		expect( d.blocks ).toHaveLength( 1 );
		expect( d.blocks[ 0 ] ).toMatchObject( {
			visible: true,
			pinned: false,
			tracking: true,
			animation: 'none',
			parent_id: null,
		} );
		expect( d.blocks[ 0 ].id ).toMatch( ULID_PATTERN );
	} );

	it( 'drops incomplete blocks (and their children) from previews', () => {
		const g = block( 'group' );
		const c = block( 'link', { parent_id: g.id } );
		const ok = block( 'divider' );
		const d = D.withoutBlocks( docOf( [ g, c, ok ] ), new Set( [ g.id ] ) );
		expect( d.blocks.map( ( b ) => b.id ) ).toEqual( [ ok.id ] );
	} );

	it( 'lists the blocks under one parent in order', () => {
		const g = block( 'group', { order: 1 } );
		const first = block( 'link', { parent_id: g.id, order: 0 } );
		const second = block( 'link', { parent_id: g.id, order: 1 } );
		const top = block( 'divider', { order: 0 } );
		const blocks = [ second, g, top, first ];
		expect( D.siblingsIn( blocks, g.id ).map( ( b ) => b.id ) ).toEqual( [
			first.id,
			second.id,
		] );
		expect( D.siblingsIn( blocks, null ).map( ( b ) => b.id ) ).toEqual( [
			top.id,
			g.id,
		] );
	} );

	it( 'knows whether a block is scheduled', () => {
		const plain = block( 'link' );
		expect( D.isScheduled( plain ) ).toBe( false );
		expect(
			D.isScheduled( {
				...plain,
				schedule: { start: '2026-01-01T00:00:00Z', end: null },
			} )
		).toBe( true );
		expect(
			D.isScheduled( {
				...plain,
				schedule: {
					start: null,
					end: null,
					weekly: { days: [ 1 ], start: '09:00', end: '17:00' },
				},
			} )
		).toBe( true );
	} );

	it( 'detects empty documents', () => {
		expect( D.isEmptyDoc( D.emptyDoc() ) ).toBe( true );
		expect(
			D.isEmptyDoc( { ...D.emptyDoc(), profile: { name: 'Me' } } )
		).toBe( false );
	} );
} );

describe( 'moveTo', () => {
	const a = block( 'link', { order: 0 } );
	const g = block( 'group', { order: 1 } );
	const c1 = block( 'link', { parent_id: g.id, order: 0 } );
	const c2 = block( 'link', { parent_id: g.id, order: 1 } );
	const z = block( 'heading', { order: 2 } );
	const doc = docOf( [ a, g, c1, c2, z ] );

	it( 'moves a top-level block into a group at a position', () => {
		const next = D.moveTo( doc, a.id, g.id, 1 );
		expect( ids( next, g.id ) ).toEqual( [ c1.id, a.id, c2.id ] );
		expect( ids( next, null ) ).toEqual( [ g.id, z.id ] );
		expect( next.blocks.map( ( b ) => b.id ) ).toEqual( [
			g.id,
			c1.id,
			a.id,
			c2.id,
			z.id,
		] );
	} );

	it( 'moves a child out to the top level and renumbers both lists', () => {
		const next = D.moveTo( doc, c1.id, null, 0 );
		expect( ids( next, null ) ).toEqual( [ c1.id, a.id, g.id, z.id ] );
		expect( ids( next, g.id ) ).toEqual( [ c2.id ] );
		expect( D.siblings( next, g.id )[ 0 ].order ).toBe( 0 );
		expect(
			next.blocks.find( ( b ) => b.id === c1.id )?.parent_id
		).toBeNull();
	} );

	it( 'never nests a group and ignores unknown targets', () => {
		const g2 = block( 'group', { order: 3 } );
		const d = docOf( [ a, g, g2 ] );
		expect( D.moveTo( d, g2.id, g.id, 0 ) ).toBe( d );
		expect( D.moveTo( d, a.id, 'NOPE', 0 ) ).toBe( d );
		expect( D.moveTo( d, a.id, z.id, 0 ) ).toBe( d );
	} );

	it( 'is wired through the reducer action', () => {
		const state = initialState( doc );
		const next = reducer( state, moveBlockTo( a.id, g.id, 0 ) );
		expect( ids( next.doc, g.id )[ 0 ] ).toBe( a.id );
		expect(
			applyOp( doc, {
				kind: 'moveTo',
				id: a.id,
				parentId: g.id,
				index: 9,
			} ).doc.blocks.at( -2 )?.id
		).toBe( a.id );
	} );
} );

describe( 'pin toggle', () => {
	it( 'leaves the document alone for an unknown id', () => {
		const doc = D.emptyDoc();
		expect( D.togglePinned( doc, 'nope' ) ).toBe( doc );
	} );
} );

describe( 'weekly windows in a loaded document', () => {
	const w = { days: [ 1, 2, 3, 4 ], start: '12:30', end: '14:00' };

	it( 'keeps weekly when a document is loaded', () => {
		const doc = D.coerceDoc( {
			blocks: [
				{
					type: 'link',
					schedule: { start: null, weekly: w },
					props: {},
				},
			],
		} );
		expect( doc.blocks[ 0 ].schedule ).toEqual( {
			start: null,
			end: null,
			weekly: w,
		} );
	} );
} );
