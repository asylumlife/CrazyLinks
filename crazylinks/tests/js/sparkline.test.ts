/**
 * Sparkline path.
 */
import { sparkPath } from '../../assets/src/editor/components/Sparkline';

describe( 'sparkline', () => {
	it( 'draws a path scaled to the max', () => {
		expect( sparkPath( [ 0, 10 ], 100, 50, 10 ) ).toBe(
			'M0.0,49.0 L100.0,1.0'
		);
		expect( sparkPath( [], 100, 50, 1 ) ).toBe( '' );
	} );
} );
