# Editor MVP — Technical Design ("WordCamp cut")

Scoped to the **demo-ready Step 12 MVP** only (target Aug 14). The full editor
spec (PRD §6/§8/§9) resumes post-launch. Every decision here biases
shippable-today over architecturally-complete. Deviations from the full spec are
deliberate and recorded in `DECISIONS.md`.

Grounded in: PRD §6 (human editing model), §8.1 (technical direction), Authoring
Contract **Part B** (declaration/prop vocabulary). Non-negotiable: the editor
reuses the **one existing save door** and never touches the fidelity guarantees.

---

## 1. State model (authoritative)

Per PRD §8.1, the **iframe DOM is not the state store**. Authoritative state is:

- **canonical source** (`_s5_canonical_html`) — the portable truth, and
- **declared operations** — a slot value written into canonical at a declared node.

The editor is a *projection* over authored HTML (§6.1). Live edits may patch the
preview iframe for responsiveness, but **nothing persists until Update**, and
Update always sends source through the compiler and replaces derived state from
compiler output.

```
in-memory editor state
  canonicalDraft : string         # the fragment being edited (Code lens = this verbatim)
  slotEdits      : {node -> value}# pending Edit-lens changes (not yet in canonicalDraft)
  headDraft      : {title,description,canonical,og...}
  dirty          : bool
iframe            : PREVIEW ONLY — re-rendered from server on load; patched client-side for feedback
```

On **Update**, pending `slotEdits` are applied to `canonicalDraft` server-side via
the tag processor (never trusting client HTML), then the single save door runs.

---

## 2. Save pipeline (the one door — no second path)

Every save — Edit-lens slot write, Code-lens full-fragment write, head form —
funnels through the **exact pipeline the importer uses**:

```
REST  POST /s5/v1/pages/{id}/save   (manage_options + nonce)
        |  body: { mode: "slots"|"canonical"|"head", ... }
        v
  build the new canonical string
     - slots:     WP_HTML_Tag_Processor writes value at data-node (server-side)
     - canonical: the submitted fragment verbatim
     - head:      _s5_head updated; canonical unchanged
        v
  Validator::validate(canonical, vocab)   <- SAME gate as import; ERROR => reject, canonical UNCHANGED
        v
  Compiler::persist_page(id, canonical)   <- SAME call as import/recompile/restore
     -> write canonical meta -> compile (parts->media) -> derive mirror
     -> set render/dynamic/islands -> ATOMIC persist w/ rollback
        v
  WP revision created (existing Revisions hooks fire on the meta change)
        v
  REST returns { ok, render, diagnostics[] }  -> editor refreshes preview
```

**This is the crux invariant.** `Compiler::persist_page` already is the shared
service (import, recompile, revision-restore all call it). The editor adds a REST
*caller*, not a new pipeline. If a validation ERROR occurs, canonical is left
untouched and diagnostics return to the editor. This satisfies "no second door"
and the STOP condition on the save pipeline.

### Slot write mechanism (tag processor)
For `mode:"slots"`, the server locates each edited node by `data-node="{id}"`
(Contract B4). **In the MVP, `data-node` is AUTHORED in source alongside
`data-edit` — the compiler does NOT inject IDs into canonical** (that would make
canonical diverge from the source file and break round-trip fidelity — a STOP
condition; see DECISIONS.md D4/F1). Writes use `WP_HTML_Tag_Processor` (WP 6.7
core):
- `text` -> set inner text of the node (bounded replace between the node's tags).
- `rich` -> sanitized innerHTML (allow only `<strong><em><a>`), then written.
- `img` -> `alt` + a logical `assets/...` `src` (media resolver still owns
  srcset/dims at compile, so canonical stays portable).
- `link` -> `href` (+ inner text if label editable).
- `bg-img` -> a declared CSS-custom-property/class the save pipeline owns (SLIP #1:
  may defer — see slip protocol).
- `embed` -> shortcode string into the node (admin-only).

The tag processor edits canonical **surgically**; it does not re-serialize the
whole document, preserving byte-fidelity of everything it does not touch.

---

## 3. Panel architecture

Single admin screen (`admin.php?page=s5-editor&post={id}`), gated `manage_options`
(editing levels deferred — single user). Layout (§8.2, trimmed for MVP):

```
+ TOP BAR ------------------------------------------------------------+
| <- WP   [mobile|tablet|desktop]   (Edit | Code)   * dirty  [Update] |
+------------------------------+--------------------------------------+
|                              |  RIGHT PANEL                          |
|   PREVIEW IFRAME             |   Edit lens -> declared fields for    |
|   (real page, resizable =    |     the selected node + breadcrumb    |
|    real CSS breakpoints)     |   Code lens -> CodeMirror(fragment)   |
|                              |     + head sidebar form               |
|                              |   Tabs: [Content][Style][Components*] |
|                              |         [Tailwind*]  (* = disabled)   |
+------------------------------+--------------------------------------+
```

No left component palette (components deferred). No structure tree — selection is
shown as a **minimal breadcrumb** only (per instruction).

---

## 4. Selection / overlay approach (Edit lens)

- The preview iframe is same-origin (served by our canvas), so the parent window
  can read its DOM for **selection only** (never as the state store).
- On Edit lens, the parent injects a lightweight overlay script into the iframe
  that: outlines every `[data-edit]` node on hover, and on click sends the node's
  `data-node` id + `data-edit` type to the parent via `postMessage`.
- **Elements without `data-edit` are simply not wired** — no hover, no click, no
  field. Absent, not greyed (§6.1: absence is intentional). This *is* the props
  demo.
- The right panel renders fields purely from the selected node's declaration
  (Contract B1). Typing patches the iframe node live (feedback) and stages the
  value in `slotEdits`; the dirty indicator lights. Nothing persists until Update.
- **L&L dynamic regions** (a node the compiler flagged, e.g. wrapping a `<Loop>`)
  are shown in Edit lens as a **single locked block** with a badge "Dynamic — edit
  in code view"; not slot-selectable. Fully visible/editable in Code lens.

---

## 5. Code lens

- **CodeMirror shipped in WP core** (`wp-codemirror`) — no build tooling, no npm
  dependency. HTML mode, editing the full canonical fragment.
- Save -> `mode:"canonical"` -> same pipeline. A validation ERROR renders **in the
  editor** as an agent-grade diagnostic list (rule id, file:line, offending token,
  permitted, did-you-mean) — loud, never silent — and canonical is unchanged.
- **Head sidebar form** (title/description/canonical/OG) writes `_s5_head` through
  the same save (`mode:"head"`). (SLIP #4: if time-boxed, head editing collapses
  to Code-lens-only — but `_s5_head` is separate from the fragment, so a small form
  is cheap; kept unless it slips.)

---

## 6. Style tab (read-only)

Renders `s5-theme/styles/tokens.css` + `scales.css` **live, generated from the
actual files at render time** (parse the CSS custom properties, emit swatches /
type ramp at real sizes / spacing steps / radius). No hand-built HTML that can
drift. Two **disabled** placeholder tabs, visibly present: "Components" and
"Tailwind mode" (label: coming soon). No editing anywhere in this tab.

> Note: the repo ships `components.css` + `utilities.css` but not
> `tokens.css`/`scales.css`. The MVP adds minimal real `tokens.css` + `scales.css`
> (custom properties only) so the Style tab reflects real files. Recorded in
> DECISIONS.md. (SLIP #3: Style tab may fall back to a static snapshot page.)

---

## 7. Tech choices (prefer boring)

| Concern | Choice | Rationale |
|---|---|---|
| Editor app shell | **Vanilla JS** (ES modules), one admin page | No build step; the repo has no bundler. §8.1 says "React shell" for the FULL editor — deliberately deferred for the MVP (DECISIONS.md). |
| Code editor | **`wp-codemirror`** (WP core) | Zero new deps; ships with WP; matches "CodeMirror from core". |
| Preview | **iframe -> the real canvas URL** (`?s5-preview=1` to suppress admin bar) | Real page, real CSS; resize = real breakpoints. iframe stays pure preview (§8.1). |
| Save transport | **REST** (`register_rest_route`) + nonce | Standard WP; one endpoint, three modes. |
| Slot write | **`WP_HTML_Tag_Processor`** (WP 6.7 core) | Surgical, byte-preserving canonical edits; server-side (never trust client HTML). |
| Node identity | **`data-node`** (Contract B4), compiler-assigned where missing | Stable target for slot writes across recompiles. |
| Persist | **`Compiler::persist_page`** | THE existing door. Not re-implemented. |

No React, no bundler, no npm additions. Everything loads as WP-enqueued assets on
the editor admin page only — **nothing ships to logged-out visitors** (asserted in
acceptance).

---

## 8. What this MVP deliberately does NOT do (deferred, per instruction)

Components/palette/studio, structure ops (insert/move/duplicate/hide/delete),
editing-levels UI (single `manage_options` user), MCP, Tailwind mode, undo/redo
(we say "revisions"), `contenteditable` anywhere, media-library management, any
Style-tab editing, React shell.

---

## 9. Self-review against PRD §8 (contradiction check)

- **§8.1 "React-based application shell"** — MVP uses vanilla JS. *Deliberate
  deviation* for a no-build demo cut; the state model (§8.1's real requirement:
  iframe not authoritative, source+ops authoritative, Update through compiler) is
  honored exactly. Recorded in DECISIONS.md.
- **§8.1 "iframe DOM is not the authoritative store"** — honored: iframe is preview
  only; slotEdits/canonicalDraft are the state; Update writes canonical server-side.
- **§8.1 "Update always sends source through the compiler and replaces derived
  state from compiler output"** — honored: REST -> `persist_page` -> derived state
  replaced.
- **§8.1 "Undo/redo operates on source transactions"** — MVP omits undo/redo; we
  demo WP **revisions** instead (each Update = a revision). A scope cut, not a
  contradiction.
- **§8.2 layout** — top bar + right panel kept; left palette + structure tree
  dropped (components/structure deferred). Consistent with the do-not-build list.
- **§6.1 "editor renders declarations, nothing else"** — the Edit lens is exactly
  this: only `data-edit` nodes are selectable; absence = no field.
- **§6.2 slot vocabulary** — the panel renders exactly the six closed types.
- **No second save door** — the single crux invariant; §8.1 + the framework's
  "one compiler, three doors" law both demand it and are satisfied.

No contradictions found that are not explicit, recorded scope cuts.
