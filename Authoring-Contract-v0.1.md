# Authoring Contract — v0.1 (provisional until editor tech-design ratifies)

**Date:** 2026-08-07 · **Owner:** Shawn Morgan
**Purpose:** single source of truth shared by the WP framework (theme + plugin), the Bricks component-library pipeline, and all agent authoring. Kills drift between the CSS framework decisions (BEM + Token Spec v0.9, Bricks chat, 2026-08-05) and the theme PRD.
**Precedence:** where this doc and older PRD phrasing disagree, this doc wins. Where this doc and BEM+Token Spec v0.9 disagree, **v0.9 wins** — this doc imports it, it does not fork it.

---

## PART A — CSS SYSTEM (imported from BEM + Token Spec v0.9)

### A1. Principles (verbatim intent)
1. **Tokens carry values. Classes carry decisions.** No class contains a raw size/color/font value — only token references.
2. **Closed vocabulary.** Class list and token list are enumerated. A gap stops the build and escalates; never filled inline.
3. **Parents own layout. Children own appearance.** Alignment, distribution, flow, spacing-between live on wrappers. Typography classes never carry placement.
4. **Fluid first.** Type and rhythm scale via `clamp()`. Breakpoints = structural reflow only (column collapse, nav swap), never size stepping.
5. **rem/em only.** Pixels only in the documented exception list (1px hairlines, -1px sr-only, 2px micro-adjust, clamp/calc internals).
6. **Type is readable from the name** — every rule is lintable by pattern.

### A2. Token grammar
```
--{category}-{step}            --space-lg, --text-xl, --radius-md
--{category}-{role}            --color-bg, --color-text, --font-display
--{category}-{role}-{variant}  --color-text-muted, --color-bg-alt
```
Vocabulary is `bg`/`text` (surface/ink REJECTED — terms must be guessable by the ACSS/Tailwind/Client-First world).

### A3. Step ladder (RULED — supersedes any "XXS–XXL" phrasing in this project's PRD)
`xs / sm / md / lg / xl / xxl / xxxl`, extended downward with `xxs` where a scale needs it. **No numeric steps** (`2xl`, `4xl`) anywhere. Applies to: `--space-*`, `--text-*`. `--radius-*` uses a 4-slot subset (`sm/md/lg/xl` mapping Foundation 6/10/20/28).
**Slots are theme law; values are per-brand.** Nobody adds slots. Default value pattern = fluid `clamp()`.

### A4. Class naming grammar (canonical — replaces Client-First "dash=utility" phrasing)
| Layer | Pattern | Example |
|---|---|---|
| Block (component root) | dashes | `.pricing-card` |
| Element | single underscore | `.pricing-card_title` |
| Modifier (enumerated axes) | double dash | `.pricing-card--dark`, `.btn--secondary` |
| Utility | `u-` prefix | `.u-text-center` |
| **Generated scale classes** | `{category}-{step}` | `.text-xl`, `.space-top-lg` |
No brand prefix. Class type is machine-parseable from pattern → compiler lints every rule in Part A mechanically.

### A5. Layers & files
```
/theme/styles/tokens.css       free-form: color palette, font families/weights   (hand/agent-authored)
/theme/styles/scales.css       locked slots, per-brand values (space/text/radius) (values-only edits)
/theme/styles/generated.css    scale classes emitted by compiler from scales.css  (READ-ONLY; editing = violation)
/theme/styles/utilities.css    the u- layer: <15 classes total, enumerated        (hand-authored, closed)
/theme/styles/components/*.css one file per block, keyed off block class          (the only open-ended hand-authored CSS)
```
- **Generated scale classes** exist for exactly two consumers: agent authoring shorthand and the editor's t-shirt controls. Set is fixed: `.text-{step}` (all text — one scale, no heading/body split; headings get font/weight/color from block/element classes, size from the text scale = tag/size decoupling) and `.space-top-{step}` / `.space-bottom-{step}`.
- **u- utility layer:** structural micro-utilities + span-scoped inline emphasis only. Under 15 total, enumerated in v0.9's inventory. Never layout systems (`grid-2`, `flex-between` are banned — layout is block-class territory).
- **Surface variants via token remapping:** `.section--dark` remaps `--color-bg`/`--color-text` descendant-wide. Zero per-component dark variants.

### A6. Compiler lint rules (mechanical, from A1–A5)
Reject on: unknown class (not in enumerated vocabulary) · raw px/hex/size values in component CSS outside exception list · numeric scale steps · layout properties in typography/element classes · new `u-` class · hand edits to `generated.css` · inline `style=` attributes · class pattern that matches no layer grammar.

### A7. Reconciliation — RULING NEEDED (one item)
**Editor "space above/below" t-shirt control vs. "parents own layout":** element-level margin utilities contradict A1.3. **Proposed resolution (lean):** the spacing control never writes margins on children — on a *section*, it swaps the section's padding step (`.section--pad-{step}` modifier or padding token step); *between siblings*, it swaps the **parent's** gap step. Control surface stays t-shirt-simple; layout ownership stays with wrappers. `.space-top/bottom-{step}` then exist only for agent use in sanctioned spots (e.g., standalone elements in prose flow), not as an editor control. Adopting this lean unless vetoed.

---

## PART B — DECLARATION / PROP VOCABULARY (provisional v0.1)

One law: **the editor renders declarations, nothing else.** Content = opt-in (this vocabulary). Structure = opt-out (`data-lock`).

### B1. Slot types (`data-edit="{type}"` on page nodes; same types as component manifest props)
| Type | Editable | Panel control | Save behavior |
|---|---|---|---|
| `text` | plain text | input | innerText write via tag processor |
| `rich` | limited rich | mini editor (bold/italic/link ONLY) | sanitized innerHTML write |
| `img` | image + alt | wp.media picker + alt field | attachment-backed markup rewrite (srcset/dims) |
| `bg-img` | CSS background image | wp.media picker | class/style rewrite owned by save pipeline |
| `link` | href (+ optional label) | URL field (+ text input) | attribute write |
| `embed` | shortcode string | textarea | shortcode stored; rendered via pipeline |

Six types. Closed set. New types = contract revision, not ad-hoc addition.

### B2. Component manifest prop types
All six slot types above, plus:
| Type | Purpose | Panel control |
|---|---|---|
| `select` | enumerated choice (variant, style option) | segmented/dropdown → **modifier class swap** (`--{value}`) |
| `toggle` | boolean (badge on/off, price-note on/off) | switch → write-time re-expand of instance (resolves `{% if %}`; no runtime template logic) |
| `attr` | component-declared data attribute (e.g., analytics ID) | input → attribute write. The ONLY Content-level path to data attributes; free attr editing stays Design+ |

Manifest per prop: `label · token · type · default · required · description`. Per-component, manifests also declare which **axes** (B3) surface.

### B3. T-shirt axes (invariant controls manifest — identical on every site, values differ)
| Axis | Range | Mechanism |
|---|---|---|
| `size` | text scale steps | `.text-{step}` swap (decoupled from tag) |
| `space` | scale steps | per A7 resolution: section padding step / parent gap step |
| `variant` | component-enumerated | modifier class swap |
| `align` | left/center/right | `u-` alignment utility swap |
Hard cap: these four. No color pickers, no free padding fields, ever.

### B4. Structural attributes
| Attr | Meaning |
|---|---|
| `data-component="{name}"` + `data-v="{ver}"` | stamped component instance (copy-on-insert; migration target) |
| `data-variant="{value}"` | active variant record (alongside modifier class) |
| `data-hidden="true"` | hidden: stored, ghosted in editor, **stripped at render** |
| `data-lock` | structure frozen (no move/duplicate/delete/insert-into) |
| `data-node="{id}"` | stable node ID (compiler-assigned where missing) |

### B5. Tag control
Separate from props: constrained dropdown (`h1–h6`, `p`), second-H1 warning, Content-accessible per site config (capability flag). Size never rides on tag (A5).

### B6. Totals
6 slot types · 3 additional prop types · 4 axes · 5 structural attributes · 1 tag control = **19 terms. This is the whole language.** Ratified or amended by the editor tech-design doc (week of Aug 24) after: LiveCanvas autopsy, Ryan's marketer list, S5T nine-page corpus test. Until then: S5T launch pages MAY carry declarations (inert in v0, becomes the editor's first test corpus) but are not required to.
