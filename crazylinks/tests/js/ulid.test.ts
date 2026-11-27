/**
 * ULIDs.
 */
import { ulid, ULID_PATTERN } from '../../assets/src/editor/lib/ulid';

describe( 'ulid', () => {
	it( 'is sortable and valid', () => {
		const a = ulid( 1000 );
		const b = ulid( 2000 );
		expect( a ).toMatch( ULID_PATTERN );
		expect( a < b ).toBe( true );
	} );
} );
