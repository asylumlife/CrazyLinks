/**
 * Validation-error mapping.
 */
import {
	blockErrors,
	errorsFromResponse,
	localErrors,
	mapErrors,
} from '../../assets/src/editor/lib/errors';
import { createBlock, emptyDoc } from '../../assets/src/editor/store/doc';
import type { PageDoc } from '../../assets/src/editor/types';

function doc(): PageDoc {
	const a = createBlock( 'link' );
	a.props = { title: 'A', url: 'https://a.test' };
	const b = createBlock( 'socials' );
	b.order = 1;
	return { ...emptyDoc(), blocks: [ a, b ] };
}

describe( 'mapErrors', () => {
	it( 'maps block paths to block ids and relative fields', () => {
		const d = doc();
		const out = mapErrors(
			[
				{ path: '/blocks/0/props/url', message: 'Bad' },
				{ path: '/blocks/1/props/items/2/url', message: 'Bad item' },
				{ path: '/blocks/1', message: 'Whole block' },
				{ path: '/design/custom_css', message: 'No CSS' },
				{ path: '/blocks/9/props/url', message: 'Out of range' },
			],
			d
		);
		expect( out[ 0 ] ).toMatchObject( {
			blockId: d.blocks[ 0 ].id,
			field: 'props/url',
		} );
		expect( out[ 1 ] ).toMatchObject( {
			blockId: d.blocks[ 1 ].id,
			field: 'props/items/2/url',
		} );
		expect( out[ 2 ] ).toMatchObject( {
			blockId: d.blocks[ 1 ].id,
			field: '',
		} );
		expect( out[ 3 ] ).toMatchObject( { field: '/design/custom_css' } );
		expect( out[ 3 ].blockId ).toBeUndefined();
		expect( out[ 4 ].blockId ).toBeUndefined();
	} );

	it( 'filters errors per block and field', () => {
		const d = doc();
		const out = mapErrors(
			[ { path: '/blocks/1/props/items/0/url', message: 'x' } ],
			d
		);
		expect( blockErrors( out, d.blocks[ 1 ].id ) ).toHaveLength( 1 );
		expect(
			blockErrors( out, d.blocks[ 1 ].id, 'props/items' )
		).toHaveLength( 1 );
		expect(
			blockErrors( out, d.blocks[ 1 ].id, 'props/style' )
		).toHaveLength( 0 );
		expect( blockErrors( out, d.blocks[ 0 ].id ) ).toHaveLength( 0 );
	} );
} );

describe( 'localErrors', () => {
	it( 'flags required fields with document paths', () => {
		const d = doc();
		d.blocks.push( { ...createBlock( 'heading' ), order: 2 } );
		const out = localErrors( d );
		expect( out ).toHaveLength( 1 );
		expect( out[ 0 ] ).toMatchObject( {
			path: '/blocks/2/props/text',
			field: 'props/text',
			blockId: d.blocks[ 2 ].id,
		} );
	} );
} );

describe( 'errorsFromResponse', () => {
	it( 'reads crazylinks_invalid_page errors', () => {
		const e = {
			code: 'crazylinks_invalid_page',
			message: 'Invalid',
			data: {
				status: 400,
				errors: [ { path: '/blocks/0', message: 'x' }, { nope: 1 } ],
			},
		};
		expect( errorsFromResponse( e ) ).toEqual( [
			{ path: '/blocks/0', message: 'x' },
		] );
	} );

	it( 'falls back to the message', () => {
		expect(
			errorsFromResponse( { code: 'rest_forbidden', message: 'Nope' } )
		).toEqual( [ { path: '/', message: 'Nope' } ] );
		expect( errorsFromResponse( null )[ 0 ].path ).toBe( '/' );
	} );
} );
