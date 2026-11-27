/**
 * Paths: immutable get and set, plain-object checks.
 */
import {
	asObject,
	getPath,
	isPlainObject,
	setPath,
} from '../../assets/src/editor/lib/paths';

describe( 'paths', () => {
	it( 'gets and sets immutably', () => {
		const a = { x: { y: 1 } };
		const b = setPath( a, [ 'x', 'z' ], 2 );
		expect( a ).toEqual( { x: { y: 1 } } );
		expect( b ).toEqual( { x: { y: 1, z: 2 } } );
		expect( getPath( b, [ 'x', 'z' ] ) ).toBe( 2 );
		expect( setPath( b, [ 'x', 'y' ], undefined ) ).toEqual( {
			x: { z: 2 },
		} );
		expect( getPath( b, [ 'nope', 'deeper' ] ) ).toBeUndefined();
	} );
} );

describe( 'plain objects', () => {
	it( 'tells plain objects from everything else', () => {
		expect( isPlainObject( {} ) ).toBe( true );
		expect( isPlainObject( [] ) ).toBe( false );
		expect( isPlainObject( null ) ).toBe( false );
		expect( isPlainObject( 'x' ) ).toBe( false );
	} );

	it( 'falls back to an empty object', () => {
		const value = { a: 1 };
		expect( asObject( value ) ).toBe( value );
		expect( asObject( [ 1 ] ) ).toEqual( {} );
		expect( asObject( undefined ) ).toEqual( {} );
	} );
} );
