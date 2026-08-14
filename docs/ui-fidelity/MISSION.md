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
- [ ] Item 3: settings rebuilt to mockup  ← /compact before, /compact after
- [ ] Item 4: Appearance cleanup + rendered-menu gate
- [ ] Item 5a–e: carry-over fixes
- [ ] Acceptance A: fidelity matrix + exceptions
- [ ] Acceptance B: clicked walkthrough
- [ ] Acceptance C: full gate + dist suite green from clean
- [ ] Acceptance D: rebuilt zip
