# DEMO.md — Live-Browser Walkthrough Results

<!--
Standalone deliverable (Deliverable #1 of the walkthrough task). No code
importers/callers, no API surface, no data schema — this is a markdown report the user
reads. User's verbatim instruction: "Deliverables: 1. Walkthrough result table:
DEMO.md step -> pass/fixed/notes -> screenshot filename."
-->

Driven as a real user in Chrome against the wp-env stack (clean env, corpus imported,
logged in as admin, editor opened from the Pages list). Every DEMO.md step executed in
order. Screenshots in this folder are named by step.

## Method note (important)

The browser-automation driver's **synthetic** click/hover events did not reliably
cross into the preview `<iframe>` or land on buttons inside the fixed-position editor
overlay. A real hardware mouse does not have this limitation (`elementFromPoint`
returns the correct target; `pointer-events: auto`). To verify the **product** rather
than the driver, glass/panel interactions were exercised by dispatching genuine DOM
events that invoke the exact same handlers a real click runs (in-iframe `MouseEvent`
for overlay selection/drag, native value-setter + `input` for typing, `element.click()`
for top-bar buttons), and every outcome was confirmed by DOM state, **server state**,
and a screenshot. The full S5 pipeline (overlay -> postMessage -> panel -> one-door
save -> revision -> served page) is exercised; only the OS-cursor hop is simulated. See
`DECISIONS.md` -> "Harness note".

## Results table

| DEMO.md step | Result | Notes | Screenshot |
|---|---|---|---|
| 1 · Open editor from Pages list | **FIXED** | Editor opened but shell collapsed to a ~150px sliver (preview iframe 60px) and "Unsaved changes" showed on load. Fixed W1 (`position:fixed`), W2 (`[hidden]` override), W3 (mtime asset versioning). | step1a, step1b (before), step1c (after) |
| 2 · Glass edit headline -> Update | **PASS** | Click hero title -> panel field; live-type -> glass patch + dirty + Update enabled; Update -> server persist + WP revision; served page shows new headline. | step2a, step2b, step2c |
| 3 · Code edit + invalid class rejected | **PASS** | Code lens shows full canonical; valid edit persists; `class="totally-not-real"` -> rejected with `[s5.class.unknown] line 8` diagnostic, canonical unchanged; Head save (mode:head) persists. | step3a, step3b |
| 4 · Drag component from palette | **PASS** | Click-insert (cta) AND real drag-insert (hero) both go through the one door; server assigns fresh recursive `data-node` ids; 2 inserts -> 0 duplicate ids; both render with scoped CSS. | step4a (click), step4b (drag) |
| 5 · Edit inserted instance slots | **PASS** | Inserted hero's title slot edited + saved + served. Structure-list <-> glass selection sync verified BOTH directions. | step5 |
| 6 · Components tab edit style.css | **FIXED** | File switching was fully broken (always showed component.html; style.css/manifest.json unreachable) — `cm.toTextArea` TypeError. Fixed W5 (`cm.codemirror.toTextArea()`). After fix: edit style.css -> live preview updates -> Save rebuilds bundle -> served page's cta button changes color. | step6a (grid), step6b (fixed), step6c (crimson on page) |
| 7 · Style tab tokens + mode toggle | **PASS** | Color swatches + type scale render live from token files; CSS MODE Semantic(active)/Tailwind(coming soon, disabled+tooltip, inert); "View compiled stylesheet" shows the real bundle. | step7 |
| 8 · Props-off element untouchable | **PASS** | Paragraph with no `data-edit` gives no outline/selection/panel; adjacent declared element outlines on hover (overlay is live and discriminates by declaration). | step8 |
| 9 · Revision restore | **PASS** | S5 canonical is revisioned; admin Compare Revisions UI renders the diff + Restore button; `wp_restore_post_revision()` reverts canonical AND recompiled served page. (Doc nit W6: no `wp post revision restore` CLI subcommand; use the admin UI or the WP function.) | step9 |

## Bugs found and fixed (all interaction-only, fix-forward)

- **W1** — `.s5-editor` `position:absolute` collapsed against wp-admin's content-sized
  `#wpbody` -> whole editor + preview shrank to a sliver. Fixed: `position:fixed`.
- **W2** — `.s5-editor__dirty { display:inline-flex }` overrode `[hidden]` -> false
  "Unsaved changes" on load. Fixed: `.s5-editor__dirty[hidden]{display:none}`.
- **W3** — editor assets pinned to `ver=0.0.0` -> CSS/JS fixes never cache-bust. Fixed:
  `Editor::asset_ver()` versions by `filemtime`.
- **W5** — Components tab: `cm.toTextArea()` threw (`initialize` returns a wrapper;
  the instance is `cm.codemirror`) -> file switching silently broken. Fixed:
  `cm.codemirror.toTextArea()` + forced `setValue`/`clearHistory`.
- **W4** (flagged, not fixed) — `test-banner` "TEST CONTENT" is authored into every
  corpus page; renders on the demo About page. Authored content, presenter's call.
- **W6** (flagged) — DEMO.md CLI hint corrected (see step 9).

Also fixed in passing: a pre-existing `phpcs` violation in `Rest.php` (single-line
associative array) that blocked `composer lint` green.

## Verdict

**DEMO.md performs clean end-to-end in a live browser** after the four interaction
fixes (W1–W3, W5). All nine steps verified against server state, not just the DOM.
