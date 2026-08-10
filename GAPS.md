# GAPS — Step 7 (S5T Ship Gate) findings

Gaps surfaced by putting a real (test) corpus on the framework. Split into
**FRAMEWORK gaps** (code the framework still owes) and **CONTENT gaps** (real
copy/assets/decisions the owner still owes). Nothing here is built beyond Step 7's
scope (Do-Not-Build: editor, components, partials system, L&L, MCP, scripts panel,
git export, redirects for the test corpus).

Severity: **BLOCKER** · **HIGH** · **MED** · **LOW**.

---

## FRAMEWORK gaps (the framework owes this code)

### HIGH — Duplicate `<link rel="canonical">` on the front page
WordPress core emits its own canonical (`http://localhost:8888/`) in addition to
the page's authored canonical (`https://series5technology.com/`), so the front
page serves **two** canonicals. The head-passthrough ruling emits the authored one
correctly; core's must be suppressed when a framework page supplies its own.
→ Step 9 (SEO ownership) or a targeted `remove_action` now if launch needs it.
Recorded, not fixed in Step 7.

### HIGH — Header/footer duplicated per page (no partials yet)
Decided acceptable for Step 7. The importer now **warns on header/footer drift**
across the corpus (and on charset/viewport drift) so copies can't silently
diverge. Every page still carries its own copy.
→ Step 8: parts/templates system collapses the duplication to one part.

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
