/**
 * Non-blocking warnings: missing alt text and nameless avatars.
 */
import * as D from '../../assets/src/editor/store/doc';
import {
	altWarnings,
	blockWarnings,
} from '../../assets/src/editor/lib/warnings';
import type { Block, PageDoc } from '../../assets/src/editor/types';

function block( type: string, extra: Partial< Block > = {} ): Block {
	return { ...D.createBlock( type ), ...extra };
}
function docOf( blocks: Block[] ): PageDoc {
	return D.normalize( { ...D.emptyDoc(), blocks } );
}

describe( 'alt text warnings', () => {
	const media = {
		5: {
			id: 5,
			url: '',
			thumb: '',
			alt: 'Library alt',
			mime: 'image/jpeg',
		},
		6: { id: 6, url: '', thumb: '', alt: '', mime: 'image/jpeg' },
	};
	it( 'flags images, gallery items and link thumbnails without alt text', () => {
		const img = block( 'image', { props: { image: 6 } } );
		const gal = block( 'gallery', {
			props: {
				items: [
					{ image: 6 },
					{ image: 6, alt: 'ok' },
					{ image: 6, decorative: true },
				],
			},
		} );
		const link = block( 'link', {
			props: { title: 'x', url: 'https://a.b', thumbnail: 6 },
		} );
		const w = altWarnings( docOf( [ img, gal, link ] ), media );
		expect( w.map( ( x ) => x.field ) ).toEqual( [
			'props/alt',
			'props/items/0/alt',
			'props/thumbnail_alt',
		] );
		expect( blockWarnings( w, gal.id, 'props/items' ) ).toHaveLength( 1 );
	} );

	it( 'accepts own alt, library alt and decorative', () => {
		const blocks = [
			block( 'image', { props: { image: 6, alt: 'Mine' } } ),
			block( 'image', { props: { image: 5 } } ),
			block( 'image', { props: { image: 6, decorative: true } } ),
			block( 'image', { props: { image: 6, alt: '   ' } } ),
		];
		expect( altWarnings( docOf( blocks ), media ) ).toHaveLength( 1 );
	} );

	it( 'warns about an avatar without a name', () => {
		const d = { ...docOf( [] ), profile: { avatar: 5 } };
		expect( altWarnings( d, media )[ 0 ].field ).toBe( '/profile/name' );
		expect(
			altWarnings( { ...d, profile: { avatar: 5, name: 'N' } }, media )
		).toEqual( [] );
	} );
} );
