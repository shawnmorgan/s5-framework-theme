# CC PROMPT — Realign to PRD v1.6 and build the v1 remainder

Paste this as a single task. Work in phases; do not skip the alignment audit.

---

You are continuing the S5 agent-native WordPress theme. The architecture was
extended after WordCamp (PRD v1.5 → v1.6) with four new companion specs. Your
job: (1) confirm the current build still matches the ratified invariants, (2)
realign anything the new specs make stale, (3) build the v1-required new work in
dependency order. The page canvas stays protected throughout — all new
sophistication lives in the settings control plane.

## Read first (these are the contract)
- PRD-Agent-Native-WordPress-Framework-v1_6.md (master — note the v1.6 changelog)
- S5-TEMPLATES-SPEC-v0.1.md (v1 LAUNCH REQUIREMENT — the theme must render a blog)
- S5-ATOMIC-DESIGN-SPEC-v0.1.md (v1.x + v2 — read for the Styles/Elements model)
- S5-PLUGIN-EXTENSIBILITY-SPEC-v0.1.md (tiered — read the component-kind schema)
- S5-DYNAMICS-SPEC-v1.5.md (Templates v0 is the front edge of this)
- Authoring-Contract-v0.1.md, DECISIONS.md, HANDOFF docs in repo

## Standing rules (unchanged, inviolate)
- One save door for every write: validate → persist → recompile → revision.
- HTML is canonical; compile on write, never on read except flagged dynamic passes.
- Loud validation; closed vocabulary; no free classes/CSS/inline styles at the door.
- Editor renders declarations only. The PAGE CANVAS stays light-touch; do not add
  authoring capability to it. All new UI in this task is SETTINGS-side.
- Control plane (settings) is fully editable by humans AND agents. Do not make any
  settings surface read-only.
- Every UI change is verified in a REAL browser (Playwright) on a REAL install
  (WP Studio/Local, or `wp core download` + php -S — never repo-mounted wp-env for
  UI verification). Build gate (wp-env) still runs and must stay green.
- Fidelity matrix + clicked walkthrough are permanent acceptance for any UI phase.
- Build hygiene: the zip excludes .DS_Store/__MACOSX/.git/node_modules; the build
  step ends by installing its own zip and smoke-testing it (an onclick save must
  return 4xx on the installed site).

## PHASE 0 — Alignment audit (report before building)
Confirm against v1.6, do not fix yet — produce ALIGNMENT.md:
1. Does the current component model already carry a `kind` concept? (v1.6 §14.8
   needs html|shortcode|block.) If not, note it as the foundational add.
2. Is the Styles/settings surface structured as tokens→scales→elements (v1.6 §8.5),
   or is it flat? Note the gap.
3. Is there an Elements/atom registry surface at all? (v1.6 §7.4) Likely absent —
   note as v1.x work.
4. Template hierarchy: does the theme resolve single/page/archive/search/404 to S5
   templates today, or only render flat pages? (S5-TEMPLATES §2) This is the
   biggest v1 gap — quantify it.
5. Any place the current build makes a SETTINGS surface read-only (contradicts
   v1.6 §2.4)? Flag each.
6. Confirm the v0.14.1 clean-zip + the five prior fixes (XSS on*, roles enforcement,
   responsive CSS, dirty-state, bare-import) are present on the CURRENT branch and
   covered by suites that run in `npm test`. (This was a prior divergence scare —
   re-confirm on a clean zip install, not a repo copy.)
Report ALIGNMENT.md and stop for my review before Phase 1.

## PHASE 1 — Templates + native baseline dynamics (v1 LAUNCH REQUIREMENT)
This is the priority. The theme is not shippable until it renders a blog.
Per S5-TEMPLATES-SPEC-v0.1:
1. Template hierarchy resolution: single/page/archive(all main-query variants)/
   index/search/404 → S5 canonical-HTML templates (parts + content region).
2. Templates settings section: register + condition-assign templates; author via
   the existing `?template=` editor mode (never a second editor). Complete the
   template-parts UI (header/footer/nav/shared regions as `<s5-part>`).
3. Dynamics v0 — the minimum native binding, scoped HARD to the baseline:
   - `s5-field`: current-post CORE fields only (title, content, excerpt,
     featured-image, date, modified, author, permalink). No ACF/meta adapters.
   - `s5-loop`: the main query, paginated, with `<s5-empty>`. Covers index, all
     archives, search — same loop code, WP resolves which posts.
   - the core-field registry these bind against (this registry IS the Dynamics
     seed — build it to extend later, ship it core-only now).
   - rides the existing dynamic-pass seam; static pages stay byte-clean.
4. Comments: render WP comment list + form in the single template (recommended
   in-scope per the bar; confirm with me if you hit real cost).
5. Acceptance: create a post → it renders on a single template with real title/
   content/image. Create 3 posts → the blog index and a category archive loop and
   paginate. Search returns results via the loop; empty search shows `s5-empty`.
   404 works. All via Playwright on a real install. Round-trip harness green on a
   static page (templates don't break page fidelity). Full gate green from clean.

## PHASE 2 — Component-kind schema (foundational for plugin extensibility)
Per S5-PLUGIN-EXTENSIBILITY-SPEC-v0.1 §7:
1. Component manifest gains `kind`: html | shortcode | block.
2. Resolution pass branches on kind: html → stamp (today's behavior); shortcode →
   render via the shortcode pass; block → `render_block()` with attributes.
3. Tier 1 (Embed atom): verify the editor preview renders shortcode OUTPUT, not
   raw `[shortcode]` text. Fix if it shows raw text.
4. Tier 2 (registered plugin component): prove one — a shortcode-body component
   with a param exposed as a prop (a simple form plugin or a synthetic test
   shortcode). Inserts from palette, prop editable, renders on the served page.
5. Do NOT build Tier 3 block-injection islands or the theme.json bridge in this
   task — those are the Woo track (separate, pending Woo-team answers on IAPI
   block coverage + caching). Just land the schema + kind branch + Tiers 1–2.
6. Acceptance: a shortcode-kind component round-trips (insert → prop edit → save →
   served output correct); the html-kind path is unchanged; gate green.

## PHASE 3 — Styles tab three-part structure (v1.x, if time; else report + defer)
Per S5-ATOMIC-DESIGN-SPEC-v0.1 §4 + v1.6 §8.5:
1. Restructure the Styles settings screen to tokens → scales (type/spacing/radius)
   → element styles as a visible hierarchy, wired to the real files, fully editable.
2. Do NOT build the full element-atom registry, the Component Builder primitive
   canvas, or slot-forwarding — those are v2. This phase is only the editable
   three-part Styles view + surfacing the registered elements list read/edit.
3. If Phase 1+2 consume the budget, STOP after Phase 2, report, and leave Phase 3
   for the next task. Phase 1 is the launch blocker; Phase 3 is polish.

## Deliverables
Per phase: ALIGNMENT.md (Phase 0) · code + DECISIONS.md entries for judgment calls
· Playwright walkthrough screenshots on a real install · full gate green from clean
· rebuilt clean zip (cruft-excluded, self-smoke-tested), re-tagged. Report at each
phase boundary; do not roll into the next phase without the current one green.

Do not report Phase 1 done until a post renders on a single template and a blog
archive loops+paginates, both clicked in a real browser.
