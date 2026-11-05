/**
 * v1.1 polish: drag between groups, alt-text warnings, contrast pairs, focal points, post lock,
 * block registry fields.
 */
import * as D from '../../assets/src/editor/store/doc';
import {
	applyOp,
	initialState,
	reducer,
} from '../../assets/src/editor/store/reducer';
import { moveBlockTo } from '../../assets/src/editor/store/actions';
import { DROP_PREFIX, resolveDrop } from '../../assets/src/editor/lib/dnd';
import {
	altWarnings,
	blockWarnings,
} from '../../assets/src/editor/lib/warnings';
import {
	badgeIssue,
	blend,
	contrastIssues,
	inkFor,
} from '../../assets/src/editor/lib/contrast';
import { resolveColorTokens } from '../../assets/src/editor/lib/theme';
import {
	focalCss,
	focalFromPoint,
	normalizeFocal,
	readFocal,
} from '../../assets/src/editor/lib/focal';
import {
	applyHeartbeat,
	heartbeatPayload,
	lockFromBoot,
} from '../../assets/src/editor/lib/lock';
import { blockDef } from '../../assets/src/editor/registry/blocks';
import type { Block, PageDoc } from '../../assets/src/editor/types';

function block( type: string, extra: Partial< Block > = {} ): Block {
	return { ...D.createBlock( type ), ...extra };
}
function docOf( blocks: Block[] ): PageDoc {
	return D.normalize( { ...D.emptyDoc(), blocks } );
}
const ids = ( d: PageDoc, parent: string | null ) =>
	D.siblings( d, parent ).map( ( b ) => b.id );

describe( 'moveTo', () => {
	const a = block( 'link', { order: 0 } );
	const g = block( 'group', { order: 1 } );
	const c1 = block( 'link', { parent_id: g.id, order: 0 } );
	const c2 = block( 'link', { parent_id: g.id, order: 1 } );
	const z = block( 'heading', { order: 2 } );
	const doc = docOf( [ a, g, c1, c2, z ] );

	it( 'moves a top-level block into a group at a position', () => {
		const next = D.moveTo( doc, a.id, g.id, 1 );
		expect( ids( next, g.id ) ).toEqual( [ c1.id, a.id, c2.id ] );
		expect( ids( next, null ) ).toEqual( [ g.id, z.id ] );
		expect( next.blocks.map( ( b ) => b.id ) ).toEqual( [
			g.id,
			c1.id,
			a.id,
			c2.id,
			z.id,
		] );
	} );

	it( 'moves a child out to the top level and renumbers both lists', () => {
		const next = D.moveTo( doc, c1.id, null, 0 );
		expect( ids( next, null ) ).toEqual( [ c1.id, a.id, g.id, z.id ] );
		expect( ids( next, g.id ) ).toEqual( [ c2.id ] );
		expect( D.siblings( next, g.id )[ 0 ].order ).toBe( 0 );
		expect(
			next.blocks.find( ( b ) => b.id === c1.id )?.parent_id
		).toBeNull();
	} );

	it( 'never nests a group and ignores unknown targets', () => {
		const g2 = block( 'group', { order: 3 } );
		const d = docOf( [ a, g, g2 ] );
		expect( D.moveTo( d, g2.id, g.id, 0 ) ).toBe( d );
		expect( D.moveTo( d, a.id, 'NOPE', 0 ) ).toBe( d );
		expect( D.moveTo( d, a.id, z.id, 0 ) ).toBe( d );
	} );

	it( 'is wired through the reducer action', () => {
		const state = initialState( doc );
		const next = reducer( state, moveBlockTo( a.id, g.id, 0 ) );
		expect( ids( next.doc, g.id )[ 0 ] ).toBe( a.id );
		expect(
			applyOp( doc, {
				kind: 'moveTo',
				id: a.id,
				parentId: g.id,
				index: 9,
			} ).doc.blocks.at( -2 )?.id
		).toBe( a.id );
	} );
} );

describe( 'resolveDrop', () => {
	const a = block( 'link', { order: 0 } );
	const b = block( 'link', { order: 1 } );
	const g = block( 'group', { order: 2 } );
	const c1 = block( 'link', { parent_id: g.id, order: 0 } );
	const c2 = block( 'link', { parent_id: g.id, order: 1 } );
	const empty = block( 'group', { order: 3 } );
	const doc = docOf( [ a, b, g, c1, c2, empty ] );

	it( 'reorders inside one list like a sortable list', () => {
		expect( resolveDrop( doc.blocks, a.id, b.id ) ).toEqual( {
			parentId: null,
			index: 1,
		} );
		expect( resolveDrop( doc.blocks, b.id, a.id ) ).toEqual( {
			parentId: null,
			index: 0,
		} );
		expect( resolveDrop( doc.blocks, c1.id, c2.id ) ).toEqual( {
			parentId: g.id,
			index: 1,
		} );
	} );

	it( 'moves a block into a group by dropping on a child, before that child', () => {
		expect( resolveDrop( doc.blocks, a.id, c2.id ) ).toEqual( {
			parentId: g.id,
			index: 1,
		} );
	} );

	it( 'moves a block out of a group by dropping on a top-level block', () => {
		expect( resolveDrop( doc.blocks, c2.id, a.id ) ).toEqual( {
			parentId: null,
			index: 0,
		} );
	} );

	it( 'uses the end-of-group drop zone, including for an empty group', () => {
		expect( resolveDrop( doc.blocks, a.id, DROP_PREFIX + g.id ) ).toEqual( {
			parentId: g.id,
			index: 2,
		} );
		expect(
			resolveDrop( doc.blocks, a.id, DROP_PREFIX + empty.id )
		).toEqual( { parentId: empty.id, index: 0 } );
		expect(
			resolveDrop( doc.blocks, c2.id, DROP_PREFIX + g.id )
		).toBeNull();
		expect( resolveDrop( doc.blocks, c1.id, DROP_PREFIX + g.id ) ).toEqual(
			{ parentId: g.id, index: 1 }
		);
	} );

	it( 'keeps groups at the top level', () => {
		expect(
			resolveDrop( doc.blocks, g.id, DROP_PREFIX + empty.id )
		).toBeNull();
		expect( resolveDrop( doc.blocks, empty.id, c1.id ) ).toEqual( {
			parentId: null,
			index: 2,
		} );
		expect( resolveDrop( doc.blocks, a.id, a.id ) ).toBeNull();
		expect( resolveDrop( doc.blocks, a.id, 'missing' ) ).toBeNull();
	} );

	it( 'produces the document the drop describes', () => {
		const t = resolveDrop( doc.blocks, a.id, c2.id )!;
		const next = D.moveTo( doc, a.id, t.parentId, t.index );
		expect( ids( next, g.id ) ).toEqual( [ c1.id, a.id, c2.id ] );
	} );
} );

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

describe( 'contrast pairs', () => {
	const labels = {};
	const ok = {
		bg: '#ffffff',
		text: '#111111',
		muted: '#555555',
		btn: '#111111',
		btnText: '#ffffff',
		btnFill: 'solid',
		accent: '#4f46e5',
	};
	it( 'passes a sound design', () => {
		expect( contrastIssues( ok, labels ) ).toEqual( [] );
	} );
	it( 'reports muted text, button text and headings with their ratios', () => {
		const issues = contrastIssues(
			{ ...ok, muted: '#aaaaaa', btnText: '#222222', text: '#dddddd' },
			labels
		);
		const by = Object.fromEntries( issues.map( ( i ) => [ i.id, i ] ) );
		expect( by.muted.ratio ).toBeLessThan( 4.5 );
		expect( by.button.ratio ).toBeLessThan( 4.5 );
		expect( by.text.min ).toBe( 4.5 );
		expect( by.headings.min ).toBe( 3 );
	} );
	it( 'checks the outline button against the page, not its own fill', () => {
		const bad = contrastIssues(
			{ ...ok, btnFill: 'outline', btn: '#eeeeee', btnText: '#000000' },
			labels
		);
		expect( bad.map( ( i ) => i.id ) ).toContain( 'button' );
		expect(
			contrastIssues(
				{
					...ok,
					btnFill: 'outline',
					btn: '#111111',
					btnText: '#ffffff',
				},
				labels
			)
		).toEqual( [] );
	} );
	it( 'samples gradients along their length', () => {
		const issues = contrastIssues(
			{ ...ok, text: '#ffffff', bg: '#000000', bgTo: '#ffffff' },
			labels
		);
		expect( issues.map( ( i ) => i.id ) ).toContain( 'text' );
	} );
	it( 'flags a white-on-light custom badge colour', () => {
		expect( badgeIssue( '#ffee00' ) ).toBeLessThan( 4.5 );
		expect( badgeIssue( '#222222' ) ).toBeNull();
		expect( badgeIssue( 'red' ) ).toBeNull();
	} );
	it( 'mirrors the PHP blend and ink helpers', () => {
		expect( blend( '#000000', '#ffffff', 0.5 ) ).toBe( '#808080' );
		expect( inkFor( '#ffffff' ) ).toBe( '#000000' );
		expect( inkFor( '#111111' ) ).toBe( '#ffffff' );
	} );
	it( 'resolves tokens from page overrides over the preset', () => {
		const preset = {
			label: 'P',
			background: { type: 'gradient', from: '#000000', to: '#333333' },
			text: { color: '#ffffff' },
			button: { color: '#ff0000', text_color: '#ffffff' },
			accent: '#00ff00',
			dark: { background: { from: '#010101' } },
		};
		const t = resolveColorTokens( { text: { muted: '#999999' } }, preset );
		expect( t ).toMatchObject( {
			bg: '#000000',
			bgTo: '#333333',
			text: '#ffffff',
			muted: '#999999',
			btn: '#ff0000',
			accent: '#00ff00',
			btnFill: 'solid',
		} );
		expect( resolveColorTokens( {}, preset, true ).bg ).toBe( '#010101' );
		expect( resolveColorTokens( {}, undefined ).text ).toBe( '#111111' );
	} );
} );

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
	it( 'formats object-position like the server', () => {
		expect( focalCss( { x: 0.25, y: 0.8 } ) ).toBe( '25% 80%' );
		expect( focalCss( undefined ) ).toBe( '' );
	} );
} );

describe( 'post lock', () => {
	const boot = {
		locked: true,
		postId: 7,
		nonce: 'n',
		activeLock: '',
		user: { name: 'Alice', avatar: 'a.png' },
		takeOverUrl: '/take',
	};
	it( 'starts locked out when someone else holds the page', () => {
		const s = lockFromBoot( boot );
		expect( s.lockedOut ).toBe( true );
		expect( s.user?.name ).toBe( 'Alice' );
		expect( lockFromBoot( undefined ).lockedOut ).toBe( false );
		expect(
			lockFromBoot( { ...boot, locked: false, activeLock: '1:2' } )
				.lockedOut
		).toBe( false );
	} );
	it( 'sends the lock with each heartbeat', () => {
		const mine = lockFromBoot( {
			...boot,
			locked: false,
			user: null,
			activeLock: '1:2',
		} );
		expect( heartbeatPayload( mine ) ).toEqual( {
			post_id: 7,
			lock: '1:2',
		} );
		expect( heartbeatPayload( lockFromBoot( undefined ) ) ).toBeNull();
	} );
	it( 'locks the editor out when the heartbeat reports a takeover, and adopts a refreshed lock', () => {
		const mine = lockFromBoot( {
			...boot,
			locked: false,
			user: null,
			activeLock: '1:2',
		} );
		const taken = applyHeartbeat( mine, {
			lock_error: { name: 'Bob', avatar_src: 'b.png' },
		} );
		expect( taken.lockedOut ).toBe( true );
		expect( taken.user ).toEqual( { name: 'Bob', avatar: 'b.png' } );
		expect( applyHeartbeat( mine, { new_lock: '9:2' } ).activeLock ).toBe(
			'9:2'
		);
		expect( applyHeartbeat( mine, undefined ) ).toBe( mine );
	} );
} );

describe( 'registry additions', () => {
	const keys = ( type: string ) =>
		blockDef( type as never ).fields.map( ( f ) => f.key );
	it( 'link has tile size, focal point and thumbnail alt fields', () => {
		expect( keys( 'link' ) ).toEqual(
			expect.arrayContaining( [
				'tile_size',
				'focal_point',
				'thumbnail_alt',
				'decorative',
			] )
		);
		const focal = blockDef( 'link' ).fields.find(
			( f ) => f.key === 'focal_point'
		)!;
		expect( focal.kind ).toBe( 'focal' );
		expect( focal.focalOf ).toBe( 'thumbnail' );
		expect( focal.showIf?.( {} ) ).toBe( false );
		expect( focal.showIf?.( { thumbnail: 4 } ) ).toBe( true );
		const tile = blockDef( 'link' ).fields.find(
			( f ) => f.key === 'tile_size'
		)!;
		expect( tile.options?.map( ( o ) => o.value ) ).toEqual( [
			'',
			'2x1',
			'2x2',
		] );
	} );
	it( 'image and email capture got their new fields', () => {
		expect( keys( 'image' ) ).toContain( 'decorative' );
		expect(
			blockDef( 'image' )
				.fields.find( ( f ) => f.key === 'alt' )
				?.showIf?.( { decorative: true } )
		).toBe( false );
		expect( keys( 'email_capture' ) ).toEqual(
			expect.arrayContaining( [ 'success_text', 'pending_text' ] )
		);
		expect( blockDef( 'socials' ).trackable ).toBe( true );
	} );
} );
