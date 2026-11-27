/**
 * Drag and drop: resolving a drop target to a parent and index.
 */
import * as D from '../../assets/src/editor/store/doc';
import { DROP_PREFIX, resolveDrop } from '../../assets/src/editor/lib/dnd';
import type { Block, PageDoc } from '../../assets/src/editor/types';

function block( type: string, extra: Partial< Block > = {} ): Block {
	return { ...D.createBlock( type ), ...extra };
}
function docOf( blocks: Block[] ): PageDoc {
	return D.normalize( { ...D.emptyDoc(), blocks } );
}
const ids = ( d: PageDoc, parent: string | null ) =>
	D.siblings( d, parent ).map( ( b ) => b.id );

describe( 'resolveDrop', () => {
	const a = block( 'link', { order: 0 } );
	const b = block( 'link', { order: 1 } );
	const g = block( 'group', { order: 2 } );
	const c1 = block( 'link', { parent_id: g.id, order: 0 } );
	const c2 = block( 'link', { parent_id: g.id, order: 1 } );
	const empty = block( 'group', { order: 3 } );
	const doc = docOf( [ a, b, g, c1, c2, empty ] );

	it( 'reorders inside one list like a sortable list', () => {
		expect( resolveDrop( doc.blocks, a.id, b.id ) ).toEqual( {
			parentId: null,
			index: 1,
		} );
		expect( resolveDrop( doc.blocks, b.id, a.id ) ).toEqual( {
			parentId: null,
			index: 0,
		} );
		expect( resolveDrop( doc.blocks, c1.id, c2.id ) ).toEqual( {
			parentId: g.id,
			index: 1,
		} );
	} );

	it( 'moves a block into a group by dropping on a child, before that child', () => {
		expect( resolveDrop( doc.blocks, a.id, c2.id ) ).toEqual( {
			parentId: g.id,
			index: 1,
		} );
	} );

	it( 'moves a block out of a group by dropping on a top-level block', () => {
		expect( resolveDrop( doc.blocks, c2.id, a.id ) ).toEqual( {
			parentId: null,
			index: 0,
		} );
	} );

	it( 'uses the end-of-group drop zone, including for an empty group', () => {
		expect( resolveDrop( doc.blocks, a.id, DROP_PREFIX + g.id ) ).toEqual( {
			parentId: g.id,
			index: 2,
		} );
		expect(
			resolveDrop( doc.blocks, a.id, DROP_PREFIX + empty.id )
		).toEqual( { parentId: empty.id, index: 0 } );
		expect(
			resolveDrop( doc.blocks, c2.id, DROP_PREFIX + g.id )
		).toBeNull();
		expect( resolveDrop( doc.blocks, c1.id, DROP_PREFIX + g.id ) ).toEqual(
			{ parentId: g.id, index: 1 }
		);
	} );

	it( 'keeps groups at the top level', () => {
		expect(
			resolveDrop( doc.blocks, g.id, DROP_PREFIX + empty.id )
		).toBeNull();
		expect( resolveDrop( doc.blocks, empty.id, c1.id ) ).toEqual( {
			parentId: null,
			index: 2,
		} );
		expect( resolveDrop( doc.blocks, a.id, a.id ) ).toBeNull();
		expect( resolveDrop( doc.blocks, a.id, 'missing' ) ).toBeNull();
	} );

	it( 'produces the document the drop describes', () => {
		const t = resolveDrop( doc.blocks, a.id, c2.id )!;
		const next = D.moveTo( doc, a.id, t.parentId, t.index );
		expect( ids( next, g.id ) ).toEqual( [ c1.id, a.id, c2.id ] );
	} );
} );
