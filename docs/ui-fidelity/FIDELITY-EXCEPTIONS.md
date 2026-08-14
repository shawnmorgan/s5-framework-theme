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
