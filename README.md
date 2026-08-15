# S5 — Agent-Native WordPress Framework

Pages are authored directly as **HTML** by an AI agent or developer. WordPress
compiles, validates, stores, and serves that HTML. Humans edit only the
capabilities the author explicitly exposes, through the built-in S5 canvas
editor. There is **no page-builder abstraction** between source and output,
and **no companion plugin** — S5 ships as a single WordPress theme.

## Source of truth — read these first

1. [`Agent-Native-WordPress-One-Sheet-v1.md`](./Agent-Native-WordPress-One-Sheet-v1.md) — thesis and positioning.
2. [`PRD-Agent-Native-WordPress-Framework-v1_5.md`](./PRD-Agent-Native-WordPress-Framework-v1_5.md) — approved architecture.
3. [`BUILD-ORDER-Agent-Native-WP-v1.md`](./BUILD-ORDER-Agent-Native-WP-v1.md) — step-by-step execution sheet.
4. [`DECISIONS.md`](./DECISIONS.md) — every judgment call made along the way, with the why.
5. [`DEMO.md`](./DEMO.md) — a live click-script for the editor, and the real-site install path.

## Current status

Build Order Steps 0–12 are complete, plus template parts, SEO/AEO ownership,
frontend interactive islands, the component registry, and the Editor MVP
(canvas + Code lens + Style/Advanced tabs). Distribution as a real installable
theme zip is complete and gate-tested. Roles & Access (Client/Marketer/
Developer capability tiers) is live: every entrance — the editor, the
Theme Settings screens, and the REST save door — resolves through
`S5\Roles::can()` (per-user override → WP-role seed → matrix), not raw WP
capabilities, so a role the matrix denies is blocked even if the underlying
WP account holds `manage_options`.

What exists now:

| Path | Purpose |
|---|---|
| `s5-theme/` | The theme — the entire shippable product. No companion plugin. |
| `s5-theme/style.css` | WP theme header (name, version, description) — the only file WordPress requires to recognize S5 as a theme. |
| `s5-theme/functions.php` | Bootstraps the framework: autoloads `src/`, registers the `wp s5` CLI namespace from `cli/`, wires every class's `register()` hooks. |
| `s5-theme/site/` | The **fenced site subtree** (D14/D15) — everything a real install authors: `s5.config.json`, `styles/` (design tokens + vocabulary), `components/`, `parts/`, `assets/`, `pages/`, and the compiled `dist/`. Product updates never touch this directory; `wp s5 init` scaffolds it on a fresh install and never overwrites an existing file. |
| `s5-theme/src/` | The framework's PHP classes (autoloaded, no Composer package needed at runtime): `Storage`, `Compiler`, `Validator`, `Grammar`, `Stylesheet`, `Render`, `Rest`, `Revisions`, `Editor`, `Settings`, `Admin`, `Roles`, `Templates`, `PartExpander`, `MediaResolver`, `ComponentRegistry`, `Islands`, `SeoGate`, `Schema`, `Redirects`, `Integrations`, and more — one class per concern, see each file's own doc comment for its contract. |
| `s5-theme/cli/` | The `wp s5` CLI commands: `InitCommand` (`wp s5 init` — scaffold `site/`), `ImportCommand` (`wp s5 import [<dir>]`), `ValidateCommand` (`wp s5 validate [<dir>]`), `RecompileCommand` (`wp s5 recompile [<slug>\|--all]`), `ComponentCommand`, `HelloCommand` (`wp s5 hello`, the smoke test). |
| `s5-theme/templates/` | The editor's own admin-screen templates (`editor.php`, `editor-drawers.php`) and the runtime canvas template that serves a framework page's derived render. |
| `s5-theme/assets/editor/` | The editor's vanilla JS (no build step): shell, panel, palette, structure tree, Code lens, Style/Advanced tabs, the selection overlay injected into the live preview. |
| `pages/` | The source corpus — HTML documents `wp s5 import` turns into pages. Also the QA corpus the round-trip fidelity harness diffs against. |
| `tests/` | The acceptance suite: one script per Build Order step (`acceptance-step0.sh` … `acceptance-step11.sh`), plus `acceptance-editor.sh`, `acceptance-components.sh`, `acceptance-dist.sh`, `acceptance-disable.sh`, `acceptance-classswap.sh`, `acceptance-roles.sh`, and `run-all.sh` (the full gate). |
| `tools/build-zip.mjs` | Builds the distributable `dist/s5-theme.zip` from `s5-theme/` — strips dev-only files, compiles the design-system bundle, asserts every critical asset is present before it will produce a zip. |
| `.wp-env.json` | Docker dev environment (WordPress + PHP) for the acceptance suite. Mounts the repo so the suite can read `s5.config.json` and drive `wp s5` commands in-container. **Not** how a real site runs S5 — see "Install on a real site" below. |

## Install on a real site (the uploadable zip)

The framework installs like any other WordPress theme — no CLI, no Composer,
no build tools on the target.

```bash
npm run build:zip          # produces dist/s5-theme.zip (self-contained)
```

Then, in wp-admin:

1. **Appearance → Themes → Add Theme → Upload Theme** → choose `dist/s5-theme.zip`
   → **Install** → **Activate**.
2. On activation the design system compiles and, if `site/` doesn't already
   exist, `wp s5 init` scaffolds it — a starter config, starter tokens, and the
   empty authoring directories — so the site is stylable and ready to author
   into immediately.
3. Open the top-level **S5** menu (Developer role only):
   - **Setup & Status** — environment health (framework code, design system,
     compiled CSS bundle, framework-page count) and, on a site with existing
     content, an *"N existing pages found — rendering via the styled
     fallback until adopted"* list with per-page and bulk **Adopt into S5**.
   - **Theme Settings** — General, Templates, StyleGuide, Components,
     CSS/Tailwind, Integrations, and Roles & Access, each gated by the
     resolved S5 role (see `S5\Roles`).
4. **Existing content is safe.** Menus, front-page settings, and existing
   pages/posts survive activation untouched. Non-framework pages render
   through a styled fallback (the design system's base typography), never
   browser-default. Adopting a page is explicit and reversible via the
   page's revisions.
5. **Edit with S5** appears as a row action on framework pages (and in the
   admin bar when viewing one); framework pages open the S5 editor, not
   Gutenberg — gated by the viewer's resolved S5 role, not just a raw WP
   capability, so a Client sees Content + Style only and a Marketer adds the
   Advanced/structure surfaces plus their permitted Theme Settings screens.

> The permanent guarantee that this all works from the zip with **no repo
> mount** is the `distribution` acceptance suite (`npm run test:dist`), which
> installs the built zip into a clean, mount-free WordPress and asserts
> styled serving for both a fresh site and a site that already had content.

## Requirements (for the dev acceptance suite)

- **Docker Desktop** (running) — `wp-env` needs it. Not required to install
  the built zip on a real site — see above.
- **Node.js** ≥ 18 and npm.

## Setup and the test loop

```bash
npm install                          # installs @wordpress/env
npm run env:start                    # boot WordPress in Docker
npm test                             # run the full acceptance gate (destroy → start → all suites)
```

> **macOS note:** Docker Desktop cannot bind-mount paths under TCC-protected
> folders (`~/Desktop`, `~/Documents`, `~/Downloads`). If the repo lives
> there, either grant Docker **Full Disk Access** (System Settings → Privacy
> & Security) or run the suite from a mirror at a plain path (e.g.
> `rsync -a --delete --exclude node_modules --exclude vendor --exclude .git
> ./ ~/s5-work/framework-theme/` and run `npm test` from there).

`npm test` runs the full acceptance suite in order and is green only if
every step passes. Any failure exits nonzero with a clear message.
`SKIP_DESTROY=1 npm test` (`npm run test:reuse`) reuses an already-running
environment instead of a clean destroy → start — faster for iterating on a
single failing suite, but not a substitute for a clean run before a release
tag.

### The round-trip fidelity harness (`npm run test:roundtrip`)

The standing Ship Gate check. For every page in the corpus it:

1. imports the corpus (`wp s5 import`);
2. requests each page over HTTP;
3. normalizes **only the sanctioned transforms** — enumerated as an auditable
   list in `tests/acceptance-step3.sh` (head assembly, the canvas body
   wrapper, media resolution reversed via the `_s5_media_map` manifest,
   template-part expansion, dynamics);
4. diffs the served **body byte-for-byte** against source, and asserts the
   **head contract** (title/description once & matching, canonical matching,
   each OG tag present, each JSON-LD block verbatim);
5. **self-verifies**: it arms a render-path mutation, requires the harness to
   go red with a delta report, then disarms it and requires green again.

## Other commands

```bash
npm run env:stop            # stop the environment
npm run env:destroy         # tear it down completely
npm run env:cli             # wp-env run cli wp <...> shorthand
npm run lint                # WordPress Coding Standards (phpcs) via the env
npm run test:phpunit        # PHPUnit units
npm run test:step0 .. test:step11   # individual Build Order step suites
npm run test:roundtrip      # Step 3 round-trip fidelity harness (Ship Gate #1)
npm run test:editor         # Editor MVP acceptance (Step 12) — the one save door, role-gated saves
npm run test:components     # Component registry + CSS bundle + copy-on-insert
npm run test:dist           # the uploadable zip works with zero repo mounts
npm run test:disable        # disabled ≠ hidden — dropped from render + mirror
npm run test:classswap      # style axis-swap, loud, no free-form classes
npm run test:roles          # Roles & Access: hidden AND blocked, server-side
npm run validate            # wp s5 validate the real corpus (Authoring Contract)
npm run recompile           # wp s5 recompile --all (regenerate derived render + mirror)
```

## Naming

`s5` is a **placeholder** identity (PRD Open Question #1: the product name is
unresolved). When a name lands, change `s5.config.json` and find/replace the
`s5` / `S5` prefixes, text-domain, and namespaces.
