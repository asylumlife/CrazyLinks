/**
 * Focal points: reading, clamping and storing.
 */
import {
	focalFromPoint,
	normalizeFocal,
	readFocal,
} from '../../assets/src/editor/lib/focal';

describe( 'focal point', () => {
	it( 'reads, clamps and defaults', () => {
		expect( readFocal( undefined ) ).toEqual( { x: 0.5, y: 0.5 } );
		expect( readFocal( { x: 2, y: -1 } ) ).toEqual( { x: 1, y: 0 } );
		expect( readFocal( { x: 'a' } ) ).toEqual( { x: 0.5, y: 0.5 } );
	} );
	it( 'turns a click into a stored value and drops the centre', () => {
		expect( focalFromPoint( 25, 75, 100, 100 ) ).toEqual( {
			x: 0.25,
			y: 0.75,
		} );
		expect( focalFromPoint( 50, 50, 100, 100 ) ).toBeUndefined();
		expect( focalFromPoint( 500, -5, 100, 100 ) ).toEqual( { x: 1, y: 0 } );
		expect( focalFromPoint( 1, 1, 0, 0 ) ).toBeUndefined();
		expect( normalizeFocal( { x: 0.3333333, y: 0.5 } ) ).toEqual( {
			x: 0.333,
			y: 0.5,
		} );
	} );
} );
