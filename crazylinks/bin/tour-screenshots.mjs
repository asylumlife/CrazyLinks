/* eslint-disable no-console */
/**
 * Takes the screenshots used by docs/FEATURES.md from the local dev site.
 *
 * Usage (from crazylinks/, dev site running, see docs/DEV-ENVIRONMENT.md):
 *   TOUR_PAGE_ID=108 TOUR_PASS="$(sed -n 's/^ADMIN_PASSWORD=//p' ~/Sites/.crazylinks-dev-credentials)" \
 *   node bin/tour-screenshots.mjs [public|themes|admin ...]
 *
 * Env: TOUR_BASE (default http://crazylinks.test:8888), TOUR_SLUG (tour), TOUR_URL_BASE (linktree),
 * TOUR_USER (admin), TOUR_OUT (../docs/screenshots/tour), TOUR_THEME_SLUGS (comma list of
 * "theme:page-slug" pairs for the themes sheet).
 */
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const BASE = process.env.TOUR_BASE ?? 'http://crazylinks.test:8888';
const SLUG = process.env.TOUR_SLUG ?? 'tour';
const URL_BASE = process.env.TOUR_URL_BASE ?? 'linktree';
const USER = process.env.TOUR_USER ?? 'admin';
const PASS = process.env.TOUR_PASS ?? '';
const PAGE_ID = Number( process.env.TOUR_PAGE_ID ?? 0 );
const OUT = resolve(
	here,
	process.env.TOUR_OUT ?? '../../docs/screenshots/tour'
);
const want = process.argv.slice( 2 );
const run = ( name ) => ! want.length || want.includes( name );
const host = new URL( BASE ).hostname;

mkdirSync( OUT, { recursive: true } );

const browser = await chromium.launch( {
	channel: 'chrome',
	// The dev host has no /etc/hosts entry when MAMP PRO is not managing it.
	args: [ `--host-resolver-rules=MAP ${ host } 127.0.0.1` ],
} );

/**
 * Screenshots the area covered by the given locators, with a margin.
 *
 * @param {import('@playwright/test').Page} page     Page.
 * @param {string}                          name     File name without extension.
 * @param {Object|Object[]}                 locators Locators to cover.
 * @param {number}                          margin   Margin in CSS pixels.
 */
async function shot( page, name, locators, margin = 14 ) {
	const boxes = [];
	for ( const l of [ locators ].flat() ) {
		const n = await l.count();
		for ( let i = 0; i < n; i++ ) {
			const b = await l.nth( i ).boundingBox();
			if ( b ) {
				boxes.push( b );
			}
		}
	}
	if ( ! boxes.length ) {
		console.warn( `skip ${ name }: nothing matched` );
		return;
	}
	const vw = page.viewportSize().width;
	const x1 = Math.max( 0, Math.min( ...boxes.map( ( b ) => b.x ) ) - margin );
	const y1 = Math.max( 0, Math.min( ...boxes.map( ( b ) => b.y ) ) - margin );
	const x2 = Math.min(
		vw,
		Math.max( ...boxes.map( ( b ) => b.x + b.width ) ) + margin
	);
	const y2 = Math.max( ...boxes.map( ( b ) => b.y + b.height ) ) + margin;
	await page.screenshot( {
		path: `${ OUT }/${ name }.png`,
		fullPage: true,
		clip: { x: x1, y: y1, width: x2 - x1, height: y2 - y1 },
	} );
	console.log( name );
}

const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 };

if ( run( 'public' ) ) {
	const ctx = await browser.newContext( phone );
	const page = await ctx.newPage();
	await page.goto( `${ BASE }/${ URL_BASE }/${ SLUG }/` );
	await page.addStyleTag( {
		content: '*{animation:none!important;transition:none!important}',
	} );
	// Load lazy images, then make the viewport as tall as the page so the background spans it.
	await page.evaluate( async () => {
		const imgs = [ ...document.images ];
		imgs.forEach( ( i ) => ( i.loading = 'eager' ) );
		await Promise.all(
			imgs.map( ( i ) =>
				i.complete
					? null
					: new Promise( ( r ) => {
							i.onload = i.onerror = r;
							setTimeout( r, 8000 );
						} )
			)
		);
	} );
	const height = await page.evaluate(
		() => document.documentElement.scrollHeight
	);
	await page.setViewportSize( { width: 390, height } );
	await page.waitForTimeout( 500 );
	const b = ( type ) => page.locator( `.cl-blocks > .cl-block--${ type }` );
	const links = b( 'link' );
	await shot( page, 'profile', page.locator( '.cl-profile' ) );
	await shot( page, 'links', links );
	await shot( page, 'heading', b( 'heading' ).nth( 1 ) );
	await shot( page, 'text-divider', [ b( 'text' ), b( 'divider' ) ] );
	await shot( page, 'image', b( 'image' ) );
	await shot( page, 'countdown', b( 'countdown' ).nth( 0 ) );
	await shot( page, 'countdown-sizes', [
		b( 'countdown' ).nth( 1 ),
		b( 'countdown' ).nth( 2 ),
	] );
	await shot( page, 'map', b( 'map' ) );
	await shot( page, 'video', b( 'video' ) );
	await shot( page, 'gallery', b( 'gallery' ) );
	await shot( page, 'latest-posts', b( 'latest-posts' ) );
	await shot( page, 'product', b( 'product' ).nth( 0 ) );
	await shot( page, 'product-newest', b( 'product' ).nth( 1 ) );
	await shot( page, 'payment', b( 'payment' ) );
	await shot( page, 'contact', b( 'contact' ) );
	await shot( page, 'email-capture', b( 'email-capture' ) );
	await shot( page, 'socials', b( 'socials' ) );
	await shot( page, 'section', b( 'group' ).nth( 0 ) );
	await shot( page, 'section-grid', b( 'group' ).nth( 1 ) );
	await ctx.close();
}

if ( run( 'themes' ) ) {
	// One 390px-wide capture per theme, laid out as a contact sheet.
	const pairs = ( process.env.TOUR_THEME_SLUGS ?? '' )
		.split( ',' )
		.filter( Boolean )
		.map( ( p ) => p.split( ':' ) );
	const ctx = await browser.newContext( phone );
	const page = await ctx.newPage();
	const tiles = [];
	for ( const [ theme, slug ] of pairs ) {
		await page.goto( `${ BASE }/${ URL_BASE }/${ slug }/`, {
			waitUntil: 'networkidle',
		} );
		await page.waitForTimeout( 400 );
		const buf = await page.screenshot( {
			clip: { x: 0, y: 0, width: 390, height: 640 },
		} );
		tiles.push( { theme, src: buf.toString( 'base64' ) } );
	}
	await ctx.close();
	if ( tiles.length ) {
		const sheet = await browser.newContext( {
			viewport: { width: 1180, height: 800 },
			deviceScaleFactor: 1,
		} );
		const p = await sheet.newPage();
		await p.setContent(
			`<style>body{margin:0;padding:20px;background:#f3f4f6;font:600 13px system-ui;display:grid;grid-template-columns:repeat(5,1fr);gap:16px}
			figure{margin:0;text-align:center;color:#374151}img{width:100%;border-radius:10px;display:block;margin-bottom:6px;box-shadow:0 1px 4px rgba(0,0,0,.2)}</style>` +
				tiles
					.map(
						( t ) =>
							`<figure><img src="data:image/png;base64,${ t.src }"><figcaption>${ t.theme }</figcaption></figure>`
					)
					.join( '' )
		);
		await p.screenshot( { path: `${ OUT }/themes.png`, fullPage: true } );
		console.log( 'themes' );
		await sheet.close();
	}
}

if ( run( 'admin' ) ) {
	if ( ! PASS || ! PAGE_ID ) {
		console.warn( 'admin: set TOUR_PASS and TOUR_PAGE_ID' );
	} else {
		const ctx = await browser.newContext( {
			viewport: { width: 1360, height: 1040 },
			deviceScaleFactor: 1,
		} );
		const page = await ctx.newPage();
		await page.goto( `${ BASE }/wp-login.php` );
		await page.fill( '#user_login', USER );
		await page.fill( '#user_pass', PASS );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );

		await page.goto(
			`${ BASE }/wp-admin/edit.php?post_type=crazylinks_page&page=crazylinks-analytics&page_id=${ PAGE_ID }&range=30`
		);
		const panel = ( title ) =>
			page.locator( '.cl-panel', {
				has: page.getByRole( 'heading', { name: title } ),
			} );
		await shot( page, 'analytics-overview', [
			page.locator( '.cl-cards' ),
			panel( 'Views and clicks per day (UTC)' ),
		] );
		await shot( page, 'analytics-links', panel( 'Links' ) );
		await shot(
			page,
			'analytics-sources',
			panel( 'What each source clicks' )
		);
		await shot( page, 'analytics-hours', panel( 'Time of day' ) );
		await shot( page, 'analytics-activity', panel( 'Activity' ) );
		await shot(
			page,
			'analytics-visitors',
			panel( 'Where visitors come from' )
		);

		await page.goto(
			`${ BASE }/wp-admin/post.php?post=${ PAGE_ID }&action=edit`
		);
		await page.locator( '.cl-editor' ).waitFor();
		await page
			.frameLocator( 'iframe.cl-phone__screen' )
			.locator( '.cl-page' )
			.waitFor( { timeout: 20_000 } );
		await page.addStyleTag( {
			content:
				'#wpfooter,.notice,div.updated,div.error{display:none!important}',
		} );
		await page.screenshot( { path: `${ OUT }/editor-links.png` } );
		console.log( 'editor-links' );

		// Open the weekly countdown's panel.
		const row = page
			.locator( '.cl-block__main', { hasText: 'Friday live stream' } )
			.first();
		if ( await row.count() ) {
			await row.scrollIntoViewIfNeeded();
			await row.click();
			await page.waitForTimeout( 400 );
			await row.evaluate( ( el ) =>
				el.scrollIntoView( { block: 'start' } )
			);
			await page.evaluate( () => window.scrollBy( 0, -90 ) );
			await page.waitForTimeout( 300 );
			await page.screenshot( {
				path: `${ OUT }/editor-countdown.png`,
			} );
			console.log( 'editor-countdown' );
			await row.click();
		}
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		for ( const tab of [ 'Design', 'Settings', 'Analytics' ] ) {
			await page
				.locator( '.cl-tabs' )
				.getByRole( 'tab', { name: tab, exact: true } )
				.click();
			await page.waitForTimeout( 1200 );
			await page.screenshot( {
				path: `${ OUT }/editor-${ tab.toLowerCase() }.png`,
				fullPage: tab === 'Analytics',
			} );
			console.log( `editor-${ tab.toLowerCase() }` );
		}
		await ctx.close();
	}
}

await browser.close();
