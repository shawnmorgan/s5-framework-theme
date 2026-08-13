# S5 Framework — Editor & Settings Handoff

**What this is:** two self-contained HTML mockups that define the *design and interaction contract* for the S5 Framework theme's authoring surfaces. They are the source of truth for **what the UI is and how it behaves** — not the implementation. Open them in a browser to see the intended product; then wire them to the real theme.

**Files:**
- `canvas-mockup.html` — the page/template editor (canvas).
- `theme-settings-mockup.html` — the WP-admin theme settings screen.

**Ground truth for the product itself:** S5 is a single WordPress **theme** (not a plugin). Product code in `src/`, site content in `site/`. Humans and agents author the **same canonical HTML**, validated against a **closed class vocabulary**. What you approve is what ships — no builder round-trip, no drift.

---

## 0. Non-negotiable rules

These are architectural invariants. The mockups encode them visually; the build must enforce them for real. **Do not "improve" the UI in a way that breaks one of these.**

1. **Closed class vocabulary.** The only classes that may appear in authored HTML are those defined in `components.css` + `utilities.css`. The editor never emits arbitrary classes or inline CSS. There is **no free-form CSS field anywhere in the canvas.** Styling = component variants + t-shirt sizing + theme-role color only.
2. **No page-builder primitives.** The left palette shows **only registered components** (Webflow/Sanity client model) plus the **single `Embed` primitive**. Never add generic "div / section / column" primitives.
3. **Disabled ≠ hidden.** "Disabled" means the element is **dropped by the compiler before render** — it is *not* in the output HTML at all. This is distinct from responsive visibility (which hides at a breakpoint but still ships). See §2.4.
4. **No trashing on the canvas.** Elements are component-derived; deleting one could damage shared component parts. The element toolbar offers **edit · duplicate · disable** — never delete. Re-enable happens from the structure tree.
5. **Component CSS is read-only in the editor.** In the Component Builder, HTML/Logic/Props are editable; **CSS is read-only** and comes from `components.css`. The only place to change component CSS is the CSS/Tailwind tab (which edits the vocabulary itself).
6. **Structure tree is the only structural surface.** The right panel is *always* the structure tree — never a jammed-together props/style/structure sidebar. Selection is synced across stage ⇄ tree ⇄ left panel.
7. **Two token layers, never merged.** `--s5e-*` = the dark **tool** chrome. `--s5-*` = the real **site** design system (light). The tool is dark; the site preview is light. Deliberate.
8. **Roles gate surfaces.** Developer / Marketer / Client. The role decides which editor tabs render and which settings screens exist. See §4.

---

## 1. Shared conventions (both files)

- **Self-contained.** FontAwesome is loaded from CDN; all other CSS/JS is inline. No build step to *view* them.
- **Icons:** FontAwesome 6.5.1. Keep the CDN link or bundle FA locally in the theme.
- **Design tokens** are declared as CSS custom properties in `:root`. In the real theme these must be generated from `tokens.css` / `scales.css` so swatches never drift from the compiled site.
- **All data is mock.** Arrays like `TPL_HIER`, `COMPONENTS`, `INTEGRATIONS`, `PERMS`, `REDIRECTS` are placeholder JS literals. In the build they come from WP (options, registered components, the template registry, `wp_get_theme()`, etc.).
- **All "Save/Apply/Deploy" buttons are inert.** No persistence is wired.

---

## 2. `canvas-mockup.html` — the editor

Layout: **top toolbar** · **left panel (palette ⇄ props)** · **center stage (Edit ⇄ Code lens)** · **right panel (structure tree)**.

### 2.1 Top toolbar

| Element | ID | What it must do |
|---|---|---|
| Logo | `.tb-logo` | Home / dashboard link. |
| **Gear** | `#pageSettingsBtn` | Opens the **Page Settings** drawer — *or* the **Display & Conditions** drawer in template mode (see §2.6). |
| Tools dropdown | `#toolsBtn` / `#toolsMenu` | Three items: **Components** (registered library), **Styleguide** (tokens & scales), **CSS/Tailwind** (source layers). Each should deep-link to the corresponding settings screen (§3). |
| Viewport control | `.vp-btn[data-vp]` | Sets preview width: All / 768–991 / ≤767 / custom. Toggles `.stage-frame.mobile/.tablet`. Cosmetic width only — **not** the same as responsive visibility. |
| **Lens** | `.lens-btn[data-lens]` | `edit` = live preview iframe; `code` = canonical-HTML source view. Swaps the center panel only. |
| Undo / Redo | `.tb-icon-btn` | Editor history. |
| View | `.tb-text-btn` | Preview the page (front-end). |
| **Save** | `#saveBtn` / `#dirtyDot` | Persists the page. `markDirty()` shows the dirty dot; wire real dirty-state + save. |

**Removed on purpose — do not re-add:** any AI/assistant button (authoring is agent-driven, not an on-page chatbot), and the green "+" add button.

### 2.2 Left panel — palette (default)

- `#palette` shows registered components grouped **Sections / Content / Dynamic**, plus the **Primitive → Embed** (`.pal-item.embed`, `raw` badge).
- Real build: populate these groups from the **registered-component registry**. Each tile is a drag source that inserts the component's canonical HTML at the drop point. Enforce that only registered components + Embed are insertable.
- `Embed` is the only tile that inserts raw third-party HTML/script (still recorded in the tree; still disable-able).

### 2.3 Left panel — props editor (on selection)

`showProps()` swaps `#palette` → `#props`. Three tabs (`.prop-tab`): **Content · Style · Advanced**.

- **Content** (`#contentFields`, rendered by `renderContent(el)` keyed on `data-kind`):
  - `heading` / `text` → text content field.
  - `media` → **Media Library** button (`.media-lib`) + thumbnail (`.media-thumb`) + Alt. Wire the button to the WP media modal; write back `data-src`/`data-alt`.
  - `button` → text + link (href) + open-in select.
  - `card` → its sub-fields; `section`/`group`/`collection` → a "container — select a child" note (no direct props).
  - **Enabled/Disabled toggle** (`#enableSwitch`) lives at the top of Content — see §2.4.
- **Style** — component **Variant** segmented (`#variantSeg`), **t-shirt sizing**: one **XXS→XXL step-slider** for text size (`#sizeSlider`, snaps to the scale ladder), Ollie-style **Dimensions** group for spacing (`#spaceLink` link-toggle + edge step-sliders), and *maybe* a theme-role **color swatch** row (`.swatch`, theme roles only — never a color picker). **No class input. No raw CSS.**
- **Advanced** — HTML **tag** select (`#tagSeg`), SEO/meta bits, and **responsive visibility** (All / Desktop / Mobile). This is where tag & SEO moved out of Content.

`renderContent` currently switches on `data-kind`; in the build, drive the field schema from the **component's registered prop definitions** (same schema the Component Builder edits, §3.4).

### 2.4 Disabled vs. visibility (critical)

- **Disable** (`toggleDisabled(el)` / `setDisabled(el,bool)`): sets `data-disabled="true"`, adds `.is-disabled` (visually removes it from the glass), red-lines its row in the tree (`.trow.row-disabled`), and increments the amber footer badge (`#hiddenCount`, "N disabled · not compiled").
  - **Contract:** a disabled element is **excluded from compiled output entirely** — the compiler drops any `data-disabled="true"` node before render. It is not `display:none` in production.
  - It **vanishes from the glass**, so the *only* way to re-enable it is from the **structure tree** (its red-lined row stays there) or the Content-tab toggle when reselected.
- **Responsive visibility** (Advanced tab): hides at a breakpoint but **still ships** in the HTML. Keep these two concepts separate in the compiler and the UI.

### 2.5 Center stage

- **Edit lens:** `#stage` / `#frame` render the live **light** site preview using the real `--s5-*` tokens. `.stage-frame` intentionally has **no `overflow:hidden`** (clipping it hides selection outlines/tags of edge elements — that was a real bug; don't reintroduce overflow).
- Every preview element carries `data-el`, `data-kind`, `data-tag` (media adds `data-src`/`data-alt`; buttons carry real hrefs). Selection draws `.sel-outline` + a floating `.sel-tag` toolbar with **pencil (edit) · duplicate · disable** — **no trash**.
- **Code lens:** `#codeview` / `#codePre` shows the canonical HTML with syntax highlighting and a validity footer. In the build this is the actual serialized canonical HTML, and it must round-trip with the visual editor.

### 2.6 Structure tree (right)

- `buildTreeFromDOM(el, container)` walks every `[data-el]` node and auto-assigns `el-N` ids so **every row maps to a real element on the glass.** Clicking a row selects that element (and vice-versa). Do not special-case rows.
- `templatePartRow(...)` renders template-part rows (site-header / site-footer) — these represent `<s5-part name="…">` includes.
- Disabled rows are red-lined and are the re-enable entry point.

### 2.7 Gear drawers (two modes)

The gear opens a **different drawer depending on mode**, decided by a URL param:

```js
const params = new URLSearchParams(location.search);
const TEMPLATE_MODE = params.has('template');   // ?template=<file>
// activeDrawer() → tplDrawer if TEMPLATE_MODE else pageDrawer
```

- **Page mode (default)** → `#pageDrawer` **Page Settings**: WP Page Options (title, permalink, status, template, parent, homepage/nav toggles), Page Meta & SEO (SEO title, meta description, canonical, index/follow, OG image + live preview), and **Schema (JSON-LD)** injection. Wire to the page's post meta; JSON-LD injects into `<head>` on render.
- **Template mode (`?template=…`)** → `#tplDrawer` **Display & Conditions**: content width / header / footer / featured-image / title toggles, plus **Include/Exclude condition groups** (Post type / Taxonomy / Post / Author / Template — is / is not), a **Priority** field, and a live plain-English preview. A template has no title/permalink/SEO of its own — it's **a rule + a layout.**
- The **Include/Exclude condition builder** (`.cond-group` / `.cond-row`) is shared with the settings Integrations screen — build it **once** as a reusable rule-builder component (see §3.6).

### 2.8 The `?template=` contract (dependency)

The settings **Template Editor** embeds this file as an iframe: `canvas-mockup.html?template=<file>`. Therefore:
- **Both files must live in the same directory** (currently `pages/`). If you move one, update the iframe `src`.
- The `?template=` param is what flips the gear to Display & Conditions and relabels the drawer sub-header (`#tplDrawerSub`) from the template file. In the build, load the actual template's canonical HTML into the stage.

---

## 3. `theme-settings-mockup.html` — theme settings

Rendered inside a **mocked WP admin** (top bar + left menu, "S5 Framework" active). Dark chrome, matching the canvas. Left **sub-nav** (`.snav[data-screen]`) switches `.screen` sections. Seven screens.

### 3.1 General (`data-screen="general"`)

- **Identity** — site name, tagline, **Logo** and **Favicon** as `.media-field` (thumbnail + Media Library button). Wire to WP media + theme mods.
- **Redirects** (`#redirects`, `renderRedirects()`, add via `#rdrAdd`) — from → 301/302 → to table. Wire to a redirects store.
- **Build & Deploy** (`.deploy-status`) — status, last build, environment select, **Deploy now** button, compile toggles. Wire to the real compile/deploy pipeline.
- **Removed on purpose:** Homepage & front-page/posts-page settings (use WP's native **Settings → Reading**); Navigation (defined in the header component); all head/analytics (moved to **Integrations**, §3.6).

### 3.2 Templates (`data-screen="templates"`)

- **Template Parts** (`#tplParts`, data `TPL_PARTS`) — site-header/footer etc. Each row has an **enable/disable toggle** (`.tpl-switch`), a pencil (opens the template editor modal), and non-core parts get delete. "+ Add part" = `#addPart`.
- **Template Hierarchy** (`#tplHierarchy`, data `TPL_HIER`) — the full WP hierarchy (Front Page, Single Post, Page, Archive, Search, 404, etc., marked `core:true`) plus **CPT/taxonomy/author/custom** templates (`core:false`, deletable, `CUSTOM` badge). "+ Add template" = `#addTpl`.
- `showAddForm(hostId, mode)` renders the inline add form; `slugToFile()` auto-generates filenames (`single-<slug>.html`, `archive-<slug>.html`, …) by type. `renderTemplates(list, hostId, mode)` renders both cards; `rebindTplEdits()` re-wires pencils after re-render.
- **Real build:** this list is the **template registry**. Core WP templates cannot be deleted. Editing (pencil) opens the **canvas** on that template (§3.3). Enabling/disabling a part controls whether it's compiled.

### 3.3 Template Editor modal (`#tplModal`)

- Full-screen modal over settings. `openTemplateEditor(t)` sets `#tplCanvasFrame.src = 'canvas-mockup.html?template=' + encodeURIComponent(t.file)` and opens the modal. Back/close via `#tplModalBack` / `#tplModalClose`.
- **This is the same canvas editor**, in template mode. Do not build a second editor. Honors the `?template=` contract (§2.8).

### 3.4 StyleGuide (`data-screen="styleguide"`)

- **Fonts** card (font source segmented + headings/body inputs + weight badges).
- **Colors / Type / Space / Radius** token cards (`renderColors()` / `renderScale()` over `#colorTokens`, `#typeScale`, `#spaceScale`, `#radiusScale`). Each card has an **`.edit-tools`** affordance: **pencil** → edit mode (pencil hides, values become inputs, header reveals **+ add** and **✓ save**). `bindColorEdit()` wires it.
- Right side = a **light `.specimen`** (Client-First style): live type ramp, buttons, cards, color roles, badges, inputs — so a non-dev *sees* the primitives.
- **Real build:** the token cards read/write `tokens.css` + `scales.css` (the values layer). Editing a token must regenerate the compiled CSS. The specimen renders with the real `--s5-*` tokens.

### 3.5 Components (`data-screen="components"`)

- `#compGrid`, data `COMPONENTS`, `renderCard()`. Each card: icon, name, `<slug>`, **enable switch**, variants, meta, and a **pencil** (`.comp-open`) → `openComponentBuilder(i)`.
- **Component Builder** (`#cbModal`) — Crocoblock-style full-screen modal, **S5-adapted**:
  - **Props** (`#cbFields`, `renderFields()`) — editable field schema (label, token, type, default, required, description). This is the same schema that drives the canvas Content/Style fields (§2.3).
  - **Live view** — rendered from template + props.
  - **HTML** (`#cbHtmlPre`) — editable, **vocabulary-validated** against the closed class list.
  - **CSS** (`#cbCssPre`) — **READ-ONLY** (from `components.css`; edit in CSS/Tailwind).
  - **Logic** (`#cbLogicPre`) — editable.
  - `fillCode()` populates the panels; back/close via `#cbBack` / `#cbClose`.
- **Real build:** cards come from the registered-component registry; the builder reads/writes the component definition (template, prop schema, logic). Enforce vocabulary validation on the HTML panel and read-only on CSS.

### 3.6 CSS / Tailwind (`data-screen="css"`) & Integrations (`data-screen="integrations"`)

- **CSS/Tailwind** — inner **layer tabs** (`#layerTabs`): **Tokens (🔒) · Classes · Custom CSS**, data `LAYERS`, `renderLayer(key)`. An **engine toggle** (`#engineToggle`) switches the whole view between **CSS** and **Tailwind** output. Tokens are locked (edit them in StyleGuide). This screen *is* the closed vocabulary — edits here change what the validator allows.
- **Integrations** — `#intList`, data `INTEGRATIONS`, `renderIntegrations()`, add via `#intAdd`. Each card: editable name, enable switch, delete, **snippet textarea**, **Injection point** select (Head / Before `</body>` / After `<body>` / Footer), and **Include/Exclude conditions** (`intConditions()`, same rule builder as the template drawer, §2.7). **All head/analytics/pixel/verification code lives here** — nothing goes in a raw `<head>` field.
- **Real build:** integrations persist to an options store; the theme injects each enabled snippet at its chosen point, on pages matching its conditions. Reuse the **one** condition-rule-builder component across template conditions and integration conditions.

### 3.7 Roles (`data-screen="roles"`)

- Three role cards (Developer / Marketer / Client) + a **permission matrix** (`#permTable`, data `PERMS`, `renderPerms()`), grouped **Canvas editor · Theme Settings · Build & deploy**.
- **Contract (see §4):** the matrix is the spec for which surfaces each role can reach. The **canvas rows are the switch** for which editor tabs render when that role opens a page.

---

## 4. Roles — the access contract

Three built-in roles, mapped onto native WP roles so this can be enforced with `current_user_can()` gates rather than fully custom capabilities:

| Role | Maps to | Canvas editor | Theme Settings |
|---|---|---|---|
| **Developer** | Administrator | Everything (incl. Embed primitive, structure tree, Advanced) | Everything (incl. Roles, CSS/Tailwind, Deploy) |
| **Marketer** | Editor | Content · Style · **Advanced** · structure tree · Page Settings gear | Sees settings; **no** Roles / CSS·Tailwind / Deploy / Components / Templates edit |
| **Client** | Author | **Content · Style only** — no Advanced, no structure surgery, no Embed | **Never sees the settings page** |

**Enforcement, not decoration:** hide/disable the tabs and screens server-side per role. A Client must not be able to reach Advanced, the structure tree's disable controls, the Embed primitive, or any settings screen — even by URL. The `PERMS` array in the mockup is the exhaustive row-by-row spec; treat every dash as "hidden **and** blocked."

*(If you'd rather register genuinely custom WP roles — `s5_developer`, `s5_marketer`, `s5_client` — that's fine; the matrix is unchanged. The role→WP-role mapping above is just the cheapest path.)*

---

## 5. Wiring checklist (quick pass)

- [ ] Palette groups ← registered-component registry; only registered + Embed insertable.
- [ ] Content/Style/Advanced fields ← component prop schema (shared with Component Builder).
- [ ] Media fields ← WP media modal; write `data-src`/`data-alt`.
- [ ] Style tab: variants + t-shirt sliders + theme-role color only. **No class/CSS input.**
- [ ] Disable = compiler drops `data-disabled="true"` before render; re-enable from tree.
- [ ] Structure tree row ⇄ element selection sync, both directions.
- [ ] Gear: `?template=` → Display & Conditions; otherwise Page Settings.
- [ ] Page Settings ← post meta; JSON-LD → `<head>`.
- [ ] Save / Undo / Redo + real dirty state.
- [ ] Settings sub-nav ← 7 screens, gated by role.
- [ ] Template registry (parts + hierarchy), core templates undeletable, enable toggles compile-gated.
- [ ] Template pencil → canvas iframe with `?template=`.
- [ ] StyleGuide token cards ← `tokens.css`/`scales.css`; edits regenerate compiled CSS.
- [ ] Component Builder: HTML vocab-validated, **CSS read-only**, Logic/Props editable.
- [ ] CSS/Tailwind layer tabs + engine toggle; Tokens locked.
- [ ] Integrations: snippet + injection point + conditions; all head/analytics folded in here.
- [ ] **One** shared Include/Exclude condition-rule-builder (templates + integrations).
- [ ] Roles enforced server-side (`current_user_can`), matrix = spec.
- [ ] Keep `canvas-mockup.html` and `theme-settings-mockup.html` co-located (iframe dependency).

---

## 6. What NOT to do (regression guards)

- Don't add page-builder primitives to the palette.
- Don't add any raw-CSS / class-name field to the canvas.
- Don't add a trash/delete on the element toolbar.
- Don't make "disabled" mean `display:none` in output — it must be **absent** from output.
- Don't collapse the right panel into a mixed props/style/structure sidebar — it's the tree, always.
- Don't make component CSS editable in the Component Builder.
- Don't put analytics/head code anywhere but Integrations.
- Don't add an AI/chatbot button to the toolbar.
- Don't let a Client reach Advanced, structure disable controls, Embed, or any settings screen.
- Don't reintroduce `overflow:hidden` on `.stage-frame` (clips selection outlines).
