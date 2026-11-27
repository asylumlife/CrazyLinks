/**
 * Class name joining.
 */
import { classes } from '../../assets/src/editor/lib/classes';

describe( 'class names', () => {
	it( 'joins the parts that apply', () => {
		expect( classes( 'a', false, undefined, null, '', 'b' ) ).toBe( 'a b' );
		expect( classes( undefined, 'only' ) ).toBe( 'only' );
		expect( classes() ).toBe( '' );
	} );
} );
