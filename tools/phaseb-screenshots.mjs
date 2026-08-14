#!/usr/bin/env node
/**
 * phaseb-screenshots.mjs — capture the rebuilt (Phase B) editor + report JS errors.
 *
 * Headless Chromium, own /tmp profile — never the user's Chrome. Logs into wp-admin,
 * opens the fullscreen S5 editor, captures the default view + code lens + a drawer,
 * and reports any console errors (a rebuilt shell must render clean).
 *
 * Usage: S5_EDIT_POST=<id> node tools/phaseb-screenshots.mjs
 */

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'docs', 'demo-walkthrough', 'phase-b');
mkdirSync(OUT, { recursive: true });

const BASE = process.env.S5_BASE || 'http://localhost:8888';
const USER = process.env.S5_USER || 'admin';
const PASS = process.env.S5_PASS || 'password';
const EDIT_POST = process.env.S5_EDIT_POST || '';

const shot = async (page, name) => {
	await page.screenshot({ path: join(OUT, name) });
	process.stdout.write(`  ${name}\n`);
};

const ctx = await chromium.launchPersistentContext(join('/tmp', 's5-phaseb-profile'), {
	headless: true,
	viewport: { width: 1440, height: 900 },
});
const page = ctx.pages()[0] || (await ctx.newPage());

const errors = [];
page.on('console', (m) => {
	if (m.type() === 'error') errors.push(m.text());
});
page.on('pageerror', (e) => errors.push('PAGEERROR: ' + e.message));

try {
	await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', USER);
	await page.fill('#user_pass', PASS);
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		page.click('#wp-submit'),
	]);

	await page.goto(`${BASE}/wp-admin/admin.php?page=s5-editor&post=${EDIT_POST}`, {
		waitUntil: 'domcontentloaded',
	});
	await page.waitForSelector('.s5-editor', { timeout: 15000 });
	await page.waitForTimeout(2500);
	await shot(page, '01-editor-default.png');

	// Open the gear drawer.
	await page.click('[data-s5-page-settings]');
	await page.waitForTimeout(500);
	await shot(page, '02-page-settings-drawer.png');
	await page.click('[data-s5-drawer-close]');
	await page.waitForTimeout(300);

	// Code lens.
	await page.click('[data-s5-lens="code"]');
	await page.waitForTimeout(600);
	await shot(page, '03-code-lens.png');
	await page.click('[data-s5-lens="edit"]');
	await page.waitForTimeout(300);

	// Tools menu open.
	await page.click('[data-s5-tools-btn]');
	await page.waitForTimeout(300);
	await shot(page, '04-tools-menu.png');

	process.stdout.write(`  console-errors: ${errors.length}\n`);
	errors.slice(0, 10).forEach((e) => process.stdout.write(`    ! ${e}\n`));
	process.stdout.write('done\n');
} finally {
	await ctx.close();
}
