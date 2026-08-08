# BUILD ORDER — Agent-Native WordPress v1

**Purpose:** execution sheet for Claude Code, Codex, Cursor, or another coding agent.  
**Read with:** PRD v1.3 + Authoring Contract v0.1.  
**Primary optimization:** shortest path from blank WordPress install to a real production site running on S5 without a builder translation layer.

---

## Operating rules for the agent

1. Read the PRD and Authoring Contract before modifying code.
2. Build in the order below.
3. Do not begin a later step until the current step's acceptance test passes.
4. Prefer the smallest implementation that proves the architectural invariant.
5. Do not add generalized UI, builder controls, abstractions, or compatibility layers unless the current step requires them.
6. Every mutation path added later must reuse the same compiler/save services.
7. HTML remains canonical. Derived artifacts must always be regenerable.
8. When a feature can be deferred safely, defer it and record the gap.
9. Use real pages from the target production site as early as possible. Do not optimize only against synthetic fixtures.
10. Preserve a permanent automated test for every load-bearing invariant once established.

---

# TRACK A — Get a real site live as fast as possible

## Step 0 — Bootstrap and lock invariants

### Build
- Theme/plugin repository scaffold.
- Minimal test environment and WP-CLI access.
- Machine-readable configuration for paths, page source, theme/plugin namespaces.
- A short architecture README pointing agents to the PRD + Authoring Contract.

### Do not build
- Admin UI.
- Human editor.
- Components system.
- MCP.
- WooCommerce integration.
- L&L replacement.

### Acceptance test
- Theme and plugin activate with no PHP errors.
- `wp` commands execute in the target environment.
- Test command runs successfully.

### Exit condition
**The repository can be installed and tested repeatedly from a clean WordPress environment.**

---

## Step 1 — Serve canonical HTML without Gutenberg/builder translation

### Build
- Framework page storage in registered post meta.
- Canvas/runtime template.
- Render path that serves stored HTML fragment rather than Gutenberg-generated page markup.
- Required filter suppression for byte-clean framework pages.
- Normal WordPress page identity, permalink, title, status, and permissions remain intact.

### Do not build
- Head parser/importer.
- Media rewriting.
- L&L.
- Components.
- Editing declarations.

### Acceptance test
- Manually place a known HTML fragment in framework storage.
- Request the page with `curl`.
- Served body contains the expected fragment with no unexplained WordPress transformations.

### Exit condition
**WordPress can act as a runtime for authored HTML without a builder representation.**

---

## Step 2 — Build `wp s5 import`

### Build
- CLI import command for a directory of full HTML documents.
- Head/body parsing.
- Slug mapping and page create/update behavior.
- Store canonical source HTML.
- Extract/store baseline title, description, canonical, OG/meta artifacts needed for the page.
- Idempotent re-import behavior.

### Do not build
- Sophisticated SEO UI.
- Media library transformation.
- Components/editor.

### Acceptance test
- Import one real page from disk.
- Re-import it twice.
- The same WordPress page updates rather than duplicates.
- Correct head/body data is stored and rendered.

### Exit condition
**A source HTML file can become a real WordPress page with one command.**

---

## Step 3 — Make round-trip fidelity permanent

### Build
- Automated import -> request -> normalize sanctioned transforms -> diff harness.
- Failure output that identifies unexplained deltas.
- Fixtures from at least one real production page.

### Do not build
- New product features until this is green.

### Acceptance test
- A known page imports and round-trips with zero unexplained deltas.
- Intentionally mutate the renderer and prove the test fails.

### Exit condition
**Render fidelity is executable infrastructure, not a written promise.**

---

## Step 4 — Implement Authoring Contract validation

### Build
- HTML parse validation.
- Class extraction/validation.
- Token/scale/name grammar rules required by the current Authoring Contract.
- Inline-style prohibition except enumerated compiler-owned exceptions.
- Unknown vocabulary fails loudly.
- Agent-readable diagnostics with location, rule, permitted vocabulary, and likely repair.

### Do not build
- Auto-invention of missing classes.
- Silent CSS generation.
- General-purpose style UI.

### Acceptance test
- Valid real page passes.
- Unknown class fails.
- Illegal inline value fails.
- Diagnostic gives enough context for an agent to correct the file without human reverse engineering.

### Exit condition
**Strictness improves authoring reliability rather than creating opaque failures.**

---

## Step 5 — Split canonical source from derived render HTML

### Build
- Canonical portable source storage.
- Derived render storage.
- Atomic compile/persist flow.
- Semantic visible `post_content` mirror.
- Recompile derived artifacts from canonical source.
- Revision registration for canonical source.

### Do not build
- Git two-way merge.
- Human editing UI.

### Acceptance test
- Canonical source can be changed and compiled.
- Derived render is regenerated deterministically.
- `post_content` contains useful semantic visible HTML but is never served as the framework page.
- Restore a source revision and regenerate derived state.

### Exit condition
**Source is portable truth; render output is disposable environment state.**

---

## Step 6 — Add media resolution

### Build
- Logical/relative asset discovery.
- Media sideload/deduplication strategy.
- Attachment-backed output with dimensions/srcset where appropriate.
- Lazy/LCP behavior.
- Environment-specific media only in derived render output.

### Do not build
- Media-management replacement UI.

### Acceptance test
- Same canonical source imports in two environments without baked attachment IDs.
- Derived output uses each environment's media URLs/IDs correctly.
- Git/source remains portable.

### Exit condition
**Media no longer forces environment-specific data into canonical HTML.**

---

## Step 7 — Put the real personal/S5 site on it

### Build
- Every actual production page needed for launch.
- Header/footer/parts at the simplest maintainable level.
- Analytics/scripts, favicon, redirects, 404, sitemap/robots integration, caching, forms/embeds required by the real site.
- Fix gaps found by actual usage.

### Do not build
- Features not required by the real site.
- Human editor because it sounds useful later.
- Fleet tooling.

### Acceptance test
- Whole site imports from source.
- Round-trip harness passes across the production corpus.
- Desktop/mobile review passes.
- Production cache and redirects work.

### Exit condition
**A real site runs on S5 in production and daily maintenance no longer requires a WordPress builder.**

---

# TRACK B — Turn the personal runtime into core v1

## Step 8 — Templates, parts, and generic dynamics

### Build
- Templates and reusable parts with condition assignment.
- Tangible Loops & Logic integration for generic loops, fields, conditions, archives, and CPT contexts.
- Shortcode compatibility with explicit Content-level allowlisting later.
- Dynamic runtime order remains after write-time compilation.

### Do not build
- Native S5 query language yet.
- Visual query builder ever.

### Acceptance test
- Build at least one archive/CPT-driven real page using L&L inside canonical S5 templates.
- Conditions/fields render correctly under page cache rules.

### Exit condition
**Normal dynamic WordPress marketing/content sites are practical without S5 rebuilding query logic.**

---

## Step 9 — Baseline SEO/AEO ownership

### Build
- S5-native document metadata: title, description, canonical, OG/social basics, basic robots handling.
- Compiler gates for headings, alt text, internal links, LCP/animation visibility.
- Base schema graph where authoritative: WebSite/WebPage/Organization or LocalBusiness/BreadcrumbList/Article as applicable.
- Schema generated from the same content/domain values that render visibly.
- Provider interface boundary for future Yoast/Rank Math integration.

### Do not build
- Keyword scoring.
- Readability scoring.
- Rank tracking.
- Search Console dashboard.
- Full redirect/index-management suite.

### Acceptance test
- Real pages emit correct metadata without a dedicated SEO plugin.
- Schema validates structurally and matches visible content.
- Installing a specialist SEO provider can be accommodated without duplicate output architecture.

### Exit condition
**S5 owns SEO artifacts that are inherent to the document, while specialist SEO operations remain external.**

---

## Step 10 — Frontend state and interactive islands

### Build
- Optional interaction module convention for components/pages.
- Prefer WordPress Interactivity API + Script Modules when appropriate.
- Vanilla ES modules allowed when simpler.
- Sidecar/dependency discovery so only required modules load.
- Demonstrate local state and shared state without a full SPA runtime.

### Do not build
- Mandatory React frontend.
- Redux/global runtime for every page.
- Hydrate every component.

### Acceptance test
- Static page ships no unnecessary interaction JS.
- Interactive component updates before page refresh.
- Two components can share a small state store where required.
- Server remains authoritative for persisted/business data.

### Exit condition
**S5 can deliver Astro-island-like interactive UX while HTML remains canonical/server-rendered.**

---

## Step 11 — Components as registered human capabilities

### Build
- Component definition format: HTML partial + CSS + manifest + optional interaction/logic.
- Copy-on-insert expansion into canonical page HTML.
- Version stamp.
- Component registry.
- Basic deterministic migration mechanism only as required.

### Do not build
- Component JSON as canonical page state.
- Generic visual primitives for marketers.

### Acceptance test
- Agent registers a component.
- Component can be inserted and expands into real canonical HTML.
- Unregistered component cannot be inserted through the human-facing capability surface.

### Exit condition
**Registered components define what humans may compose without becoming the page representation.**

---

## Step 12 — Human editing projection

### Build
- React admin/editor shell.
- iframe preview.
- declared slot/prop controls only.
- structure view filtered by capability.
- insert/move/duplicate/hide/delete only at permitted component/section granularity.
- Content/Design/Developer levels.
- one dirty-state/update path through compiler services.

### Do not build
- Add Div/Container/Grid.
- free layout authoring.
- free CSS at Content level.
- built-in AI chat.

### Acceptance test
- Undeclared text cannot be edited.
- Declared field can be edited.
- Registered component can be inserted.
- Unregistered structure cannot be invented.
- Save creates/restores through the same compiler/revision path.

### Exit condition
**Humans can safely operate the site without receiving a page builder.**

---

## Step 13 — External agent operations

### Build
- WordPress Abilities representing stable S5 application services.
- MCP exposure through the WordPress adapter.
- Reads first: contract, design system, components, pages, validation.
- Writes second: validate/apply source, set declared values, insert component, run gates/import.
- WordPress user/capability-backed authorization.

### Do not build
- proprietary chat UI.
- second mutation/save pipeline.

### Acceptance test
- External agent reads the contract/site vocabulary.
- External agent performs a permitted change.
- Change passes the exact compiler/revision path used by CLI/editor.
- Content-level token cannot perform Developer-level source mutation.

### Exit condition
**Any compatible external agent can operate S5 without learning a builder abstraction or bypassing framework safety.**

---

# TRACK C — v1.5 WooCommerce (target after core v1)

## Step 14 — Woo read/context adapter

### Build
- Current-product context.
- Woo CRUD/API-backed field access.
- Product/archive/taxonomy template contexts.
- Product query primitives needed by the first real store.

### Do not build
- Woo builder widgets.
- private-meta dependence where Woo provides APIs.

### Acceptance test
- Agent-authored single product and archive templates render real Woo data correctly.

### Exit condition
**Woo data is available to authored HTML through stable commerce semantics.**

---

## Step 15 — Woo state/actions and interactive UX bridge

### Build
- variation state/selection bridge.
- quantity/add-to-cart actions.
- notices/cart-count synchronization.
- required Woo scripts/store APIs/interactivity integration.
- extension hook points.

### Do not build
- visual swatch editor.
- custom commerce correctness logic that Woo already owns.

### Acceptance test
- Agent authors a custom variation-swatches UI in HTML/CSS/JS.
- Woo remains authoritative for variation validity/stock/price/add-to-cart.
- Cart badge/state updates without page refresh where appropriate.

### Exit condition
**Agents can design modern product UX freely without reimplementing Woo business logic.**

---

## Step 16 — Woo Cart/Checkout and production corpus

### Build
- S5 shells for cart/checkout/account where needed.
- Embed/use Woo-owned transactional applications rather than rewriting them.
- Product schema/variant/offer compilation from Woo authoritative data.
- Real-store regression suite.

### Do not build
- custom checkout engine.
- universal compatibility with every specialty Woo extension.

### Acceptance test
- Real test orders succeed through shipping/tax/payment flows.
- Variable products work.
- Normal extensions using preserved hooks continue working.
- Structured product data matches storefront content/state.

### Exit condition
**A normal production Woo store can be built at agent/code speed on S5. This is the v1.5 commercial-readiness gate.**

---

# TRACK D — v2 native dynamics / remove L&L

## Step 17 — Inventory actual L&L usage

### Build
- Extract every L&L pattern used across real S5 + Woo production sites.
- Rank by frequency and architectural importance.
- Define the smallest native S5 dynamic vocabulary that covers the corpus.

### Do not build
- a speculative clone of all L&L features.

### Acceptance test
- Every production L&L use is categorized as native-v2-required, plugin/shortcode responsibility, or intentionally unsupported.

### Exit condition
**The v2 language is derived from evidence rather than imagination.**

---

## Step 18 — Native dynamic runtime

### Build
- Loop/query.
- Field.
- If/Else.
- context.
- variables/includes.
- pagination.
- post/CPT/taxonomy/user adapters.
- Woo contexts.
- escaping/security/query limits.
- compiler diagnostics.

### Do not build
- visual query builder.
- generic programming language.

### Acceptance test
- Migrate the supported production corpus from L&L.
- Render/behavior regression suite stays green.

### Exit condition
**Normal S5 sites no longer depend on L&L and the dynamic language remains small, code-first, and agent-readable.**

---

# TRACK E — commercial/agency hardening only after product proof

## Step 19 — Commercial readiness

Build only after v1/v1.5 are proven on multiple production sites:
- update/licensing infrastructure;
- onboarding/agent skill package;
- documentation;
- supported hosting/install matrix;
- migration/update policy;
- support diagnostics;
- security review;
- release channels;
- telemetry only if privacy posture permits and product need is clear.

### Exit condition
**A developer who did not build S5 can purchase it, connect their preferred agent, build a site, deploy it, and recover from errors without founder intervention.**

---

# Permanent prioritization rule

When choosing between two tasks, prefer the one that most directly reduces:

**time from design/brief -> production-ready WordPress frontend**

without violating:

**Agents/developers author directly. Humans edit declared capabilities. WordPress runs it. No builder translation layer.**
