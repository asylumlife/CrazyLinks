/**
 * Setup wizard: starting document.
 */
import { buildDocument } from '../../assets/src/editor/lib/wizard';

describe( 'wizard', () => {
	it( 'builds a starting document', () => {
		const doc = buildDocument( {
			name: ' Ana ',
			bio: 'Hi',
			avatar: 4,
			theme: 'neon',
			socials: 'instagram.com/ana\nbad entry',
		} );
		expect( doc.profile ).toEqual( { name: 'Ana', bio: 'Hi', avatar: 4 } );
		expect( doc.design ).toEqual( { theme: 'neon' } );
		expect( doc.blocks ).toHaveLength( 1 );
		expect( doc.blocks[ 0 ].props ).toEqual( {
			items: [
				{ network: 'instagram', url: 'https://instagram.com/ana' },
			],
			style: 'icons',
		} );
		expect(
			buildDocument( { name: '', bio: '', theme: '', socials: '' } )
				.blocks
		).toEqual( [] );
	} );
} );
