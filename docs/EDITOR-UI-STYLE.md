# S5 Editor — UI Style Contract (PressFlow / Save-the-Date, DARK-FIRST)

The visual language for the S5 editor admin surface. **Dark is the only mode built
now**; every value is a CSS variable so a light theme is a later variable swap, not a
rewrite. Scope: the editor chrome only (`s5-theme/assets/editor/editor.css`, enqueued
solely on the editor screen). Nothing here reaches the front end or logged-out visitors.

## Source of truth

- **4 PressFlow screenshots** — the authoritative *dark* visual reference (mirrored in
  `docs/ui-reference/` where the sandbox allowed; on the owner's Desktop otherwise):
  1. Structure / Sitemap (light variant) — cards + PAGES tree + segmented control
  2. Structure / Sitemap (dark variant) — same, on the near-black dot-grid canvas
  3. Project Settings — forms (stage chips, radio cards, toggles, icon-row actions)
  4. Style Guide — palette + type + button grids as WHITE cards on the dark canvas
- **2 Save-the-Date HTML mockups** (`save-the-date-screens-v1.3.html`, `…v1_4.html`) —
  the developer-reference CSS with the exact tokens + component grammar. StD is
  *light-content on dark WP chrome*; we render its grammar in the PressFlow dark palette.

The tokens below are transcribed from the StD v1.4 `:root` block + the PressFlow screens.

---

## TOKENS

Exposed as CSS custom properties scoped under `.s5-editor`. `--s5e-*` are the canonical
roles; StD source names are noted for traceability.

### Accent (indigo) — StD `--wp-accent*`
| role | value | var |
|------|-------|-----|
| accent | `#3858e9` | `--s5e-accent` |
| accent hover | `#1d35b4` | `--s5e-accent-hover` |
| accent tint | `#e8edff` | `--s5e-accent-tint` |
| accent-on-dark link | `#a8b8ff` | `--s5e-accent-bright` |

### Dark surfaces (PressFlow) + StD WP chrome
| role | value | var | source |
|------|-------|-----|--------|
| canvas (near-black, dot-grid) | `#0e1013` | `--s5e-canvas` | PressFlow |
| panel / card surface (one step lighter) | `#1a1d21` | `--s5e-surface` | PressFlow |
| surface raised (inputs, chips) | `#23272e` | `--s5e-surface-2` | PressFlow |
| sidebar | `#1e1e1e` | `--s5e-sidebar` | StD `--wp-sidebar-bg` |
| admin-bar black | `#1d2327` | `--s5e-bar` | StD `--wp-admin-bar` |

### Dark text — StD `--wp-sidebar-text*`
| role | value | var |
|------|-------|-----|
| primary | `#f0f0f0` | `--s5e-text` |
| dim / secondary | `#a7aaad` | `--s5e-text-dim` |
| faint (meta) | `#7c8085` | `--s5e-text-faint` |

### Semantic — StD `--wp-success` / `--wp-destructive`
| role | value | var |
|------|-------|-----|
| success | `#00a32a` | `--s5e-success` |
| success tint | `rgba(0,163,42,.14)` | `--s5e-success-tint` |
| destructive | `#cc1818` | `--s5e-danger` |
| destructive tint | `rgba(204,24,24,.14)` | `--s5e-danger-tint` |

### Borders / rows (dark) — StD sidebar hover/active alphas
- border: `rgba(255,255,255,.10)` → `--s5e-border`
- border-strong: `rgba(255,255,255,.16)` → `--s5e-border-strong`
- hover row: `rgba(255,255,255,.04)` → `--s5e-row-hover` (StD `--wp-sidebar-hover`)
- active/selected row: `rgba(255,255,255,.08)` → `--s5e-row-active` (StD `--wp-sidebar-active-bg`),
  plus a 2px accent left-edge or tinted border.

### Radii — 2 / 4 / 8 ONLY (StD `--radius-*`)
`--s5e-radius-sm: 2px` · `--s5e-radius-md: 4px` · `--s5e-radius-lg: 8px`

### Gaps — 4 / 8 / 16 / 24 / 32 (StD `--gap-*`)
`--s5e-gap-1: 4px` · `--s5e-gap-2: 8px` · `--s5e-gap-3: 16px` · `--s5e-gap-4: 24px` · `--s5e-gap-5: 32px`

### Type — StD `--font-body` / `--font-mono`
- Sans: `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif` → `--s5e-font`
- Mono: `Menlo, Consolas, monaco, ui-monospace, monospace` (code / paths / values) → `--s5e-mono`
- Base 13px / line-height 1.5. Micro-labels: 11px, `font-weight:600`, uppercase,
  `letter-spacing:.8px`, dim (StD `.section-label`).

### Icons
Monoline ~1.6px stroke, 16px, `currentColor`, inline SVG only (no icon font, no CDN —
StD's FontAwesome/Bootstrap-Icons CDN links are NOT ported; we inline SVG glyphs).

---

## PATTERNS → S5 surfaces (with the StD class each derives from)

### 1. Top bar (`.s5-editor__topbar`) — StD `.editor-topbar` on `--wp-admin-bar`
Dark bar. **Left:** S5 mark (rounded accent tile) + page title + subtitle (`template ·
status`, StD `.editor-topbar-cpt` accent-tint pill). **Center:** icon toolbar; the ACTIVE
view is a **filled-indigo pill** with icon+label — the lens (Edit/Code) and panel tabs
(Style/Components) map here. **Right:** ghost undo/redo, dirty **dot**, solid-indigo
**Update** (StD `.btn-primary`).

### 2. Structure cards (`.s5-editor__structure`, selected element) — StD `.card` + `.card-header`
Card + title row (drag handle `⋮⋮`, type icon, name, accent **status badge** `• Component`
/ `• Dynamic` / `• Hidden` — maps PressFlow `• Generated`), muted meta line, and a **chip
inventory** of small outlined pills for editable slots (`H1`, `2 buttons`, `Media`).
Dashed **"+ Add section"** pill between cards = insert affordance.

### 3. Left panel (`.s5-editor__leftpanel`) — StD `.settings-nav` / `.admin-sidebar`
Uppercase panel label + action button top row (StD `.settings-nav-header`); rows with
icon, name, count badge; **selected row = tinted bg + 3px accent left-border** (StD
`.settings-nav-item.active`); collapse chevrons (StD `.panel-chevron`).

### 4. Segmented control (`.s5-editor__viewport`, `__lens`, `__cssmode-switch`) — StD `.radio-group` / `.preview-style-tabs`
Pill container, 1px border; **active segment filled indigo** (StD `.radio-option.active`).

### 5. Settings forms (`.s5-editor__styletab`, head form) — StD `.field-*`, `.toggle`, `.visual-option`
Dark cards with title-bar strip (StD `.card-header`); uppercase micro-labels
(`.section-label`); dark inputs with subtle borders + accent focus ring (`.field-input`);
**stage chips** (done = outlined+check, active = filled indigo — StD stage buttons);
**radio CARDS** with accent-border + tint selected (StD `.visual-option.active`) for the
Semantic/Tailwind toggle; **toggle rows** with indigo checks (StD `.toggle.on`); icon-button
edit/delete row actions.

### 6. Style guide (`.s5-editor__styletab` content) — the deliberate contrast move
The Style tab renders design tokens as **WHITE content cards on the dark canvas**:
palette grid (swatch + role + hex in mono), type-scale specimen rows at real sizes,
button variations × states, spacing/scale rows. **Why:** sites are light so the design
system previews on white; the TOOL is dark. Intentional inversion (matches PressFlow
screen 4 and StD's white `.std-preview-container` on dark chrome).

### 7. CodeMirror (`.s5-editor__code`, `.CodeMirror`, `.s5-editor__stylesheet-view`)
Dark theme matching the surfaces, mono stack, accent selection/diagnostics.

---

## Hard rules (do not break)

- **Dark only** now; structured for a one-variable-block light swap later (a `:root`
  light token set behind `.s5-editor[data-theme="light"]`, not built).
- **Fonts local/system only** — no CDN, nothing on the front end. StD's CDN icon links
  are not ported; glyphs inline as SVG or unicode.
- **Zero handler changes.** All `data-s5-*` hooks and JS-bound state classes
  (`.is-active`, `.is-component`, `[data-s5-active]`, `[data-s5-viewport]`, `is-disabled`,
  `is-soon`) preserved exactly. Styling adds classes/wrappers only.
- **WP-admin context:** the editor is a `position:fixed` full-screen takeover; it must
  neutralize inherited wp-admin `.button`/form/`.CodeMirror` styling *within* `.s5-editor`
  and never leak outside it. Scope every rule under `.s5-editor`.
- Editor assets never reach logged-out visitors (standing grep in the gate).
