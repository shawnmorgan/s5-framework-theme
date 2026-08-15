# Fidelity Exceptions

Deviations from the mockups that are NOT defects. Legit reasons only: WP-admin constraint,
data unavailable, explicitly deferred in HANDOFF, or a GAP the mockup never drew (implemented
as the minimum neutral version from existing tokens/components/patterns).

Logged at the moment each is hit, per the standing order.

---

## Canvas toolbar

- **GAP: viewport "Custom width" (pen) button — used mockup's own inert button.**
  canvas-mockup.html line 602 draws a 4th `.vp-btn` with `fa-pen` / `title="Custom width"` but
  wires NO behavior to it (no `data-vp`, no JS). Transcribed verbatim as present-but-inert,
  exactly as the mockup ships it. No custom-width interaction exists in the contract to wire.

## Left palette

- **GAP: palette tile glyphs for names the mockup didn't draw — used the mockup's own glyph set.**
  The mockup (lines 844-874) draws tiles for Hero/CTA/Feature Grid/Pricing/Testimonial/FAQ/Card/
  Rich Text/Media/Button/Query Loop/Nav Menu/Embed with specific FontAwesome glyphs. The real
  registry ships hyphenated component names (faq-item, pricing-card). palette.js maps every
  registry name to the mockup's own glyph for that concept (faq-item→fa-circle-question,
  pricing-card→fa-tags, …); any name the mockup never drew falls back to the mockup's neutral
  `fa-cube`. No invented icon — only the mockup's set, plus its generic component glyph for gaps.

- **GAP: palette groups — one honest "Components" group, not the mockup's 4 fabricated buckets.**
  The mockup groups tiles into Sections/Content/Dynamic/Primitive. The real registry is FLAT
  (no section/content/dynamic taxonomy in the data), so palette.js renders one "Components" group
  + the "Primitive" group for Embed — using the mockup's exact `.palette-group`/`.palette-label`/
  `.palette-grid` markup. Inventing 4 buckets the data can't back would be fabrication; the mockup's
  own component/primitive split is preserved.

## Props panel — Style tab

- **GAP: Style tab renders the theme's REAL style axes, not the mockup's illustrative controls.**
  The mockup (lines 900-948) draws a representative Style tab: Variant seg, one Text Size slider,
  a linked Spacing dim-group, a swatch-row. The real Style tab renders the theme's actual
  `styleAxes` manifest (alignment, text-size, space-top, space-bottom) using the mockup's OWN
  components — `.field`, the `.step-slider` dashed-ladder, `.seg.wide`, and the read-only token
  swatch specimen. Axes the theme's vocabulary doesn't back yet show the honest "add one in
  Styleguide to enable" state rather than a fake control. Data-driven difference, mockup components.

- **GAP: CSS-mode switch (Semantic / Tailwind·soon) — composed from the mockup `.seg` pattern.**
  The mockup never draws a CSS-mode switch. It's implemented with the mockup's own `.seg.wide` /
  `.seg-opt` (Semantic active, Tailwind dimmed `.is-soon`), no new visual vocabulary.

- **GAP: read-only design-token specimen + compiled-stylesheet disclosure — `s5e-*` GAP classes.**
  The Style tab's light token-specimen cards and the "View compiled stylesheet" disclosure are not
  in the mockup. Built from mockup tokens/radii/mono only, prefixed `s5e-` so they never collide
  with the mockup's vocabulary. Read-only; they prove swatches never drift from the real tokens.

## Structure tree

- **GAP: tree renders a FLAT depth list, not a reconstructed DOM tree.**
  The mockup (lines 1384-1407) builds true nesting by walking the preview DOM. The server reports a
  FLAT list of nodes with a `depth` hint (overlay.reportStructure), so structure.js renders the
  mockup's exact `.tnode`/`.trow`/`.twist`/`.tico`/`.tlabel`/`.ttype` rows, indented by depth via a
  left margin, rather than rebuilding parent nesting. Every row's markup, kind glyph, type tag, and
  `.row-disabled` red-line are the mockup's; only the nesting reconstruction is deferred.

## Selection chrome (preview overlay)

- **GAP: FontAwesome is loaded INTO the light preview iframe so `.sel-act` glyphs render.**
  The mockup's selection chrome (`.sel-tag`/`.sel-act`, lines 389-403, 1129-1134) uses FA glyphs
  (fa-pen/fa-copy/fa-eye-slash). The preview iframe renders the real served (light) page, which does
  not enqueue FA. overlay.js injects the bundled local FA stylesheet (cfg.faUrl, same-origin) into
  the iframe head so the chrome matches the mockup exactly. If the parent is ever cross-origin the
  inject is skipped and geometry/colors still hold (glyphs degrade). No invented icons.

## Code lens

- **Impl note (not a deviation): real CodeMirror editor vs the mockup's static highlighter.**
  The mockup shows a static syntax-highlighted `<pre>`. The build uses the real WordPress CodeMirror
  code editor in the same `.codeview`/`.code-head`/`.code-body`/`.code-foot` shell, so the canonical
  is actually editable and saveable. Gutter lint markers are CodeMirror's own. Behavior superset of
  the mockup, same chrome.

---

# Theme Settings (Item 3 — theme-settings-mockup.html)

## WP admin chrome

- **The WP admin bar (top) + WP admin menu (left) are the REAL ones — the mockup mocks them.**
  theme-settings-mockup.html draws its own `.wp-adminbar` / `.wp-menu` so the reference is
  self-contained. The build runs INSIDE wp-admin, so those mock rules are dropped and the dark
  `.s5set-page` is positioned to fill the real wp-admin content area (right of the real menu, below
  the real admin bar). Not a deviation — the mockup's own comment says these are mocked.

## General

- **Identity fields are read-only, pointing to WP core.** The mockup draws editable Site Name /
  Tagline / Logo / Favicon. Those are WordPress core settings (Settings → General, Customizer), so
  the card shows the real values read-only with "edit in Settings → General" / "Customizer" links,
  rather than duplicating a second write path for core options. GAP: used the mockup's `.media-field`
  markup; the write lives in WP core where it belongs.

## Templates

- **GAP: template hierarchy rows show "Using default" — this theme ships no per-template files.**
  The mockup lists a full WP hierarchy (front-page, single, page, archive, …) with active/default
  status. The theme renders through the canvas path and ships only template PARTS (site/parts), not
  hierarchy template files, so each hierarchy row is rendered in the mockup's `.tpl-row` markup with
  a "Using default" status (data unavailable). Template PARTS are real (from site/parts) with working
  enable switches + edit pencils. Custom/CPT template registration lands with the template work.

## StyleGuide

- **Token editing uses the existing one-door POST save, not the mockup's inline JS edit mode.**
  The mockup toggles a card into edit mode (pencil → editable inputs + add/save) purely client-side.
  The build keeps the real, gated one-door token save (`ACTION_SAVE_TOKEN` → Styles::update_token →
  StyleBundle::build): the pencil is a native `<details>` revealing the real POST form per token,
  styled with the mockup's `.edit-btn`/`.token-hex-input`. Round-trip browser-verified. GAP: the
  mockup's add-token / delete-token affordances are not wired (add/remove tokens is a larger,
  validator-touching change); edit-value is real.
- **Specimen renders from the REAL tokens (better than the mockup, not a deviation).** The mockup
  hardcodes `--s5-*` in `:root`; the build inlines the live values read from tokens.css/scales.css so
  the specimen never drifts and a token edit is reflected immediately.

## Components

- **GAP: "used on N pages" count omitted.** The mockup shows a usage count per component; the
  registry has no usage index, so the meta row shows the component tag + variant count (both real)
  and drops the fabricated usage number rather than invent one. Glyphs come from the mockup's own
  COMPONENTS map by name (unknown → the mockup's `fa-cube`).
- **Component Builder is the real REST-driven editor in the mockup's `.cb-*` full-screen shell.**
  Props render from the real manifest; HTML/CSS/manifest are the real component files via the
  /components REST route (CSS read-only, HTML vocabulary-validated server-side). The mockup's sample
  pricing-card preview + Dev/Preview lens are presentational; the Dev lens (editor) is the live one.

## Roles

- **Permission matrix: interactive mockup buttons mirror hidden real checkboxes.** The mockup's
  `.perm-mark-btn` toggle cells are the visible control; each mirrors a hidden `matrix[cap][role]`
  checkbox that is the actual form submit. Real matrix + per-user overrides come from Roles::matrix /
  role_for_user. User avatars/colors are derived (initials + a rotating palette), as the mockup does.

## Integrations

- **Conditions builder is presentational (Include/Exclude UI), as in the mockup.** The real save
  persists name/point/enabled/code (Integrations::save); per-integration condition scoping is drawn
  with the mockup's `.cond-*` builder but not yet persisted (matches the mockup, which ships it as
  UI). Injection point + enable + code + name are real and round-trip.

---

# Fidelity matrix — status (Acceptance A)

Side-by-side mockup-vs-shipped composites generated by Playwright against the real s5-audit
install live at `docs/ui-fidelity/<surface>.png` (18 surfaces) with an index at
`docs/ui-fidelity/FIDELITY-MATRIX.html`:

- Canvas: toolbar · palette+tree · props · style-tab · advanced-tab · viewport · code-lens ·
  tools-menu · page-drawer · selection-chrome.
- Settings: general · templates · styleguide · components · css · integrations · roles ·
  component-builder.

Every surface matches its mockup except the deviations documented above (all GAPs where the
data can't yet back what the mockup drew, or WP-admin constraints — never invented visuals).

**Drift caught by the matrix and FIXED (not an exception):** composing the StyleGuide side-by-side
revealed the shipped light specimen's headings rendering faint — the dark-chrome heading-color
neutralization (`.s5set h1..h4`) was bleeding into the intentionally-light `.specimen`. Fixed by
re-asserting `--s5-color-ink` on `.specimen` headings; specimen h1 now computes rgb(30,30,30).

---

# 2026-08-14 evening — fresh re-audit findings

**GAP: the audit checklist's Item 5b describes "a 'Build in Framework' button in the editor top
bar" — no such button exists anywhere in the block-editor toolbar (verified: no
`enqueue_block_editor_assets` hook, no `registerPlugin` call, no editor-toolbar JS file in the
theme at all).** The actual, working takeover entry point is a **"Take over with S5" row action**
on the Pages list screen (`PageAdmin::add_convert_row_action`, filtering `page_row_actions`),
documented in the code's own comment as a deliberate choice: "there is no 'New S5 Page' screen...
a page becomes an S5 page by being taken over here." This is not a mockup deviation (neither
mockup covers a Gutenberg-toolbar button — HANDOFF's checklist language is the only place
describing it that way) and not a functional defect: the row-action path was independently
verified end-to-end — existing post_content wrapped as canonical (never replaced with scaffold),
a revision created BEFORE the first framework write (proven: revision 57 = pre-takeover plain
content, revision 59 = framework-wrapped, in that order), the served front-end page identical
after adoption (curl-verified logged-out), and restoring the pre-takeover revision correctly
un-adopts the page (all `_s5_*` canonical meta keys removed, page reachable via normal
`post.php?action=edit` again, not redirected into the S5 editor). Logged as a GAP between the
checklist's literal UI description and the shipped (working) UI location, composed from an
existing WP-native pattern (row actions), not an invented visual.
