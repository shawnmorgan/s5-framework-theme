# S5-PLUGIN-EXTENSIBILITY-SPEC — v0.1

**Date:** 2026-08-21 · **Owner:** Shawn Morgan · **Status:** Approved architecture; tiered build (see §7)
**Companion to:** PRD v1.5 (extends §7 Components, §12.4 interactivity, §14 Woo) · S5-ATOMIC-DESIGN-SPEC-v0.1 · S5-DYNAMICS-SPEC-v1.5

---

## 0. Why this exists

"Works with the WordPress plugin ecosystem" is half of why a business stays on WordPress instead of leaving for Astro. S5 must let third-party plugins participate without breaking the canonical-HTML architecture or the lightweight page canvas. This spec covers shortcode plugins, block plugins (including WooCommerce's Interactivity-API blocks), and how to style plugin output from outside the block editor.

## 1. The two plugin shapes

**Shape 1 — insertable plugins (forms, sliders, tables, galleries, commerce blocks).** Output triggered by a shortcode token or a block. Self-contained: the plugin owns its markup, JS, and styling. These need an **authoring path** — how a non-coder gets them into a page. This is the subject of this spec.

**Shape 2 — global plugins (SEO, caching, security, analytics).** Hook into `wp_head`, filter content, act site-wide. Not inserted anywhere. Supported by **not fighting them** — the existing head-passthrough lets Yoast/Rank Math write tags; SEO-provider seams defer to them (PRD §13.4). Mostly already handled; not an authoring problem.

## 2. The unifying decision: every injectable is a component

**A component's body is one of three kinds:** authored HTML (normal components), a **shortcode** (Shape-1 shortcode plugins), or a **block invocation** (Shape-1 block plugins). Same wrapper, same stamp, same slot system, same palette presence, same page-canvas behavior. Three body-kinds, one component model.

Consequence: plugin support and the component story become **one story** — "everything is a component, including the blocks and plugins you already use." The page canvas never learns that a given component renders via shortcode or block; it sees a component. All plugin-specific and block-specific config UI lives in the **Component Builder** (settings), never leaking to the front-end canvas — which is the lightweight-canvas protection.

## 3. The three tiers of insertion

**Tier 1 — Embed atom (universal escape hatch).** Paste a shortcode or arbitrary embed markup; lands in canonical HTML; renders via the existing shortcode pass. Guarantees any plugin with a shortcode works. **Developer-gated** (arbitrary paste = code execution surface). This is the existing Embed primitive (PRD §6.2/§6.4). *Requirement:* the editor preview must render the shortcode's **output** (run it server-side, show the result in the canvas iframe), not raw `[shortcode]` text — verify the existing runtime preview does this.

**Tier 2 — registered plugin components (shortcode/simple plugins).** Known plugins (Gravity Forms, WPForms, CF7) wrapped as palette components whose body is `[shortcode id={prop}]`, with the plugin's parameters exposed as **props** (a form-picker dropdown for `id`). Non-coder inserts cleanly, picks the form, never sees a shortcode. **Design+.** This is the existing component system with a shortcode body — strictly better than a page-builder widget because it reuses the component model.

**Tier 3 — block-injection islands (block plugins, incl. Woo).** A registered block's **server-rendered output** (via `render_block()`) dropped into canonical HTML as a **hydrating island** container; the block's own script hydrates it client-side. This is the Woo mechanism — "containerization in blocks" = the S5 island model (`data-s5-island`). You consume block **output** (HTML + hydration), never the block **editor**. Reuses the island convention, the sidecar-driven asset enqueue, and the compile-pass pattern.

## 4. Block-injection architecture (Tier 3 detail)

- A block-injection component stores a **block invocation** (block name + attributes) in its definition; the compile/request pass calls `render_block()` on it — a third sanctioned transform alongside shortcodes and dynamics.
- **Attributes → props:** for known blocks, the block's attributes (which product, column count, and its declared style-supports) are exposed as component props via the Component Builder's block-config UI. For unknown blocks, the Embed/paste path renders block markup directly.
- **Hydration & assets:** the block's frontend script/styles enqueue only on pages using it — the existing sidecar island-enqueue already does this. Because output is produced by WP's own `render_block()`, the markup shape matches what the block's hydration expects.
- **Compile-time vs request-time:** static blocks (layout) render at compile into derived HTML; dynamic blocks (add-to-cart, live stock) render at request or hydrate from an API client-side — same static-unless-flagged-dynamic model as pages. The block's own dynamic-ness decides.
- **Caching invariant:** a block-injection island on a catalog page must NOT force `DONOTCACHEPAGE`. Page stays static-cacheable; island hydrates per-user client-side. This is the speed claim and the one hard constraint.
- **Compatibility boundary (honest):** IAPI-native blocks (Woo's current direction — Mini Cart, Product Gallery) inject cleanly. Older React-hydration blocks that assume block-editor save-process rendering are hit-or-miss. *Open questions for the Woo team (Shawn has Slack access): which Woo blocks are IAPI-native today; do any force site-wide cache exclusion.*

## 5. Styling injected blocks (three layers)

The honest boundary: a block's internal markup is the plugin's, not yours — same as every theme. What you can do, layered:

**Layer 1 — block-supports attributes → component props (native, non-coder).** Modern blocks declare "supports" (color, spacing, typography, borders) that WordPress renders from attributes without the editor. Expose these as props in the registered plugin-component; write them into the block's attributes at render. A real slice of block styling flows through the existing props-as-slots model.

**Layer 2 — theme.json token bridge (global, automatic, native).** Blocks read `theme.json` for default colors/spacing/typography. The theme generates a `theme.json` from the S5 token system so injected blocks **inherit the S5 palette/scale/type by default** and look native without per-block styling. *New build: tokens → theme.json as a compile target alongside tokens → dist/s5.css, kept in sync.* This is the elegant answer to "make Woo blocks look like they belong," and it works with WordPress's grain. **Scope decision (owner):** build with the Woo track (so the first Woo demo looks native) vs defer (blocks look generic until CSS'd).

**Layer 3 — targeted site CSS (Developer escape hatch).** For everything blocks don't expose or inherit, a developer writes CSS targeting the block's own classes (`.wc-block-*`) into site CSS. **Requires a validator exception:** foreign-block-targeting selectors are allowed in Developer-authored site CSS (never in atom/component definitions), flagged as external-targeting so they're auditable. This is the first sanctioned exception to closed-vocabulary — bless it consciously: you cannot restyle a plugin without targeting its classes.

## 6. Where the surfaces live

- Block/plugin **config** (which block, attr→prop mapping, exposed style-supports): **Component Builder** (settings). Sophistication welcome here; never on the page canvas.
- Layer-1 style props: edited as component **slots** on the page canvas, like any component prop.
- Layer-2 theme.json: derived from the **Styles tab** tokens — one source, two outputs (dist/s5.css + theme.json).
- Layer-3 targeted CSS: **Developer** site-CSS surface.

## 7. Build staging

- **Component-kind schema (foundational, build first):** component manifest gains `kind` (html | shortcode | block); for block kind, a block-name + attribute-to-prop map; the component-resolution pass branches on kind (stamp / shortcode-pass / `render_block()`). Required for any block injection.
- **Tier 1 + Tier 2 (near-term, mostly existing machinery):** Embed atom (verify output-preview); registered plugin components (shortcode body + props). Small; the general plugin-compat story.
- **Tier 3 (Woo-track core):** `render_block()` transform on the component-kind schema; ship/validate against Woo's block set FIRST (known, testable surface) before generalizing to arbitrary block plugins.
- **theme.json bridge (with Woo track):** tokens → theme.json compile target.
- **Component Builder block-wrapping UI (maturity/UX layer, defer):** agent/file-authored block-component definitions work without it; the visual block-wrapping UI follows.

## 8. Owner scope decisions

1. Starter plugin components in v1 (a Gravity/WPForms/CF7 wrapper as demonstration) vs Embed-atom escape hatch only, with plugin-components as a "register your own / we add popular ones later" pattern? *Escape hatch alone satisfies "works with plugins"; starter components are polish/demo.*
2. Embed atom gating: Developer-only for raw paste, Design+ for registered plugin components (the raw-vs-composed split used everywhere else). *Recommended.*
3. Block injection v1 scope: Woo block set only (tight, testable) vs general "any registered block" (large compatibility matrix). *Recommended: build the mechanism general, validate/ship against Woo first.*
4. theme.json bridge: with Woo track (native-looking blocks in the first demo) vs deferred. *Recommended: with Woo track.*
