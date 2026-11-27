/**
 * Analytics helpers.
 */
import { clicksByBlock } from '../../assets/src/editor/lib/analytics';

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
