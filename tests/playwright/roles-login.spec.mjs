/**
 * S5 Framework — Roles & Access, real browser logins (post-sweep v2, item 1
 * standing gate).
 *
 * The pre-existing tests/acceptance-roles.sh proves Roles::can() resolves
 * correctly as PHP logic — but that suite passed even while the product was
 * completely broken (every entrance hard-gated on manage_options ahead of
 * Roles::can() ever running), because it never drove a real browser session.
 * This is the replacement standing gate: create three REAL WordPress users
 * (Author/Editor/Administrator), log in as each through the real login form,
 * and assert what a human in that seat actually sees and can reach —
 * including a direct URL-jump to a blocked route, which must be refused
 * server-side, not just absent from the menu.
 *
 * Run: SITE_URL=http://localhost:8888 node tests/playwright/roles-login.spec.mjs
 *   (SITE_URL defaults to http://localhost:8888, the wp-env default.)
 *
 * Requires the target site to have three users already seeded with these
 * exact credentials (tests/run-all.sh seeds them via `wp user create` before
 * calling this script — see the seed block in run-all.sh's Roles section).
 */

import { chromium } from 'playwright';

const SITE_URL = process.env.SITE_URL || 'http://localhost:8888';
const USERS = {
	client: { user: 's5pw_client', pass: 'PwLogin123!', wpRole: 'author' },
	marketer: { user: 's5pw_marketer', pass: 'PwLogin123!', wpRole: 'editor' },
	developer: { user: 's5pw_developer', pass: 'PwLogin123!', wpRole: 'administrator' },
};

let failures = 0;
function pass( msg ) {
	process.stdout.write( `✔ ${msg}\n` );
}
function fail( msg ) {
	failures++;
	process.stderr.write( `✘ ${msg}\n` );
}

async function login( page, creds ) {
	await page.goto( `${SITE_URL}/wp-login.php` );
	await page.fill( '#user_login', creds.user );
	await page.fill( '#user_pass', creds.pass );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'networkidle' );
}

async function logout( page ) {
	// wp_logout_url() needs a live nonce; fetch it from within an authenticated
	// admin page rather than hardcoding one.
	const nonce = await page.evaluate( async () => {
		const res = await fetch( '/wp-admin/', { credentials: 'same-origin' } );
		const html = await res.text();
		const m = html.match( /action=logout&(?:amp;)?_wpnonce=([a-f0-9]+)/ );
		return m ? m[ 1 ] : null;
	} );
	if ( nonce ) {
		await page.goto( `${SITE_URL}/wp-login.php?action=logout&_wpnonce=${nonce}` );
		const logoutLink = page.locator( 'a', { hasText: 'log out' } );
		if ( await logoutLink.count() ) {
			await logoutLink.first().click();
		}
	}
	await page.context().clearCookies();
}

async function run() {
	const browser = await chromium.launch();
	const context = await browser.newContext();
	const page = await context.newPage();

	// --- Client (Author): reaches the editor; blocked from Theme Settings and
	// the S5 top-level menu is absent entirely (hidden AND blocked). -----------
	await login( page, USERS.client );

	await page.goto( `${SITE_URL}/wp-admin/` );
	const s5MenuVisible = await page.locator( '#toplevel_page_s5' ).count();
	if ( 0 === s5MenuVisible ) {
		pass( 'Client: "S5" top-level menu absent from sidebar' );
	} else {
		fail( 'Client: "S5" top-level menu unexpectedly present' );
	}

	// Open the editor directly by post id (ABOUT_POST_ID, env-supplied — see
	// run-all.sh). NOT via the pages-list row action: WordPress's own Author
	// role capability (edit_others_pages) hides every page not authored BY
	// that user from the pages list by design — real, correct core behavior,
	// unrelated to the S5 role gate this test exists to prove. A direct link
	// (e.g. one a Marketer/Developer shares) is the realistic path for a
	// Client who needs to reach a specific page anyway.
	const aboutId = process.env.ABOUT_POST_ID || '5';
	await page.goto( `${SITE_URL}/wp-admin/admin.php?page=s5-editor&post=${aboutId}` );
	const editorLoaded = await page.locator( '[data-s5-editor]' ).count();
	if ( editorLoaded ) {
		pass( 'Client: reaches the canvas editor directly (Content+Style surfaces)' );
	} else {
		fail( 'Client: editor screen did not render for a role that should reach it' );
	}

	// Direct URL-jump to Theme Settings — must be refused, not just hidden.
	const settingsResp = await page.goto( `${SITE_URL}/wp-admin/admin.php?page=s5-settings` );
	const settingsBody = await page.textContent( 'body' );
	if ( settingsResp.status() >= 400 || /not allowed to access/i.test( settingsBody ) ) {
		pass( 'Client: URL-jump to Theme Settings refused' );
	} else {
		fail( `Client: URL-jump to Theme Settings was NOT refused (status ${settingsResp.status()})` );
	}

	await logout( page );

	// --- Marketer (Editor): reaches the editor AND Theme Settings, but only
	// General + StyleGuide; Roles/CSS/Templates/Components/Integrations/Deploy
	// stay blocked even by direct URL. --------------------------------------
	await login( page, USERS.marketer );

	await page.goto( `${SITE_URL}/wp-admin/admin.php?page=s5-settings` );
	const generalVisible = await page.locator( 'text=General' ).count();
	const rolesLinkVisible = await page.locator( '[data-screen="roles"]' ).count();
	if ( generalVisible > 0 ) {
		pass( 'Marketer: reaches Theme Settings (General screen present)' );
	} else {
		fail( 'Marketer: Theme Settings did not render General for a role that should see it' );
	}
	if ( 0 === rolesLinkVisible ) {
		pass( 'Marketer: Roles sub-nav entry absent (hidden)' );
	} else {
		fail( 'Marketer: Roles sub-nav entry unexpectedly visible' );
	}

	// URL-jump to the Roles screen directly — content must never render, even
	// if the router falls back gracefully instead of a hard 403.
	await page.goto( `${SITE_URL}/wp-admin/admin.php?page=s5-settings&screen=roles` );
	const rolesMatrixVisible = await page.locator( 'text=Permission matrix' ).count();
	if ( 0 === rolesMatrixVisible ) {
		pass( 'Marketer: URL-jump to Roles screen never renders its content' );
	} else {
		fail( 'Marketer: URL-jump to Roles screen rendered blocked content' );
	}

	await logout( page );

	// --- Developer (Administrator): unchanged, sees everything. ---------------
	await login( page, USERS.developer );

	await page.goto( `${SITE_URL}/wp-admin/` );
	const devS5Menu = await page.locator( '#toplevel_page_s5' ).count();
	if ( devS5Menu > 0 ) {
		pass( 'Developer: "S5" top-level menu present' );
	} else {
		fail( 'Developer: "S5" top-level menu missing for the unrestricted role' );
	}

	await page.goto( `${SITE_URL}/wp-admin/admin.php?page=s5-settings&screen=roles` );
	const devRolesMatrix = await page.locator( 'text=Permission matrix' ).count();
	if ( devRolesMatrix > 0 ) {
		pass( 'Developer: reaches the Roles screen and sees the permission matrix' );
	} else {
		fail( 'Developer: Roles screen content missing for the unrestricted role' );
	}

	await browser.close();

	if ( failures > 0 ) {
		process.stderr.write( `\n${failures} check(s) failed.\n` );
		process.exit( 1 );
	}
	process.stdout.write( '\nAll role-login checks passed.\n' );
}

run().catch( ( e ) => {
	process.stderr.write( `Fatal: ${e.stack || e}\n` );
	process.exit( 1 );
} );
