# GAPS — Step 7 (S5T Ship Gate) findings

Every gap the real-site requirement surfaces is recorded here with a severity.
This is the Step 8+ agenda. Nothing here is built in Step 7 (per the Do-Not-Build
list: editor, components, partials, L&L, MCP, scripts panel, git export).

Severity: **BLOCKER** (gate cannot proceed) · **HIGH** (needed before real launch) ·
**MED** (needed soon, has a safe interim) · **LOW** (cosmetic / deferrable).

---

## BLOCKER — the S5T 2026 nine-page corpus is not in `pages/`

Step 7's scope ruling: *"content comes from the existing S5T rebuild corpus only …
If any are missing or not finished HTML, STOP and output the exact list — do not
generate page content unprompted."*

`pages/` currently holds **4 files, none of which is the real corpus**:

| File | What it is | Status |
|---|---|---|
| `series-5-technology.html` | Real, contract-clean **Home**-style page (nav, hero `<picture>`, feature grid, CTA, footer, full head w/ OG + JSON-LD). | ✅ real, but it is ONE page and its slug is `series-5-technology`, not `index`/`/`. |
| `index.html` | Step-2 **placeholder stub** — `<title>S5 — Home</title>`, `canonical=https://example.com/`, one-line body "Welcome to S5". | ❌ stub, not S5T content. |
| `about.html` | Step-2 **placeholder stub** — `About S5`, `example.com/about/`, one-line body. | ❌ stub. |
| `fix-my-store.html` | Step-2 **placeholder stub** — one-line body, no head OG, `example.com`. | ❌ stub; also slug must match the LIVE URL exactly (unknown to me). |

The nine required pages (PRD §17.0 check 5 / Step 7 scope) and their presence:

| # | Page | Present as finished S5T HTML? |
|---|---|---|
| 1 | Home | ⚠️ only as `series-5-technology.html` (real content, wrong filename for `/`) |
| 2 | About | ❌ missing (stub only) |
| 3 | Commerce | ❌ missing |
| 4 | Apps | ❌ missing |
| 5 | Manage | ❌ missing |
| 6 | FAQ | ❌ missing |
| 7 | Book a Call | ❌ missing |
| 8 | Privacy | ❌ missing |
| 9 | Fix My Store | ❌ missing (stub only; live-URL slug unknown) |

**Resolution required from owner (see the STOP report):** provide the S5T 2026
corpus as finished HTML docs, and the exact live Fix My Store URL. I will not
generate this content.

---

## HIGH — Header/footer duplicated per page (no partials yet)

Decided for Step 7: shared header/footer markup duplicated per page is acceptable;
partials are Step 8. **Recorded duplication:** every page will carry its own copy
of the site header and footer markup. Import will **warn on header/footer drift**
between pages (a diff in the shared region across the corpus) so copies don't
silently diverge before the Step 8 partials system replaces them.
→ Step 8: parts/templates system; collapse duplicated header/footer to one part.

## MED — Redirect map lives in `s5.config.json`, applied via `template_redirect`

Decided: a `redirects` slug→URL (301) map in `s5.config.json`, served through a
`template_redirect` hook, covering changed old-site URLs. Not yet built (needs the
real old→new URL list from the migration). Interim: none — until the map exists,
changed URLs 404.
→ Step 7 (this step, once corpus + old-URL list arrive): populate + wire the map.

## MED — 404 handling via theme `404.php`

Decided: theme `404.php` serves an imported `404.html` page's canonical if
`pages/404.html` exists, else a minimal branded fallback. Not yet built.
→ Step 7 (once corpus arrives, if a 404 page is provided).

## MED — Analytics / favicon / OG carried in each page's `<head>`

Decided: these ride in each page's head per the Step 2 head contract; there is **no
scripts panel** (that is later). The head extractor already preserves OG/meta/
JSON-LD verbatim; favicon `<link>` and analytics `<script>` must be present in each
source page's head. **Gap:** the head extractor currently reports non-OG/canonical
`<link>` (e.g. `rel=icon`) and non-JSON-LD `<script>` (e.g. analytics) as
"unrecognized" and does NOT store them. Analytics/favicon in the head will be
dropped unless the extractor's allowlist is widened.
→ Step 7 (once corpus arrives): widen the head allowlist to carry favicon `<link>`
and analytics `<script>` through the head contract, OR confirm they belong in a
later scripts panel. **Needs a ruling** — flagged in the STOP report.

## LOW — Sitemap / robots

Decided: enable WP core sitemap and VERIFY it includes framework pages; default
`robots.txt`. Verifiable only once real pages exist. No code gap expected (core
sitemap covers published pages), but the "framework pages included" assertion is
pending the corpus.

## LOW — TidyCal embed survives canonical→render untouched

Decided: verify the TidyCal embed `<script>` + container round-trip untouched and
function in-browser. The round-trip harness already proves `<script>` blocks in the
body survive verbatim; **Book a Call** page (with the real embed) is required to
verify end-to-end. Pending the corpus.

---

## Notes on what is already solid (not gaps)

- Round-trip fidelity, idempotent import, Authoring-Contract validation, media
  resolution (attachment-backed, srcset, LCP-not-lazy, no `data-s5-*` in served
  output), canonical/derived split + revisions — all green in the single-env run.
- Anonymous cache headers: to be verified against the real served pages (check 7);
  host cache config is a deploy concern, not this repo's.
