# PRD — Agent-Native WordPress Framework

**Version:** 1.4  
**Date:** 2026-08-08  
**Owner:** Shawn Morgan  
**Status:** Approved architecture; ready for build  
**Supersedes:** PRD v1.3. **v1.4 restores four decisions dropped in the v1.3 revision:** admin IA + generated style guide (§8.4), per-user editing-level override (§9), llms.txt + AI-crawler robots policy (§13.1a), scripts panel spec (§6.8). Adds the S5T Ship Gate (§17.0).  
**Canonical companion spec:** Authoring Contract v0.1, which itself defers to BEM + Token Spec v0.9 where stated

---

## 0. Product Law

This framework is **not a page builder**.

Pages are authored directly as HTML by an AI agent or a developer. There is no visual authoring abstraction in which a user drags generic elements onto a canvas, configures layout primitives, or indirectly produces HTML through a proprietary element tree.

The product has two intentionally asymmetric surfaces:

1. **Authoring surface — agents and developers**  
   Authors write real HTML, CSS, component definitions, manifests, and declarations directly.

2. **Editing surface — marketers and clients**  
   Humans may edit only capabilities explicitly declared by the author. If a field, prop, or slot is not wired, it is not editable. If a component is not registered, it cannot be inserted.

The editor can **exercise author-defined capabilities but can never create new design or structural capabilities**.

There is no built-in AI chat interface. External agents connect through files, CLI, WordPress Abilities, and MCP. The framework provides the contract, tools, compiler, manifests, diagnostics, and permissions; the user chooses the agent.

---

## 1. Thesis

Page builders are abstraction layers created for a pre-agent era, when humans needed to be spared from writing HTML. That abstraction is increasingly a tax when the primary author is an AI system already fluent in HTML, CSS, JavaScript, and structured code.

The framework removes that tax.

**Agents author the site. Developers define the rules. Humans edit declared capabilities. WordPress runs it.**

The goal is to make production WordPress frontend development approach the speed of building the same marketing site in Astro, Next.js, or static HTML while preserving WordPress's CMS, plugin, user, media, SEO, revision, hosting, and ecosystem advantages.

Core rules:

- **HTML is canonical.** The page document remains the document.
- **No builder representation sits between source and rendered markup.**
- **Compile on write, never on read, except for explicitly dynamic runtime passes.**
- **The editor renders declarations, nothing else.**
- **Content editability is opt-in.** Undeclared content is immutable to Content users.
- **Structural composition is registered, not improvised.** Humans insert registered components, not generic Div/Container/Grid primitives.
- **Components are definitions for insertion, validation, and migration; expanded HTML remains the canonical page representation.**
- **One compiler, multiple doors.** CLI, editor, and MCP/Abilities must flow through the same validation and save pipeline.

---

## 2. Target Users and Non-Goals

### 2.1 Primary users

**Initial developer / agency user:**
- Uses Claude Code, Codex, Cursor, or similar agents for frontend development.
- Can already produce polished Astro/Next/static marketing sites quickly.
- Wants WordPress for CMS, ecosystem, plugins, users, media, SEO integrations, hosting familiarity, and client ownership.
- Does not want to translate agent output into Bricks, Elementor, Divi, Gutenberg, Oxygen, Etch, or another builder representation.

**Secondary marketer / client user:**
- Needs to edit copy, images, links, metadata, and approved component instances.
- Should not be asked to understand arbitrary layout, CSS, component internals, or HTML structure.
- Receives only the capabilities the author intentionally exposes.

### 2.2 Site class

**For:** marketing sites, brochure sites, church/nonprofit sites, personal brands, content-led SMB sites, landing-page-heavy sites, and similar builds currently suitable for Astro/static delivery but desired on WordPress.

**Not for core v1:** WooCommerce stores, membership sites, heavy-feature applications, multilingual platforms, visual application builders, or generalized low-code development. WooCommerce becomes an explicit v1.5 milestone after the core runtime/editor architecture is proven.

### 2.3 Explicit non-goals

- No generic drag-and-drop visual page authoring.
- No Div / Container / Grid / Flex / arbitrary layout construction for marketers.
- No free color, spacing, breakpoint, typography, or arbitrary CSS controls at Content level.
- No built-in AI chat sidebar.
- No custom AI model hosting.
- No visual query builder.
- No two-way Git merge engine.
- No attempt to replace WordPress's plugin ecosystem.
- No attempt to recreate every SEO, redirect, form, ecommerce, membership, cache, or analytics feature inside the framework.
- No wp.org-distribution requirement for v1.

---

## 3. System Architecture

### 3.1 Deliverables

1. **Shell theme** — runtime, design-system container, frontend delivery.
2. **Companion plugin** — canonical storage, compiler/importer, derived render artifacts, editor, component registry, Abilities/MCP surface, Git export.
3. **Authoring Contract** — the machine-readable/human-readable law agents must follow.
4. **Component manifests** — the declared vocabulary humans may insert or edit.

### 3.2 Canonical source vs derived artifacts

The framework maintains a hard distinction between portable source and environment-specific render output.

#### Canonical source HTML

The authoritative page document.

Properties:
- Human-readable.
- Agent-authored or developer-authored.
- Portable across local/staging/production.
- Revisioned.
- Exported to Git.
- Contains declarations such as `data-edit`, `data-component`, `data-v`, `data-hidden`, `data-lock`, and stable node identifiers.
- Uses logical/portable media references rather than environment-specific attachment IDs.

#### Derived render HTML

Generated by the compiler from canonical source on import/save.

May contain:
- resolved media markup;
- environment-specific attachment URLs/srcset/dimensions;
- hidden-node removal;
- deterministic component write-time expansions;
- sanitized/normalized render transformations.

Properties:
- Regenerable.
- Never canonical.
- Never exported to Git as source.
- Recompiled after source revision restore.

#### `post_content` mirror

A derived **visible semantic HTML mirror** for WordPress/search/plugin introspection.

It should preserve meaningful structure such as headings, paragraphs, lists, links, and images while removing framework-only editing metadata where appropriate.

It is never the served page source.

### 3.3 Runtime pipeline

Request-time rendering should remain deliberately small:

`fetch compiled render HTML → dynamic pass (L&L if used) → shortcode pass → serve/cache`

Hidden-node stripping, media normalization, validation, structural compilation, and other deterministic transformations happen at write time.

### 3.4 WordPress ownership boundaries

WordPress continues to provide:
- users and roles;
- posts/pages/CPTs;
- permalinks;
- media library;
- revisions;
- plugin ecosystem;
- REST and Abilities APIs;
- SEO-plugin compatibility;
- forms/shortcodes/plugins;
- cache/hosting integrations.

The framework replaces the **frontend authoring abstraction**, not WordPress itself.

---

## 4. Compiler / Importer

The compiler is the load-bearing component and the real center of the product.

It runs on:
- CLI import;
- editor Update;
- MCP/Abilities write operations;
- component migration;
- source revision restore where recompilation is needed.

### 4.1 Core jobs

1. Parse and validate source HTML.
2. Assign stable node IDs where missing.
3. Validate class vocabulary and naming rules against the Authoring Contract.
4. Reject raw values or inline author styles outside explicit compiler-owned exceptions.
5. Validate parent-owned layout rules and closed utility vocabulary.
6. Validate declarations and prop vocabulary.
7. Validate component stamps and versions.
8. Resolve write-time component conditionals.
9. Compile hidden-node behavior into render output.
10. Resolve media references into environment-specific attachment-backed markup.
11. Build the visible semantic `post_content` mirror.
12. Generate/regenerate sidecar indexes for editor navigation and manifests.
13. Run SEO/accessibility/performance gates defined for the current version.
14. Persist canonical source and derived artifacts atomically.
15. Trigger native revision behavior and Git export as appropriate.
16. Trigger cache purge hooks where configured.

### 4.2 Diagnostics

Validation must be agent-friendly, not merely strict.

Errors should explain:
- what violated the contract;
- where it occurred;
- the permitted vocabulary;
- the likely correction;
- relevant component/manifest context where available.

Unknown classes, invalid scale steps, illegal utilities, layout ownership violations, undeclared props, malformed component stamps, and forbidden inline values fail loudly.

The compiler should make repair cheap enough that strictness improves velocity rather than slowing it.

---

## 5. CSS / Design System

The canonical CSS law is the Authoring Contract v0.1 and imported BEM + Token Spec v0.9.

Core rules:

- Tokens carry values. Classes carry decisions.
- Closed token and class vocabularies where enumerated.
- Blocks use dash naming.
- Elements use single underscore.
- Modifiers use double dash.
- Structural micro-utilities use the closed `u-` layer.
- Generated scales use named steps (`xxs` where allowed through `xxxl`), never numeric aliases.
- Layout lives on parent/component classes.
- Typography/appearance classes do not own arbitrary placement.
- Fluid scales are default; breakpoints are for structural reflow, not size stepping.
- `generated.css` is compiler-owned and read-only.
- Component CSS is the principal open-ended authored CSS layer.

### 5.1 Inline style rule

Author-authored arbitrary `style=` attributes are forbidden.

A narrowly defined compiler-owned exception may exist for content values that cannot reasonably be represented by a static class vocabulary, such as an editable background image exposed as a media prop. The allowed mechanism must be enumerated and lintable, for example a compiler-owned CSS custom property consumed by component CSS.

No general inline-style escape hatch exists.

---

## 6. Human Editing Model

### 6.1 Core law

**The editor renders declarations, nothing else.**

The editing surface is a projection over authored HTML, not the authoring system that created the page.

If an author does not wire a slot or prop, the human cannot edit it.

If an author does not register a component, the human cannot insert it.

The absence of a field is intentional, not a missing feature.

### 6.2 Slot vocabulary

The current closed slot types are:
- `text`
- `rich`
- `img`
- `bg-img`
- `link`
- `embed`

The declaration vocabulary remains closed until real corpus usage proves a missing primitive.

### 6.3 Additional component prop types

- `select`
- `toggle`
- `attr`

Manifests define the allowed props, labels, defaults, requirements, descriptions, axes, and insertion behavior.

### 6.4 Content-level embed security

Content-level embed fields may invoke only an explicitly allowlisted set of shortcodes/components for that site.

Arbitrary shortcode source remains a Developer-level capability.

### 6.5 T-shirt axes

The global human-facing axes remain intentionally small:
- size;
- space;
- variant;
- align.

No color pickers. No free padding. No arbitrary breakpoints. No generic CSS editing at Content level.

### 6.6 Structural model

Humans may operate at approved section/component-instance granularity where not locked:
- insert registered component;
- move;
- duplicate;
- hide;
- delete where permitted.

Inside components, structure belongs to the component definition. Component internals change via declared props and hide semantics, not arbitrary deletion/restructuring.

`data-lock` freezes structural actions where required.

### 6.7 Anti-builder guardrail

The editor must never evolve into generalized visual layout authoring.

There is no Content-level:
- Add Div;
- Add Container;
- arbitrary Grid/Flex configuration;
- free class invention;
- free CSS;
- arbitrary spacing values;
- arbitrary color values;
- arbitrary breakpoint styling.

If a new composition capability is required, the author or agent creates/registers a component or extends the contract deliberately.

### 6.8 Scripts panel (restored — Design+ capability)

Settings surface with three injection fields — head, body-open, footer — implemented via the three core hooks, with **global values plus per-page override** (a landing-page pixel must not ship site-wide).

- Admin/Design-gated; raw script injection is never a Content capability.
- Stored outside page fragments: survives re-import, never appears in Git as page content.
- Each entry displays its script weight so the performance tradeoff is visible, not silent.

---

## 7. Components

A component is a reusable authored definition consisting of:
- HTML partial/template;
- component CSS;
- manifest;
- optional write-time logic/conditionals;
- version stamp;
- optional schema template;
- preview metadata/thumbnail.

### 7.1 Copy-on-insert

Components are **copy-on-insert**.

When inserted, the component expands into real page HTML stamped with:
- `data-component="{name}"`
- `data-v="{version}"`

The expanded HTML becomes part of the canonical page document.

The component definition is used for:
- insertion;
- validation;
- prop interpretation;
- previews;
- migrations.

It is **not** a hidden canonical element-tree representation.

### 7.2 Migrations

Markup changes to registered components are applied through explicit deterministic migration passes:
- find stamped instances;
- extract declared content/prop state;
- re-expand against the new version;
- diff;
- validate;
- replace;
- revision/export normally.

### 7.3 Component Studio

The future Component Studio may borrow strong ideas from CrocoBuilder-like workbenches:
- live preview;
- HTML template view;
- component CSS view;
- manifest/props view;
- optional logic view;
- version/migration information.

This is a **component-authoring workbench**, not a generic page builder.

---

## 8. Editing Surface

### 8.1 Technical direction

Use a React-based application shell for the editing surface.

Do not adopt Gutenberg/block-editor architecture as the page representation.

The page preview runs in an iframe, but the iframe DOM is not the authoritative state store.

Authoritative editor state is the canonical source representation plus declared operations.

Live edits may patch the preview for responsiveness, but Update always sends source/state through the compiler and replaces persisted/derived state from compiler output.

Undo/redo operates on source transactions, not arbitrary DOM snapshots.

### 8.2 Layout

Potential v1 editor layout:

- Top bar: back to WP, responsive preview widths, code/edit toggle where capability allows, undo/redo, dirty state, Update.
- Left panel: registered component palette and approved snippets.
- Right panel: structure tree filtered by editing level plus declared content/prop controls.
- Preview: read-only visual page with selection sync.

### 8.3 Code / editor toggle

Developer-capable users may toggle between:
- code/source-oriented view;
- projected visual editing surface.

The visual surface never becomes the canonical page authoring abstraction.

### 8.4 Admin IA and the Style surface (restored)

Site-level admin app uses the block-theme Site Editor *metaphor* (2025/2026 visual language) without the Site Editor itself: **Templates / Components / Partials / Style**.

- **Templates:** assignable renderers with display conditions visible in the list.
- **Components:** registry grid → Component Studio (§7.3).
- **Partials:** header/footer/nav/shared sections, condition-assignable.
- **Style:** Tokens panel (free-form: color, font families/weights; paste-in or agent-written) + Scales panel (locked slots × per-brand values grid) + the **generated style guide**.

**The generated style guide is load-bearing, not decoration.** The compiler renders it from tokens + scales + design-system classes on every build — every heading level, text style, button variant, spacing step, in the real CSS. It is simultaneously the human reference and the agent constraint document: one artifact that cannot drift from the stylesheet it documents. Pages remain in the normal WP pages list and open the editing surface.

---

## 9. Editing Levels / Trust Boundaries

Use a namespaced capability model independent of standard WP admin capability assumptions.

**Assignment (restored):** levels are assignable **per-role AND per-user, with the per-user setting winning**. This covers the client-owns-install scenario: a client may hold full WP admin (it is their site) while their user is pinned to Content. This is a guardrail, not a lock — anyone with real admin can change their own level — and that is the correct posture: it prevents accidents and scope creep, not sabotage, and preserves "full ownership plus a safe editing surface" as the honest client story.

### Content

May:
- edit declared slot values;
- edit declared component props;
- choose media for declared fields;
- insert registered components;
- move/duplicate/hide/delete at approved granularity;
- edit allowed page meta;
- use allowlisted embed types.

May not:
- edit arbitrary HTML;
- invent classes;
- add arbitrary attributes;
- invoke arbitrary shortcodes;
- author new layout primitives;
- edit arbitrary CSS.

### Design

Adds constrained control over:
- tag/class/attribute editing where allowed;
- parts/partials;
- approved dynamic snippets;
- scripts/settings surfaces.

All writes still pass compiler validation.

### Developer

Adds:
- raw canonical source;
- component authoring;
- imports;
- migrations;
- unrestricted framework operations within WordPress permissions.

### MCP / external agents

An MCP token is backed by a WordPress user and inherits the effective editing level/capabilities of that user.

No MCP write bypasses the compiler.

---

## 10. CLI, Abilities, and MCP

### 10.1 No built-in chat

The framework intentionally does not ship its own AI chat interface.

Users may work with Claude Code, Codex, Cursor, future agents, or direct human code authoring.

### 10.2 One pipeline, three doors

All mutation surfaces ultimately call the same application/compiler services:

1. CLI / repository import.
2. Human editing surface.
3. WordPress Abilities exposed to external agents through MCP.

No door may create a second save pipeline.

### 10.3 Abilities-first operation model

Where practical, framework operations should be modeled as typed abilities/services such as:
- read authoring contract;
- read design system;
- list/read components and manifests;
- read page source;
- validate source;
- compile page;
- apply page source;
- update declared slot;
- insert component;
- set page meta;
- run quality gate;
- trigger repository import;
- purge cache.

The same underlying service layer may also be called directly by CLI/editor code where appropriate; the invariant is shared validation and persistence behavior.

---

## 11. Git / Source Control

### 11.1 Source of truth modes

Before handoff, repository-driven workflows may treat Git/source files as operational source of truth and deploy with CLI import.

After handoff, WordPress may become canonical for approved editor changes with continuous one-way export.

No automatic two-way merge engine is required.

### 11.2 Export rule

Git exports **canonical portable source only**:
- canonical page HTML;
- tokens/scales;
- component definitions;
- component manifests;
- parts/templates;
- other authored framework source.

Environment-specific derived render HTML, attachment IDs, compiled media URLs, caches, and sidecar indexes are not authoritative Git source.

---

## 12. Dynamic Content, State, and Runtime Behavior

### 12.1 v1 dynamic layer — Tangible Loops & Logic

For v1 and v1.5, Tangible Loops & Logic (L&L) remains the preferred generic dynamic layer for loops, conditions, CPT/field rendering, archive context, and related server-side dynamic behavior where plain HTML cannot express the requirement.

This is a deliberate time-to-market dependency, not the permanent language boundary of the product. L&L prevents S5 from prematurely rebuilding query, field, conditional, and context semantics while the core authoring/compiler model is still being proven.

Shortcodes remain the compatibility layer for forms and legacy/plugin functionality. Runtime dynamic passes occur only after write-time compilation.

### 12.2 Permanent rule — no visual query builder

Dynamic behavior is authored as code by an agent/developer. S5 does not provide a generalized visual query-builder abstraction.

If a marketer needs to insert a dynamic composition, the author registers a component whose implementation already contains the approved query/dynamic behavior. The marketer may configure only declared props.

### 12.3 v2 — native S5 dynamic runtime

After production use through v1 and v1.5 establishes the actual required corpus, S5 should replace the L&L dependency with a deliberately small native server-side dynamic language/runtime.

The goal is **not L&L feature parity**. The target is the smallest vocabulary that covers the recurring needs of S5 sites, expected to include roughly:
- `Loop` / query;
- `Field`;
- `If` / `Else`;
- current object/context;
- variables;
- template/include;
- pagination;
- post/CPT, taxonomy, user, and Woo-aware contexts;
- adapters for common field providers where needed.

The native runtime should compile/execute against WordPress APIs and domain adapters rather than introduce a new canonical page representation. HTML remains the document.

### 12.4 Frontend state and interactivity

HTML remains canonical and server-rendered. JavaScript is an **opt-in behavioral layer**, not the canonical component/page representation.

S5 supports modern pre-refresh state and application-like interactions through small interactive islands. Preferred default: WordPress Interactivity API + Script Modules where they fit; ordinary ES modules/vanilla JavaScript remain valid when simpler.

State is divided conceptually into:
- **local UI state** — tabs, accordions, modals, active gallery image, menu state;
- **shared client state** — cart badge, filters, comparison selections, coordinated component state;
- **authoritative server/business state** — cart contents, stock, checkout, user/account state, persisted data.

Client state may update optimistically, but authoritative commerce/content state synchronizes through WordPress/Woo APIs.

Interactive components may optionally contain an interaction module alongside HTML/CSS/manifest source. The compiler/sidecar tracks page interaction dependencies and enqueues only required modules. Zero-interaction pages should remain zero-JS wherever practical.

### 12.5 Ownership rule

The framework must not recreate WordPress capabilities already solved well by plugins unless the frontend-authoring model requires a stable adapter or the dependency materially conflicts with the agent-native architecture.

---

## 13. SEO / AEO / Metadata

S5 owns SEO/AEO output that is a natural consequence of owning the canonical page document and authoritative domain data. It does **not** attempt to become a full SEO operations suite.

### 13.1 Layer A — S5 document SEO (owned)

S5 should natively own the baseline page/post artifacts needed for excellent machine-readable output:
- `<title>`;
- meta description;
- canonical URL;
- Open Graph / social-card basics;
- basic robots directives where appropriate;
- heading/document-outline validation;
- image alt validation;
- internal-link validation;
- LCP/animation visibility rules;
- page-level metadata editing through the S5 surface.

The compiler gate should detect deterministic structural defects rather than score writing quality or keywords.

### 13.1a AEO artifacts (restored)

- **llms.txt generator:** built from the page mirror; regenerated on save. Cheap, on-thesis, ships in Phase 1.
- **Robots policy panel:** plain-language allow/block toggles for AI crawlers (GPTBot, ClaudeBot, PerplexityBot, et al.) so the access decision is the owner's, made consciously — never a silent default. Coexists with the SEO-provider model: if a specialist provider owns robots output, the panel writes through it.

### 13.2 Layer B — semantic/schema compilation (owned)

Where S5 has authoritative structured context, structured data should be compiled from the same source that produces visible content so schema cannot silently drift from the page.

Expected owned schema/artifacts include, where applicable:
- `WebSite`;
- `WebPage`;
- `Organization` / `LocalBusiness`;
- `BreadcrumbList`;
- `Article` / `BlogPosting`;
- component-bound schema for semantically appropriate registered components;
- WooCommerce `Product`, `ProductGroup`/variants, `Offer`, aggregate/review data where eligible and actually represented.

The framework must distinguish schema.org correctness from search-engine rich-result eligibility. It must not imply that every valid schema type generates a rich result or ranking benefit.

### 13.3 Layer C — SEO operations (deferred/integrated)

S5 does not rebuild the specialist operational surface of Yoast, Rank Math, Search Console, or similar tools. Defer/integrate for:
- keyword/readability scoring;
- rank tracking and keyword research;
- advanced redirect management;
- advanced sitemap/index-control workflows;
- large-scale crawl management;
- Search Console/analytics dashboards;
- bulk SEO operations;
- specialist social/schema tooling not derivable from S5-owned data.

### 13.4 SEO provider adapters

Use a provider model rather than hardcoding undocumented plugin storage assumptions. Conceptually:
- **Native provider:** S5 persists and outputs its own metadata/schema.
- **Yoast provider:** S5 editing surface feeds the supported Yoast integration/output path and avoids duplicate ownership.
- **Rank Math provider:** same principle through supported Rank Math APIs/hooks.

The marketer should not have to leave the S5 editing surface merely because a specialist SEO provider is installed.

### 13.5 AEO posture

Do not promise rankings in AI/search systems. Preferred claim: **S5 produces machine-readable, semantically structured content by construction.**

Architectural advantages include server-rendered content, clean semantic HTML, compiler-enforced document structure, schema synchronized with visible content, and genuine removal of hidden content from public output.

## 14. WooCommerce Roadmap — v1.5

WooCommerce support is an explicit post-v1 milestone. The objective is to achieve the same agent-native speed for normal WooCommerce stores without recreating a visual Woo builder.

### 14.1 Commerce ownership boundary

**WooCommerce owns commerce correctness:**
- product/variation models;
- prices, tax, inventory, stock;
- cart and session state;
- coupons;
- shipping;
- checkout;
- payments/gateways;
- orders/customers;
- validation and extension business logic.

**S5 owns the presentation/runtime bridge:**
- agent-authored product/archive templates;
- template parts and conditional assignment;
- stable Woo data/context primitives;
- add-to-cart/variation interaction adapters;
- required scripts/assets;
- extension hook points;
- Woo-aware schema compilation;
- Cart/Checkout placement inside S5-authored shells.

**Agents own frontend presentation:**
- product layouts;
- product cards;
- galleries;
- variation swatch presentation;
- sticky purchase panels;
- tabs/accordions;
- responsive behavior;
- animation;
- interaction styling and UX that can reasonably be expressed in HTML/CSS/JS.

S5 does not build inspector panels for presentation behaviors an author can write directly.

### 14.2 Templates and parts

Woo v1.5 should support condition-assignable S5 templates/parts analogous in responsibility to Woo/block-theme templates while remaining canonical S5 HTML rather than block markup. Initial targets:
- single product;
- shop/product archive;
- product category/tag archives;
- reusable product parts;
- product cards/query compositions;
- header cart/mini-cart surfaces where needed.

### 14.3 Woo data/action vocabulary

S5 should provide a compact stable adapter over Woo APIs rather than force agents to depend on private template internals or meta storage. Required capabilities are expected to cover:
- current product/context;
- title/descriptions;
- image/gallery;
- price/sale state;
- SKU;
- attributes/variations;
- stock/availability;
- quantity;
- add-to-cart;
- ratings/reviews;
- related/up-sell/cross-sell queries;
- notices;
- product/archive query context.

Exact authoring syntax is a technical-design decision; the invariant is that these primitives expose commerce semantics, **not layout**.

### 14.4 Variation UX

S5 does not need a traditional “variation swatches builder.” Woo/S5 adapters expose variation data and state; the agent authors the visual UX in HTML/CSS/JS while preserving Woo's authoritative variation validation and commerce behavior.

### 14.5 Cart and Checkout

Do not initially rebuild Woo Cart/Checkout. Treat them as Woo-owned transactional applications embedded within an S5-authored shell. Preserve compatibility with Woo's supported APIs/runtime and extension behavior.

### 14.6 Extension compatibility

S5 templates must preserve defined Woo hooks/extension points so third-party commerce extensions can participate without requiring S5-specific rewrites wherever practical.

Initial support target is **normal core WooCommerce stores plus conventional payment/shipping extensions**. Subscriptions, bookings, memberships, composites, bundles, product add-ons, advanced dynamic pricing, and other specialty domains are added only from real production demand.

### 14.7 Woo v1.5 exit criterion

An agent can build a production Woo storefront's frontend as canonical S5 HTML/CSS/JS at roughly code-agent speed; S5 connects that presentation to Woo's authoritative commerce state without a builder-element translation layer.

## 15. Performance Model

Target: as close to static delivery as practical while retaining WordPress.

- Canonical source is compiled on write.
- Request path serves a prepared HTML fragment plus only required dynamic passes.
- Full-page cache remains highly effective.
- No builder runtime ships to visitors.
- Editor code ships only to authenticated editing contexts.
- Motion/JS assets load only when needed.
- Interactive-island modules load only on pages/components that declare them.

Performance should be treated as a consequence of architectural simplicity, not an after-the-fact optimization layer.

---

## 16. Success Criteria

### 16.1 Core product success

A competent AI coding agent should be able to take a design/brief to a production-ready WordPress marketing site with only a small additional workflow tax compared with producing the equivalent Astro/Next/static site.

### 16.2 v1 success

1. A real production site is authored primarily by an agent using canonical HTML/CSS source and deployed through the framework.
2. Served markup remains explainably faithful to compiled source with permanent round-trip/fixture tests.
3. Unknown/illegal design-system vocabulary fails loudly with actionable diagnostics.
4. A Content user can edit declared copy/images/props and insert/move/hide approved components without being exposed to generalized layout authoring.
5. Undeclared content is not editable.
6. Unregistered structures cannot be inserted by Content users.
7. Every mutation path — CLI, editor, MCP — uses the same compiler/save pipeline.
8. Native revisions can restore canonical source and regenerate derived artifacts.
9. Git export remains portable and free of environment-specific render state.
10. The framework demonstrates materially lower time-to-site than rebuilding equivalent agent output through a conventional WordPress builder abstraction.

### 16.3 North-star metric

**Time from design/brief to production-ready WordPress frontend.**

The product succeeds if it meaningfully closes the speed gap between agent-built Astro/Next/static sites and agent-built WordPress sites.

---

## 17. Build Plan

### 17.0 S5T Ship Gate (the pin)

Timeline is secondary; **this checklist is the definition of "stable enough to ship series5technology.com."** All items green = ship, whatever the date. Any item red = not yet, whatever the date.

1. **Fidelity:** round-trip harness green across the full S5T page corpus — import → request → diff shows zero unexplained deltas (sanctioned transforms only: head assembly, media resolution).
2. **Idempotence:** re-importing the corpus twice changes nothing (same pages update, no duplicates, no drift).
3. **Validation:** compiler rejects a planted unknown class and a planted inline style with actionable diagnostics; the real corpus passes clean.
4. **Media:** every image serves attachment-backed with dimensions + srcset; LCP image not lazy-loaded; canonical source remains free of environment-specific IDs.
5. **Pages:** all nine pages live (Home, About, Commerce, Apps, Manage, FAQ, Book a Call, Privacy, Fix My Store — **existing Fix My Store URL preserved exactly**), both viewports reviewed.
6. **Launch ops:** TidyCal embed fires, analytics fires, 404 works, 301 map covers changed URLs, sitemap/robots present, favicon/OG in place.
7. **Performance:** full-page cache serving on anonymous requests; CWV spot-check green on Home + one content-heavy page.
8. **Recovery:** one page restored from a revision and one from Git source, both regenerating correctly.

Gate applies to Track A (Steps 0–7) of the Build Order. Everything beyond it — editor, components, MCP, dynamics — is explicitly *not* required to ship S5T.

### Phase 0 — Personal-site MVP / runtime proof (2026)

Goal: prove that WordPress itself is not the bottleneck.

Scope:
- shell theme;
- canonical page storage;
- compiled render storage;
- CLI import;
- head/body split;
- stylesheet/design-system enqueue;
- basic class validation;
- basic shortcode compatibility;
- basic page/meta support;
- round-trip QA harness;
- enough launch operations to run a real personal/marketing site.

Explicitly defer:
- on-glass editor;
- component studio polish;
- MCP write surface;
- WooCommerce;
- native dynamic runtime;
- migrations unless required by real usage;
- fleet tooling;
- generalized admin UX.

Exit criterion:

**An agent can author a multi-page site as files, one command imports it, and WordPress serves the expected frontend without builder translation.**

### Phase 1 — Core v1 / compiler and handoff

- canonical/render split fully implemented;
- media pipeline;
- semantic `post_content` mirror;
- richer diagnostics;
- document SEO + quality gates;
- native baseline metadata/schema compilation;
- revisions;
- Git export;
- parts/templates and L&L-backed generic dynamics;
- frontend interactive-island convention;
- QA fixtures from production corpus;
- declared human editing surface;
- registered component insertion;
- Content/Design/Developer capability levels;
- component registry/workbench sufficient for production use;
- external-agent reads/writes through shared services/Abilities/MCP once core save semantics are stable.

Exit criterion:

**S5 is a complete agent-native WordPress system for normal marketing/content sites: agents author directly, humans edit declared capabilities, and all mutation paths preserve the same compiler contract.**

### Phase 1.5 — WooCommerce (target 2027)

- native Woo adapter;
- single-product/archive/taxonomy template contexts;
- condition-assignable Woo templates/parts;
- product fields/query primitives;
- variations and add-to-cart state/actions;
- gallery/product-card patterns;
- Cart/Checkout shell integration;
- Woo extension hooks;
- Woo-aware schema;
- production test corpus using real S5 stores.

Do not build traditional Woo builder widgets or attempt complete extension parity.

Exit criterion:

**A normal WooCommerce store can be authored at agent/code speed without translating the design into builder elements.**

### Phase 2 — Native S5 dynamics / remove L&L dependency

Only after v1 + Woo production use identifies the required dynamic corpus:
- native Loop/query primitive;
- Field;
- If/Else;
- context;
- variables/includes;
- pagination;
- post/CPT/taxonomy/user adapters;
- Woo-aware context;
- common custom-field provider adapters;
- migration path from the supported L&L subset;
- security/escaping/query limits;
- compiler diagnostics and fixtures.

Do not pursue full L&L parity and do not build a visual query builder.

Exit criterion:

**Normal S5 dynamic sites no longer require L&L, while HTML remains canonical and the dynamic vocabulary remains small enough for agents to learn from the Authoring Contract.**

### Phase 3 — Multi-site / agency hardening

Only after repeated production use proves need:
- fleet updater;
- shared component/version distribution;
- centralized QA/operations;
- broader deployment integrations;
- commercial licensing/update infrastructure.

## 18. Commercial Positioning

Working category:

**Agent-native WordPress frontend**

Core promise:

**Build WordPress sites at code-agent speed.**

Supporting explanation:

**Your agent writes real HTML and CSS. WordPress runs it. Clients edit only what you expose.**

Alternative concise expression:

**Agents author. Developers define the rules. Marketers edit declared capabilities. WordPress runs it.**

Initial commercial wedge:
- agencies;
- freelancers;
- WordPress developers already using AI coding agents;
- teams considering Astro/Next/static approaches primarily because WordPress builder workflows have become the bottleneck.

Do not position primarily as a mass-market DIY page builder.

---

## 19. Open Questions

1. Product name.
2. Final editor technical design and state protocol.
3. Rich-text slot scope beyond bold/italic/link, if corpus proves necessary.
4. Final L&L licensing/distribution posture.
5. LiveCanvas autopsy findings that materially affect storage/editor bootstrapping.
6. Background-image compiler-owned value mechanism.
7. Exact semantic HTML shape of the `post_content` mirror.
8. Shortcode allowlist configuration UX.
9. Initial Abilities naming/schema conventions.
10. Quantified benchmark methodology for “Astro/Next-level agent velocity.”
11. Exact S5 interactive-component convention and WordPress Interactivity API boundary.
12. Woo adapter authoring syntax and hook vocabulary.
13. Exact supported Woo v1.5 extension matrix.
14. Native v2 dynamic-language syntax and supported L&L migration subset.
15. SEO provider adapter contracts for Yoast and Rank Math.

---

## 20. Architecture Guardrail

If a future feature request would make a marketer capable of inventing arbitrary layout or styling that was not explicitly authored/registered, the default answer is **no**.

The correct extension path is usually:

1. author or agent defines a new component/capability;
2. compiler validates it;
3. the capability appears in the human editing surface;
4. humans may then exercise it safely.

The product should never become a conventional page builder one convenience feature at a time.
