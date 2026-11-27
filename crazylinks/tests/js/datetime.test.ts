/**
 * Date-times: local input round trip and weekly schedule windows.
 */
import {
	DEFAULT_WEEKLY,
	cleanDays,
	formatDays,
	isTime,
	isoToLocalInput,
	localInputToIso,
	setWeeklyTime,
	toggleWeekday,
	weeklySummary,
	withWeekly,
} from '../../assets/src/editor/lib/datetime';

describe( 'datetime', () => {
	it( 'round-trips local input values as UTC ISO strings', () => {
		const iso = localInputToIso( '2026-11-05T09:30' );
		expect( iso ).toMatch( /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/ );
		expect( isoToLocalInput( iso ) ).toBe( '2026-11-05T09:30' );
		expect( localInputToIso( '' ) ).toBeNull();
		expect( isoToLocalInput( null ) ).toBe( '' );
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
} );
