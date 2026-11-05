/**
 * Pin toggle, weekly schedule helpers, sections and heading registry entries.
 */
import {
	DEFAULT_WEEKLY,
	cleanDays,
	formatDays,
	isTime,
	setWeeklyTime,
	toggleWeekday,
	weeklySummary,
	withWeekly,
} from '../../assets/src/editor/lib/datetime';
import {
	sectionIssue,
	sectionSamples,
	sectionText,
} from '../../assets/src/editor/lib/contrast';
import { reducer } from '../../assets/src/editor/store/reducer';
import * as A from '../../assets/src/editor/store/actions';
import * as S from '../../assets/src/editor/store/selectors';
import {
	coerceDoc,
	createBlock,
	emptyDoc,
	togglePinned,
} from '../../assets/src/editor/store/doc';
import { blockDef } from '../../assets/src/editor/registry/blocks';
import type { PageDoc } from '../../assets/src/editor/types';

const meta = {
	id: 1,
	title: 'T',
	slug: 't',
	status: 'draft',
	link: '',
	modified: '',
};

describe( 'pin toggle', () => {
	it( 'flips pinned through the store and is undoable', () => {
		const b = createBlock( 'link' );
		const doc: PageDoc = { ...emptyDoc(), blocks: [ { ...b, order: 0 } ] };
		let s = reducer( undefined, A.reset( doc, meta ) );
		s = reducer( s, A.togglePinned( b.id ) );
		expect( s.doc.blocks[ 0 ].pinned ).toBe( true );
		expect( S.isDirty( s ) ).toBe( true );
		s = reducer( s, A.togglePinned( b.id ) );
		expect( s.doc.blocks[ 0 ].pinned ).toBe( false );
		s = reducer( s, A.undo() );
		expect( s.doc.blocks[ 0 ].pinned ).toBe( true );
	} );

	it( 'leaves the document alone for an unknown id', () => {
		const doc = emptyDoc();
		expect( togglePinned( doc, 'nope' ) ).toBe( doc );
	} );
} );

describe( 'weekly windows', () => {
	const w = { days: [ 1, 2, 3, 4 ], start: '12:30', end: '14:00' };

	it( 'summarises in one line', () => {
		expect( weeklySummary( w ) ).toBe( 'Mon–Thu, 12:30–14:00, site time' );
		expect(
			weeklySummary( { days: [ 5 ], start: '22:00', end: '01:00' } )
		).toBe( 'Fri, 22:00–01:00 (ends the next day), site time' );
		expect(
			weeklySummary( {
				days: [ 1, 2, 3, 4, 5, 6, 7 ],
				start: '09:00',
				end: '09:00',
			} )
		).toBe( 'Every day, all day from 09:00, site time' );
		expect( weeklySummary( { ...w, days: [] } ) ).toBe(
			'Pick at least one day.'
		);
		expect( weeklySummary( { ...w, end: '' } ) ).toBe(
			'Enter a start and end time.'
		);
		expect( weeklySummary( null ) ).toBe( '' );
	} );

	it( 'formats day runs', () => {
		expect( formatDays( [ 1, 3, 5 ] ) ).toBe( 'Mon, Wed, Fri' );
		expect( formatDays( [ 6, 7, 1 ] ) ).toBe( 'Mon, Sat–Sun' );
		expect( cleanDays( [ 3, 1, 3, 9, 'x' ] ) ).toEqual( [ 1, 3 ] );
	} );

	it( 'toggles days and sets times', () => {
		expect( toggleWeekday( w, 5 ).days ).toEqual( [ 1, 2, 3, 4, 5 ] );
		expect( toggleWeekday( w, 2 ).days ).toEqual( [ 1, 3, 4 ] );
		expect( setWeeklyTime( w, 'end', '15:45:00' ).end ).toBe( '15:45' );
		expect( setWeeklyTime( w, 'start', '' ).start ).toBe( '' );
		expect( isTime( '23:59' ) ).toBe( true );
		expect( isTime( '24:00' ) ).toBe( false );
	} );

	it( 'adds and removes the window without touching the one-off range', () => {
		const sched = { start: '2026-11-01T00:00:00Z', end: null };
		const on = withWeekly( sched, DEFAULT_WEEKLY );
		expect( on ).toEqual( { ...sched, weekly: DEFAULT_WEEKLY } );
		expect( withWeekly( on, null ) ).toEqual( sched );
		expect( 'weekly' in withWeekly( on, null ) ).toBe( false );
	} );

	it( 'keeps weekly when a document is loaded', () => {
		const doc = coerceDoc( {
			blocks: [
				{
					type: 'link',
					schedule: { start: null, weekly: w },
					props: {},
				},
			],
		} );
		expect( doc.blocks[ 0 ].schedule ).toEqual( {
			start: null,
			end: null,
			weekly: w,
		} );
	} );
} );

describe( 'sections', () => {
	it( 'is labelled Section with background, layout and collapsible fields', () => {
		const def = blockDef( 'group' );
		expect( def.label ).toBe( 'Section' );
		const keys = def.fields.map( ( f ) => f.key );
		for ( const k of [
			'heading',
			'description',
			'collapsible',
			'collapsed',
			'layout',
			'background/type',
			'text_color',
			'padding',
			'radius',
		] ) {
			expect( keys ).toContain( k );
		}
		expect(
			def.fields.find( ( f ) => f.key === 'background/type' )
				?.clearsParent
		).toBe( true );
		expect( def.summary( { title: 'Old' } ) ).toBe( 'Old' );
		expect( def.summary( { heading: 'New', title: 'Old' } ) ).toBe( 'New' );
	} );

	it( 'mirrors the server contrast rules', () => {
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

describe( 'heading', () => {
	it( 'exposes the new props', () => {
		const keys = blockDef( 'heading' ).fields.map( ( f ) => f.key );
		for ( const k of [
			'size',
			'weight',
			'color',
			'uppercase',
			'subtext',
			'divider',
			'spacing',
			'emoji',
			'icon',
		] ) {
			expect( keys ).toContain( k );
		}
		const icon = blockDef( 'heading' ).fields.find(
			( f ) => f.key === 'icon'
		);
		expect( icon?.kind ).toBe( 'icon' );
		expect( icon?.showIf?.( { emoji: '🎤' } ) ).toBe( false );
	} );
} );
