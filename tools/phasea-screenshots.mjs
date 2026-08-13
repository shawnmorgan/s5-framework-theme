#!/usr/bin/env node
/**
 * phasea-screenshots.mjs — capture the Phase A WordPress-integration surfaces.
 *
 * Self-contained headless Chromium (Playwright, own userDataDir) — never touches any
 * other running browser (honors "quit fucking with my chrome browser"). Logs into
 * wp-admin and captures:
 *   01 pages-list      — "S5" post-state badge + row actions (Edit with S5 / Edit content (WP))
 *   02 s5-menu         — the S5 admin menu (Setup & Status + Theme Settings only)
 *   03 theme-settings  — the settings app shell (7-screen sub-nav)
 *   04 appearance-menu — Appearance cleaned (no Patterns/Customize/Fonts)
 *   05 editor-fullscreen — the chrome-free fullscreen editor (NO #adminmenu/#wpadminbar)
 *
 * Usage: S5_EDIT_POST=<id> node tools/phasea-screenshots.mjs
 */

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'docs', 'phase-a-walkthrough');
mkdirSync(OUT, { recursive: true });

const BASE = process.env.S5_BASE || 'http://localhost:8888';
const USER = process.env.S5_USER || 'admin';
const PASS = process.env.S5_PASS || 'password';
const EDIT_POST = process.env.S5_EDIT_POST || '';

const shot = async (page, name) => {
	await page.screenshot({ path: join(OUT, name), fullPage: false });
	process.stdout.write(`  ${name}\n`);
};

const ctx = await chromium.launchPersistentContext(join('/tmp', 's5-phasea-profile'), {
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

	// 01 — pages list with S5 badge + row actions.
	await page.goto(`${BASE}/wp-admin/edit.php?post_type=page`, { waitUntil: 'networkidle' });
	await page.waitForTimeout(600);
	await shot(page, '01-pages-list-badge.png');

	// 02 — the S5 menu expanded.
	await page.goto(`${BASE}/wp-admin/admin.php?page=s5`, { waitUntil: 'networkidle' });
	await page.waitForTimeout(500);
	await shot(page, '02-s5-menu-setup.png');

	// 03 — the Theme Settings app shell.
	await page.goto(`${BASE}/wp-admin/admin.php?page=s5-settings`, { waitUntil: 'networkidle' });
	await page.waitForTimeout(500);
	await shot(page, '03-theme-settings-shell.png');

	// 04 — Appearance menu (cleaned). Hover Appearance to expand the submenu.
	await page.goto(`${BASE}/wp-admin/themes.php`, { waitUntil: 'networkidle' });
	await page.waitForTimeout(500);
	await shot(page, '04-appearance-cleaned.png');

	// 05 — the fullscreen editor (no admin chrome).
	if (EDIT_POST) {
		await page.goto(`${BASE}/wp-admin/admin.php?page=s5-editor&post=${EDIT_POST}`, {
			waitUntil: 'domcontentloaded',
		});
		await page.waitForTimeout(2500); // let the preview iframe + panels load
		await shot(page, '05-editor-fullscreen.png');

		// Prove the chrome is truly absent (not hidden): report element presence.
		const chrome = await page.evaluate(() => ({
			adminmenu: !!document.querySelector('#adminmenu'),
			wpadminbar: !!document.querySelector('#wpadminbar'),
			wpwrap: !!document.querySelector('#wpwrap'),
			s5editor: !!document.querySelector('.s5-editor'),
		}));
		process.stdout.write(`  chrome-check: ${JSON.stringify(chrome)}\n`);
	} else {
		process.stdout.write('  (S5_EDIT_POST not set — skipped fullscreen editor shot)\n');
	}

	process.stdout.write('done\n');
} finally {
	await ctx.close();
}
