#!/usr/bin/env node
/**
 * dist-screenshots.mjs — capture the distribution deliverable screenshots.
 *
 * Runs a self-contained headless Chromium (Playwright) with its OWN userDataDir, so
 * it never touches any other running browser. Logs into the wp-env admin and captures
 * the five required shots against the ZIP install (theme s5-dist) at localhost:8888:
 *
 *   1. Setup & Status screen (env checks + adoption list)
 *   2. Top-level S5 admin menu
 *   3. S5 editor open on a framework page
 *   4. Styled fallback (a non-framework page on the front end)
 *   5. Styled framework page on the front end
 *
 * Usage: node tools/dist-screenshots.mjs
 */

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'docs', 'dist-walkthrough');
mkdirSync(OUT, { recursive: true });

const BASE = process.env.S5_BASE || 'http://localhost:8888';
const USER = process.env.S5_USER || 'admin';
const PASS = process.env.S5_PASS || 'password';
const FW_URL = process.env.S5_FW_URL;       // a framework page permalink
const FALLBACK_URL = process.env.S5_FB_URL; // a non-framework page permalink
const EDITOR_URL = process.env.S5_EDITOR_URL;

const shot = async (page, name) => {
	await page.screenshot({ path: join(OUT, name), fullPage: false });
	process.stdout.write(`  screenshot: ${name}\n`);
};

const browser = await chromium.launchPersistentContext(
	join('/tmp', 's5-shot-profile'),
	{ headless: true, viewport: { width: 1440, height: 900 } }
);
const page = browser.pages()[0] || (await browser.newPage());

try {
	// Log in.
	await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', USER);
	await page.fill('#user_pass', PASS);
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		page.click('#wp-submit'),
	]);

	// 1. Setup & Status.
	await page.goto(`${BASE}/wp-admin/admin.php?page=s5`, { waitUntil: 'networkidle' });
	await shot(page, 'A-setup-status.png');

	// 2. Top-level S5 menu (visible in the admin sidebar on the same screen).
	await shot(page, 'A-s5-menu.png');

	// 3. Editor open on a framework page.
	if (EDITOR_URL) {
		await page.goto(EDITOR_URL, { waitUntil: 'networkidle' });
		await page.waitForTimeout(1500);
		await shot(page, 'B-editor-open.png');
	}

	// 4. Styled fallback (non-framework page).
	if (FALLBACK_URL) {
		await page.goto(FALLBACK_URL, { waitUntil: 'networkidle' });
		await shot(page, 'A-styled-fallback.png');
	}

	// 5. Styled framework page.
	if (FW_URL) {
		await page.goto(FW_URL, { waitUntil: 'networkidle' });
		await shot(page, 'B-styled-framework-page.png');
	}

	process.stdout.write('done\n');
} finally {
	await browser.close();
}
