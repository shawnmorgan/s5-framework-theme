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

## Current status — Build Order **Step 1** complete (serve canonical HTML)

> **Exit condition:** the repository can be installed and tested repeatably from
> a clean WordPress environment.

What exists now, and nothing more:

| Path | Purpose |
|---|---|
| `s5-theme/` | Shell theme. `style.css` (WP header), `functions.php` (enqueue stub), `index.php`/`header.php`/`footer.php` (minimal fallback templates). |
| `s5-plugin/` | Companion plugin — the shippable unit. Owns its `composer.json` / `vendor/` (PSR-4 autoload), the `wp s5` CLI namespace with one smoke command (`wp s5 hello`), plus `phpcs.xml` and `phpunit.xml.dist`. |
| `s5-plugin/src/Storage.php` | Registers the `_s5_canonical_html` page meta (the canonical body-fragment). A page is a *framework page* when that meta is non-empty. |
| `s5-plugin/src/Render.php` | `template_include` render path: routes framework pages to the canvas template, bypassing theme + Gutenberg; turns off the block editor for them. |
| `s5-plugin/templates/canvas.php` | Runtime template — emits the stored fragment **verbatim** in a minimal `<html>` shell (`wp_head`/`wp_footer` stay outside the fragment). Never runs `the_content`. |
| `s5.config.json` | Machine-readable layout: paths, namespaces, `s5` prefix / text-domain. |
| `.wp-env.json` | Docker dev environment (WordPress 6.7, PHP 8.2) mounting the theme + plugin. |
| `tests/acceptance.sh` | **The Step 0 acceptance test** (bash). |
| `tests/acceptance-step1.sh` | **The Step 1 acceptance test** — stores a known fragment, `curl`s the page, asserts byte-clean output. |
| `tests/mu-plugins/` | Test-only mu-plugin (`s5-debug-guard.php`) mounted into WordPress so notices surface to the acceptance test. |
| `s5-plugin/tests/phpunit/` | PHPUnit bootstrap scaffold, reserved for Step 4 compiler units. |
| `s5-plugin/composer.json` / `s5-plugin/phpcs.xml` | PSR-4 autoload + WordPress Coding Standards lint (live inside the plugin so `vendor/` mounts into the container). |

Not built yet (later steps — see the Build Order): importer (`wp s5 import`),
compiler, canonical/render split, media resolution, editor, components, MCP.

## Requirements

- **Docker Desktop** (running) — wp-env needs it.
- **Node.js** ≥ 18 and npm.
- **Composer** — installs the plugin's autoloader and dev tooling.

## Setup and the test loop

```bash
npm install                          # wp-env
composer install -d s5-plugin        # plugin autoloader + phpcs/phpunit (builds s5-plugin/vendor/)
npm run env:start                    # boot WordPress in Docker
npm test                             # run the Step 0 acceptance test
```

> **macOS note:** Docker Desktop cannot bind-mount paths under TCC-protected
> folders (`~/Desktop`, `~/Documents`, `~/Downloads`). If the repo lives there,
> either grant Docker **Full Disk Access** (System Settings → Privacy & Security)
> or keep the working copy under a plain path such as `~/Projects`.

`npm test` runs `tests/acceptance.sh`, which:

1. starts wp-env (idempotent);
2. activates `s5-plugin`;
3. activates `s5-theme`;
4. requests the site root and asserts **no PHP errors/notices** in `debug.log`;
5. runs `wp s5 hello` and asserts it prints `s5 ok` and exits `0`.

Any failure exits nonzero with a clear message.

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
```

## Naming

`s5` is a **placeholder** identity (PRD Open Question #1: the product name is
unresolved). When a name lands, change `s5.config.json` and find/replace the
`s5` / `S5` prefixes, text-domain, and namespaces.
