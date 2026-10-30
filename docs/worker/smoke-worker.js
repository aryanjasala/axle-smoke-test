#!/usr/bin/env node
/**
 * Axle Smoke Test: reference external worker.
 *
 * Loads the key URLs of a run in a real browser on your own machine or in CI,
 * takes a screenshot of each, and uploads the results to the site. The site
 * compares them with the worker baseline like any other tier.
 *
 * Not part of the plugin zip. Needs Node 20+ and Playwright:
 *   npm install playwright && npx playwright install chromium
 *
 * Usage:
 *   SITE=https://example.com WP_USER=admin WP_APP_PASSWORD="abcd efgh ijkl mnop qrst uvwx" \
 *     node smoke-worker.js [run-id]
 *
 * Without a run ID it picks the newest run. The application password must
 * belong to a user who can run smoke tests (update_plugins by default).
 */
const { chromium } = require( 'playwright' );

const SITE = ( process.env.SITE || '' ).replace( /\/$/, '' );
const AUTH = 'Basic ' + Buffer.from( `${ process.env.WP_USER }:${ process.env.WP_APP_PASSWORD }` ).toString( 'base64' );
const SELECTORS = [ 'header', 'nav', 'main', 'footer', 'h1', 'form', '.wp-site-blocks > *', '#page', '#content' ];

async function api( method, route, body ) {
	const response = await fetch( `${ SITE }/?rest_route=/axle-smoke-test/v1${ route }`, {
		method,
		headers: { Authorization: AUTH, 'Content-Type': 'application/json' },
		body: body ? JSON.stringify( body ) : undefined,
	} );
	const data = await response.json();
	if ( ! response.ok ) {
		throw new Error( `${ method } ${ route }: ${ response.status } ${ data.message || '' }` );
	}
	return data;
}

async function checkPage( page, url ) {
	const errors = [];
	const failed = [];
	page.removeAllListeners();
	page.on( 'pageerror', ( error ) => errors.push( { message: String( error.message ).slice( 0, 500 ), url: '', kind: 'error' } ) );
	page.on( 'console', ( message ) => {
		if ( 'error' === message.type() ) {
			errors.push( { message: message.text().slice( 0, 500 ), url: message.location().url || '', line: message.location().lineNumber || 0, kind: 'console' } );
		}
	} );
	page.on( 'requestfailed', ( request ) => failed.push( { url: request.url(), kind: request.resourceType(), status: 0 } ) );
	page.on( 'response', ( response ) => {
		if ( response.status() >= 400 && response.url() !== url ) {
			failed.push( { url: response.url(), kind: response.request().resourceType(), status: response.status() } );
		}
	} );

	const started = Date.now();
	const response = await page.goto( url, { waitUntil: 'load', timeout: 60000 } );
	await page.waitForTimeout( 1500 );
	const layout = await page.evaluate( ( selectors ) => {
		const out = {};
		for ( const selector of selectors ) {
			document.querySelectorAll( selector ).forEach( ( element, index ) => {
				if ( index > 2 ) {
					return;
				}
				const rect = element.getBoundingClientRect();
				out[ selector + ( index ? ` #${ index + 1 }` : '' ) ] = {
					x: Math.round( rect.left + window.scrollX ),
					y: Math.round( rect.top + window.scrollY ),
					w: Math.round( rect.width ),
					h: Math.round( rect.height ),
					visible: rect.width > 0 && rect.height > 0 && element.checkVisibility( { opacityProperty: true, visibilityProperty: true } ),
				};
			} );
		}
		return out;
	}, SELECTORS );
	const thumbnail = ( await page.screenshot( { type: 'png' } ) ).toString( 'base64' );

	return { http_status: response ? response.status() : 0, js_errors: errors.slice( 0, 50 ), failed: failed.slice( 0, 50 ), layout, load_ms: Date.now() - started, thumbnail };
}

( async () => {
	if ( ! SITE || ! process.env.WP_USER || ! process.env.WP_APP_PASSWORD ) {
		console.error( 'Set SITE, WP_USER and WP_APP_PASSWORD.' );
		process.exit( 2 );
	}

	const id = process.argv[ 2 ] || ( await api( 'GET', '/runs&per_page=1' ) )[ 0 ].id;
	const run = await api( 'GET', `/runs/${ id }` );
	console.log( `Run #${ run.id }: ${ run.urls.length } URLs` );

	const browser = await chromium.launch();
	const page = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	for ( const url of run.urls ) {
		let data;
		try {
			data = await checkPage( page, url.url );
		} catch ( error ) {
			data = { error: String( error.message ).slice( 0, 500 ) };
		}
		const stored = await api( 'POST', `/runs/${ run.id }/results`, { tier: 'worker', url_key: url.key, data } );
		console.log( `${ stored.verdict.padEnd( 5 ) } ${ url.label }` );
	}
	await browser.close();

	const done = await api( 'POST', `/runs/${ run.id }/complete`, { tier: 'worker' } );
	console.log( `Verdict: ${ done.verdict }` );
	process.exit( 'red' === done.verdict ? 1 : 0 );
} )().catch( ( error ) => {
	console.error( error.message );
	process.exit( 2 );
} );
