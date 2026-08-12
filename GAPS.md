# GAPS — Step 7 (S5T Ship Gate) findings

Gaps surfaced by putting a real (test) corpus on the framework. Split into
**FRAMEWORK gaps** (code the framework still owes) and **CONTENT gaps** (real
copy/assets/decisions the owner still owes). Nothing here is built beyond Step 7's
scope (Do-Not-Build: editor, components, partials system, L&L, MCP, scripts panel,
git export, redirects for the test corpus).

Severity: **BLOCKER** · **HIGH** · **MED** · **LOW**.

---

## FRAMEWORK gaps (the framework owes this code)

### ✅ FIXED (Step 9) — Duplicate `<link rel="canonical">`
Core's `rel_canonical` is now suppressed on framework pages (`Render::own_canonical`);
each page ships exactly one authored canonical. Asserted by acceptance-step9.

### ✅ FIXED (Step 8) — Header/footer duplication
Header/footer are now a single reusable part each (`parts/site-header.html`,
`parts/site-footer.html`); pages reference `<s5-part>` and the compiler expands it.
The importer still warns on drift for any remaining duplicated regions.

### ✅ CLOSED (PRD v1.5) — L&L is a retired dev-only fixture; no contradiction
Earlier review flagged a "contradiction": PRD v1.5 §12.1 retires L&L, yet Step 8 as
built runs on L&L. **Ruling (PRD v1.5 + S5-DYNAMICS-SPEC-v1.5): this is not a
contradiction.** L&L (4.2.4, owner-provided GPL zip in gitignored `vendor-plugins/`)
is a **retired, dev-only fixture dependency** that exists solely to exercise the
dynamic-pass **seam** — dynamic-page flagging, runtime pipeline ordering, and the
static byte-clean guarantee — so that seam is proven before the native engine is
built. **L&L never appears in any real site's canonical HTML.** The S5T launch corpus
is **fully static**, so no real-canonical L&L exists and there is no fidelity or
ownership violation to resolve. **S5 Dynamics v0** (native `s5-loop`/`s5-field`/
`s5-if`/`s5-empty`, per S5-DYNAMICS-SPEC-v1.5) replaces the fixture at its build
trigger — the **first real dynamic page** (shawnmorgan.co blog) — at which point Step 8
acceptance is re-pointed at the native engine and the L&L vendor is removed. PRD Open
Question #4 is resolved by v1.5 (L&L retired; native dynamics owned). Finding closed.

### MED — AEO artifacts not built (llms.txt + robots panel)
PRD §13.1a calls for an `llms.txt` generator (from the page mirror) and a
plain-language AI-crawler robots policy panel. Step 9 built Layer A (document
metadata) + Layer B (schema) but not these AEO artifacts. Deferred to a later pass.

### LOW — Interactivity API not used (vanilla ES modules only)
Step 10 islands use vanilla ES modules (the smallest proof). The discovery/enqueue
seam can drive the WordPress Interactivity API / Script Modules where richer state
is needed; not built yet.

### MED — 404 status pathway relies on WP setting the 404
`404.php` renders an authored `404` framework page (if `pages/404.html` was
imported) through the canvas, else a branded fallback, preserving WP's 404 status.
No `pages/404.html` is in the test corpus, so the fallback path is what's
exercised. Adding a real 404 page is a CONTENT gap.

### MED — Redirect map not wired
A `redirects` slug→URL 301 map in `s5.config.json` served via `template_redirect`
is the decided design. **Out of scope for the test corpus** (ruling: "none for the
test corpus"). Wire + populate when the real old→new URL list arrives.

### LOW — `unrecognized` head reporting is now vestigial
With ordered verbatim passthrough, nothing in the head is "unrecognized" anymore;
`HtmlDocument::$unrecognized` is always empty and the importer's warning loop over
it no-ops. Harmless; remove in a later cleanup.

### LOW — Sitemap "framework pages included" assertion
WP core sitemap covers published pages; needs a positive assertion in the gate
that framework pages appear. Pending (verifiable now that nine real pages exist).

---

## CONTENT gaps (the owner owes real copy / assets / decisions)

### BLOCKER (for launch, not for the gate) — All nine pages are TEST CONTENT
Every page in `pages/` is placeholder scaffolding, marked with a visible
"TEST CONTENT" banner and `data-s5="test-marker"`. Replace with real copy before
launch. This is authorized test scaffolding, not launch content.

### HIGH — Real TidyCal embed
`book-a-call.html` has a placeholder `<iframe class="embed_frame" src="about:blank">`
marked `TODO-REAL-EMBED`. The gate verifies the embed **container renders**; it can
NOT verify the calendar **functions** until the real TidyCal snippet is dropped in.

### HIGH — Analytics tag
Every head carries a `<!-- TODO-GA4 -->` placeholder comment (passed through
verbatim, asserted present). The gate verifies the **marker is in the served
head**; it can NOT verify analytics **fires** until the real GA4 tag replaces it.

### HIGH — Fix My Store live slug
`fix-my-store.html` uses a **placeholder** slug. The live URL must be supplied and
the file renamed to match it exactly (canonical + nav/footer links updated).

### MED — Favicon + OG image are placeholders
`assets/favicon.svg` and `assets/og-cover.png` are generated placeholders; the head
`<link rel="icon">` and `og:image` resolve, but real brand assets should replace
them.

### MED — Real copy, canonical host, and IA links
Canonicals/OG all point at `https://series5technology.com/…` (correct host) but the
body copy, headings, and any deep links are placeholder. Confirm the nine-page IA
(Commerce · Apps · Manage · About · Book a Call in the nav; Privacy · Fix My Store
in the footer) matches the real site map.

---

## Solid (not gaps)

- Round-trip fidelity across all nine pages, idempotent import, Authoring-Contract
  validation (corpus clean; planted violations caught), media resolution
  (attachment-backed, srcset, LCP-not-lazy, no `data-s5-*` in served output),
  canonical/derived split + revisions, single-environment full-suite runner.
- Head ruling: structured extraction + ordered verbatim passthrough; theme owns
  charset/viewport (emitted once, drift-warned); favicon + analytics comment reach
  the served head verbatim.
