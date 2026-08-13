#!/usr/bin/env node
/**
 * build-zip.mjs — produce a self-contained, uploadable S5 theme zip.
 *
 * The zip is what a real site receives from "Appearance → Add Theme → Upload". It
 * must boot and render STYLED with zero assumptions about the target: no composer,
 * no node, no repo mount. That means the zip must contain, pre-built:
 *
 *   - the production Composer autoloader + vendor/ (dev deps excluded);
 *   - all admin/editor assets, templates, and the product-owned fallback CSS;
 *   - the shipped site/ subtree (design system, components, parts, pages);
 *   - the COMPILED design-system bundle site/dist/s5.css  ← the piece the old zip
 *     was missing, which left every install unstyled.
 *
 * It excludes dev-only cruft (tests, phpcs/phpunit config, composer.json/lock, the
 * node build tooling itself) so the artifact stays lean.
 *
 * Usage:  node tools/build-zip.mjs   (or: npm run build:zip)
 *
 * No external npm dependencies — uses only Node built-ins + the system `zip` and
 * `composer` binaries (composer is optional; if a production vendor/ is already
 * present it is reused).
 */

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, cpSync, readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const THEME_SRC = join(ROOT, 's5-theme');
const DIST_DIR = join(ROOT, 'dist');
const STAGE = join(DIST_DIR, '.stage');
const STAGE_THEME = join(STAGE, 's5-theme');
const ZIP_OUT = join(DIST_DIR, 's5-theme.zip');

// Paths (relative to the staged theme) that must NOT ship in the release.
const EXCLUDE = [
	'tests',
	'phpcs.xml',
	'phpunit.xml.dist',
	'composer.json',
	'composer.lock',
	'.gitignore',
	'node_modules',
];

const log = (m) => process.stdout.write(`• ${m}\n`);
const ok = (m) => process.stdout.write(`✔ ${m}\n`);
const die = (m) => { process.stderr.write(`✘ ${m}\n`); process.exit(1); };

/* ------------------------------------------------------------------ */
/* 1. Fresh staging copy of the theme.                                */
/* ------------------------------------------------------------------ */
if (!existsSync(THEME_SRC)) die(`theme source not found at ${THEME_SRC}`);
rmSync(STAGE, { recursive: true, force: true });
mkdirSync(STAGE, { recursive: true });
log('Staging theme copy…');
cpSync(THEME_SRC, STAGE_THEME, { recursive: true, dereference: true });

/* ------------------------------------------------------------------ */
/* 2. Production vendor/ (autoloader without dev deps).               */
/* ------------------------------------------------------------------ */
function haveComposer() {
	try { execFileSync('composer', ['--version'], { stdio: 'ignore' }); return true; }
	catch { return false; }
}
const stagedVendorAutoload = join(STAGE_THEME, 'vendor', 'autoload.php');
if (haveComposer()) {
	log('composer install --no-dev (production autoloader)…');
	try {
		execFileSync(
			'composer',
			['install', '--no-dev', '--no-interaction', '--optimize-autoloader', '--quiet'],
			{ cwd: STAGE_THEME, stdio: 'inherit' }
		);
	} catch (e) {
		die(`composer install failed: ${e.message}`);
	}
} else if (existsSync(join(THEME_SRC, 'vendor', 'autoload.php'))) {
	log('composer not found — reusing existing vendor/ from the theme source.');
	// Already copied by cpSync above.
} else {
	die('composer not found and no vendor/ present. Install composer or vendor deps first.');
}
if (!existsSync(stagedVendorAutoload)) die('production vendor/autoload.php missing after install.');
ok('Production autoloader present.');

/* ------------------------------------------------------------------ */
/* 3. Compile the design-system bundle: site/dist/s5.css.             */
/*    Deterministic order (mirrors S5\StyleBundle::build):            */
/*    designTokens → vocabulary → each component's style.css (alpha). */
/* ------------------------------------------------------------------ */
function buildBundle(siteDir) {
	const configPath = join(siteDir, 's5.config.json');
	if (!existsSync(configPath)) die(`site/s5.config.json not found at ${configPath}`);
	const config = JSON.parse(readFileSync(configPath, 'utf8'));

	const styles = config.styles || {};
	const ordered = [
		...(Array.isArray(styles.designTokens) ? styles.designTokens : []),
		...(Array.isArray(styles.vocabulary) ? styles.vocabulary : []),
	];

	const parts = ['/* Compiled by S5 (build-zip). Do not edit — regenerated on recompile. */'];

	for (const rel of ordered) {
		const p = join(siteDir, rel.replace(/^\/+/, ''));
		if (existsSync(p)) {
			parts.push(`/* --- ${rel} --- */`);
			parts.push(readFileSync(p, 'utf8').replace(/\n+$/, ''));
		}
	}

	const componentsRel = (config.paths && config.paths.components) || 'components';
	const componentsDir = join(siteDir, componentsRel);
	if (existsSync(componentsDir)) {
		const names = readdirSync(componentsDir)
			.filter((n) => statSync(join(componentsDir, n)).isDirectory())
			.sort();
		for (const name of names) {
			const css = join(componentsDir, name, 'style.css');
			if (existsSync(css)) {
				parts.push(`/* --- component: ${name} --- */`);
				parts.push(readFileSync(css, 'utf8').replace(/\n+$/, ''));
			}
		}
	}

	const distRel = (config.paths && config.paths.dist) || 'dist';
	const distDir = join(siteDir, distRel);
	mkdirSync(distDir, { recursive: true });
	const outfile = join(distDir, 's5.css');
	writeFileSync(outfile, parts.join('\n') + '\n', 'utf8');
	return outfile;
}
log('Compiling design-system bundle (site/dist/s5.css)…');
const bundle = buildBundle(join(STAGE_THEME, 'site'));
const bundleBytes = statSync(bundle).size;
if (bundleBytes < 200) die(`compiled bundle looks empty (${bundleBytes} bytes).`);
ok(`Bundle compiled: ${bundleBytes} bytes.`);

/* ------------------------------------------------------------------ */
/* 4. Strip dev-only cruft.                                           */
/* ------------------------------------------------------------------ */
log('Removing dev-only files from the staged theme…');
for (const rel of EXCLUDE) {
	rmSync(join(STAGE_THEME, rel), { recursive: true, force: true });
}

/* ------------------------------------------------------------------ */
/* 5. Zip it (top-level dir must be s5-theme/).                       */
/* ------------------------------------------------------------------ */
rmSync(ZIP_OUT, { force: true });
log('Creating dist/s5-theme.zip…');
try {
	execFileSync('zip', ['-r', '-q', '-X', ZIP_OUT, 's5-theme'], { cwd: STAGE, stdio: 'inherit' });
} catch (e) {
	die(`zip failed: ${e.message}`);
}

/* ------------------------------------------------------------------ */
/* 6. Verify the artifact contains the critical files.               */
/* ------------------------------------------------------------------ */
function zipList() {
	return execFileSync('unzip', ['-Z1', ZIP_OUT], { encoding: 'utf8' }).split('\n');
}
const entries = zipList();
const must = [
	's5-theme/style.css',
	's5-theme/functions.php',
	's5-theme/index.php',
	's5-theme/vendor/autoload.php',
	's5-theme/assets/fallback.css',
	's5-theme/src/Admin.php',
	's5-theme/site/s5.config.json',
	's5-theme/site/dist/s5.css',
];
const mustNot = ['s5-theme/tests/', 's5-theme/composer.json', 's5-theme/phpcs.xml'];
let bad = false;
for (const m of must) {
	if (!entries.some((e) => e === m)) { process.stderr.write(`  missing: ${m}\n`); bad = true; }
}
for (const m of mustNot) {
	if (entries.some((e) => e.startsWith(m))) { process.stderr.write(`  should not ship: ${m}\n`); bad = true; }
}
if (bad) die('release verification failed.');

rmSync(STAGE, { recursive: true, force: true });
const size = (statSync(ZIP_OUT).size / 1024).toFixed(0);
ok(`Release ready: dist/s5-theme.zip (${size}K) — contains compiled site/dist/s5.css.`);
