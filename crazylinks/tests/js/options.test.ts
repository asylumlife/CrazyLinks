/**
 * Shared option lists.
 */
import {
	animationOptions,
	backgroundTypeOptions,
	buttonFillOptions,
	opts,
	patternOptions,
	weekdayOptions,
} from '../../assets/src/editor/registry/options';

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
