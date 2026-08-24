# S5-TEMPLATES-SPEC — v0.1

**Date:** 2026-08-21 · **Owner:** Shawn Morgan · **Status:** Approved for build — **v1 launch requirement**
**Companion to:** PRD v1.5 (extends §8.4 admin IA, §12 Dynamic Content) · S5-DYNAMICS-SPEC-v1.5 (this spec triggers and scopes Dynamics v0) · Authoring Contract v0.1

---

## 0. The bar (non-negotiable)

**A WordPress theme that cannot render a post is not a theme.** The template hierarchy is the contract every theme signs with WordPress. The launch bar is: **everything Twenty Twenty-Five does natively, S5 does natively.** TT25 renders single posts, pages, archives, search, 404, and comments from WordPress's own data with no plugin. S5 must match that exact baseline.

**What is NOT required at the bar** (legitimately punted, precedented by TT25 and Ollie): ACF/custom fields, custom post types beyond core, WooCommerce, query loops beyond the main query, taxonomy queries beyond what the main query resolves. For those, the honest v1 answer is "install L&L for now, native S5 Dynamics later." This punt is documented and acceptable — TT25 punts the same things.

## 1. Two problems inside "templates"

**Problem A — template hierarchy + layout (static, native, required).** The theme answering WordPress's "what renders a single post?" with an S5 template. Each template is a **canonical-HTML layout** (parts + a designated content region). This is the static half — the same authoring as a page, plus wiring the WP template hierarchy to resolve to S5 templates. Ships regardless.

**Problem B — content binding (dynamic, native, required at baseline scope).** The content region of a single/archive template must render the post's data (title, content, image, meta) and archives must loop. This is dynamic data, and because a PHP template calling `the_content()` on nothing renders nothing, **this must be solved natively to be a theme at all.** It is therefore the **v0 of S5 Dynamics** — not the whole epic, only what the baseline requires.

## 2. Template hierarchy (Problem A)

S5 resolves the WordPress template hierarchy to S5 templates:
- `index` (blog home / fallback)
- `single` (single post)
- `page` (single page)
- `archive` (category, tag, date, author — all off the main query)
- `search` (search results — main query)
- `404`

Each S5 template:
- is a canonical-HTML layout composed of registered parts + a content region;
- is authored/edited via the existing `?template=` editor mode (same editor, never a second one);
- is registered and condition-assignable in the **Templates** settings section;
- resolves via the standard WP hierarchy so WordPress serves the right template automatically.

Template **parts** (header, footer, nav, and other shared block-theme-style regions) are static shared HTML expanded via `<s5-part>`, registerable and editable in the Templates settings section. This half largely exists; v1 completes the settings UI so a human or agent can add/name/author parts and reference them from templates.

## 3. Content binding — Dynamics v0 scope (Problem B)

The **minimum native primitives** that make WordPress's own data render. Scoped hard to the TT25 baseline; nothing beyond.

**`s5-field` — current-post core fields only.** Binds the current context post's WordPress-core fields: `title`, `content`, `excerpt`, `featured-image`, `date`, `modified`, `author`, `permalink`. "Core fields" = whatever WordPress exposes without a plugin — exactly the TT25 surface. Output escaped/inert per Dynamics spec §7. **No** ACF/MetaBox/Pods adapters in v0 (that is the deferred adapter tier).

**`s5-loop` — the main query.** Walks the posts WordPress already handed the template (the main query), renders each with the loop body. Reverse-chron, **paginated**. Covers index, all archive variants, and search — because WP resolves *which* posts before the template runs, the loop code is identical regardless of archive type. `<s5-empty>` renders the no-results state (required for search/empty archives).

**Field registry.** The post's renderable pieces are registered as bindable fields — this registration IS the seed of the full Dynamics system. v0 registers core post fields; later tiers extend the same registry with ACF/meta/Woo adapters. The thing required to render a post at all is the thing Dynamics grows from.

**Execution:** rides the existing dynamic-pass seam (render → dynamic pass → shortcode pass → serve). Templates with binding are flagged dynamic; static pages stay byte-clean. Per S5-DYNAMICS-SPEC-v1.5 execution model.

## 4. Comments (baseline surface)

TT25 renders comments on posts (list + form). Baseline-required unless explicitly cut. Recommended v1: render the WordPress comment list and comment form in the single template's content region via a native comments part/primitive (WordPress core `comments_template()` output wrapped in an S5 part). **Scope decision for the owner:** in for v1 (matches TT25 bar) or explicitly punted (a defensible cut, but a documented gap vs the bar).

## 5. What v0 explicitly excludes (→ L&L now, native Dynamics later)

- Custom post type templates beyond `post`/`page` (unless owner scopes CPT in — see open decision).
- ACF / MetaBox / Pods / arbitrary post-meta fields.
- Query loops beyond the main query (custom `WP_Query`, related posts, meta queries).
- Taxonomy queries beyond main-query archives.
- Conditionals beyond what rendering a loop/empty-state needs.
- Any expression language (never, per Dynamics §0).

These are the reserved-expansion tiers of S5-DYNAMICS-SPEC-v1.5 §11. The commercial gate holds: no external revenue on non-native dynamics — v1 templates ship to Shawn's dogfood fleet on native-baseline + L&L-for-extras; the commercial dynamic capability arrives with the fuller Dynamics build.

## 6. Open scope decisions (owner)

1. **Archive breadth:** confirm v1 covers the full main-query archive set (index + category + tag + date + author + search) — it is the same loop code regardless, so this is "match TT25," not extra scope. *Recommended: yes, all main-query archives.*
2. **CPT at launch:** posts + pages only, or arbitrary registered CPT templates day one? Posts-only is the minimal baseline; CPT support generalizes the template registry. *Owner decides.*
3. **Comments:** in (matches bar) or punted (documented gap)? *Recommended: in.*
4. **Post meta / custom fields:** core-only in v1 (small), or does the baseline need any meta binding (reopens the adapter question)? *Recommended: core-only; meta is the deferred adapter tier.*

## 7. v1 template scope (locked to the bar, pending §6 answers)

Template hierarchy resolution (single/page/archive/index/search/404) → native canonical-HTML templates + registered parts + `s5-field` (current-post core fields) + `s5-loop` (main query, paginated, with `s5-empty`) + the core-field registry they bind against + comments (recommended). Native, dependency-free, exactly the TT25 capability, structurally the v0 of S5 Dynamics.
