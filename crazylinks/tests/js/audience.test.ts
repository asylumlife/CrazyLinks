/**
 * Audience picker options.
 */
import { audienceOptions } from '../../assets/src/editor/components/AudienceField';

const res = {
	adapter: 'mailchimp',
	available: true,
	default: 'a1b2c3d4e5',
	audiences: [
		{ id: 'a1b2c3d4e5', name: 'Fans', member_count: 4 },
		{ id: 'f0f0f0f0f0', name: 'Wholesale', member_count: 1 },
	],
	error: '',
};

describe( 'audienceOptions', () => {
	it( 'starts with the site default and lists every audience', () => {
		expect( audienceOptions( res, '' ) ).toEqual( [
			{ value: '', label: 'Site default (Fans)' },
			{ value: 'a1b2c3d4e5', label: 'Fans' },
			{ value: 'f0f0f0f0f0', label: 'Wholesale' },
		] );
	} );

	it( 'keeps an unknown saved id selectable', () => {
		const opts = audienceOptions( res, 'zzzzzz1234' );
		expect( opts[ opts.length - 1 ] ).toEqual( {
			value: 'zzzzzz1234',
			label: 'zzzzzz1234 (not found)',
		} );
	} );
} );
