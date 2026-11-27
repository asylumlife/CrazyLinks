/**
 * Colour field: typed values, shown values and reset or clear actions.
 */
import {
	colorAction,
	shownColor,
	typedColor,
} from '../../assets/src/editor/components/ColorField';

describe( 'colour field', () => {
	it( 'removes the key when an ordinary field is emptied', () => {
		expect( typedColor( ' #abc ', false ) ).toBe( '#abc' );
		expect( typedColor( '   ', false ) ).toBeUndefined();
	} );

	it( 'stores an empty string where the schema accepts one', () => {
		expect( typedColor( '', true ) ).toBe( '' );
		expect( typedColor( '#abc', true ) ).toBe( '#abc' );
	} );

	it( 'shows the value, else the fallback, and nothing for an explicit "no colour"', () => {
		expect( shownColor( '#111111', '#222222', false ) ).toBe( '#111111' );
		expect( shownColor( undefined, '#222222', false ) ).toBe( '#222222' );
		expect( shownColor( undefined, undefined, false ) ).toBe( '' );
		expect( shownColor( '', '#222222', true ) ).toBe( '' );
		expect( shownColor( undefined, '#222222', true ) ).toBe( '#222222' );
	} );

	it( 'offers reset or clear on an ordinary field only when a colour is set', () => {
		expect( colorAction( undefined, '#222222', false ) ).toBeNull();
		expect( colorAction( '#111111', '#222222', false ) ).toEqual( {
			kind: 'reset',
			next: undefined,
		} );
		expect( colorAction( '#111111', undefined, false ) ).toEqual( {
			kind: 'clear',
			next: undefined,
		} );
	} );

	it( 'lets a theme colour be overridden with "no colour", then reset', () => {
		// The theme sets an overlay and the page has no value of its own: clearing stores ''.
		expect( colorAction( undefined, '#000000', true ) ).toEqual( {
			kind: 'clear',
			next: '',
		} );
		expect( colorAction( '#111111', '#000000', true ) ).toEqual( {
			kind: 'clear',
			next: '',
		} );
		// From "no colour", reset removes the key so the theme applies again.
		expect( colorAction( '', '#000000', true ) ).toEqual( {
			kind: 'reset',
			next: undefined,
		} );
		expect( colorAction( undefined, undefined, true ) ).toBeNull();
	} );
} );
