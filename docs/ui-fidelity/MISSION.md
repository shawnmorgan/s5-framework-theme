# FIDELITY-REPAIR MISSION (supersedes prior Phase A/B/C "done" claims)

**Standing law:** `docs/ui-reference/canvas-mockup.html`, `theme-settings-mockup.html`,
`HANDOFF.md` are the CONTRACT. I implement them verbatim. Any invented icon, layout,
color, component = defect. "I preferred it" is never a reason.

## Verification environment (DECIDED)
- Fresh **WP Studio** site `s5-audit`. NOT wp-env (:8888 is Docker/wp-env = forbidden).
  NOT manna-church (Studio) / silva-management (Local) — unrelated projects, untouched.
- **Playwright own headed Chromium**, `--user-data-dir=/tmp/s5-audit-profile`. Never user's Chrome.
- "Verified" = Playwright clicked it, expected result happened, screenshot exists.
  Synthetic events / direct REST calls DO NOT count as UI verification.
- wp-env automated gate still must stay green — necessary, no longer sufficient.

## FIX LIST
1. **SAVE broken in browser.** REST door works server-side; JS Save wiring does not.
   Diagnose (nonce / unbound handler after rebuild / dirty-state gating). Prove via
   Playwright: edit headline → Save → reload SERVED page → assert change → revision exists.
2. **DEAD CONTROLS.** Every canvas-mockup control performs its function OR renders the
   mockup's exact disabled/coming state. Walk every toolbar btn, tab, drawer, slider,
   palette tile, tree row, viewport toggle, lens switch — click, verify, screenshot.
3. **INVENTED SETTINGS UI → rebuild to theme-settings-mockup.html.** Same layout regions,
   same left subnav + SEVEN screens in mockup order (general, templates, styleguide,
   components, css, integrations, roles), same --s5e-* tokens, same components, same icons.
   Icons FROM mockups (FontAwesome, bundled locally) — invent zero. Same for canvas chrome.
   [/compact BEFORE item 3. /compact BETWEEN item 3 and item 5.]
4. **APPEARANCE REGRESSION.** Patterns + Customize visible again under Appearance.
   Re-apply Phase A cleanup, find why a later phase reverted it, harden gate to assert the
   RENDERED admin menu (absence in actual admin output), not existence of removal code.
5. **CARRY-OVER AUDIT FIXES (same pass):**
   a. Seeder::convert_page() WRAPS existing post_content as canonical body in the part-framed
      shell — never replaces with starter scaffold. Revision BEFORE first framework write;
      served page identical after adoption; restore returns pre-adoption. Suite test.
      starter_canonical() remains only for Add S5 Page.
   b. Bare `wp s5 import` resolves config pagesSource against site root from any CWD.
   c. Ship full text-scale-* and space-top/bottom-* ladders (xxs–xxxl, wired to scale custom
      props) in starter utilities.css so size/spacing axes light up out of the box.
   d. Loud door: slots mode zero effective writes → 400 + diagnostics; anchor-less insert →
      400 or inserts inside main content, never after footer part.
   e. style.css → version 0.9.0; theme description rewritten for installed audience (no repo refs).

## ACCEPTANCE (has teeth)
- **A. FIDELITY MATRIX:** docs/ui-fidelity/<surface>.png side-by-side (mockup left, shipped right)
  for: toolbar, palette, props tabs (Content/Style/Advanced), structure tree, code lens,
  both gear drawers, selection chrome, all 7 settings screens. Every deviation fixed OR listed
  in FIDELITY-EXCEPTIONS.md w/ one-line reason (legit: WP-admin constraint / data unavailable /
  HANDOFF-deferred only).
- **B. CLICKED WALKTHROUGH:** full DEMO.md via Playwright in the real install, screenshot per
  step → docs/demo-walkthrough/real-browser/. Unclickable step = failure.
- **C.** Full wp-env gate + distribution suite green from clean.
- **D.** Rebuilt dist/s5-theme.zip is the artifact all above verified against.

Deliver: fidelity matrix + exceptions file, clicked-walkthrough screenshots, gate output,
DECISIONS entries per judgment call, rebuilt zip. NOT done until Playwright clicked Save in a
real browser and watched the served page change.

## STANDING ORDER — anti-improvisation (LAW, applies to every surface)
1. TRANSCRIBE, don't interpret. Open the mockup file, COPY its actual markup structure, class
   names, CSS values into the theme, then wire real data into that markup. Porting existing code,
   not building UI that resembles it. Writing NEW css values / icons / layouts / component shapes
   not in the mockup = the defect this task exists to fix → STOP.
2. GAP PROTOCOL. Mockup doesn't cover it → it's a GAP not a design opportunity. Implement the
   minimum neutral version using ONLY tokens/components/patterns already in the mockups. Log in
   FIDELITY-EXCEPTIONS.md as "GAP: <what> — used <existing pattern>" AT THE MOMENT, not at end.
   Never invent a visual element; compose from what the contract contains.
3. SELF-CHECK each surface before the next: mockup + build side by side in browser, diff by eye,
   fix before continuing. No banking fidelity debt for the acceptance pass.
4. Report drift honestly + immediately. Finding my own drift is fine; user finding it is not.

## PROGRESS LOG (update as I go — survives compaction)
- [x] ENV: fresh Studio site s5-audit up (localhost:8882, admin/s5audit), zip installed+activated,
      9 framework pages seeded, Playwright headed Chromium proven (00-editor-smoke.png). D33 logged.
      Observed defects on smoke: text-only toolbar (no FA icons), letter-only palette tiles,
      empty structure tree, placeholder scaffold content (ties to Item 5a).
- [~] Item 1: SAVE — DIAGNOSED + PROVEN WORKING (not the handler).
      Root cause of "Save broken": the seeded DEMO CORPUS pages (site/pages/*.html) carry
      ZERO data-edit/data-node anchors → nothing selectable → nothing dirty → Save stays
      permanently disabled. On a page WITH anchors (New S5 Page, starter_canonical), the
      full chain works: select page-eyebrow → edit → Save → POST /s5/v1/pages/27/save
      {mode:slots} → 200 {ok:true} → canonical persisted ("EDITED HEADLINE 20260814") →
      2 revisions. Playwright-proven, shot 01-save-repro.png.
      FIX APPLIED: removed dead empty click handler in editor.js (was a 2nd listener on the
      same [data-s5-update] button — the classic "looks wired, does nothing"); panel.js is
      now sole owner of the save click.
      RESOLVED: authored data-edit/data-node into all 9 corpus pages (user chose source-author
      over server-inject). `wp s5 validate` → 9 valid, 0 warn. Re-seeded. PROVEN on fix-my-store
      (post 15): 12 editable nodes → edit → Save → 200 → canonical persisted → LOGGED-OUT
      front-end shows new text → 3 revisions (shot 01b-save-corpus.png). Demo text restored.
      D34 logged. NOTE: seed-content change → needs full clean gate + dist suite before ship.
      [x] Item 1 COMPLETE.
- [~] Item 2: canvas chrome TRANSCRIPTION (reframed per standing order — not "walk", but port).
      DONE: (1) FontAwesome 6.5.1 Free bundled locally at assets/vendor/fontawesome (css + woff2
      solid/regular/brands, ~400KB), enqueued in Editor.php as s5-fontawesome dep of editor.css;
      fullscreen doc prints it via wp_print_styles(). FA confirmed loading in browser (computed
      font "Font Awesome 6 Free"). (2) Added mockup token-name aliases --s5e-bg/-2/-3 to
      editor.css :root so verbatim mockup CSS resolves. (3) TOOLBAR transcribed verbatim from
      canvas-mockup.html (markup classes .tb/.tb-icon-btn/.vp/.lens/.tb-save + exact FA glyphs;
      data-s5-* hooks grafted on; editor.js aligned to mockup classes active/.open + dirty-dot
      display toggle). CSS ported verbatim (mockup lines 85-174), old .s5-editor__tb* removed.
      Side-by-side eye-diff (02-toolbar-shipped.png vs 02-toolbar-mockup.png): MATCH except
      pen "Custom width" btn was missing → ADDED (mockup ships it inert) + logged GAP in
      FIDELITY-EXCEPTIONS.md.
      REMAINING canvas surfaces to transcribe: left palette⇄props (Content/Style/Advanced),
      structure tree, code lens, both gear drawers, selection chrome. Then /compact → Item 3.
      [x] Item 2 COMPLETE — ALL canvas surfaces transcribed verbatim + browser-verified.
      DONE (this session):
      • CSS: replaced ALL invented .s5-editor__* body/panel/palette/props/stage/tree/drawer rules
        with the mockup's VERBATIM CSS (.body/.panel/.palette/.props/.stage-wrap/.codeview/.tree/
        .drawer/.scrim + fields/seg/step-slider/swatch/enable-row/switch/media-field/dim-group/
        cond-*/og-*/chip/schema-code). TOKEN HYGIENE: collapsed to ONE vocabulary — the mockup's
        --s5e-bg/-2/-3/line/line-soft/text*/accent*/brand*/danger*/warning/radius*/topbar-h; the
        invented aliases (--s5e-surface*/border*/row-*/accent-bright|hover|tint/sidebar/bar/
        success*/gap-*) were DELETED. `grep var(--s5e-…` = single clean set.
      • template editor.php: body region rebuilt to mockup .body/.panel.left(.palette⇄.props)/
        .stage-wrap(.stage-scroll⇄.codeview)/.panel.right(.tree). data-s5-* hooks grafted on.
      • editor-drawers.php: BOTH drawers rebuilt to mockup .drawer/.scrim with full Page Options/
        Meta&SEO/Schema + Display&Conditions (cond builder), exact FA section icons; head-form
        hooks (data-s5-head*, data-s5-head-save, data-s5-head-seg) preserved.
      • JS emit-sites rewired to mockup classes: palette.js (.pal-item + FA glyph map + groups),
        panel.js renderFields (per-kind .field markup + .enable-row; Button Text→text slot, Link→
        href — both server-valid), structure.js (.tnode/.trow + kind glyphs + .row-disabled),
        editor.js (drawer .open, props .active/.hidden, ptab .active), overlay.js (mockup
        .sel-tag/.sel-act + FA injected into iframe via cfg.faUrl), codelens/styleaxes/advancedtab/
        stylestab/componentstab (class renames to mockup or s5e-* GAP classes). ZERO s5-editor__ left.
      • Editor.php: added faUrl to both S5Editor localize blocks (page + template mode).
      BROWSER-VERIFIED on s5-audit post 15 (Playwright headed, docs/ui-fidelity/_shots/03-13):
        palette 14 tiles · tree 12 rows · select→props active + sel-chrome 3 FA tools · Style tab
        (real axes + token specimen) · Advanced · viewport tablet active · Code lens renders +
        line-numbered canonical · Tools menu · Page drawer + scrim (head fields pre-filled).
      HISTORICAL-FAILURE RE-TESTS (all pass): (1) SAVE round-trip → served logged-out render shows
        new text. (2) DISABLE → tree row red-lined + text DROPPED from served render. (3) CODE-LENS
        save → edit persisted after reload. GAPs logged in FIDELITY-EXCEPTIONS.md at the moment.
      Corpus restored via `wp s5 import <pages-dir>` (bare form fails — that's Item 5b).
- [x] Item 3: settings rebuilt to theme-settings-mockup.html — COMPLETE, browser-verified.
      SCREENS order fixed → mockup order (general·templates·styleguide·components·css·integrations·roles).
      settings.css: mockup CSS ported VERBATIM, scoped under `.s5set` (dropped the mocked WP admin
      bar/menu — WP provides the real ones; `.s5set-page` fills the wp-admin content area). FA bundled +
      enqueued. Settings.php render_screen → mockup `.page-head`/`.subnav`(.snav)/`.screen-wrap`(.screen);
      each screen rebuilt to mockup markup wired to REAL data: General (identity read-only→WP core,
      redirects, build/deploy), Templates (real parts + hierarchy GAP "Using default"), StyleGuide
      (real tokens + inline real-token style so specimen never drifts + one-door token save), Components
      (registry cards + mockup glyphs + REST-driven `.cb-*` builder), CSS (layer tabs+lock+engine toggle,
      REAL file contents, Tailwind honest coming-soon), Integrations (real save form in mockup `.int-card`
      + add-clone template), Roles (role cards + `.perm-mark-btn` mirroring hidden checkboxes + users).
      settings.js: subnav toggle, switches, redirects, css layers, integrations add/del/switch/conditions,
      perm toggles, user-role colouring. components.js aligned to `.cb-modal`.open + input name + slug.
      DRIFT FOUND+FIXED in-flight: 3 PHP `?>`-leak defects (Components $glyphs, Roles $role_meta,
      Integrations $icon_for arrays emitted as text); wp-admin heading-color bleed neutralized under .s5set;
      cb-modal z-index raised above wp chrome. BROWSER-VERIFIED (docs/ui-fidelity/_shots/s3-*): all 7 screens
      render to the mockup; INTERACTIONS pass — add-integration clones a .int-card, int switch mirrors hidden
      enabled, Tailwind engine disabled (coming-soon), css layer switch, roles perm toggle flips hidden
      checkbox, component builder opens with real REST data, token-edit save round-trips (install token file
      changed then restored from clean repo source). GAPs logged in FIDELITY-EXCEPTIONS.md; D35 in DECISIONS.
      [/compact AFTER item 3, before item 5 — user-ordered.]
- [x] Item 4: Appearance cleanup + rendered-menu gate — DONE. Root cause: remove_submenu_page()
      called with stale EXACT slugs while WP registers Patterns/Customize with query strings
      (site-editor.php?p=/pattern, customize.php?return=…) + Fonts as font-library.php → every call
      silently no-oped; old gate only checked the removal-target CONSTANT (code-exists). Fix:
      remove by PREFIX against the real registered $submenu['themes.php']; Fonts added to removal +
      block lists. Gate §7 hardened to a removal-EFFECT test (seed real slugs + run remover; plus a
      bootstrapped rendered-menu check). Authoritative rendered-menu assertion = Playwright real HTTP.
      Verified: rendered submenu = Themes + Theme File Editor only; direct URLs 302 to Themes. D36.
- [x] Item 5a–e: carry-over fixes — ALL DONE, each proven on the real install. (a) convert_page WRAPS
      content (adopt_canonical), revision-before-write, Storage::clear_page + on_restore un-adopt on
      empty canonical → restore returns pre-adoption; starter_canonical only for Add-S5-Page; dist
      step 4 extended. (b) pages_root() absolute (installed theme site/pages) → bare import from any
      CWD. (c) text-scale-*/space-top-*/space-bottom-* ladders (24 classes) in live + starter
      utilities.css → axes AVAILABLE. (d) slots zero-effective-writes → 400 s5_no_effective_slots +
      diagnostics; anchor-less insert splices before </main> not after footer, no-main → 400 s5_no_main.
      (e) style.css 0.9.0 + installed-audience description, no repo refs. D37.
- [x] Acceptance A: fidelity matrix — 18 side-by-side composites (10 canvas + 8 settings) at
      docs/ui-fidelity/<surface>.png + FIDELITY-MATRIX.html; FIDELITY-EXCEPTIONS.md finalized.
      Caught + fixed a specimen heading-color bleed while composing. Committed 5de5a2b.
- [x] Acceptance B: clicked DEMO.md walkthrough — docs/demo-walkthrough/real-browser/, 10/10 real
      Playwright clicks (incl. Save round-trip to served + disable-drops-from-served) + RESULTS.md.
      Committed 65b7121. ACCEPTANCE.md summary written.
- [x] Acceptance C: full clean wp-env gate + dist suite (destroy→start→all, no SKIP_DESTROY) —
      GREEN. "ALL SUITES PASSED (single-environment clean-slate run)", GATE_EXIT=0. All 13 step
      suites + the Distribution suite (both scenarios, mount-free, incl. the 5a adopt/restore
      extensions) + Phase A (hardened Appearance rendered-menu gate) + Phase B Disable + Style
      class-swap + Phase C Roles. Results: docs/ui-fidelity/gate/full-clean-gate-results.txt.
      Path: run 1 = 2 stale assertions my intentional changes invalidated (Phase A subnav attr;
      Style axis-absent — 5c makes it available) + 1 pre-existing test bug the clean run exposed
      (Disable eval never set a user → the Phase-C-4b structure role gate refused it). Run 2 = my
      own $NAVCOUNT pass-message typo (set -u). Run 3 = moved the Phase A §7 Appearance assertions
      into a standalone eval-file probe (multi-statement eval-in-$() was fragile under
      set -euo pipefail) → all green. D38. Commits 31252fc, 426b891.
- [x] Acceptance D: dist/s5-theme.zip rebuilt (560K) from the source that passed the green gate —
      bundles FontAwesome + settings/editor assets + the freshly-compiled site/dist/s5.css (with the
      5c ladders) + version 0.9.0; no dev cruft; release verification asserts every critical asset.
      Installed fresh into clean WP and confirmed it carries every fidelity asset. THE MISSION IS
      COMPLETE.
