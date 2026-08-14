# S5 Fidelity-Repair — Acceptance

The full deliverable set for the fidelity-repair mission (make the shipped UI match the
mockups exactly and make every interaction work in a real browser). All UI verification
was done via Playwright driving a real browser against a real WordPress install
(WP Studio site `s5-audit`, localhost:8882) — never the repo-mounted wp-env; the wp-env
automated gate is the separate, still-required regression gate.

---

## Fix list — status

| Item | What | Status |
|---|---|---|
| 1 | SAVE worked end-to-end in the browser (root cause: non-editable corpus, not the handler) | ✅ proven: edit → Save → served page changes → revisions |
| 2 | Canvas chrome transcribed VERBATIM from canvas-mockup.html; every control functions | ✅ all surfaces; historical-failure surfaces (Save · Disable · Code-lens) re-tested |
| 3 | Settings UI rebuilt to theme-settings-mockup.html — 7 screens in mockup order | ✅ all 7 + component builder, wired to real data |
| 4 | Appearance regression (Patterns/Customize/Fonts) removed; rendered-menu gate hardened | ✅ root cause D36; rendered menu = Themes + Theme File Editor only |
| 5a | convert_page() WRAPS content (not scaffold); revision-before-write; restore un-adopts | ✅ proven on real install + dist-suite test |
| 5b | bare `wp s5 import` resolves from any CWD | ✅ proven from /tmp |
| 5c | full text-scale-* / space-top/bottom-* ladders → axes light up | ✅ ui_axes AVAILABLE |
| 5d | loud door: zero-write slots → 400; anchor-less insert never after footer | ✅ both proven |
| 5e | style.css 0.9.0 + installed-audience description | ✅ |

## Standing order (anti-improvisation) — honored

Every surface was TRANSCRIBED from the mockup (its markup, classes, `--s5e-*` tokens, FA
glyphs, CSS ported verbatim), then real data wired into that markup. Every place the data
couldn't back what the mockup drew is a logged GAP implemented from the mockup's own
tokens/components — never an invented visual. Drift was reported and fixed the moment it
was found (missing pen button, blank code lens, 3 PHP `?>` leaks, heading-color bleed).

---

## Acceptance deliverables

### A. Fidelity matrix
`docs/ui-fidelity/<surface>.png` — 18 side-by-side composites (mockup left, shipped right):
10 canvas surfaces + 8 settings views. Index: `docs/ui-fidelity/FIDELITY-MATRIX.html`.
Deviations documented in `docs/ui-fidelity/FIDELITY-EXCEPTIONS.md` (all GAPs where the data
can't yet back the mockup, or WP-admin constraints — never invented visuals).

### B. Clicked DEMO.md walkthrough
`docs/demo-walkthrough/real-browser/` — every DEMO.md step performed as a real Playwright
click against the real install, one screenshot per step, results in `RESULTS.md`.
Includes the Save round-trip (served page shows the new text) and disable-drops-from-served
(tree row red-lined + text removed from the served render). 10/10 steps passed.

### C. Automated gate + distribution suite — ✅ GREEN
`npm test` from the wp-env mirror — clean `destroy → start → all suites` (mandatory full
run, not SKIP_DESTROY, because 5a is compiler/storage-touching). **ALL SUITES PASSED
(single-environment clean-slate run), GATE_EXIT=0.** All 13 step suites + the distribution
suite (both scenarios: fresh site + site-with-content, incl. the 5a adopt/restore checks) +
Phase A (hardened Appearance rendered-menu gate) + Phase B Disable + Style class-swap +
Phase C Roles. Full results: `docs/ui-fidelity/gate/full-clean-gate-results.txt`.

Three suites went red on the first clean run — investigated each against the real install
before touching anything; none was a runtime regression (D38): two were stale assertions my
intentional changes invalidated (the Item 3 sub-nav attribute; the Item 5c size axis, now
available), and one was a pre-existing test bug the clean run exposed (the Disable eval never
set a user, so the Phase-C-4b structure role gate refused it). Fixed the tests, re-ran clean
→ green. The Phase A §7 Appearance assertions were moved into a standalone `eval-file` probe
(`tests/lib/appearance-menu-probe.php`) because multi-statement eval-in-`$()` was fragile
under `set -euo pipefail`.

### D. Rebuilt artifact — ✅ built (560K)
`dist/s5-theme.zip` — rebuilt from the source that passed the green gate; the artifact
everything above was verified against. Bundles FontAwesome (the mockups' icon system), the
settings + editor JS the reskinned UIs depend on, the freshly-compiled `site/dist/s5.css`
(with the 5c ladders), version 0.9.0, and the installed-audience description — no dev cruft.
build-zip.mjs release verification asserts every critical asset, so a dropped one fails the
build loudly. Installed fresh into a clean WordPress and confirmed it carries every asset.

---

## Decisions recorded this pass
D33 (env) · D34 (Item 1 root cause) · D35 (settings rebuild + plumbing-adapts calls) ·
D36 (Appearance root cause + gate hardening) · D37 (carry-over fixes a–e). See `DECISIONS.md`.
