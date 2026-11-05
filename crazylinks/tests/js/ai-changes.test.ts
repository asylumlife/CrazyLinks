/**
 * Change-list rendering helpers and the error mapper used by the AI tab.
 */
import {
	changeIcon,
	countChanges,
	sortChanges,
	summarizeChanges,
} from '../../assets/src/editor/ai/changes';
import { modes, modeInfo } from '../../assets/src/editor/ai/modes';
import type { ChangeItem } from '../../assets/src/editor/ai/types';

const items: ChangeItem[] = [
	{ kind: 'design', text: 'Restyle: button shape → pill', block_id: null },
	{ kind: 'add', text: 'Add link “Tour” at the top', block_id: 'N1' },
	{ kind: 'update', text: 'Change link “A”: title', block_id: 'A' },
	{ kind: 'add', text: 'Add heading “Music” after link “A”', block_id: 'N2' },
	{ kind: 'remove', text: 'Remove link “Old”', block_id: 'B' },
];

describe( 'change list', () => {
	it( 'counts per kind in display order', () => {
		expect( countChanges( items ) ).toEqual( [
			{ kind: 'add', count: 2 },
			{ kind: 'update', count: 1 },
			{ kind: 'remove', count: 1 },
			{ kind: 'design', count: 1 },
		] );
	} );

	it( 'summarizes in plain words', () => {
		expect( summarizeChanges( items ) ).toBe(
			'2 added, 1 changed, 1 removed, design updated'
		);
		expect( summarizeChanges( [] ) ).toBe( 'No changes' );
		expect(
			summarizeChanges( [
				{
					kind: 'profile',
					text: 'Update the profile: bio',
					block_id: null,
				},
			] )
		).toBe( 'profile updated' );
	} );

	it( 'sorts by kind and keeps the server order within a kind', () => {
		expect( sortChanges( items ).map( ( i ) => i.text ) ).toEqual( [
			'Add link “Tour” at the top',
			'Add heading “Music” after link “A”',
			'Change link “A”: title',
			'Remove link “Old”',
			'Restyle: button shape → pill',
		] );
		expect( items[ 0 ].kind ).toBe( 'design' );
	} );

	it( 'has an icon per kind', () => {
		for ( const kind of [
			'add',
			'update',
			'move',
			'remove',
			'design',
			'profile',
		] as const ) {
			expect( changeIcon( kind ) ).toMatch( /^[a-z0-9-]+$/ );
		}
		expect( changeIcon( 'remove' ) ).toBe( 'trash' );
	} );
} );

describe( 'modes', () => {
	it( 'offers the five server modes with examples', () => {
		expect( modes().map( ( m ) => m.mode ) ).toEqual( [
			'edit',
			'build_from_site',
			'build_from_description',
			'restyle',
			'analytics_qa',
		] );
		for ( const m of modes() ) {
			expect( m.examples.length ).toBeGreaterThan( 0 );
			expect( m.label ).not.toBe( '' );
		}
		expect( modeInfo( 'restyle' ).label ).toBe( 'Restyle' );
	} );
} );

describe( 'describeError', () => {
	it( 'keeps field errors and the server message', async () => {
		const { describeError } =
			await import( '../../assets/src/editor/ai/run' );
		const out = describeError( {
			code: 'crazylinks_ai_invalid_ops',
			message: 'These changes cannot be applied to this page.',
			data: {
				errors: [
					{
						path: '/operations/0/props/url',
						message: 'URL is not allowed.',
					},
				],
			},
		} );
		expect( out.message ).toBe(
			'These changes cannot be applied to this page.'
		);
		expect( out.errors ).toEqual( [
			{ path: '/operations/0/props/url', message: 'URL is not allowed.' },
		] );
		expect( describeError( { code: 'fetch_error' } ).message ).toMatch(
			/reach your site/
		);
		expect( describeError( null ).message ).toBe(
			'The AI request failed.'
		);
	} );
} );
