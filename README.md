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

## Current status — Build Order **Step 0** (Bootstrap and lock invariants)

> **Exit condition:** the repository can be installed and tested repeatably from
> a clean WordPress environment.

What exists now, and nothing more:

| Path | Purpose |
|---|---|
| `s5-theme/` | Shell theme. `style.css` (WP header), `functions.php` (enqueue stub), `index.php` (fallback template). |
| `s5-plugin/` | Companion plugin. Bootstrap + PSR-4 autoload + the `wp s5` CLI namespace with one smoke command, `wp s5 hello`. |
| `s5.config.json` | Machine-readable layout: paths, namespaces, `s5` prefix / text-domain. |
| `.wp-env.json` | Docker dev environment (WordPress 6.7, PHP 8.2) mounting the theme + plugin. |
| `tests/acceptance.sh` | **The Step 0 acceptance test** (bash). |
| `tests/phpunit/` | PHPUnit bootstrap scaffold, reserved for Step 4 compiler units. |
| `composer.json` / `phpcs.xml` | PSR-4 autoload + WordPress Coding Standards lint. |

Not built yet (later steps — see the Build Order): storage/meta, render path,
importer (`wp s5 import`), compiler, media resolution, editor, components, MCP.

## Requirements

- **Docker Desktop** (running) — wp-env needs it.
- **Node.js** ≥ 18 and npm.
- **Composer** — installs the plugin's autoloader and dev tooling.

## Setup and the test loop

```bash
npm install                 # wp-env
composer install            # plugin autoloader + phpcs/phpunit
npm run env:start           # boot WordPress in Docker
npm test                    # run the Step 0 acceptance test
```

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
```

## Naming

`s5` is a **placeholder** identity (PRD Open Question #1: the product name is
unresolved). When a name lands, change `s5.config.json` and find/replace the
`s5` / `S5` prefixes, text-domain, and namespaces.
