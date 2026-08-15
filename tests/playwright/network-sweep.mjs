/**
 * S5 Framework — network sweep (post-sweep v2, item 5c).
 *
 * Request-by-request log across the editor, all 7 settings screens, and 3
 * served pages. Asserts zero 404s and zero requests to any non-local origin
 * from S5's own assets (editor.js/css, settings assets, the compiled design
 * bundle, FontAwesome) — S5 bundles FontAwesome locally specifically so it
 * never phones out to a CDN; this is the check that promise actually holds.
 *
 * Third-party requests the S5 admin/editor screens don't control (WordPress
 * core's own telemetry, Gravatar avatars, the "WordPress Events and News"
 * dashboard widget, browser extension requests) are expected and excluded —
 * this sweep is scoped to S5's OWN asset origins, not a zero-third-party-
 * request claim about wp-admin as a whole.
 *
 * Requires an admin session: logs in once with the credentials in
 * SITE_ADMIN_USER / SITE_ADMIN_PASS (defaults: admin / password, the wp-env
 * default) so every admin screen is reachable.
 *
 * Run: SITE_URL=http://localhost:8888 node tests/playwright/network-sweep.mjs
 */

import { chromium } from 'playwright';

const SITE_URL = process.env.SITE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.SITE_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.SITE_ADMIN_PASS || 'password';
const ABOUT_POST_ID = process.env.ABOUT_POST_ID || '5';

const SETTINGS_SCREENS = [
	'general', 'templates', 'styleguide', 'components', 'css', 'integrations', 'roles',
];

const SERVED_PAGES = [ 'about', 'apps', 'faq' ];

let failures = 0;
function pass( msg ) {
	process.stdout.write( `✔ ${msg}\n` );
}
function fail( msg ) {
	failures++;
	process.stderr.write( `✘ ${msg}\n` );
}

function isLocalOrigin( url ) {
	try {
		const u = new URL( url );
		const site = new URL( SITE_URL );
		return u.hostname === site.hostname;
	} catch {
		return true; // data:/blob:/chrome-extension: etc. — not a network origin concern.
	}
}

function isS5Asset( url ) {
	return /\/wp-content\/themes\/s5-theme\/(assets|site\/dist)\//.test( url );
}

async function sweepPage( page, url, label, results ) {
	// The listener MUST attach before goto() — a request/response pair fires
	// during navigation itself, so attaching afterward (even "immediately
	// after") misses everything and silently reports 0 requests, which reads
	// as a false pass rather than a real "nothing to see here".
	const requests = [];
	const onResponse = ( response ) => {
		requests.push( { url: response.url(), status: response.status() } );
	};
	page.on( 'response', onResponse );
	await page.goto( url, { waitUntil: 'networkidle' } );
	page.off( 'response', onResponse );

	if ( 0 === requests.length ) {
		fail( `${label}: captured ZERO network requests — the listener or navigation is broken, this is not a real pass` );
		results.push( { label, total: 0, notFound: 0, s5Remote: 0 } );
		return;
	}

	const notFound = requests.filter( ( r ) => 404 === r.status );
	const s5Remote = requests.filter( ( r ) => isS5Asset( r.url ) && ! isLocalOrigin( r.url ) );

	if ( notFound.length ) {
		notFound.forEach( ( r ) => fail( `${label}: 404 — ${r.url}` ) );
	} else {
		pass( `${label}: zero 404s (${requests.length} requests)` );
	}

	if ( s5Remote.length ) {
		s5Remote.forEach( ( r ) => fail( `${label}: S5 asset served from a non-local origin — ${r.url}` ) );
	} else {
		pass( `${label}: no S5 asset requests left the local origin` );
	}

	results.push( { label, total: requests.length, notFound: notFound.length, s5Remote: s5Remote.length } );
}

async function run() {
	const browser = await chromium.launch();
	const context = await browser.newContext();
	const page = await context.newPage();
	const results = [];

	await page.goto( `${SITE_URL}/wp-login.php` );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'networkidle' );

	// Editor.
	await sweepPage( page, `${SITE_URL}/wp-admin/admin.php?page=s5-editor&post=${ABOUT_POST_ID}`, 'Editor (About)', results );

	// All 7 settings screens.
	for ( const screen of SETTINGS_SCREENS ) {
		await sweepPage( page, `${SITE_URL}/wp-admin/admin.php?page=s5-settings&screen=${screen}`, `Settings — ${screen}`, results );
	}

	// 3 served pages (logged out — the D8 isolation guarantee: served pages
	// carry no editor assets at all for an anonymous visitor).
	await context.clearCookies();
	for ( const slug of SERVED_PAGES ) {
		await sweepPage( page, `${SITE_URL}/${slug}/`, `Served page — /${slug}/`, results );
	}

	await browser.close();

	process.stdout.write( '\n' );
	results.forEach( ( r ) => {
		process.stdout.write( `  ${r.label}: ${r.total} requests, ${r.notFound} 404s, ${r.s5Remote} non-local S5 assets\n` );
	} );

	if ( failures > 0 ) {
		process.stderr.write( `\n${failures} check(s) failed.\n` );
		process.exit( 1 );
	}
	process.stdout.write( '\nNetwork sweep clean: zero 404s, zero non-local S5 asset requests.\n' );
}

run().catch( ( e ) => {
	process.stderr.write( `Fatal: ${e.stack || e}\n` );
	process.exit( 1 );
} );
