/**
 * Pure document operations.
 */
import * as D from '../../assets/src/editor/store/doc';
import { ULID_PATTERN } from '../../assets/src/editor/lib/ulid';
import type { Block, PageDoc } from '../../assets/src/editor/types';

function block( type: string, extra: Partial< Block > = {} ): Block {
	return { ...D.createBlock( type ), ...extra };
}

function docOf( blocks: Block[] ): PageDoc {
	return D.normalize( { ...D.emptyDoc(), blocks } );
}

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

	it( 'lists referenced attachments', () => {
		const d: PageDoc = {
			...D.emptyDoc(),
			profile: { avatar: 3, banner: 4 },
			design: { background: { image: 5 } },
			settings: { seo: { image: 6 } },
			blocks: [
				block( 'gallery', {
					props: { items: [ { image: 7 }, { image: 7 } ] },
				} ),
				block( 'image', { props: { image: 8 } } ),
			],
		};
		expect( D.mediaIds( d ).sort() ).toEqual( [ 3, 4, 5, 6, 7, 8 ] );
	} );

	it( 'detects empty documents', () => {
		expect( D.isEmptyDoc( D.emptyDoc() ) ).toBe( true );
		expect(
			D.isEmptyDoc( { ...D.emptyDoc(), profile: { name: 'Me' } } )
		).toBe( false );
	} );
} );
