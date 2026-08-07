# PRD — Agent-Native WordPress Framework (Working Title: TBD)

**Version:** 1.1 · **Date:** 2026-08-07 · **Owner:** Shawn Morgan · **Status:** Approved for build
**v1.1 changes:** Tailwind dropped for semantic CSS system (S5T Client-First); admin IA finalized (Templates/Components/Partials/Style); generated style guide; controls manifest + t-shirt editor controls; tag/size decoupling.
**Customer zero:** shawnmorgan.co · **Next:** alexmcfarland, collisionwithjay.com, S5T marketing pages

---

## 1. Thesis

Page builders are abstraction layers built for a pre-AI era, when humans needed to be spared from writing HTML. The author has changed: agents write production HTML natively. The abstraction is now a tax, not a value.

This product removes the abstraction. **Agents write the site. WordPress runs it. Clients touch only what was marked touchable.**

- Storage format = the document itself. HTML is authored, stored, rendered, and edited as HTML. No element tree, no block markup, no compile-on-read, no translation layer that can silently diverge.
- Compile on write, never on read. All expensive/validating work (Tailwind build, validation, indexing, media rewriting) happens at import/save. Request time serves flat HTML with two string passes.
- The client's vocabulary is components. Structural freedom at section/component level; content editing via marked slots; component internals governed by props.

## 2. Target & Non-Goals

**For:** marketing sites, brochure sites, church/nonprofit sites, personal brands, content-led SMB sites — the class of site currently routed to Astro or held reluctantly in WP.

**Not for:** WooCommerce stores, membership sites, heavy-feature web apps, multilingual sites. These remain on Bricks/Woo (through EOY 2027 exception) or React/Xano/Supabase.

**Explicit non-goals (v1):** visual query builder, two-way git sync, per-breakpoint hide, keyword/readability scoring, sitemap/redirect/index-control modules (SEO-plugin territory), fleet dashboard, hosting bundling (Wharfe organs — never in this product), wp.org distribution.

## 3. Architecture

### 3.1 Deliverables
1. **Shell theme** — runtime + design system container.
2. **Companion plugin** — storage, render pipeline, compiler/importer, editor, MCP surface, git mirror.

### 3.2 Shell theme
- Owns `<head>`, global chrome assembly, token layer, stylesheet enqueue, L&L load.
- PHP is runtime, never content. PHP serves fragments, resolves part conditions, processes L&L + shortcodes, enqueues assets. No page layout ever lives in a PHP template. Global chrome (header/footer/nav) = **parts** (see 3.7), not hardcoded PHP.
- Admin IA mirrors the block-theme Site Editor metaphor (2025/2026 visual language): **Templates / Components / Partials / Style**. Custom admin app — the actual Site Editor is never used (it edits block markup; we have none). Templates = assignable renderers (single, archive, single product in v2, CPT singles) with visible display conditions. Partials = header/footer/nav/shared sections, condition-assignable. Components = library grid → Croco-style editor screen. Style = the living style sheet.
- **Style tab = Tokens panel (editable/paste-in) + Scales panel (slot × value grid) + generated style guide.** Compiler renders the style guide from tokens + scales + design-system classes on every build (every heading level, text style, button variant, spacing scale, rendered in the real CSS). It is simultaneously the human reference and the agent constraint document — one artifact, cannot drift from the CSS it documents. Pages stay in the normal WP pages list and open the on-glass editor.

### 3.3 Storage & render
- Page fragment (body HTML) stored in postmeta. Pages are real WP posts/pages → SEO plugins, permalinks, CPTs all work natively.
- wpautop/content filters disabled for canvas template. Gutenberg bypassed for framework pages.
- **Render pipeline (request time):** fetch fragment → strip `data-hidden` nodes (tag processor) → L&L pass (`<tangible>` tags) → `do_shortcode()` → serve. Full-page cacheable.
- **post_content mirror:** on every save/import, write stripped-text render (excluding hidden nodes) into `post_content`. Makes WP search and SEO-plugin content analysis work. Never served.
- **Revisions:** fragment meta keys registered via `wp_post_revision_meta_keys` (WP 6.4+). Client saves ride native WP revisions → self-serve restore. Git = deep history + DR.

### 3.4 Compiler / importer (write-time; the load-bearing component)
Runs on `wp s5 import <dir>` and on every editor save. Jobs:
1. Assign stable node IDs where missing.
2. **Validate loudly, reject bad input:** malformed HTML, unknown classes (Tailwind classes not in build emit nothing — must be flagged, never silently accepted), token-discipline violations, `data-repeat`-style layout fragility (containers must tolerate N children — auto-flow grid / flex-wrap), locked-region violations.
3. **Class validation against the semantic system (no CSS build step):** every class used must exist in the shipped stylesheet and obey naming rules (underscore = component/custom class, dash-only = utility — class type is machine-parseable). Closed utility set: agents never invent utilities. Layout (grid/flex/gap/columns) lives in component classes, never utilities. No inline styles. Token discipline (ADU): all font-size/spacing through `--text-*`/`--space-*` scales; arbitrary px/hex = import failure. Missing-class report; stub generation is explicit opt-in to a review file, never silent, never to the live stylesheet.
4. **Sidecar index (derived, regenerable, never authored):** node map, slot inventory, component instances + versions, insertion targets, animation-asset needs per page. Cache for the editor; HTML remains canonical.
5. **Media pass:** sideload relative-path assets into media library; rewrite `<img>` to attachment-backed markup with srcset/sizes, explicit width/height, `loading="lazy"` below fold, `fetchpriority="high"` on LCP. Resolve assets by file hash/path per environment (sideload if absent, match if present) — never by baked attachment ID (Local/staging/prod portability).
6. **SEO gate** (see §6.1).

### 3.5 Editor
**Model: panel-based (Sanity-style), not contenteditable.** Page renders as read-only live preview in iframe; panel writes into it.

**Core law — declaration-driven editing:** the editor is a schema renderer with no innate opinions. It renders exactly what was declared, nothing else. Content editability is **opt-in** via declarations: `data-edit` attributes on page-level nodes, manifest props on components (no prop connected = no field exists — hero image undeclared means no hero image editing, absent not grayed), controls-manifest axes per component. Background images (CSS-side content) are their own declared slot type (`data-edit="bg-img"` / prop); save pipeline owns the rewrite. Structure is the inverse: **opt-out** — insert/move/duplicate/hide default-on at section level, frozen only by `data-lock`. Props decide what changes; locks decide what moves. Docs consequence: one authoring contract (declaration vocabulary), no editor feature manual.

**Layout:**
- **Top bar:** back-to-WP (left) · responsive controls = iframe viewport resize, real Tailwind breakpoints (center) · code/edit toggle, capability-gated (center-right) · undo/redo + dirty-state + **Update** (right).
- **Left panel:** component palette from manifests (thumbnail, name, drag/click-insert; targets from sidecar). L&L snippet library (post loop, conditional, CPT field, form shortcode) — pre-built blocks inserted as visible tags, params edited in right panel. Snippet library, NOT a query builder — permanent scope line. Gated Design+.
- **Right panel:** default = structure tree generated from sidecar, **filtered by editing level** (Content sees components/editables only; full DOM only for Developer). Click element (tree or glass) → edit panel: content field (plain / minimal rich: bold-italic-link), tag dropdown (constrained h1–h6/p, second-H1 warning, capability-flagged, Content-accessible per site config), **t-shirt controls**, classes + data attributes (visible to all; **free editing at Design+** with manifest validation on class edits), hide toggle, per-node handles (duplicate / move / hide / delete).
- **T-shirt controls (controls manifest = theme.json analog):** every marketer knob is a class swap within the closed utility set — never a free value. Global axes: heading/text size steps (XXL→XS via `heading-style-*`/text-size utilities), space-above/space-below steps, button/component variant, alignment. Editor renders segmented pickers; selection swaps the sanctioned class on the node. Per-component manifests declare which axes each component exposes (a locked block exposes none). Hard cap on axes — no color pickers, no free padding fields, ever. Data attributes for Content-level users exist only as component-declared props (e.g., analytics-ID field); free attribute editing stays Design+.
- **Selection syncs both directions** (glass ↔ tree). **One dirty-state model** across all panels → single Update = one save = one WP revision = one git commit. Live typing updates preview DOM client-side; nothing persists until Update.

**Structural model:**
- Clients insert, move, duplicate, delete freely at **section / component-instance granularity**, anywhere. Drop zones from sidecar node map.
- **Inside components, structure belongs to the component:** internals flex via props and **hide** — never free deletion. Duplicate copies content ("make another like this").
- **Hide** (`data-hidden="true"`): first-class everywhere. Inside components it is the only structural verb (every component child hideable by default). Sections: hide (common case) + delete (confirm-gated, recoverable). Tree shows hidden nodes ghosted with eye icon. Global on/off only — responsive/conditional visibility is L&L territory.
- **Hidden = stripped at render.** Stored fragment keeps hidden nodes (lossless un-hide); render pipeline removes them from served HTML entirely (SEO-clean; better than any builder's display:none). Editor preview ghosts them. post_content mirror excludes them. Gate flags hiding the only H1 / LCP image.
- **`data-lock`:** freedom by default; locks are the deliberate exception (legal blocks, frozen regions).

### 3.6 Components
- Component = HTML partial file in theme: `{{prop}}` tokens + `data-edit` slots + `data-component` + `data-v` stamp. Manifest per component: label/token/type/default/required/description per prop, thumbnail, variants, schema template (§6.3).
- **Copy-on-insert, stamped.** Instance = frozen expanded HTML in the page. Styling changes propagate free via classes/tokens (shell stylesheet). Markup changes = explicit **migration pass** (WP-CLI): find stamped instances, extract slot content + variant, re-expand new template, swap node. Deterministic, diffable. (Migration CLI ships v1.1 — needed at first component update, not launch.)
- Conditionals in templates (`{% if badge %}`) resolve at **write time** (prop toggle → re-expand instance). No runtime template language in components.
- **Component editor screen (Croco-pattern):** live preview / HTML template with props / scoped CSS (per-component file keyed off root class, concatenated at build) / manifest panel. v1 = functional-plain; polish is v1.1.
- **Ships with:** `embed` component (single prop: shortcode string — the client-lens insertion path for forms/newsletter/any shortcode plugin; agents write shortcodes directly in fragments), FAQ, TL;DR/key-takeaways, stat/definition blocks (AEO answer-shaped units), breadcrumbs part.

### 3.7 Partials & templates
- Header/footer/nav/sections-shared = **partials**: HTML fragments with **display conditions** (post type, taxonomy, role, URL pattern — copy the JetThemeCore/block-theme condition model, don't invent).
- **Templates** (single, archive, 404, search) = fragments rendering native content via L&L (`<Field name=content>`, loops).
- **Content boundary (hard rule):** posts and CPT entries are authored natively (Gutenberg/classic — client's comfort zone, zero training). Framework owns pages, templates, parts — presentation, never post authoring.

### 3.8 Dynamic & legacy
- **L&L (Tangible Loops & Logic)** = entire dynamic layer: loops, conditionals, CPT/ACF/MetaBox fields, archive context. Free, active, proven-in-production embedded in raw HTML (LiveCanvas precedent).
- **Shortcode pass** after L&L = legacy plugin compatibility (forms, everything).

### 3.9 Stack defaults
- **Semantic CSS system — canonical spec is the Authoring Contract v0.1, which imports BEM + Token Spec v0.9 (Bricks chat, ruled 2026-08-05) verbatim.** Grammar: blocks = dashes, elements = single underscore, modifiers = double dash, utilities = `u-` prefix (<15, closed), generated scale classes (`.text-{step}`, spacing steps) emitted by compiler. Ladder: `xxs–xxxl`, no numeric steps. Tokens carry values, classes carry decisions; parents own layout. **Shared artifact with the Bricks component-library CSS** — one design system, two runtimes. Agents may draft in Tailwind (v0 output); v0-to-semantic converts; only the semantic system lands in the repo.
- **Three-layer style architecture:**
  1. **Tokens (free-form):** color palette + type families/weights. Brand-specific, paste-in or agent-written. `tokens.css`.
  2. **Scales (locked slots, editable values):** spacing, sizing, type size, radius — slots hard-baked by the theme (XXS→XXL; radius 4-slot). Nobody adds slots, ever; brands set values. Default value pattern = fluid `clamp()`. Type split: family/weight = token, size = scale. `scales.css`.
  3. **Stylesheet:** `utilities.css` = **generated by the compiler from the scales** (read-only; hand-editing = violation) + `components/*.css` = the only hand-authored CSS beyond tokens/scale values. Layout lives in component classes, never utilities.
  Consequence: controls manifest is invariant across all sites (t-shirt pickers always map to the same slots with per-brand values); compiler validation trivial (utility derives from a slot or doesn't exist); agents cannot invent scale steps.
- **Tag/size decoupling:** `heading-style-*` visual classes independent of heading tag. Tag = semantics/SEO outline; size = visual t-shirt control.
- **Motion (motion.dev) default; GSAP escape hatch** (free incl. plugins) for complex choreography. Sidecar records per-page animation needs → JS enqueued only where used. Zero-JS pages stay zero-JS.
- **Animation/AEO convention (agent playbook, gate-enforced):** content fully visible in initial CSS; hide-states only under `html.js` scope or animate-from-visible. Never `opacity:0` initial on content.

### 3.10 Scripts panel
Settings screen: head / body-open / footer fields, global + per-page override. Three core hooks. Admin-gated. Stored outside fragments (survives re-import, never in git as page content). Show script weight per entry — the perf tradeoff is visible, not silent.

### 3.11 Editing levels (parallel capability system)
- Namespaced capability (`s5_edit_level`), assignable per-role AND per-user (user wins). Never touches WP admin caps.
- **Content:** slot values, component props, media, insert/move/duplicate/hide/delete at section level, embed component. Meta panel (§6.2).
- **Design:** + tag/class/attribute editing, parts, L&L snippets, script panels.
- **Developer:** + code lens, import, migrations, raw fragments.
- Client-owns-install scenario: client user pinned to Content even with WP admin. Guardrail posture (prevents accidents, not sabotage) — the honest sales line.

### 3.12 Git mirror
- **WP canonical after handoff.** Continuous one-way export on save: fragments (HTML — human-readable backup by definition), stylesheet, components, parts. One commit per Update.
- Repo-driven control = `wp s5 import` pointed at repo: deliberate whole-state operation. Both directions exist; only one is live. No merge engine (two-way sync explicitly out of scope).

### 3.13 MCP (ships with the theme)
- **Primary loop stays local-first:** agent writes files on disk → `wp s5 import`. The file format is the interface.
- **Bundled MCP — one rule above all: a third door into the same pipeline, never a second pipeline.** Every MCP write flows through the identical compiler/validation/save path as CLI import and editor saves (same gate, same revision, same git commit). No tool may write fragments around the compiler.
- **Purpose:** remote operation (live-site edits from Claude apps, production gate runs, future customers without local dev) + **agent onboarding**: MCP resources expose the Authoring Contract, style guide, tokens/scales, and component manifests so any connecting agent learns the system before writing (the Croco skills-in-plugin move, MCP-native).
- **Implementation: WordPress Abilities API.** Register every framework operation as an ability; the official WP MCP adapter exposes them. WP-native rail, minimal owned surface, future adapters free.
- **Tool surface v1:** reads (pages/fragments, components+manifests, partials, templates+conditions, tokens/scales, style guide, validation report) · writes (apply page HTML via compiler, update slot values, insert component instance, create page, set meta) · ops (run gate, purge cache, trigger import-from-repo).
- **Auth = editing levels:** an MCP token is a user with an edit level. Content-scoped token: slots/props/meta only, no raw HTML. Developer-scoped: full surface.
- **Timing:** post-editor phase (week 4–5 of PRD roadmap) — abilities registration is thin once pipeline + capability system exist. First milestone = read + import-trigger; full write surface follows.

### 3.14 Ops
- **Cache purge hooks v1:** on-glass save → page purge; import/migration/token change → site purge. Rocket.net, Cloudflare, standard page-cache plugin integrations.
- **Fleet updater:** git-based release puller. Required before site two (v1.1).
- **QA harness:** snapshot tests (fixture fragments → expected HTML) for the render pipeline — the one component whose regression breaks every site. Built during customer-zero from real fragments.

## 4. Performance model
- Tier: as close to static as WP gets. Request = fetch string + two passes; cache hit ≈ static file (edge-cached: origin never runs). Cache misses are cheap (no tree reconstruction).
- No builder runtime ships to visitors. One compiled Tailwind stylesheet (used classes only). Motion only on animating pages. Editor JS only for logged-in editors.
- What stays dynamic (inherent, correct): logged-in/personalized responses (L&L user-state conditionals), forms, search.

## 5. Positioning line
"Agent-native building, marketer-operated editing — with the best technical SEO output in WordPress." Not an SEO plugin; not a page builder; the post-builder WordPress.

## 6. SEO / AEO

**Principle (the Hyperdrive inversion):** enforcement lives in the compiler; levers live in the marketer's panel. SEO as a property of the system, operated by the marketer — not a service embodied in a dev.

### 6.1 Compiler SEO gate (every import AND every save — covers non-agent authors)
Exactly one H1 · heading hierarchy doesn't skip · alt on content images · title + meta description present · single canonical · OG tags · internal links resolve · LCP element not hidden or lazy-loaded · animation convention (§3.9). Surfaced as plain-language marketer checklist at Update time, not dev output.

### 6.2 Meta panel (Content-level, in the edit screen)
Title, description, OG image, canonical + live Google-result/OG-card preview + Twitter cards. noindex intentionally NOT built (SEO-plugin territory).

### 6.3 Schema bound to components (differentiator)
Manifests carry JSON-LD templates populated from props: FAQ → FAQPage, pricing → Offer, testimonial → Review, article header → Article, breadcrumbs → BreadcrumbList. Marketer composes page = schema assembles itself.

### 6.4 Entity panel
Organization/LocalBusiness once in settings (name, logo, locations, hours, sameAs) → sitewide entity schema.

### 6.5 AEO
Server-rendered zero-JS-dependency content (inherent) · hidden nodes genuinely absent (§3.5) · llms.txt generator from page mirror · answer-shaped default components (§3.6) · robots policy panel: plain-language AI-crawler toggles (GPTBot, ClaudeBot, PerplexityBot) — conscious owner decision, not silent default.

### 6.6 SEO-plugin coexistence (load-bearing)
Default posture: framework ships SEO-plugin-free for the target class (meta + entity + schema + gate + breadcrumbs covers it). Sites needing sitemaps-with-lastmod / redirects / index controls / dashboards install Yoast or RankMath — normal dependency, zero shame. **When detected: our meta panel fields write through to their meta keys** (our pages bypass the post editor where their metabox lives; marketer never leaves the edit screen). Native meta storage = fallback. Schema overlap documented; ours on by default, one toggle to yield. Our head output stands down where theirs owns it.

## 7. Build plan (AI-adjusted, 4–6 weeks)

| Phase | Scope | Exit |
|---|---|---|
| **W1 — Pipeline** | Shell theme, storage+render, WP-CLI import, compiler v1 (IDs, validation, Tailwind build, mirror, SEO gate), git commit-on-save | Agent writes HTML locally → one command → live page. Already replaces Bricks authoring path |
| **W2–3 — Editor** | Panel editor, structure tree, selection sync, save pipeline, editing levels, admin app shell | Slip-risk zone (human-gated polish loops). Panel model de-risks vs contenteditable |
| **W4 — Components & surfaces** | Component editor, palette+insert, hide semantics, embed component, parts+conditions, media pass, meta/entity/scripts panels, revisions, cache purge | Full feature surface on infra from W1–3 |
| **W5–6 — Customer zero** | shawnmorgan.co built for real; QA harness from real fragments | Not buffer: closes demo→client-grade gap |

**v1.1:** migration CLI, fleet updater, component-editor polish, llms.txt if it slips.
**Scope discipline:** the only thing that turns 6 weeks into 12 is polishing the component editor to Croco's screenshot. Don't.

## 8. Success criteria (v1)
1. shawnmorgan.co authored by agent, imported, served with full-page cache, CWV green.
2. A Content-level user can: edit copy/images, insert/move/duplicate/hide components, set page meta — with zero training beyond a 10-minute walkthrough.
3. Every save = WP revision + git commit; restore works from both.
4. SEO gate passes on every shipped page; schema validates in Rich Results test.
5. Yoast absent and nothing missed for this site class.

## 9. Commercial posture (recorded, not v1 scope)
Build for internal use; document the build publicly (personal brand — "veteran builds the post-builder WordPress"). Sell decision deferred to demand evidence (~6 months). Expectation bar: $100K+ on eventual launch (LTD/early-access to warm audience + agency licensing — comps: Bricks/ACSS/Etch). Support tax acknowledged (~30% of hours post-launch) — do not schedule against Wharfe build. Wharfe relationship: shared editing-layer IP, two runtimes, routing rule = "needs WP ecosystem → theme; doesn't → Wharfe deletes the WP tax." Theme never grows fleet/hosting organs.

## 10. Open questions
1. **Product name.**
2. **Editor tech stack** — 70% of build effort; deserves its own technical design doc before W2 (React vs vanilla+iframe protocol, overlay architecture).
3. Rich-text slot scope (is bold/italic/link enough for v1 marketing sites — likely yes).
4. L&L licensing check for redistribution/bundling vs. require-install.
5. LiveCanvas autopsy findings to fold into storage/editor bootstrapping decisions (license purchased — do before W1).
