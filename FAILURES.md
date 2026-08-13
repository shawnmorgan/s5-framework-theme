# FAILURES — the installable zip has never worked as a theme

**Date:** 2026-08-13
**Artifact under test:** `dist/s5-theme.zip` (the committed release, 156K, built 09:30).
**How reproduced:** Extracted the zip and installed it as a theme at a **non-mounted**
path (`wp-content/themes/s5-dist`) in the running wp-env, so **none** of the dev
mounts (`s5-repo`, `s5-pages`, `mu-plugins`) leak into it. This is byte-identical to
what a real site receives from `wp theme install <zip>`. No composer, no node, no repo
on the target.

Every prior "acceptance" ran with `.wp-env.json` mounting the **entire repo** at
`wp-content/s5-repo` (and the corpus at `wp-content/s5-pages`). `repo_root()` even
hard-codes `WP_CONTENT_DIR/s5-repo/s5-theme/site` as a candidate. **That mount is the
only reason it ever worked.** Strip it and the theme is broken.

**Decision confirmed with owner (2026-08-13):** distribution stays **ONE theme** (D14).
"child theme / site" throughout the spec means the fenced `site/` subtree inside
`s5-theme`, not a second theme.

---

## F1 — The compiled CSS bundle is not in the zip (root cause of "unstyled everything")

- `site/dist/s5.css` is **gitignored** (`.gitignore: dist/`) and **absent** from the zip.
  `unzip -l dist/s5-theme.zip | grep dist/s5.css` → nothing.
- At runtime `StyleBundle::url()` returns `''` when the file is missing, and
  `functions.php` `s5_theme_enqueue_assets()` then **returns silently** — no stylesheet,
  no notice.
- **Reproduced:** requesting the served framework page `/about/` returns HTTP 200 with
  only `wp-block-library-css` in `<head>`. No `s5.css`, no `s5-bundle`. The canonical
  HTML renders with **browser-default typography** (Times New Roman) — exactly the
  screenshot.
- Direct asset probe: `GET /wp-content/themes/s5-dist/site/dist/s5.css` → **HTTP 404**.

**Severity: critical.** Nothing on the site is styled by the design system.

## F2 — No top-level "S5" admin menu; editor entry is buried

- Programmatic check after activation: **NO top-level S5 menu** in `$GLOBALS['menu']`.
- What exists today: `Editor` registers a *hidden* admin page (empty-parent
  `add_submenu_page`) plus a "New S5 Page" submenu under **Pages** only. There is no
  discoverable S5 surface — no Editor, Style, Components, or Setup/Status entry.
- Matches the screenshots: no S5 anywhere in the admin sidebar.

**Severity: high.** The product is invisible in the admin.

## F3 — "Add Page" drops into Gutenberg

- `use_block_editor_for_post_type('page')` → **true**. New/blank pages open the block
  editor (screenshot 1: "Type / to choose a block").
- `Render::disable_block_editor()` only fires for pages that are **already** framework
  pages (`_s5_canonical_html` present). A brand-new page is not a framework page yet, so
  nothing routes the user into S5. Chicken-and-egg: the only non-Gutenberg path is the
  buried "New S5 Page" submenu (see F2), which the user can't find.

**Severity: high.** The default authoring flow bypasses the framework entirely.

## F4 — Non-framework content renders through a bare, unstyled template

- Existing `post_content` pages/posts (e.g. the seeded "About Us") survive activation
  (content intact — activation is non-destructive, good), but render through the
  theme's **`index.php`**, which is `get_header()` + `the_content()` + `get_footer()`
  with **zero design-system CSS**. Served `/about-us/` head carries only WP core
  stylesheets (`wp-block-library`, `global-styles`, `classic-theme-styles`) — no S5
  base typography.
- Result: any non-adopted page is **browser-default styled**, not "on-brand fallback."

**Severity: high.** A real site full of existing content looks broken on day one.

## F5 — First-activation seeding is not reliable on a real host

- On **CLI activation** in the clean install, `after_switch_theme` DID fire and seed 9
  framework pages (seeding reads `site/pages/` via `get_stylesheet_directory()`, which
  resolves without any mount — that part is sound).
- BUT the owner's real staging site showed **no seeded pages** and Gutenberg. Likely
  causes on a real host: `after_switch_theme` fires in a request context where the
  `current_user_can('switch_themes')` guard or option-write timing differs, or the
  theme was uploaded/activated before `vendor/autoload.php` was present. Seeding must
  not be the *only* path to content, and its success/failure must be **surfaced**, not
  silent.

**Severity: medium.** Seeding is a convenience, not the contract; the contract is that
the theme is never a dead end.

## F6 — Everything fails silently

- Missing bundle → silent (F1). No child/site defaults → silent. No content → an empty
  Gutenberg screen. Nothing tells the user what is missing or how to fix it.
- The spec's law: **nothing bails silently.** Missing built CSS / config / site defaults
  must raise a **prominent admin notice** naming exactly what's missing and the single
  action that fixes it, and activation without a usable site subtree must land on a
  **Setup screen**, never a dead theme.

**Severity: high (systemic).**

---

## What was NOT broken (verified, so we don't "fix" it)

- **Autoloader boots from the zip.** `vendor/autoload.php` is bundled; `Plugin::boot()`
  runs; the `wp s5` CLI registers. No white screen.
- **`repo_root()` resolves config without a mount** via `get_stylesheet_directory()/site`
  (candidate #1). `s5.config.json` is found. The failure is the missing *built* artifact
  (F1), not config resolution.
- **Editor admin assets load** (`assets/editor/editor.{css,js}` → HTTP 200). No 404s
  there.
- **Activation is non-destructive.** Existing pages, posts, and content survive.

---

## Fix plan (mapped to phases)

- **F1** → Phase 2: `build:zip` compiles `site/dist/s5.css` into the zip; Phase 3: if the
  bundle is missing/stale at runtime, rebuild on demand + loud notice (never serve
  unstyled).
- **F2** → Phase 3: top-level **S5** menu → Editor · Style · Components · Setup/Status.
- **F3** → Phase 3: "Add S5 Page" + framework pages open S5 by default; "Edit with S5"
  row action + admin-bar link.
- **F4** → Phase 3: styled **fallback template** with design-system base typography for
  non-framework content.
- **F5** → Phase 3: adoption path (per-page + bulk "Adopt into S5"), Setup screen reports
  N existing pages; seeding failure surfaced.
- **F6** → Phase 2/3: admin notices name what's missing + the one fix; Setup screen on
  activation without a usable site subtree.
- **All** → Phase 4: a standing **distribution** acceptance suite that installs the zip
  with **zero mounts** and asserts styled serving, both scenarios.
