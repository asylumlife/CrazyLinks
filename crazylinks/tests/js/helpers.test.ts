/**
 * Small pure helpers: contrast, date-times, paths, preview bridge, sparkline, wizard, analytics.
 */
import { contrast, parseHex } from '../../assets/src/editor/lib/contrast';
import {
	isoToLocalInput,
	localInputToIso,
} from '../../assets/src/editor/lib/datetime';
import { getPath, setPath } from '../../assets/src/editor/lib/paths';
import { buildSrcDoc } from '../../assets/src/editor/lib/preview';
import { sparkPath } from '../../assets/src/editor/components/Sparkline';
import { buildDocument } from '../../assets/src/editor/lib/wizard';
import { clicksByBlock } from '../../assets/src/editor/lib/analytics';
import { backgroundCss, effective } from '../../assets/src/editor/lib/theme';
import { ulid, ULID_PATTERN } from '../../assets/src/editor/lib/ulid';

describe( 'contrast', () => {
	it( 'computes WCAG ratios', () => {
		expect( contrast( '#000', '#fff' ) ).toBeCloseTo( 21, 0 );
		expect( contrast( '#777777', '#ffffff' ) ).toBeLessThan( 4.5 );
		expect( contrast( 'red', '#fff' ) ).toBeNull();
		expect( parseHex( '#11223344' ) ).toEqual( [ 17, 34, 51 ] );
	} );
} );

describe( 'datetime', () => {
	it( 'round-trips local input values as UTC ISO strings', () => {
		const iso = localInputToIso( '2026-11-05T09:30' );
		expect( iso ).toMatch( /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/ );
		expect( isoToLocalInput( iso ) ).toBe( '2026-11-05T09:30' );
		expect( localInputToIso( '' ) ).toBeNull();
		expect( isoToLocalInput( null ) ).toBe( '' );
	} );
} );

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

describe( 'preview document', () => {
	it( 'injects the bridge into the server document', () => {
		const out = buildSrcDoc(
			{
				html: '',
				document:
					'<!DOCTYPE html><html><body class="cl-body"><main></main></body></html>',
			},
			120
		);
		expect( out ).toContain( 'var y=120;' );
		expect( out.indexOf( 'crazylinks-preview' ) ).toBeLessThan(
			out.indexOf( '</body>' )
		);
	} );

	it( 'wraps a bare fragment', () => {
		expect(
			buildSrcDoc( { html: '<div class="cl-page"></div>' }, 0 )
		).toContain(
			'<main id="cl-main" class="cl-main"><div class="cl-page"></div></main>'
		);
	} );
} );

describe( 'sparkline', () => {
	it( 'draws a path scaled to the max', () => {
		expect( sparkPath( [ 0, 10 ], 100, 50, 10 ) ).toBe(
			'M0.0,49.0 L100.0,1.0'
		);
		expect( sparkPath( [], 100, 50, 1 ) ).toBe( '' );
	} );
} );

describe( 'wizard', () => {
	it( 'builds a starting document', () => {
		const doc = buildDocument( {
			name: ' Ana ',
			bio: 'Hi',
			avatar: 4,
			theme: 'neon',
			socials: 'instagram.com/ana\nbad entry',
		} );
		expect( doc.profile ).toEqual( { name: 'Ana', bio: 'Hi', avatar: 4 } );
		expect( doc.design ).toEqual( { theme: 'neon' } );
		expect( doc.blocks ).toHaveLength( 1 );
		expect( doc.blocks[ 0 ].props ).toEqual( {
			items: [
				{ network: 'instagram', url: 'https://instagram.com/ana' },
			],
			style: 'icons',
		} );
		expect(
			buildDocument( { name: '', bio: '', theme: '', socials: '' } )
				.blocks
		).toEqual( [] );
	} );
} );

describe( 'analytics', () => {
	it( 'maps per-block clicks and tolerates missing data', () => {
		expect(
			clicksByBlock( {
				blocks: [ { block_id: 'A', clicks: 3 }, { block_id: 'B' } ],
			} )
		).toEqual( { A: 3 } );
		expect( clicksByBlock( null ) ).toEqual( {} );
		expect( clicksByBlock( {} ) ).toEqual( {} );
	} );
} );

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

describe( 'ulid', () => {
	it( 'is sortable and valid', () => {
		const a = ulid( 1000 );
		const b = ulid( 2000 );
		expect( a ).toMatch( ULID_PATTERN );
		expect( a < b ).toBe( true );
	} );
} );
