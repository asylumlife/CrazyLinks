/**
 * Shortcut hints.
 */
import {
	isApplePlatform,
	primaryShortcut,
} from '../../assets/src/editor/lib/shortcut';

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
