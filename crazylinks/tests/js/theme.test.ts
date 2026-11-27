/**
 * Theme: preset lookup, overrides over presets and colour token resolution.
 */
import {
	DEFAULT_THEME,
	backgroundCss,
	effective,
	presetFor,
	resolveColorTokens,
} from '../../assets/src/editor/lib/theme';

describe( 'theme', () => {
	it( 'resolves overrides over presets', () => {
		const preset = {
			label: 'P',
			accent: '#111111',
			button: { color: '#222222' },
		};
		expect( effective( { accent: '#333333' }, preset, [ 'accent' ] ) ).toBe(
			'#333333'
		);
		expect( effective( {}, preset, [ 'button', 'color' ] ) ).toBe(
			'#222222'
		);
		expect(
			backgroundCss( {
				type: 'gradient',
				from: '#000',
				to: '#fff',
				angle: 90,
			} )
		).toBe( 'linear-gradient(90deg, #000, #fff)' );
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

describe( 'colour tokens', () => {
	it( 'resolves tokens from page overrides over the preset', () => {
		const preset = {
			label: 'P',
			background: { type: 'gradient', from: '#000000', to: '#333333' },
			text: { color: '#ffffff' },
			button: { color: '#ff0000', text_color: '#ffffff' },
			accent: '#00ff00',
			dark: { background: { from: '#010101' } },
		};
		const t = resolveColorTokens( { text: { muted: '#999999' } }, preset );
		expect( t ).toMatchObject( {
			bg: '#000000',
			bgTo: '#333333',
			text: '#ffffff',
			muted: '#999999',
			btn: '#ff0000',
			accent: '#00ff00',
			btnFill: 'solid',
		} );
		expect( resolveColorTokens( {}, preset, true ).bg ).toBe( '#010101' );
		expect( resolveColorTokens( {}, undefined ).text ).toBe( '#111111' );
	} );
} );
