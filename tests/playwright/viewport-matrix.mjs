/**
 * S5 Framework — viewport matrix (post-sweep v2, item 5a).
 *
 * The original QA sweep's viewport pass was invalid: the browser-resize tool
 * it used changed the OS window but never the page's actual rendering
 * viewport (window.innerWidth stayed 1442 regardless of the requested
 * size) — confirmed by direct JS inspection at the time, so no screenshot
 * from that pass reflected a real mobile/tablet viewport. This script uses
 * Playwright's own browser-CONTEXT viewport (not a window resize bolted on
 * after launch), and explicitly asserts window.innerWidth actually changed
 * BEFORE trusting any screenshot — closing exactly the gap the sweep
 * disclosed.
 *
 * 375 / 768 / 1440 × all 9 corpus pages = 27 screenshots, saved to
 * docs/qa-sweep/viewports/<page>-<width>.png.
 *
 * Run: SITE_URL=http://localhost:8888 node tests/playwright/viewport-matrix.mjs
 */

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const OUT_DIR = join( ROOT, 'docs', 'qa-sweep', 'viewports' );
mkdirSync( OUT_DIR, { recursive: true } );

const SITE_URL = process.env.SITE_URL || 'http://localhost:8888';

const PAGES = [
	'about', 'apps', 'book-a-call', 'commerce', 'faq',
	'fix-my-store', 'index', 'manage', 'privacy-policy',
];

const VIEWPORTS = [
	{ label: '375', width: 375, height: 844 },
	{ label: '768', width: 768, height: 1024 },
	{ label: '1440', width: 1440, height: 900 },
];

let failures = 0;
function pass( msg ) {
	process.stdout.write( `✔ ${msg}\n` );
}
function fail( msg ) {
	failures++;
	process.stderr.write( `✘ ${msg}\n` );
}

async function run() {
	const browser = await chromium.launch();

	for ( const vp of VIEWPORTS ) {
		const context = await browser.newContext( { viewport: { width: vp.width, height: vp.height } } );
		const page = await context.newPage();

		for ( const slug of PAGES ) {
			const url = slug === 'index' ? `${SITE_URL}/` : `${SITE_URL}/${slug}/`;
			await page.goto( url, { waitUntil: 'networkidle' } );

			// The gate the sweep's own tooling failed: assert the viewport ACTUALLY
			// changed before trusting anything the screenshot shows.
			const actualWidth = await page.evaluate( () => window.innerWidth );
			if ( actualWidth !== vp.width ) {
				fail( `${slug} @ ${vp.label}px: window.innerWidth is ${actualWidth}, not ${vp.width} — viewport did not actually change, screenshot would be invalid` );
				continue;
			}

			// Overflow check: the page body must never scroll horizontally at this
			// viewport (the class of bug the original sweep's zero-@media finding
			// predicted). document.documentElement.scrollWidth > clientWidth means
			// something is overflowing the viewport horizontally.
			const overflow = await page.evaluate( () => {
				const de = document.documentElement;
				return de.scrollWidth - de.clientWidth;
			} );

			const outfile = join( OUT_DIR, `${slug}-${vp.label}.png` );
			await page.screenshot( { path: outfile, fullPage: true } );

			if ( overflow > 1 ) {
				// 1px tolerance for scrollbar-width rounding.
				fail( `${slug} @ ${vp.label}px: horizontal overflow of ${overflow}px (page.scrollWidth > viewport width) — see ${outfile}` );
			} else {
				pass( `${slug} @ ${vp.label}px: viewport confirmed (${actualWidth}px), no horizontal overflow — ${outfile}` );
			}
		}

		await context.close();
	}

	await browser.close();

	process.stdout.write( `\n${PAGES.length * VIEWPORTS.length} screenshots captured to ${OUT_DIR}\n` );
	if ( failures > 0 ) {
		process.stderr.write( `${failures} check(s) failed.\n` );
		process.exit( 1 );
	}
	process.stdout.write( 'All viewport checks passed.\n' );
}

run().catch( ( e ) => {
	process.stderr.write( `Fatal: ${e.stack || e}\n` );
	process.exit( 1 );
} );
