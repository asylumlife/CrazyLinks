/**
 * Helpers shared across the editor: colour field values, shortcut hints, class names, plain-object
 * checks, error lookups, theme presets, background samples and shared option lists.
 */
import {
	colorAction,
	shownColor,
	typedColor,
} from '../../assets/src/editor/components/ColorField';
import { classes } from '../../assets/src/editor/lib/classes';
import { backgroundSamples, blend } from '../../assets/src/editor/lib/contrast';
import {
	errorFor,
	forBlock,
	isValidationError,
	subMessages,
} from '../../assets/src/editor/lib/errors';
import { asObject, isPlainObject } from '../../assets/src/editor/lib/paths';
import {
	isApplePlatform,
	primaryShortcut,
} from '../../assets/src/editor/lib/shortcut';
import { DEFAULT_THEME, presetFor } from '../../assets/src/editor/lib/theme';
import {
	animationOptions,
	backgroundTypeOptions,
	buttonFillOptions,
	opts,
	patternOptions,
	weekdayOptions,
} from '../../assets/src/editor/registry/options';
import type { MappedError } from '../../assets/src/editor/types';

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

describe( 'shortcut hints', () => {
	it( 'recognises Apple platforms', () => {
		expect( isApplePlatform( 'MacIntel' ) ).toBe( true );
		expect( isApplePlatform( 'iPhone' ) ).toBe( true );
		expect( isApplePlatform( 'Win32' ) ).toBe( false );
		expect( isApplePlatform( 'Linux x86_64' ) ).toBe( false );
	} );

	it( 'uses Command on Apple devices and Ctrl elsewhere', () => {
		expect( primaryShortcut( 'Z', false, 'MacIntel' ) ).toBe( '⌘Z' );
		expect( primaryShortcut( 'Z', true, 'MacIntel' ) ).toBe( '⇧⌘Z' );
		expect( primaryShortcut( 'Z', false, 'Win32' ) ).toBe( 'Ctrl+Z' );
		expect( primaryShortcut( 'Z', true, 'Win32' ) ).toBe( 'Ctrl+Shift+Z' );
	} );
} );

describe( 'class names', () => {
	it( 'joins the parts that apply', () => {
		expect( classes( 'a', false, undefined, null, '', 'b' ) ).toBe( 'a b' );
		expect( classes( undefined, 'only' ) ).toBe( 'only' );
		expect( classes() ).toBe( '' );
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

describe( 'error lookups', () => {
	const errors: MappedError[] = [
		{
			path: '/design/text/color',
			field: '/design/text/color',
			message: 'A',
		},
		{ path: '/settings/seo', field: '/settings/seo', message: 'B' },
		{
			path: '/blocks/0/props/url',
			field: 'props/url',
			blockId: 'X',
			message: 'C',
		},
		{
			path: '/blocks/0/props/items/1/url',
			field: 'props/items/1/url',
			blockId: 'X',
			message: 'D',
		},
	];

	it( 'finds a page-level error at a path or below it', () => {
		expect( errorFor( errors, '/design/text/color' ) ).toBe( 'A' );
		expect( errorFor( errors, '/design/text' ) ).toBe( 'A' );
		expect( errorFor( errors, '/design/tex' ) ).toBeUndefined();
		expect( errorFor( errors, '/settings/seo' ) ).toBe( 'B' );
		expect( errorFor( errors, '/profile/name' ) ).toBeUndefined();
	} );

	it( 'narrows entries to a block and a field', () => {
		expect( forBlock( errors, 'X' ) ).toHaveLength( 2 );
		expect( forBlock( errors, 'X', 'props/items' ) ).toHaveLength( 1 );
		expect( forBlock( errors, 'Y' ) ).toEqual( [] );
	} );

	it( 'keys sub-path messages by the path below the field', () => {
		expect( subMessages( errors, 'props/items' ) ).toEqual( {
			'1/url': 'D',
		} );
		expect( subMessages( errors, 'props/url' ) ).toEqual( {} );
	} );

	it( 'tells validator rejections from other failures', () => {
		expect(
			isValidationError( {
				code: 'crazylinks_invalid_page',
				data: { errors: [ { path: '/', message: 'bad' } ] },
			} )
		).toBe( true );
		expect( isValidationError( { code: 'fetch_error' } ) ).toBe( false );
		expect( isValidationError( null ) ).toBe( false );
	} );
} );

describe( 'theme preset', () => {
	const themes = {
		default: { label: 'Default' },
		neon: { label: 'Neon' },
	};

	it( 'resolves the slug and preset of a design', () => {
		expect( presetFor( { theme: 'neon' }, themes ) ).toEqual( {
			slug: 'neon',
			preset: themes.neon,
		} );
	} );

	it( 'falls back to the default theme', () => {
		expect( presetFor( {}, themes ) ).toEqual( {
			slug: DEFAULT_THEME,
			preset: themes.default,
		} );
		// An unknown slug is kept, but renders with the default preset.
		expect( presetFor( { theme: 'gone' }, themes ) ).toEqual( {
			slug: 'gone',
			preset: themes.default,
		} );
		expect( presetFor( {}, {} ).preset ).toBeUndefined();
	} );
} );

describe( 'background samples', () => {
	const tokens = {
		bg: '#000000',
		text: '#ffffff',
		muted: '#cccccc',
		btn: '#ffffff',
		btnText: '#000000',
		btnFill: 'solid',
		accent: '#ff0000',
	};

	it( 'is the solid colour, or five samples along a gradient', () => {
		expect( backgroundSamples( tokens ) ).toEqual( [ '#000000' ] );
		const samples = backgroundSamples( { ...tokens, bgTo: '#ffffff' } );
		expect( samples ).toHaveLength( 5 );
		expect( samples[ 0 ] ).toBe( '#000000' );
		expect( samples[ 2 ] ).toBe( blend( '#ffffff', '#000000', 0.5 ) );
		expect( samples[ 4 ] ).toBe( '#ffffff' );
	} );
} );

describe( 'shared option lists', () => {
	const values = ( list: Array< { value: string } > ) =>
		list.map( ( o ) => o.value );

	it( 'builds options from pairs', () => {
		expect( opts( [ [ 'a', 'A' ] ] ) ).toEqual( [
			{ value: 'a', label: 'A' },
		] );
	} );

	it( 'lists the schema values in order', () => {
		expect( values( backgroundTypeOptions() ) ).toEqual( [
			'solid',
			'gradient',
			'image',
			'pattern',
		] );
		expect( values( buttonFillOptions() ) ).toEqual( [
			'solid',
			'outline',
			'soft',
			'glass',
		] );
		expect( values( animationOptions() ) ).toEqual( [
			'none',
			'pulse',
			'shake',
			'wobble',
			'shimmer',
		] );
	} );

	it( 'stores dots under the value the control asks for', () => {
		expect( values( patternOptions() )[ 0 ] ).toBe( 'dots' );
		expect( values( patternOptions( '' ) )[ 0 ] ).toBe( '' );
		expect( patternOptions() ).toHaveLength( 6 );
	} );

	it( 'lists the weekdays Monday first', () => {
		expect( weekdayOptions() ).toEqual( [
			{ value: 'mon', label: 'Monday' },
			{ value: 'tue', label: 'Tuesday' },
			{ value: 'wed', label: 'Wednesday' },
			{ value: 'thu', label: 'Thursday' },
			{ value: 'fri', label: 'Friday' },
			{ value: 'sat', label: 'Saturday' },
			{ value: 'sun', label: 'Sunday' },
		] );
	} );
} );
