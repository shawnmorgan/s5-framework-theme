#!/usr/bin/env node
/**
 * reskin-screenshots.mjs — capture the dark-reskinned S5 editor.
 *
 * Self-contained headless Chromium (Playwright, own userDataDir) — never touches any
 * other running browser. Logs into wp-admin, opens the S5 editor on a framework page,
 * and captures each panel/lens into docs/demo-walkthrough/reskin-dark/.
 *
 * Usage: S5_EDITOR_URL="…" node tools/reskin-screenshots.mjs
 */

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'docs', 'demo-walkthrough', 'reskin-dark');
mkdirSync(OUT, { recursive: true });

const BASE = process.env.S5_BASE || 'http://localhost:8888';
const USER = process.env.S5_USER || 'admin';
const PASS = process.env.S5_PASS || 'password';
const EDITOR_URL = process.env.S5_EDITOR_URL;

const shot = async (page, name) => {
	await page.screenshot({ path: join(OUT, name) });
	process.stdout.write(`  ${name}\n`);
};

const ctx = await chromium.launchPersistentContext(join('/tmp', 's5-reskin-profile'), {
	headless: true,
	viewport: { width: 1440, height: 900 },
});
const page = ctx.pages()[0] || (await ctx.newPage());

try {
	await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', USER);
	await page.fill('#user_pass', PASS);
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		page.click('#wp-submit'),
	]);

	await page.goto(EDITOR_URL, { waitUntil: 'networkidle' });
	await page.waitForSelector('.s5-editor', { timeout: 15000 });
	await page.waitForTimeout(2000); // let the preview iframe + structure load

	await shot(page, '01-content-edit.png');

	// Code lens.
	await page.click('[data-s5-lens="code"]');
	await page.waitForTimeout(800);
	await shot(page, '02-code-lens.png');

	// back to edit, then Style tab.
	await page.click('[data-s5-lens="edit"]');
	await page.click('[data-s5-tab="style"]');
	await page.waitForTimeout(1200);
	await shot(page, '03-style-tab.png');

	// Components tab.
	await page.click('[data-s5-tab="components"]');
	await page.waitForTimeout(1000);
	await shot(page, '04-components-tab.png');

	process.stdout.write('done\n');
} finally {
	await ctx.close();
}
