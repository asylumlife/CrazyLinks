/**
 * Contrast: WCAG ratios, design contrast pairs, section text colours and background samples.
 */
import {
	backgroundSamples,
	badgeIssue,
	blend,
	contrast,
	contrastIssues,
	inkFor,
	parseHex,
	sectionIssue,
	sectionSamples,
	sectionText,
} from '../../assets/src/editor/lib/contrast';

describe( 'contrast', () => {
	it( 'computes WCAG ratios', () => {
		expect( contrast( '#000', '#fff' ) ).toBeCloseTo( 21, 0 );
		expect( contrast( '#777777', '#ffffff' ) ).toBeLessThan( 4.5 );
		expect( contrast( 'red', '#fff' ) ).toBeNull();
		expect( parseHex( '#11223344' ) ).toEqual( [ 17, 34, 51 ] );
	} );
} );

describe( 'design contrast pairs', () => {
	const labels = {};
	const ok = {
		bg: '#ffffff',
		text: '#111111',
		muted: '#555555',
		btn: '#111111',
		btnText: '#ffffff',
		btnFill: 'solid',
		accent: '#4f46e5',
	};
	it( 'passes a sound design', () => {
		expect( contrastIssues( ok, labels ) ).toEqual( [] );
	} );
	it( 'reports muted text, button text and headings with their ratios', () => {
		const issues = contrastIssues(
			{ ...ok, muted: '#aaaaaa', btnText: '#222222', text: '#dddddd' },
			labels
		);
		const by = Object.fromEntries( issues.map( ( i ) => [ i.id, i ] ) );
		expect( by.muted.ratio ).toBeLessThan( 4.5 );
		expect( by.button.ratio ).toBeLessThan( 4.5 );
		expect( by.text.min ).toBe( 4.5 );
		expect( by.headings.min ).toBe( 3 );
	} );
	it( 'checks the outline button against the page, not its own fill', () => {
		const bad = contrastIssues(
			{ ...ok, btnFill: 'outline', btn: '#eeeeee', btnText: '#000000' },
			labels
		);
		expect( bad.map( ( i ) => i.id ) ).toContain( 'button' );
		expect(
			contrastIssues(
				{
					...ok,
					btnFill: 'outline',
					btn: '#111111',
					btnText: '#ffffff',
				},
				labels
			)
		).toEqual( [] );
	} );
	it( 'samples gradients along their length', () => {
		const issues = contrastIssues(
			{ ...ok, text: '#ffffff', bg: '#000000', bgTo: '#ffffff' },
			labels
		);
		expect( issues.map( ( i ) => i.id ) ).toContain( 'text' );
	} );
	it( 'flags a white-on-light custom badge colour', () => {
		expect( badgeIssue( '#ffee00' ) ).toBeLessThan( 4.5 );
		expect( badgeIssue( '#222222' ) ).toBeNull();
		expect( badgeIssue( 'red' ) ).toBeNull();
	} );
	it( 'mirrors the PHP blend and ink helpers', () => {
		expect( blend( '#000000', '#ffffff', 0.5 ) ).toBe( '#808080' );
		expect( inkFor( '#ffffff' ) ).toBe( '#000000' );
		expect( inkFor( '#111111' ) ).toBe( '#ffffff' );
	} );
} );

describe( 'section text colour', () => {
	it( 'follows the same contrast rules as the server', () => {
		expect(
			sectionSamples( {
				type: 'gradient',
				from: '#000000',
				to: '#ffffff',
			} )
		).toHaveLength( 5 );
		expect(
			sectionSamples( { type: 'image', overlay: '#000000' } )
		).toEqual( [ '#000000' ] );
		expect( sectionText( {}, '#111111' ) ).toBe( '' );
		expect(
			sectionText(
				{ background: { type: 'solid', color: '#fde68a' } },
				'#ffffff'
			)
		).toBe( '#000000' );
		expect(
			sectionText(
				{ background: { type: 'solid', color: '#ffffff' } },
				'#111111'
			)
		).toBe( '#111111' );
		const issue = sectionIssue(
			{
				background: { type: 'solid', color: '#ffffff' },
				text_color: '#bbbbbb',
			},
			'#111111',
			[ '#ffffff' ]
		);
		expect( issue?.ratio ).toBeLessThan( 4.5 );
		expect(
			sectionIssue( { text_color: '#111111' }, '#111111', [ '#ffffff' ] )
		).toBeNull();
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
