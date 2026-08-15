/**
 * S5 Framework — WebKit parity (post-sweep v2, item 5b).
 *
 * The original QA sweep had no WebKit/Safari engine available at all. This
 * runs the same 9 corpus pages at 1440 and 375 through Playwright's real
 * WebKit engine (the actual Safari rendering engine, not a Chromium
 * emulation of one) and diffs each against the Chromium screenshot from the
 * same viewport captured by viewport-matrix.mjs — layout parity is "close
 * enough to not be a defect", not byte-identical (font hinting/anti-
 * aliasing differ legitimately between engines).
 *
 * Run viewport-matrix.mjs FIRST — this script reads its Chromium
 * screenshots from docs/qa-sweep/viewports/ as the comparison baseline.
 *
 * Run: SITE_URL=http://localhost:8888 node tests/playwright/webkit-parity.mjs
 */

import { webkit } from 'playwright';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const CHROMIUM_DIR = join( ROOT, 'docs', 'qa-sweep', 'viewports' );
const OUT_DIR = join( ROOT, 'docs', 'qa-sweep', 'webkit' );
mkdirSync( OUT_DIR, { recursive: true } );

const SITE_URL = process.env.SITE_URL || 'http://localhost:8888';

const PAGES = [
	'about', 'apps', 'book-a-call', 'commerce', 'faq',
	'fix-my-store', 'index', 'manage', 'privacy-policy',
];

const VIEWPORTS = [
	{ label: '1440', width: 1440, height: 900 },
	{ label: '375', width: 375, height: 844 },
];

/* PNG width/height live at a fixed offset in the IHDR chunk — 4 bytes each,
 * big-endian, starting at byte 16. No dependency needed to read them. */
function pngSize( buf ) {
	return { width: buf.readUInt32BE( 16 ), height: buf.readUInt32BE( 20 ) };
}

let failures = 0;
let notes = 0;
function pass( msg ) {
	process.stdout.write( `✔ ${msg}\n` );
}
function note( msg ) {
	notes++;
	process.stdout.write( `• ${msg}\n` );
}
function fail( msg ) {
	failures++;
	process.stderr.write( `✘ ${msg}\n` );
}

async function run() {
	const browser = await webkit.launch();

	for ( const vp of VIEWPORTS ) {
		const context = await browser.newContext( { viewport: { width: vp.width, height: vp.height } } );
		const page = await context.newPage();

		for ( const slug of PAGES ) {
			const url = slug === 'index' ? `${SITE_URL}/` : `${SITE_URL}/${slug}/`;
			await page.goto( url, { waitUntil: 'networkidle' } );

			const actualWidth = await page.evaluate( () => window.innerWidth );
			if ( actualWidth !== vp.width ) {
				fail( `${slug} @ ${vp.label}px (WebKit): viewport did not actually change (${actualWidth})` );
				continue;
			}

			const overflow = await page.evaluate( () => {
				const de = document.documentElement;
				return de.scrollWidth - de.clientWidth;
			} );

			const webkitFile = join( OUT_DIR, `${slug}-${vp.label}.png` );
			await page.screenshot( { path: webkitFile, fullPage: true } );

			const chromiumFile = join( CHROMIUM_DIR, `${slug}-${vp.label}.png` );

			let sizeParity = 'no Chromium baseline to compare (run test:viewports first)';
			if ( existsSync( chromiumFile ) ) {
				const cSize = pngSize( readFileSync( chromiumFile ) );
				const wSize = pngSize( readFileSync( webkitFile ) );
				const widthDelta = Math.abs( cSize.width - wSize.width );
				const heightRatio = cSize.height > 0 ? Math.abs( cSize.height - wSize.height ) / cSize.height : 0;
				if ( widthDelta > 2 ) {
					fail( `${slug} @ ${vp.label}px: WIDTH mismatch vs Chromium (chromium=${cSize.width}px, webkit=${wSize.width}px) — real layout divergence` );
					continue;
				}
				// Height differing by more than ~15% usually means a real content/
				// layout difference (a wrapped element, a missing font fallback
				// changing line count), not just anti-aliasing — flag as a note to
				// review, not a hard fail (fonts/AVIF support legitimately differ).
				if ( heightRatio > 0.15 ) {
					note( `${slug} @ ${vp.label}px: height differs by ${( heightRatio * 100 ).toFixed( 1 )}% vs Chromium (chromium=${cSize.height}px, webkit=${wSize.height}px) — review images side by side` );
				}
				sizeParity = `width matches (${wSize.width}px), height delta ${( heightRatio * 100 ).toFixed( 1 )}%`;
			}

			if ( overflow > 1 ) {
				fail( `${slug} @ ${vp.label}px (WebKit): horizontal overflow of ${overflow}px` );
			} else {
				pass( `${slug} @ ${vp.label}px (WebKit): no overflow; ${sizeParity} — ${webkitFile}` );
			}
		}

		await context.close();
	}

	await browser.close();

	process.stdout.write( `\n${PAGES.length * VIEWPORTS.length} WebKit screenshots captured to ${OUT_DIR}\n` );
	if ( notes > 0 ) {
		process.stdout.write( `${notes} height-delta note(s) — not failures, worth an eyeball.\n` );
	}
	if ( failures > 0 ) {
		process.stderr.write( `${failures} check(s) failed.\n` );
		process.exit( 1 );
	}
	process.stdout.write( 'All WebKit parity checks passed.\n' );
}

run().catch( ( e ) => {
	process.stderr.write( `Fatal: ${e.stack || e}\n` );
	process.exit( 1 );
} );
