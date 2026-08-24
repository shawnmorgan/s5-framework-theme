# ALIGNMENT.md — Current build vs PRD v1.6

**Date:** 2026-08-24 · **Branch:** step12-full-editor · **Theme version:** 0.14.1
**Method:** read-only audit against PRD v1.6 + the 4 new companion specs (Atomic, Templates, Plugin-Extensibility, Dynamics). Phase 0 of `CC-PROMPT-realign-and-build-v1_6.md`. **No code changed in this audit.**

This report answers the six Phase-0 questions. Nothing here is fixed yet — this is the map before building.

---

## Summary table

| # | Question | Verdict | Where it lands |
|---|----------|---------|----------------|
| 1 | Component model carries a `kind` (html\|shortcode\|block)? | **ABSENT** | Foundational add (Phase 2) |
| 2 | Styles surface = tokens→scales→elements? | **PARTIAL** (tokens+scales; no elements tier) | v1.x (Phase 3) |
| 3 | Elements / atom registry surface exists? | **ABSENT** | v1.x/v2 |
| 4 | Template hierarchy resolves to S5 templates? | **ABSENT — biggest v1 gap** | Phase 1 (launch blocker) |
| 5 | Any settings surface hard-coded read-only (violates §2.4)? | **2 spots to review** (1 likely fine, 1 flag) | Cheap review |
| 6 | 5 prior fixes + clean zip present on this branch, suite-covered? | **FIXES PRESENT / SUITES PARTIAL** | Re-confirmed on clean install |

---

## Q1 — Component `kind` concept — **ABSENT**

The component manifest today is `{ name, version, description, slots[] }`. There is no `kind` field and no html/shortcode/block body-kind branch in `ComponentRegistry.php` / `ComponentInsert.php`. Every component is implicitly "html" (copy-on-insert stamp).

**Gap vs §14.8 / Plugin-Extensibility §7:** the foundational add is `kind: html | shortcode | block` on the manifest + a resolution-pass branch (stamp / shortcode-pass / `render_block()`). This is Phase 2 and it is the prerequisite for all plugin extensibility.

## Q2 — Styles surface structure — **PARTIAL**

The **StyleGuide** screen (`render_styleguide`) renders **tokens** (Colors) → **scales** (Type / Space / Radius) as editable cards. That is the first two of the three tiers.

**Gap vs §8.5 / Atomic §4:** the **element-styles tier is missing** — there is no listing of registered element atoms (heading/body/eyebrow/button/label/icon) with their bound values. The three-part `tokens → scales → element styles` hierarchy is 2/3 present.

Note: there are two Styles-ish screens today — **StyleGuide** (token specimen, editable) and **CSS / Tailwind** (the raw layer files, editable). The v1.x restructure is to make the element-styles tier explicit.

## Q3 — Elements / atom registry surface — **ABSENT**

The settings app screens are: `templates, styleguide, components, css, integrations, roles`. There is **no `elements` screen** and no atom-registry storage (`site/atoms/` or an atoms manifest). Element atoms as referenced primitives (Atomic §1) do not exist yet.

**Expected:** this is explicitly v1.x/v2 in Atomic §9 (registration + Component Builder + reference-resolution pass are v2). Not a v1-launch blocker — flagged as planned future work.

## Q4 — Template hierarchy — **ABSENT (the biggest v1 gap)**

This is the launch blocker, exactly as the CC-prompt predicted.

**What exists:** `Render.php` hooks `template_include` (`route_framework_page`, prio 99) to route **framework pages** (flat canonical-HTML pages) through the canvas render path. Standard theme files `index.php` / `404.php` / `header.php` / `footer.php` exist and render posts through **WordPress's default loop** (`have_posts()` + `the_content()`), not S5 templates.

**What's missing:** the theme does **not** resolve the WP template hierarchy (single / page / archive / search / index / 404) to S5 canonical-HTML templates. The Templates settings screen lists the hierarchy rows but every row reports **"Using default"** — and the code comment says so directly:

> `Settings.php:945` — *"GAP: this theme renders through the canvas path and ships no per-template files, so each row reports 'Using default'… Custom/CPT template registration lands with the template authoring work."*

There are **no S5 template files** (`templates/single.html`, `archive.html`, etc. are referenced in the UI but don't exist as wired renderers) and **no Dynamics engine at all** — zero `s5-field` / `s5-loop` / `s5-if` anywhere in `src/`.

**Quantified gap (per S5-TEMPLATES-SPEC + PRD §12.6):**
- Template-hierarchy resolution: single / page / archive (all main-query variants) / index / search / 404 → S5 templates. **Not built.**
- Templates settings section: register + condition-assign; author via `?template=` mode. **UI mockup only; not wired.**
- Template parts (`<s5-part>` header/footer/nav): **partially exists** (parts system is real), settings UI to add/name/author parts needs completion.
- **Dynamics v0:** `s5-field` (current-post core fields) + `s5-loop` (main query, paginated, `s5-empty`) + core-field registry. **None exists.** This is the front edge of S5-DYNAMICS-SPEC-v1.5 and the theme can't render a post without it.
- Comments: **not rendered** in any S5 template.

**Bottom line:** "a theme that cannot render a post is not a theme" — by that bar, the theme is **not yet a theme**; it renders flat framework pages only. Phase 1 is the whole job here and it's substantial (template resolver + Dynamics v0 engine + core-field registry + parts UI + comments).

## Q5 — Read-only settings surfaces (§2.4 check) — **2 spots to review**

§2.4 says settings surfaces must be human- AND agent-editable; read-only posture is page-canvas-only. Two read-only spots in `Settings.php`:

1. **Component Builder → "Component CSS" panel** (`Settings.php:1096-1098`): rendered `readonly` with "read-only · from components.css / edit in the CSS / Tailwind tab." **Likely compliant** — the CSS *is* editable, just in a different control-plane tab; this panel is a read-only *view* pointing at the editable source. **Recommend:** confirm this is a deliberate "one editing home" choice, not a lock. Low priority.
2. **General screen identity input** (`Settings.php:783`): a `readonly` input — appears to mirror a WP-core value (site name/tagline owned by WP Settings). **Likely fine** (it's WP's field, not an S5 surface). Confirm.

No blanket read-only settings surface found. Neither is a clear §2.4 violation; both are worth a one-line confirmation.

## Q6 — The 5 prior fixes + clean zip — **FIXES PRESENT; re-confirmed on a clean install**

Re-verified on a **cold install of the freshly-built 0.14.1 zip** (not a repo copy), per the handoff's "audit the artifact" rule:

| Fix | Present in 0.14.1 zip | Suite in `npm test`? |
|-----|----------------------|----------------------|
| XSS on* / `javascript:` rejection | ✅ (`Validator.php`; live canary → **422** on install) | ⚠️ planted-violation fixtures exist for disable/classswap; **on*/javascript: fixture coverage should be confirmed wired into the default gate** |
| Roles CAPABILITY = `edit_posts` (not `manage_options`) | ✅ both `Editor.php` / `Settings.php` | ✅ `acceptance-roles.sh` + `roles-login.spec.mjs` (3 real users) |
| Responsive `@media`/`clamp()` | ✅ (17 in scales.css, in the zip) | ⚠️ viewport matrix exists (`test:viewports`); confirm it runs in default `npm test` |
| Dirty-state beforeunload | ✅ (`editor.js:264`) | ⚠️ no dedicated beforeunload assertion found — likely a gap |
| Bare `wp s5 import` CWD-independence | ✅ (`ImportCommand.php` uses `self::default_dir()`) | ⚠️ confirm a bare-import test in the gate |

**The "divergence scare" is fully explained:** there was **never a git divergence** — `d4c85d8` (= tag `v0.9.2-preflight`) is a direct linear ancestor of HEAD; all 11 commits since are linear, no revert. The fixes were always in HEAD. The false "fixes missing" audit came from a **stale copy-install** caused by the **`.DS_Store` in the 0.14.0 zip breaking `wp theme install`** — now fixed (0.14.1, this session): the zip is cruft-free, installs via CLI **and** the wp-admin uploader routine, and the onclick canary returns 422 on the fresh install.

**Suite-coverage caveat (this is the real Q6 finding):** the *fixes* are present, but per the Amin handoff's rule ("a branch must not be able to drop a fix and still show green"), several guarding suites need confirmation that they **run in the default `npm test` gate** — specifically the on*/javascript: planted-violation fixtures, the viewport matrix, a beforeunload assertion, and a bare-import test. If any of these isn't in the default gate, that's the gap that let the *provenance scare* look plausible. **Recommend a Phase-0.5 "wire every guarding suite into `npm test`" task** before Phase 1 feature work.

---

## Recommended sequencing (for your review — nothing built yet)

1. **Phase 0.5 (small, do first):** confirm/wire the 4 unconfirmed guarding suites into default `npm test` (on*/js-url fixtures, viewport matrix, beforeunload, bare-import). Closes the Q6 gap that made the provenance scare possible. Cheap, high-leverage.
2. **Phase 1 (the launch blocker, large):** template-hierarchy resolution + Dynamics v0 (`s5-field` core + `s5-loop` + core-field registry + `s5-empty`) + Templates settings wiring + parts UI + comments. This is the bulk of remaining v1 work.
3. **Phase 2:** component `kind` schema + Tiers 1–2 (Embed output-preview, one shortcode-kind plugin component).
4. **Phase 3 (if budget):** three-part Styles tab (add the element-styles tier).

**Stopping here for your review before Phase 1, per the CC-prompt.**
