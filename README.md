# S5 — Agent-Native WordPress Framework

Pages are authored directly as **HTML** by an AI agent or developer. WordPress
compiles, validates, stores, and serves that HTML. Humans edit only the
capabilities the author explicitly exposes. There is **no page-builder
abstraction** between source and output.

## Source of truth — read these first

1. [`Agent-Native-WordPress-One-Sheet-v1.md`](./Agent-Native-WordPress-One-Sheet-v1.md) — thesis and positioning.
2. [`PRD-Agent-Native-WordPress-Framework-v1_4.md`](./PRD-Agent-Native-WordPress-Framework-v1_4.md) — approved architecture.
3. [`BUILD-ORDER-Agent-Native-WP-v1.md`](./BUILD-ORDER-Agent-Native-WP-v1.md) — step-by-step execution sheet.

The Build Order is strict: **do not begin a step until the current step's
acceptance test passes.** Build the smallest thing that proves the invariant;
defer everything deferrable.

## Current status — Build Order **Step 6** complete (media resolution)

> **Exit condition (Step 6):** media no longer forces environment-specific data
> into canonical HTML. Canonical references assets by **portable relative path**
> (`assets/hero.png`), resolved against the asset dir declared in
> `s5.config.json` — canonical never contains attachment IDs, upload URLs, or
> srcset. On compile, the **media-resolution transform** (sanctioned transform #2)
> sideloads each `<img>`/`<source>` asset into the media library — matching by
> **content hash** (`_s5_source_hash`) so re-imports dedupe and a changed file
> re-sideloads — and the **derived render** gets that environment's attachment
> URL, srcset/sizes, explicit width/height, and lazy/LCP loading (author-set
> attributes win; the first content image is not lazy). External `http(s)`/`data:`
> URLs are untouched everywhere. An unresolvable path is a **loud compile error**
> naming the file + path. The round-trip harness maps resolved media markup back
> to its logical source before diffing, so everything non-media stays
> byte-for-byte.

> **Exit condition (Step 5):** source is portable truth; render output is
> disposable environment state. `_s5_canonical_html` stays the authored,
> revisioned, portable source. Compile turns it into `_s5_render_html` (the
> derived artifact the canvas now **serves**) via an auditable ordered transform
> pipeline plus a visible **`post_content` semantic mirror**
> (canonical body minus `<script>`/`<style>`/inline-`<svg>`/`data-*`) for WP
> search + SEO introspection — never served. Canonical, render, and mirror
> persist **atomically**. `wp s5 recompile` regenerates the derived artifacts
> deterministically; deleting them and recompiling restores them from canonical
> alone. Canonical source is revisioned, and **restoring a revision recompiles
> automatically** — the served page reverts with no manual step.

Steps 0–5 stay green; Step 6 resolves media at compile time. What exists now:

| Path | Purpose |
|---|---|
| `s5-theme/` | Shell theme. `style.css` (WP header), `functions.php` (enqueue stub), `index.php`/`header.php`/`footer.php` (minimal fallback templates). |
| `s5-plugin/` | Companion plugin — the shippable unit. Owns its `composer.json` / `vendor/` (PSR-4 autoload), the `wp s5` CLI namespace with one smoke command (`wp s5 hello`), plus `phpcs.xml` and `phpunit.xml.dist`. |
| `s5-plugin/src/Storage.php` | Registers the page meta: `_s5_canonical_html` (canonical source — revisioned), `_s5_head`, and `_s5_render_html` (derived, served, **not** revisioned). A page is a *framework page* when canonical is non-empty. |
| `s5-plugin/src/Compiler.php` | Compile: canonical → `_s5_render_html` via an **auditable ordered transform pipeline** (identity today; media/hidden-node seats reserved) + the visible `post_content` semantic mirror. `persist_page()` writes canonical + render + mirror **atomically** (rolls back if the mirror can't be stored verbatim). |
| `s5-plugin/src/Revisions.php` | Registers `_s5_canonical_html` + `_s5_head` for native revisions and **recompiles derived artifacts on revision restore** (served page reverts with no manual recompile). |
| `s5-plugin/cli/RecompileCommand.php` | `wp s5 recompile [<slug>\|--all]` — regenerates derived render + mirror from canonical. Deterministic (byte-identical). |
| `s5-plugin/src/MediaResolver.php` | Media-resolution compile transform (Step 6). Resolves canonical relative `<img>`/`<source>` refs against the declared asset dir, **sideloads/dedupes by content hash** (`_s5_source_hash`), and rewrites the **derived render** with attachment URL + srcset/sizes + width/height + lazy/LCP. Author attrs win; external URLs untouched; unresolvable path = loud compile error. Byte-fidelity preserved (targeted string replacement, never DOM re-serialization). |
| `assets/` | Local **portable** asset dir declared in `s5.config.json` (`assets.dir`). Canonical HTML references these by relative path (`assets/hero.png`); the compiler resolves them per environment. |
| `s5-plugin/src/Render.php` | `template_include` render path: routes framework pages to the canvas template, bypassing theme + Gutenberg; turns off the block editor for them. |
| `s5-plugin/templates/canvas.php` | Runtime template — emits the **derived `_s5_render_html`** (fallback: canonical) **verbatim** in a minimal `<html>` shell (`wp_head`/`wp_footer` stay outside the fragment). Never runs `the_content`; never serves the `post_content` mirror. |
| `s5-plugin/src/HtmlDocument.php` | Full-document parser: splits a source HTML file into the verbatim `<body>` fragment and the sanctioned `<head>` artifacts (title, description, canonical, OG/meta, JSON-LD). |
| `s5-plugin/cli/ImportCommand.php` | `wp s5 import [<dir>]` — turns a directory of HTML docs into pages (slug = filename, `index.html` = front page), idempotent, never duplicates. Step 4: **gates every page through validation** before write (errors block that page; import continues; exits nonzero at end). |
| `s5-plugin/src/Stylesheet.php` | Parses the **closed class vocabulary** (union of class selectors) from the theme stylesheet(s) declared in `s5.config.json` `styles.vocabulary`. No hardcoded lists. |
| `s5-plugin/src/Grammar.php` | Authoring Contract A4/A3 grammar: classifies a class into its layer (block/element/modifier/utility/scale) and flags off-ladder / numeric scale steps. |
| `s5-plugin/src/Validator.php` | Validates a page's HTML: unknown class / inline `style=` / unparseable → ERROR; defined-but-nonconforming / numeric scale → WARNING. Emits agent-readable findings (file·line·rule·token·permitted·repair·did-you-mean). |
| `s5-plugin/cli/ValidateCommand.php` | `wp s5 validate [<dir>] [--format=json]` — standalone report; nonzero on any ERROR. |
| `s5-theme/styles/` | The design-system stylesheets (`components.css`, `utilities.css`) whose class selectors **are** the vocabulary. |
| `Authoring-Contract-v0.1.md` | Part A CSS system (token/scale/name grammar) the validator enforces. |
| `pages/` | The source corpus. Full HTML documents imported into pages — including `series-5-technology.html`, the real production-bound S5T page with genuine markup complexity. Also the QA corpus the fidelity harness round-trips. |
| `s5.config.json` | Machine-readable layout: paths, namespaces, `s5` prefix / text-domain, and `styles.vocabulary` (the declared stylesheets that define the class vocabulary). |
| `.wp-env.json` | Docker dev environment (WordPress 6.7, PHP 8.2) mounting the theme + plugin, and mapping `pages/` → `wp-content/s5-pages` and the repo root → `wp-content/s5-repo` (so the validator can read `s5.config.json` + the theme styles in-container). |
| `tests/acceptance.sh` | **The Step 0 acceptance test** (bash). |
| `tests/acceptance-step1.sh` | **The Step 1 acceptance test** — stores a known fragment, `curl`s the page, asserts byte-clean output. |
| `tests/acceptance-step2.sh` | **The Step 2 acceptance test** — imports the corpus and checks idempotence, slugs, front page, verbatim body + head. |
| `tests/acceptance-step3.sh` | **The Step 3 round-trip fidelity harness** (`npm run test:roundtrip`) — import → HTTP → normalize sanctioned transforms → diff; self-verifying via an armed render mutation. Ship Gate check #1. |
| `tests/acceptance-step4.sh` | **The Step 4 acceptance test** — real corpus clean; planted unknown class → ERROR + did-you-mean; inline style outside/inside `<svg>`; numeric scale → WARNING proceeds; `--format=json` fields; round-trip still green. |
| `tests/acceptance-step5.sh` | **The Step 5 acceptance test** — derived + mirror exist and are served from `_s5_render_html`; recompile deterministic; mirror is semantic (no script/style/data-\*) and WP-searchable; revision restore reverts the served page automatically; delete-then-recompile proves disposability; negative self-test still red/green. |
| `tests/acceptance-step6.sh` | **The Step 6 acceptance test** — real images import with srcset + dimensions and the harness stays green; unchanged re-import dedupes by hash; a changed source image re-sideloads; canonical stays portable while render re-derives per environment; an unresolvable path fails loudly; recompile stays deterministic with media active. |
| `tests/fixtures/README.md` | The QA-corpus contract: what the harness guarantees, and how to add a page. |
| `tests/lib/extract-head.php` | Host-side helper the harness uses to read source head artifacts (raw bytes) for the head-contract assertions. |
| `tests/mu-plugins/` | Test-only mu-plugins mounted into WordPress: `s5-debug-guard.php` (surfaces notices) and `s5-roundtrip-mutator.php` (the armed render-path mutation for the negative test; inert unless the `s5_roundtrip_mutate` option is set). |
| `s5-plugin/tests/phpunit/` | PHPUnit bootstrap scaffold, reserved for Step 4 compiler units. |
| `s5-plugin/composer.json` / `s5-plugin/phpcs.xml` | PSR-4 autoload + WordPress Coding Standards lint (live inside the plugin so `vendor/` mounts into the container). |

Not built yet (later steps — see the Build Order): the real production corpus
(Step 7), templates/parts/dynamics (Step 8), SEO/AEO (Step 9), editor,
components, MCP.

## Requirements

- **Docker Desktop** (running) — wp-env needs it.
- **Node.js** ≥ 18 and npm.
- **Composer** — installs the plugin's autoloader and dev tooling.

## Setup and the test loop

```bash
npm install                          # wp-env
composer install -d s5-plugin        # plugin autoloader + phpcs/phpunit (builds s5-plugin/vendor/)
npm run env:start                    # boot WordPress in Docker
npm test                             # run Steps 0,1,2 + the round-trip harness
```

> **macOS note:** Docker Desktop cannot bind-mount paths under TCC-protected
> folders (`~/Desktop`, `~/Documents`, `~/Downloads`). If the repo lives there,
> either grant Docker **Full Disk Access** (System Settings → Privacy & Security)
> or keep the working copy under a plain path such as `~/Projects`.

`npm test` runs the acceptance suite in order — **Steps 0, 1, 2, then the
round-trip fidelity harness** — and is green only if every step passes. Any
failure exits nonzero with a clear message.

### The round-trip fidelity harness (`npm run test:roundtrip`)

This is the standing Ship Gate check. For every page in `pages/` it:

1. imports the corpus (`wp s5 import`);
2. requests each page over HTTP;
3. normalizes **only the sanctioned transforms** — enumerated as an auditable
   list in `tests/acceptance-step3.sh` (head assembly + the canvas body wrapper +
   media resolution mapped back to logical paths; dynamics join in Step 8);
4. diffs the served **body byte-for-byte** against source, and asserts the
   **head contract** (title/description once & matching, canonical matching,
   each OG tag present, each JSON-LD block verbatim);
5. **self-verifies**: it arms a render-path mutation, requires the harness to go
   red with a delta report, then disarms it and requires green again.

It is usable against any `pages/` corpus, so it grows with the site.

### Definition of done (Step 0)

`npm run env:start && npm test` passes from a clean clone, **twice in a row**
(the second run proves idempotence).

## Other commands

```bash
npm run env:stop            # stop the environment
npm run env:destroy         # tear it down completely
npm run lint                # WordPress Coding Standards (phpcs) via the env
npm run test:phpunit        # PHPUnit units (placeholder until Step 4)
npm run test:step1          # Step 1 acceptance (canonical HTML served byte-clean)
npm run test:step2          # Step 2 acceptance (wp s5 import → pages)
npm run test:roundtrip      # Step 3 round-trip fidelity harness (Ship Gate #1)
npm run test:step4          # Step 4 acceptance (Authoring Contract validation)
npm run test:step5          # Step 5 acceptance (canonical/derived split + revisions)
npm run test:step6          # Step 6 acceptance (media resolution)
npm run validate            # wp s5 validate the real corpus (Authoring Contract)
npm run recompile           # wp s5 recompile --all (regenerate derived render + mirror)
```

## Naming

`s5` is a **placeholder** identity (PRD Open Question #1: the product name is
unresolved). When a name lands, change `s5.config.json` and find/replace the
`s5` / `S5` prefixes, text-domain, and namespaces.
