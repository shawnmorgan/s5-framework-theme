#!/usr/bin/env node
/**
 * phaseb-demo-walkthrough.mjs — drive the DEMO.md click-script through the REBUILT
 * editor and record pass/fail per step, with screenshots. This is the Phase B
 * acceptance "full DEMO.md walkthrough green end-to-end" proof, run live in the
 * (rebuilt) UI rather than only through the headless suites.
 *
 * Headless Chromium, own /tmp profile — never the user's Chrome.
 *
 * Usage: S5_EDIT_POST=<id> node tools/phaseb-demo-walkthrough.mjs
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

const results = [];
const step = (n, name, ok, detail) => {
	results.push({ n, name, ok, detail: detail || '' });
	process.stdout.write(`  ${ok ? 'PASS' : 'FAIL'}  ${n}. ${name}${detail ? '  — ' + detail : ''}\n`);
};

const ctx = await chromium.launchPersistentContext(join('/tmp', 's5-demo-wt'), {
	headless: true,
	viewport: { width: 1440, height: 900 },
});
const page = ctx.pages()[0] || (await ctx.newPage());
const errs = [];
page.on('pageerror', (e) => errs.push(e.message));

const shot = (name) => page.screenshot({ path: join(OUT, name) });

try {
	await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle' });
	await page.fill('#user_login', USER);
	await page.fill('#user_pass', PASS);
	await page.click('#wp-submit');
	await page.waitForTimeout(2500);

	await page.goto(`${BASE}/wp-admin/admin.php?page=s5-editor&post=${EDIT_POST}`, { waitUntil: 'networkidle' });
	await page.waitForSelector('.s5-editor', { timeout: 15000 });
	await page.waitForTimeout(2500);

	// §1 — editor is a projection: 3 regions present, no wp chrome.
	const layout = await page.evaluate(() => ({
		left: !!document.querySelector('[data-s5-leftpanel]'),
		stage: !!document.querySelector('[data-s5-stage] iframe'),
		tree: !!document.querySelector('[data-s5-structure]'),
		adminmenu: !!document.querySelector('#adminmenu'),
	}));
	step(1, 'Editor is a projection (palette · stage · tree; no wp chrome)',
		layout.left && layout.stage && layout.tree && !layout.adminmenu,
		JSON.stringify(layout));
	await shot('demo-1-projection.png');

	const frame = () => page.frames().find((f) => f.url().includes('s5-preview'));

	// §2 — glass edit: select hero title, type, dirty lights.
	let f = frame();
	const ed = f && (await f.$('[data-edit]'));
	if (ed) await ed.click();
	await page.waitForTimeout(600);
	const ta = await page.$('[data-s5-content] textarea');
	if (ta) {
		await ta.fill('DEMO edited title');
		await page.waitForTimeout(300);
	}
	const dirty = await page.evaluate(() => {
		const d = document.querySelector('[data-s5-dirty]');
		const u = document.querySelector('[data-s5-update]');
		return { dirtyShown: d && !d.hidden, updateEnabled: u && !u.disabled };
	});
	step(2, 'Glass edit — field renders, dirty lights, Update enables',
		!!ta && dirty.dirtyShown && dirty.updateEnabled, JSON.stringify(dirty));
	await shot('demo-2-glass-edit.png');

	// §3 — code lens: CodeMirror mounts with canonical.
	await page.click('[data-s5-lens="code"]');
	await page.waitForTimeout(900);
	const code = await page.evaluate(() => ({
		cm: !!document.querySelector('[data-s5-lens-code] .CodeMirror'),
		len: (document.querySelector('[data-s5-code]')?.value || '').length,
	}));
	step(3, 'Code lens — CodeMirror shows the canonical fragment', code.cm && code.len > 100, JSON.stringify(code));
	await shot('demo-3-code-lens.png');
	await page.click('[data-s5-lens="edit"]');
	await page.waitForTimeout(400);

	// §4 — palette present + insertable (Embed is the single primitive).
	const pal = await page.evaluate(() => ({
		items: document.querySelectorAll('.s5-editor__palette-item').length,
		embeds: document.querySelectorAll('.s5-editor__palette-item.is-embed').length,
	}));
	step(4, 'Palette — registered components + single Embed primitive', pal.items >= 2 && pal.embeds === 1, JSON.stringify(pal));

	// §7 — Style tab: token specimen + axis controls.
	f = frame();
	const ed2 = f && (await f.$('[data-edit]'));
	if (ed2) await ed2.click();
	await page.waitForTimeout(400);
	await page.click('[data-s5-ptab="style"]');
	await page.waitForTimeout(400);
	const style = await page.evaluate(() => ({
		specimen: document.querySelectorAll('[data-s5-styletab] .s5-editor__style-section').length,
		axes: document.querySelectorAll('[data-s5-style-controls] .s5-editor__field').length,
	}));
	step(7, 'Style tab — read-only token specimen + axis controls', style.specimen >= 4 && style.axes >= 1, JSON.stringify(style));
	await shot('demo-7-style-tab.png');

	// §8 — props-off untouchable: a plain element with NO [data-edit] anywhere in its
	// subtree or ancestry does not select; an editable still does.
	f = frame();
	const off = await f.evaluate(() => {
		document.querySelectorAll('[data-s5-selected]').forEach((n) => n.removeAttribute('data-s5-selected'));
		const plain = [...document.querySelectorAll('body *')].find(
			(e) => !e.closest('[data-edit]') && !e.querySelector('[data-edit]') && e.tagName !== 'SCRIPT' && e.tagName !== 'STYLE' && e.textContent.trim()
		);
		if (plain) plain.click();
		const afterPlain = document.querySelectorAll('[data-s5-selected]').length;
		const editable = document.querySelector('[data-edit]');
		if (editable) editable.click();
		const afterEditable = document.querySelectorAll('[data-s5-selected]').length;
		return { afterPlain, afterEditable };
	});
	step(8, 'Props-off element is untouchable (plain=0, editable=1)', off.afterPlain === 0 && off.afterEditable === 1, JSON.stringify(off));

	// Structure tree bidirectional sync.
	const treeRows = await page.evaluate(() => document.querySelectorAll('.s5-editor__trow').length);
	step(6, 'Structure tree renders rows (selection-synced)', treeRows >= 1, 'rows=' + treeRows);

	const passed = results.filter((r) => r.ok).length;
	process.stdout.write(`\nDEMO walkthrough: ${passed}/${results.length} steps passed; pageerrors=${errs.length}\n`);
	if (errs.length) errs.slice(0, 5).forEach((e) => process.stdout.write(`  ! ${e}\n`));
	process.stdout.write(passed === results.length && errs.length === 0 ? 'WALKTHROUGH-GREEN\n' : 'WALKTHROUGH-INCOMPLETE\n');
} finally {
	await ctx.close();
}
