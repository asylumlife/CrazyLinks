/**
 * Editor smoke test: open the demo page, add a link block, see it in the live preview, save,
 * verify through REST, restore the original document.
 */
import { test, expect, type APIRequestContext } from '@playwright/test';

const PAGE_ID = Number( process.env.E2E_PAGE_ID ?? 5 );
const USER = process.env.E2E_USER ?? 'admin';
const PASS = process.env.E2E_PASS ?? '';
const APP_PASS = process.env.E2E_APP_PASS ?? '';
const SHOTS = process.env.E2E_SCREENSHOTS ?? '';

function auth() {
	return {
		Authorization:
			'Basic ' +
			Buffer.from( `${ USER }:${ APP_PASS }` ).toString( 'base64' ),
	};
}

async function getPage( request: APIRequestContext ) {
	const res = await request.get(
		`/wp-json/crazylinks/v1/pages/${ PAGE_ID }?context=edit`,
		{ headers: auth() }
	);
	expect( res.status() ).toBe( 200 );
	return res.json();
}

test( 'add a link block, preview it and save', async ( { page, request } ) => {
	test.skip( ! PASS || ! APP_PASS, 'Set E2E_PASS and E2E_APP_PASS.' );
	const original = await getPage( request );
	const title = `E2E link ${ Date.now() }`;
	const errors: string[] = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' ) {
			errors.push( m.text() );
		}
	} );

	try {
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', USER );
		await page.fill( '#user_pass', PASS );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );

		await page.goto( `/wp-admin/post.php?post=${ PAGE_ID }&action=edit` );
		await expect( page.locator( '.cl-editor' ) ).toBeVisible();
		const preview = page.frameLocator( 'iframe.cl-phone__screen' );
		await expect( preview.locator( '.cl-page' ) ).toBeVisible( {
			timeout: 15_000,
		} );
		if ( SHOTS ) {
			await page.screenshot( { path: `${ SHOTS }/editor-links.png` } );
		}

		await page.getByRole( 'button', { name: 'Add block' } ).click();
		const drawer = page.getByRole( 'dialog', { name: 'Add a block' } );
		await drawer.getByRole( 'button', { name: /^Link/ } ).click();
		await expect( drawer ).toBeHidden();

		await page.getByLabel( 'Title *' ).fill( title );
		await page.getByLabel( 'URL *' ).fill( 'https://example.com/e2e' );

		await expect( preview.getByText( title ) ).toBeVisible( {
			timeout: 15_000,
		} );
		if ( SHOTS ) {
			await page.screenshot( { path: `${ SHOTS }/editor-new-link.png` } );
		}

		// Pin from the collapsed row bar, next to the click count and the eye toggle.
		const bar = page
			.locator( 'li.cl-block' )
			.filter( {
				has: page.locator( '.cl-block__summary', { hasText: title } ),
			} )
			.last()
			.locator( ':scope > .cl-block__row' );
		await bar.getByRole( 'button', { name: 'Pin to top' } ).click();
		await expect(
			bar.getByRole( 'button', { name: 'Unpin' } )
		).toHaveAttribute( 'aria-pressed', 'true' );

		// Repeat weekly: default window and its one-line summary.
		await page
			.getByText( 'Visibility, schedule and more', { exact: true } )
			.click();
		await page.getByLabel( 'Repeat weekly' ).check();
		await page.getByRole( 'button', { name: 'Friday' } ).click();
		await expect( page.locator( '.cl-weekly__summary' ) ).toHaveText(
			'Mon–Thu, 09:00–17:00, site time'
		);

		await page
			.getByRole( 'button', { name: 'Update', exact: true } )
			.click();
		await expect( page.locator( '.cl-topbar__note' ) ).toHaveText(
			/Published|Saved/,
			{ timeout: 15_000 }
		);

		const saved = await getPage( request );
		const titles = saved.page.blocks.map(
			( b: { props: { title?: string } } ) => b.props.title
		);
		expect( titles ).toContain( title );
		const mine = saved.page.blocks.find(
			( b: { props: { title?: string } } ) => b.props.title === title
		);
		expect( mine.pinned ).toBe( true );
		expect( mine.schedule.weekly ).toEqual( {
			days: [ 1, 2, 3, 4 ],
			start: '09:00',
			end: '17:00',
		} );

		for ( const tab of [ 'Design', 'Settings', 'Analytics' ] ) {
			await page.getByRole( 'tab', { name: tab } ).click();
			if ( SHOTS ) {
				await page.screenshot( {
					path: `${ SHOTS }/editor-${ tab.toLowerCase() }.png`,
				} );
			}
		}
		expect( errors ).toEqual( [] );
	} finally {
		// Put the page back exactly as it was.
		const res = await request.patch(
			`/wp-json/crazylinks/v1/pages/${ PAGE_ID }`,
			{
				headers: { ...auth(), 'Content-Type': 'application/json' },
				data: { page: original.page, title: original.title },
			}
		);
		expect( res.status() ).toBe( 200 );
	}
} );

async function login( page: import( '@playwright/test' ).Page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

test( 'validation errors show inline on the offending field', async ( {
	page,
} ) => {
	test.skip( ! PASS || ! APP_PASS, 'Set E2E_PASS and E2E_APP_PASS.' );
	await login( page );
	await page.goto( `/wp-admin/post.php?post=${ PAGE_ID }&action=edit` );
	await page.getByRole( 'button', { name: 'Add block' } ).click();
	await page
		.getByRole( 'dialog', { name: 'Add a block' } )
		.getByRole( 'button', { name: /^Link/ } )
		.click();
	await page.getByLabel( 'Title *' ).fill( 'Bad link' );
	await page.getByLabel( 'URL *' ).fill( 'javascript:alert(1)' );
	await expect( page.locator( '.cl-field-error' ).first() ).toContainText(
		'URL is not allowed',
		{ timeout: 15_000 }
	);
	await expect( page.locator( '.cl-preview__note' ) ).toContainText(
		'hidden in the preview'
	);
	await expect( page.getByLabel( 'URL *' ) ).toHaveAttribute(
		'aria-invalid',
		'true'
	);
	if ( SHOTS ) {
		await page.screenshot( { path: `${ SHOTS }/editor-inline-error.png` } );
	}

	// Core media modal for the avatar.
	await page.getByRole( 'button', { name: 'Profile' } ).click();
	await page
		.locator( '.cl-profile' )
		.getByRole( 'button', { name: /^(Select|Replace)$/ } )
		.first()
		.click();
	await expect( page.locator( '.media-modal' ) ).toBeVisible();
	await page.keyboard.press( 'Escape' );
	await expect( page.locator( '.media-modal' ) ).toBeHidden();

	// Analytics tab renders totals and the per-block table.
	await page.getByRole( 'tab', { name: 'Analytics' } ).click();
	await expect( page.locator( '.cl-totals, .cl-empty' ).first() ).toBeVisible(
		{ timeout: 15_000 }
	);
	if ( SHOTS ) {
		await page.screenshot( {
			path: `${ SHOTS }/editor-analytics-data.png`,
		} );
	}

	await page.getByRole( 'button', { name: 'History' } ).click();
	const history = page.getByRole( 'dialog', { name: 'Page history' } );
	await expect(
		history.getByRole( 'button', { name: 'Load into editor' } ).first()
	).toBeVisible();
	if ( SHOTS ) {
		await page.screenshot( { path: `${ SHOTS }/editor-history.png` } );
	}
} );

test( 'onboarding wizard builds a new page and autosave turns it into a draft', async ( {
	page,
	request,
} ) => {
	test.skip( ! PASS || ! APP_PASS, 'Set E2E_PASS and E2E_APP_PASS.' );
	test.setTimeout( 120_000 );
	await login( page );
	await page.goto( '/wp-admin/post-new.php?post_type=crazylinks_page' );
	const id =
		Number( new URL( page.url() ).searchParams.get( 'post' ) ?? 0 ) ||
		( await page.evaluate( () => window.crazylinks?.post.id ?? 0 ) );
	try {
		const wizard = page.getByRole( 'dialog', { name: /Step 1 of 3/ } );
		await expect( wizard ).toBeVisible();
		await wizard.getByLabel( 'Name' ).fill( 'E2E Wizard' );
		await wizard
			.getByLabel( 'Short bio' )
			.fill( 'Made by the smoke test.' );
		await page.getByRole( 'button', { name: 'Next' } ).click();
		await page.getByRole( 'radio', { name: 'Neon' } ).click();
		await page.getByRole( 'button', { name: 'Next' } ).click();
		await page
			.getByLabel( /Paste links to your profiles/ )
			.fill(
				'instagram.com/e2e\nhttps://www.youtube.com/@e2e\nhello@example.com'
			);
		await expect(
			page
				.getByRole( 'list', { name: 'Detected profiles' } )
				.getByRole( 'listitem' )
		).toHaveCount( 3 );
		if ( SHOTS ) {
			await page.screenshot( { path: `${ SHOTS }/editor-wizard.png` } );
		}
		await page.getByRole( 'button', { name: 'Create my page' } ).click();
		const preview = page.frameLocator( 'iframe.cl-phone__screen' );
		await expect( preview.getByText( 'E2E Wizard' ).first() ).toBeVisible( {
			timeout: 15_000,
		} );
		await expect( page.locator( '.cl-status' ) ).toHaveText( 'Draft', {
			timeout: 30_000,
		} );

		const res = await request.get(
			`/wp-json/crazylinks/v1/pages/${ id }?context=edit`,
			{ headers: auth() }
		);
		const body = await res.json();
		expect( body.status ).toBe( 'draft' );
		expect( body.title ).toBe( 'E2E Wizard' );
		expect( body.page.design.theme ).toBe( 'neon' );
		expect(
			body.page.blocks[ 0 ].props.items.map(
				( i: { network: string } ) => i.network
			)
		).toEqual( [ 'instagram', 'youtube', 'email' ] );
		if ( SHOTS ) {
			await page.screenshot( { path: `${ SHOTS }/editor-new-page.png` } );
		}
	} finally {
		if ( id > 0 ) {
			await request.delete(
				`/wp-json/crazylinks/v1/pages/${ id }?force=true`,
				{ headers: auth() }
			);
		}
	}
} );

declare global {
	interface Window {
		wp: {
			data: {
				dispatch: (
					store: string
				) => Record< string, ( ...a: unknown[] ) => unknown >;
			};
		};
	}
}

test( 'footer credit, alt-text confirmation and contrast chips', async ( {
	page,
	request,
} ) => {
	test.skip( ! PASS || ! APP_PASS, 'Set E2E_PASS and E2E_APP_PASS.' );
	test.setTimeout( 120_000 );
	const original = await getPage( request );
	try {
		await login( page );
		await page.goto( `/wp-admin/post.php?post=${ PAGE_ID }&action=edit` );
		await expect( page.locator( '.cl-editor' ) ).toBeVisible();
		const preview = page.frameLocator( 'iframe.cl-phone__screen' );
		await expect( preview.locator( '.cl-page' ) ).toBeVisible( {
			timeout: 15_000,
		} );

		// Footer credit toggle shows up in the preview.
		await page.getByRole( 'tab', { name: 'Settings' } ).click();
		await page.getByRole( 'button', { name: 'Display' } ).click();
		await page.getByLabel( 'Show "Made with CrazyLinks"' ).check();
		await expect( preview.locator( '.cl-credit a' ) ).toHaveText(
			'Made with CrazyLinks',
			{ timeout: 15_000 }
		);
		await expect( preview.locator( '.cl-credit a' ) ).toHaveAttribute(
			'rel',
			/nofollow/
		);

		// An image without alt text asks before publishing.
		await page.evaluate( () => {
			const d = window.wp.data.dispatch( 'crazylinks/editor' );
			const imageId = Object.values(
				(
					window as unknown as {
						crazylinks: { media: Record< string, { id: number } > };
					}
				 ).crazylinks.media
			)[ 0 ].id;
			d.addMedia( [
				{ id: imageId, url: '', thumb: '', alt: '', mime: 'image/png' },
			] );
			d.addBlock( 'image' );
			const blocks = (
				window.wp.data as unknown as {
					select: ( s: string ) => {
						getDoc: () => {
							blocks: Array< { id: string; type: string } >;
						};
					};
				}
			 )
				.select( 'crazylinks/editor' )
				.getDoc().blocks;
			const img = blocks.find( ( b ) => b.type === 'image' );
			d.setBlockProps( img?.id, { image: imageId } );
		} );
		await page.getByRole( 'tab', { name: 'Links' } ).click();
		await expect(
			page.locator( '.cl-field-warning' ).first()
		).toContainText( 'no alternative text' );
		await page
			.getByRole( 'button', { name: 'Update', exact: true } )
			.click();
		const confirm = page.getByRole( 'dialog', {
			name: 'Some things need a look',
		} );
		await expect( confirm ).toBeVisible();
		if ( SHOTS ) {
			await page.screenshot( {
				path: `${ SHOTS }/editor-alt-confirm.png`,
			} );
		}
		await confirm
			.getByRole( 'button', { name: 'Go back and fix' } )
			.click();
		await expect( confirm ).toBeHidden();

		// Marking it decorative clears the warning on this block.
		await page.getByLabel( 'Decorative image' ).check();
		await expect( page.locator( '.cl-field-warning' ) ).toHaveCount( 0 );

		// Low-contrast muted text produces a chip with the ratio.
		await page.evaluate( () => {
			const d = window.wp.data.dispatch( 'crazylinks/editor' );
			d.setSection( 'design', [ 'background' ], {
				type: 'solid',
				color: '#ffffff',
			} );
			d.setSection( 'design', [ 'text', 'muted' ], '#e0e0e0' );
		} );
		await page.getByRole( 'tab', { name: 'Design' } ).click();
		await expect( page.locator( '.cl-contrast-chips' ) ).toContainText(
			/Muted text on the background: \d+\.\d:1, needs 4\.5:1/
		);

		// The gallery images share the same (now alt-less) media entry, so Update still asks first.
		await page
			.getByRole( 'button', { name: 'Update', exact: true } )
			.click();
		await expect( confirm ).toBeVisible();
		await confirm.getByRole( 'button', { name: 'Update anyway' } ).click();
		await expect( page.locator( '.cl-topbar__note' ) ).toHaveText(
			/Published|Saved/,
			{ timeout: 15_000 }
		);
		const saved = await getPage( request );
		expect( saved.page.settings.footer_credit ).toBe( true );
		const html = await ( await request.get( saved.link ) ).text();
		expect( html ).toContain( 'Made with CrazyLinks' );
	} finally {
		const res = await request.patch(
			`/wp-json/crazylinks/v1/pages/${ PAGE_ID }`,
			{
				headers: { ...auth(), 'Content-Type': 'application/json' },
				data: { page: original.page, title: original.title },
			}
		);
		expect( res.status() ).toBe( 200 );
	}
} );

test( 'a second user sees the edit lock and can take over', async ( {
	page,
	browser,
	request,
} ) => {
	test.skip( ! PASS || ! APP_PASS, 'Set E2E_PASS and E2E_APP_PASS.' );
	test.setTimeout( 120_000 );
	const login2 = `e2e-lock-${ Date.now() }`;
	const pass2 = `Pw-${ Date.now() }-x!`;
	const created = await request.post( '/wp-json/wp/v2/users', {
		headers: { ...auth(), 'Content-Type': 'application/json' },
		data: {
			username: login2,
			email: `${ login2 }@example.com`,
			password: pass2,
			roles: [ 'administrator' ],
			name: 'Lock Tester',
		},
	} );
	expect( created.status() ).toBe( 201 );
	const userId = ( await created.json() ).id as number;
	const other = await browser.newContext( {
		baseURL: process.env.E2E_BASE_URL ?? 'http://crazylinks.test:8888',
	} );
	try {
		await login( page );
		await page.goto( `/wp-admin/post.php?post=${ PAGE_ID }&action=edit` );
		await expect( page.locator( '.cl-editor' ) ).toBeVisible();
		await expect( page.locator( '.cl-lock' ) ).toHaveCount( 0 );

		const second = await other.newPage();
		await second.goto( '/wp-login.php' );
		await second.fill( '#user_login', login2 );
		await second.fill( '#user_pass', pass2 );
		await second.click( '#wp-submit' );
		await second.waitForURL( /wp-admin/ );
		await second.goto( `/wp-admin/post.php?post=${ PAGE_ID }&action=edit` );
		const banner = second.locator( '.cl-lock' );
		await expect( banner ).toContainText( 'is editing this page' );
		if ( SHOTS ) {
			await second.screenshot( { path: `${ SHOTS }/editor-lock.png` } );
		}
		await banner.getByRole( 'link', { name: 'Take over' } ).click();
		await expect( second.locator( '.cl-editor' ) ).toBeVisible();
		await expect( second.locator( '.cl-lock' ) ).toHaveCount( 0 );

		// The first user, reloading, is now the one locked out.
		await page.reload();
		await expect( page.locator( '.cl-lock' ) ).toContainText(
			'is editing this page'
		);
	} finally {
		await other.close();
		await request.delete(
			`/wp-json/wp/v2/users/${ userId }?force=true&reassign=1`,
			{ headers: auth() }
		);
	}
} );
