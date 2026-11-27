/**
 * Preview document: bridge injection and fragment wrapping.
 */
import { buildSrcDoc } from '../../assets/src/editor/lib/preview';

describe( 'preview document', () => {
	it( 'injects the bridge into the server document', () => {
		const out = buildSrcDoc(
			{
				html: '',
				document:
					'<!DOCTYPE html><html><body class="cl-body"><main></main></body></html>',
			},
			120
		);
		expect( out ).toContain( 'var y=120;' );
		expect( out.indexOf( 'crazylinks-preview' ) ).toBeLessThan(
			out.indexOf( '</body>' )
		);
	} );

	it( 'wraps a bare fragment', () => {
		expect(
			buildSrcDoc( { html: '<div class="cl-page"></div>' }, 0 )
		).toContain(
			'<main id="cl-main" class="cl-main"><div class="cl-page"></div></main>'
		);
	} );
} );
