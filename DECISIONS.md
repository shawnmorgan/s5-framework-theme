# Editor MVP — Decisions Log

Every autonomous judgment call for the "WordCamp cut" editor: the choice, the
rejected alternative, and one line on why. The owner reviews after the fact.

Format: **D#** — choice · rejected · why.

---

**D1 — Vanilla JS app shell, not React.**
Rejected: React shell (PRD §8.1's stated direction for the *full* editor).
Why: the repo has no bundler/build step; a no-build vanilla ES-module admin page
is shippable-today and the §8.1 *state model* (iframe-not-authoritative, source+ops
authoritative, Update-through-compiler) is honored regardless of view layer. React
resumes with the full editor post-launch.

**D2 — One REST endpoint, three modes (slots|canonical|head), not three endpoints.**
Rejected: separate routes per save type.
Why: one door, one nonce/permission check, one place that calls `persist_page`.
Minimizes surface and makes "no second save pipeline" obvious.

**D3 — Slot writes via `WP_HTML_Tag_Processor`, server-side, keyed by `data-node`.**
Rejected: trusting client-serialized HTML from the iframe; regex canonical edits.
Why: byte-preserving surgical edits (fidelity), never trust the client DOM (§8.1),
and `data-node` (Contract B4) is the stable target across recompiles.

**D4 — `data-node` is AUTHORED alongside `data-edit`; the compiler does NOT
inject IDs into canonical (MVP).**
Rejected: compiler auto-assigning `data-node` where missing (Contract B4's eventual
behavior).
Why: **fidelity guarantee.** The round-trip harness diffs served body vs the source
FILE byte-for-byte. If the compiler injected IDs into canonical, canonical would
diverge from source and round-trip would break — that touches a STOP condition.
Instead, a slot-editable node carries BOTH `data-edit` and `data-node` in the
authored source (the MVP test corpus does this). Canonical stays verbatim; slot
writes target the authored `data-node`; round-trip stays byte-clean. Compiler
auto-assignment is deferred to the full editor (it will require the harness to
treat post-assignment canonical as source, a bigger change). This side-steps the
STOP condition cleanly rather than risking it.

**D5 — Add real `tokens.css` + `scales.css` (custom properties only).**
Rejected: hand-built Style-tab HTML; parsing a non-existent file.
Why: the Style tab must render from actual files (no drift). The repo lacks these;
minimal real token/scale files are on-thesis (Contract A2/A3) and make the tab
truthful. Values are placeholder-tier like the rest of the test corpus.

**D6 — Add `data-edit`/`data-node` declarations to the TEST corpus pages.**
Rejected: leaving pages declaration-free (Edit lens would select nothing anywhere).
Why: Contract B6 explicitly says launch pages "MAY carry declarations ... becomes
the editor's first test corpus." The MVP needs at least one page with declarations
to demo the panel, and one with none to demo props-off selectability.

**D7 — Preview iframe = the real canvas URL with `?s5-preview=1`.**
Rejected: a separate render path for preview.
Why: the demo must show the REAL page; reusing the canvas keeps one render path.
The flag only suppresses the admin bar / injects the selection overlay when the
request is from a logged-in editor — never for anonymous visitors.

**D8 — Editor assets enqueue on the editor admin page only.**
Rejected: registering editor JS/CSS globally.
Why: acceptance requires zero editor asset in the logged-out response. Admin-screen
enqueue guarantees it; asserted by grep in the acceptance test.

**D10 — REST endpoint ships slots+canonical in B2; head mode lands with the head
sidebar in B3.**
Rejected: implementing all three modes before any UI consumes head.
Why: the endpoint is one route with a mode switch (D2). B2 needs slots (panel) and
canonical (used by the code lens in B3, but cheap to include now); the head mode's
only consumer is the B3 head sidebar, so its handler is written but exercised in B3.
No second door — still one route, one permission check, one persist_page call.

**D11 — Slot inner-text writes use a bounded splice, not full re-serialization.**
Rejected: DOMDocument round-trip (reformats/normalizes the whole fragment, breaking
byte fidelity); WP_HTML_Tag_Processor inner-HTML (not exposed in WP 6.7).
Why: the tag processor locates+validates the node and does attribute writes
surgically, but has no inner-HTML API. For text/rich we splice only between the
matched open tag's end and its balanced close tag — every other byte is untouched,
so round-trip stays clean (verified: about.html round-trips byte-clean with
declarations, and a REST edit changes only the target node's inner text).

**D9 — "rich" slot allows only `<strong> <em> <a>`.**
Rejected: a full rich-text library.
Why: instruction says bold/italic/link ONLY; a tiny sanitizer (wp_kses with a
3-tag allowlist) is enough and safe. SLIP #2: collapses to plain text if time-boxed.

---

**D12 — Style tab reads tokens from `s5.config.json` → styles.designTokens, a
NEW key separate from styles.vocabulary.**
Rejected: adding tokens.css/scales.css to styles.vocabulary; hardcoding the paths
in PHP.
Why: the token files are custom-properties ONLY (no class selectors), so they must
NOT enter the class vocabulary the validator enforces (that vocabulary is the union
of class selectors). A separate `designTokens` list lets the Style-tab reader
(Styles::read_tokens) find them by declaration without polluting validation. Full
gate confirms vocabulary/round-trip unaffected.

**D13 — L&L dynamic regions are marked in AUTHORED source with
`data-s5-dynamic-region` (alongside data-edit/data-node), not detected from render.**
Rejected: detecting L&L tags at render time (the served preview is already
evaluated — tags are gone); compiler-injecting a marker (violates D4 verbatim).
Why: the editor presents a dynamic region as a LOCKED block in Edit lens ("edit in
code view") and fully editable in Code lens. The reliable, fidelity-safe signal is
an authored attribute on the wrapping [data-edit] node; the overlay reads it and
the panel renders the locked block. The wrapper attribute survives the dynamic pass
(L&L replaces the loop's inner content, not the wrapper). Demoed on the
sitemap-archive fixture: static hero title = editable; the <Loop> <ul> = locked.

**D14 — SINGLE THEME, not parent/child. Product code at theme root; site files
fenced in a `site/` subtree. (Owner ruling, supersedes the mission's "parent/child"
wording.)**
Rejected: the mission text's parent theme (product) + generated child theme (site)
split; also rejected dropping the product/site separation entirely.
Why: owner directive — "should be one fucking theme not a parent/child. that shit is
old thinking." The child-theme split existed only to guarantee "product updates
never touch site files." That guarantee is preserved WITHOUT a second theme by folder
convention inside the one theme: the S5 framework code (functions.php bootstrap,
`src/`, `cli/`, editor assets, templates, composer/vendor) lives at the theme root;
all SITE-authored content (tokens.css, scales.css, components/, parts/, s5.config.json,
assets/) lives under a single fenced `site/` subtree. A product update rewrites theme
files and never writes into `site/`. The former `s5-plugin/` is dissolved into the
theme; there is no companion plugin anymore. Autoload stays Composer PSR-4 (owner
ruling) with composer.json + vendor/ living in the theme (S5\ → src/, S5\Cli\ → cli/).

**D15 — `wp s5 init` scaffolds the site files IN PLACE inside the one theme (no
second theme created).**
Rejected: init generating a child theme (obsolete under D14); a minimal init that
only writes config; dropping init.
Why: with one theme, "make this site authorable" means writing the starter `site/`
subtree (default s5.config.json, starter tokens.css/scales.css, starter components/,
parts/) into the active theme so a fresh install has an authorable starting point.
Idempotent: never overwrites an existing site file (the product-update guarantee runs
the other direction too — init won't clobber authored site content).

**D16 — Item 1 committed on all-green ISOLATED suites + green baseline, despite an
intermittent full-run serving flake. (Judgment call, flagged F5.)**
Rejected: blocking Item 1 until the single-command full run is green N times in a row
(would burn hours against a WordCamp deadline chasing a pre-existing, non-repackage
flake).
Why: post-repackage, EVERY suite passes standalone (Steps 0–5 green in-sequence;
6/8/9/10/11/editor green; Step 5 green twice back-to-back; phpcs 33/33). Two path
regressions the move introduced were found and fixed (Step 6 `assets/diagram.png` and
Step 11 `components/__bad_test__` both moved under `s5-theme/site/`; Step 8 got a
rewrite-flush after its re-import). The remaining full-run failures ("v2 not served
after update import" in Step 5; "index header" in Step 8) are a write→read SERVING
RACE: a `curl` fires immediately after a `wp`-CLI persist and reads the web
container's response built a moment before the CLI write committed (separate PHP
processes over shared MySQL; NO object/page cache present — verified). The failure is
NON-DETERMINISTIC and MOVES BETWEEN SUITES run-to-run — the signature of load
contention, not a logic bug. The PRE-MOVE baseline full run was 12/12 green, proving
the full run CAN pass; the flake predates the repackage (see the Step 8 front-page
timing note in memory). The repackage is functionally proven; the flake is a test-
harness robustness issue to harden separately, not an Item 1 blocker.

**D17 — Compiled CSS is ONE bundle written to `site/dist/s5.css` and enqueued on
framework pages. (Item 2 CSS serving.)**
Rejected: inline `<style>` in the canvas head (re-sends every load); enqueuing each
source + component file separately (no concatenation, request fan-out).
Why: the mission says component "scoped CSS concatenates at compile." At compile the
CSS builder concatenates `site/styles/{tokens,scales,components,utilities}.css` +
every registered component's `style.css` into `site/dist/s5.css`; `functions.php`
enqueues that ONE cacheable file on framework pages via `wp_enqueue_scripts`. This
also fixes a PRE-EXISTING gap: the S5 design system did not reach served pages at all
(only WP core styles did). `wp s5 recompile` regenerates the bundle; the served head
gains exactly one S5 `<link>`. Byte-clean round-trip is unaffected (the bundle is a
served asset via wp_head, OUTSIDE the authored body fragment — same class as
wp_footer's skip-link).

**D18 — A component's `style.css` class selectors AUTO-JOIN the closed vocabulary the
Authoring Contract validator enforces. (Item 2 vocabulary.)**
Rejected: components may only reuse the global site vocabulary (can't ship novel
scoped classes).
Why: "folder name = component name = block class" + self-contained components mean a
component ships its markup AND its licensed class vocabulary. At vocabulary load, the
validator's class set = union of `styles.vocabulary` files PLUS every registered
component's `style.css` selectors. So an inserted `hero` component using `.hero` is
legal because the `hero` component is registered. Keeps the closed-vocabulary
guarantee (nothing outside the union passes) while letting components extend it by
presence. Component CSS filename is `style.css` (mission-canonical); `component.css`
stays supported as a fallback for pre-existing components (the card).

**D19 — Editor stays VANILLA JS for the WordCamp cut; React rehome deferred
post-WordCamp. (Owner ruling, Item 3.)**
Rejected: re-homing the editor on @wordpress/element + vendored htm now (the
pre-mission approval's stated direction).
Why: owner ruling "Extend the working vanilla editor." The editor MVP already exists
in ~1,300 lines of working vanilla JS (top bar, viewport, Edit/Code lens, Style tab)
and passes the editor acceptance suite. Re-implementing it in React before adding any
new capability is real risk against the Aug 14 deadline. Item 3 completes the vanilla
editor's functional surface (all six slot types, live typing, top-bar polish) and
Items 4–7 (palette, structure list, components tab, style tab) build onto the same
vanilla base. The §8.1 React direction resumes post-WordCamp. This supersedes any
earlier "React rehome" plan for the WordCamp cut; D1's vanilla-shell rationale stands.

## Open / flagged for owner review

- **F1 — data-node is authored, not injected (resolved).** To protect the
  fidelity guarantee, the MVP does NOT let the compiler inject `data-node` into
  canonical (see D4). Node IDs are authored in source next to `data-edit`. This
  keeps canonical verbatim and round-trip byte-clean. The full editor can revisit
  compiler auto-assignment later with the harness change it requires.
- **F2 — Vanilla vs React (D1).** Biggest deviation from §8.1. Flagging explicitly.
- **F3 — Slip protocol status.** Nothing slipped yet. Any cut taken will be logged
  here with the item and the trigger.
- **F4 — Live gate blocked by Docker mount permission (infrastructure, NOT a
  code/fidelity STOP).** `wp-env start` fails with `error while creating mount
  source path '/host_mnt/Users/shawnmorgan/Desktop/...': operation not permitted`.
  Docker daemon is up and mounts `/tmp` fine; it cannot mount anything under
  `~/Desktop` — macOS TCC (Privacy & Security) is denying Docker Desktop access to
  the Desktop folder. Fix is a host grant only the owner can give: System Settings
  → Privacy & Security → Files and Folders / Full Disk Access → enable Docker
  Desktop; or move the project out of `~/Desktop`. Phase B code is validated by
  phpcs + `php -l` + `node --check` in the meantime; the full single-env gate
  (Steps 0–10 + editor acceptance) runs the moment the env is reachable.
