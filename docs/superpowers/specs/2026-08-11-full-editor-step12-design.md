# Full Editor (Step 12) — Technical Design

**Date:** 2026-08-11 · **Owner:** Shawn Morgan · **Status:** APPROVED — two-phase build (Phase 1 EOD Aug 14; Phase 2 post-Aug 18, gated on Phase 1 acceptance)
**Builds on:** Editor MVP ("WordCamp cut", `EDITOR-DESIGN.md`) · **Grounds in:** PRD v1.5 §6, §8, §9 · BUILD-ORDER Step 12 · Authoring Contract Part B
**Companion:** `DECISIONS.md` (new D-entries recorded as built)

This design turns the MVP editor into the **full Step 12 human-editing projection**. It is
strictly additive: every MVP guarantee (one save door, byte-fidelity, anon-asset isolation)
is preserved. The three locked decisions:

1. **Shell:** WP-provided React (`@wordpress/element`) — real React per §8.1, **no npm/bundler**.
2. **Structure ops:** full set — insert / move / duplicate / hide / delete.
3. **Capability levels:** Content / Design / Developer, **per-role AND per-user (per-user wins)** per §9.

---

## Approval rulings (locked 2026-08-12)

**Two-phase build. CanonicalOps is the shared foundation — built in Phase 1, sized for both.**

- **Phase 1 — WordCamp cut (EOD Aug 14, hard):** CanonicalOps (full byte-surgical API) ·
  React re-home of the MVP panels (wp-element + htm) · Edit lens (all six slot types, live
  typing, one Update through the one door) · Code lens (CodeMirror + in-editor diagnostics +
  head sidebar) · read-only Style tab (+ disabled Components/Tailwind tabs) · dynamic regions
  as locked blocks in Edit lens · props-driven selectability (the demo moment) · access gated
  `manage_options`. **NOT Phase 1:** structure-ops UI, capability levels, undo/redo.
- **Phase 2 — Full Step 12 (post-WordCamp, gated on Phase 1 acceptance green + explicit go):**
  structure-ops UI · three capability levels (per-user override) · undo/redo op-stack.

**Rulings on the three asks:**
1. **Undo/redo** — approved as designed (client op-stack replaying inverse ops through the same
   door). Degrade path is real: **Phase 1 ships WITHOUT undo/redo — revisions are the demo
   answer; undo lands in Phase 2.**
2. **Duplicate re-keying** — approved and **extended: re-key RECURSES** over every `data-node`
   in the copied subtree (not just the root), and the **SERVER assigns new IDs, never the
   client** — the compiler owns ID uniqueness.
3. **htm** — use the **vendored single-file `htm`** over raw `createElement` (panel code changes
   constantly; nested `createElement` is maintenance tax). No-bundler stance unchanged.

### Amendment A — structure laws enforced SERVER-SIDE in CanonicalOps (not just UI)

CanonicalOps enforces the structural laws itself; the UI merely reflects them.
- **`data-lock`** blocks ALL ops on **and inside** the locked subtree (a locked ancestor freezes
  its descendants too).
- **Component-instance internals** (anything inside a `data-component` subtree) accept **HIDE
  ONLY** — no insert / move / duplicate / delete of internals. Internals flex via **props**, per
  §6.6 ("Inside components, structure belongs to the component definition"). The component
  instance itself (the `data-component` root) remains movable/duplicable/deletable as a whole.
- **Violations = rejected op with diagnostics** — same loud posture as the Validator (rule,
  offending node, reason). Enforced in CanonicalOps so CLI, REST, and MCP all inherit it.

### Amendment B — hide routes through EXISTING data-hidden + render-strip (no second mechanism)

`hide` sets/clears `data-hidden` on the node (surgical attribute write); the **compiler's
existing hidden-node render-strip** (PRD §3.2) removes it from derived render. There is **no
second hide mechanism**. In the editor, hidden nodes are **ghosted in preview via the overlay
layer ONLY**; **served output strips them entirely** — standing rule: no editor chrome in served
output, ever. (If the compiler's hidden-strip isn't yet wired, adding it to the compile step is
the one small, recorded compiler addition — verified in §6.1, canonical keeps the node.)

---

## 0. What already exists (MVP — reused verbatim)

| Asset | Reused as |
|---|---|
| `Rest` — `POST /s5/v1/pages/{id}/save`, modes slots/canonical/head | The **one save door**. New structure ops become new *modes* on it, not a new route. |
| `Compiler::persist_page()` | Unchanged. Every mutation still ends here → compile → mirror → atomic persist → revision. |
| `Validator::validate()` | Unchanged. Every mutation validates the RESULT before persist; ERROR ⇒ reject, canonical unchanged. |
| `ComponentCommand::splice()` | The balanced-tag insert primitive. **Extracted** to a shared `CanonicalOps` service so CLI + REST share one implementation. |
| `Editor` admin screen, iframe preview, `?s5-preview=1`, `overlay.js` | The shell. React app mounts into the same screen; overlay gains selection-of-structure-nodes. |
| `Styles::read_tokens()`, Style tab | Unchanged (read-only). |

**Nothing about fidelity changes.** `data-node` stays AUTHORED (D4); the compiler never injects
IDs; every structure edit is a surgical splice on byte-bounded regions, leaving untouched bytes
identical.

---

## 1. New service layer — `CanonicalOps` (the load-bearing addition)

A single PHP class of **pure canonical→canonical string transforms**, each keyed by authored
`data-node`, each byte-surgical. This is where the real work lives; REST and CLI are thin callers.

```
CanonicalOps::insert_component(canonical, componentHtml, afterNode|null) : string     # = MVP splice()
CanonicalOps::move(canonical, node, beforeNode|afterNode)                : string|WP_Error
CanonicalOps::duplicate(canonical, node)                                 : string|WP_Error
CanonicalOps::hide(canonical, node, hidden:bool)                         : string|WP_Error   # sets/clears data-hidden
CanonicalOps::delete(canonical, node)                                    : string|WP_Error
CanonicalOps::outline(canonical)                                         : array             # the structure tree
```

- **Node bounds** are found exactly as `splice()`/`replace_inner()` already do: locate the open
  tag carrying `data-node="{id}"`, walk to its balanced close tag (nested-same-name aware).
  `move`/`duplicate`/`delete` operate on the **whole [open…close] byte range**.
- **`hide`** is the one op that does NOT restructure: it sets `data-hidden` on the node via
  `WP_HTML_Tag_Processor` (surgical attribute write). The compiler already strips hidden nodes
  into derived render (PRD §3.2 "hidden-node removal") — so hide is a *declaration*, canonical
  keeps the node, render omits it. (If the compiler's hidden-strip isn't wired yet, that becomes
  a small, recorded compiler addition — verified in §6.)
- **`outline`** parses canonical into a shallow tree of structurally-meaningful nodes for the
  React tree panel: every element carrying `data-node`, `data-edit`, `data-component`, or
  `data-lock`, with nesting, type, label, and lock/hidden state. Read-only projection; never a
  state store.

**Move/duplicate need a target.** Both operate between siblings addressed by `data-node`. A node
without a `data-node` cannot be a move target or be reordered — consistent with the law that only
declared things are operable. (Author adds `data-node` to make a region structurally editable;
absence = not operable, not a missing feature.)

---

## 2. Save door — new modes (same route, same pipeline)

`POST /s5/v1/pages/{id}/save` gains a `mode:"structure"` branch:

```
mode:"structure", op:"insert|move|duplicate|hide|delete", node, target?, component?, hidden?
   → CanonicalOps::{op}(currentCanonical, …) → newCanonical
   → Validator::validate(newCanonical)   # SAME gate; ERROR ⇒ 422 + diagnostics, canonical UNCHANGED
   → Compiler::persist_page(id, newCanonical)   # SAME door → revision
   → return { ok, render, outline, diagnostics }
```

For `op:"insert"` the endpoint resolves `component` through `ComponentRegistry::is_registered()`
+ `load()` — **an unregistered component is refused at the door** (the acceptance guarantee),
identical to the CLI. No second insert path.

The existing slots/canonical/head modes are untouched.

---

## 3. Capability levels (§9) — `Capabilities` service

A namespaced level model independent of raw WP caps, resolved per user:

```
Levels: content < design < developer
Resolution (per-user WINS):
  1. user meta  _s5_level            → if set, use it
  2. role map   s5.config.json levels.roles{role:level}  → else highest matching role's level
  3. default    'content'
Anyone with real WP `manage_options` may change their own level (guardrail, not a lock — §9).
```

**What each level may do (gates which lenses/ops the React app renders AND which the REST door
accepts — server is authoritative, UI merely reflects it):**

| Capability | Content | Design | Developer |
|---|---|---|---|
| Edit declared slots (Edit lens) | ✓ | ✓ | ✓ |
| Choose media for declared img/bg-img | ✓ | ✓ | ✓ |
| Insert registered component | ✓ | ✓ | ✓ |
| move / duplicate / hide / delete (unlocked nodes) | ✓ | ✓ | ✓ |
| Edit page meta (head form) | ✓ | ✓ | ✓ |
| Code lens (edit tags/classes/attrs where allowed) | — | ✓ | ✓ |
| Parts/partials, scripts/settings | — | ✓ | ✓ |
| Raw canonical source (full fragment replace) | — | — | ✓ |
| Component authoring / import / migration | — | — | ✓ |

- **`data-lock`** freezes structure ops on a node for **all** levels (author's hard freeze, §6.6).
- Enforcement is **server-side in `Rest::can_do(op, level)`**; the React UI hides what the level
  can't do purely for UX. A Content-level token that hand-crafts a `mode:"canonical"` request is
  rejected by the door — this is the §9 / Step 13 acceptance ("Content token cannot do Developer
  mutation"), proven now so MCP inherits it for free.
- MVP's `Editor::CAPABILITY = manage_options` is replaced by "can open editor = level ≥ content
  AND `edit_post`"; the gate stays honest for anon (no level ⇒ no editor, no assets).

---

## 4. React shell (`@wordpress/element`, no build step)

- The MVP's vanilla `editor.js`/`panel.js`/`codelens.js`/`stylestab.js` are **re-homed under a
  React tree** mounted into the same admin screen container. React comes from WP core
  (`wp-element`, `wp-components`, `wp-i18n`) via `wp_enqueue_script` deps — zero npm.
- **No JSX build:** components author markup with `wp.element.createElement` through a terse `h()`
  alias (or a single vendored `htm` file if readability demands — decided during build, recorded).
  This is the §8.1 "React application shell" requirement met without a toolchain (recorded
  deviation from *bundled* React; the state contract §8.1 actually mandates is honored exactly —
  see §5).
- Panels: **left** = component palette (registered components from a new
  `GET /s5/v1/components`) + structure tree (from `outline`); **right** = declared field controls
  (Edit lens) or CodeMirror (Code lens, Design+); **top bar** unchanged + undo/redo (§5).

## 5. State model (§8.1 — unchanged contract, now with a tree)

Authoritative state stays **canonical source + declared operations**; the iframe is preview-only.
Additions:

- **`outline`** (server-derived tree) drives the structure panel; selection syncs iframe↔tree↔
  right panel via `postMessage` (overlay extended to report `data-node` on structural nodes).
- **Undo/redo operates on source transactions** (§8.1): the client keeps a stack of
  *applied-op descriptors* (not DOM snapshots); undo replays the inverse op through the same door.
  Each Update is already a WP revision, so revisions remain the durable history; the op stack is
  the in-session ergonomic layer. (If time-boxed, undo/redo degrades to "revisions only" — a
  recorded slip, not a contradiction.)
- Live edits patch the iframe for feedback; **nothing persists until the op/save hits the door.**

---

## 6. Fidelity & compiler checks (must-verify, could-STOP)

These are the places the build could touch a fidelity guarantee — each is verified, and any that
can't reuse an existing path **stops for you** per the standing rule:

1. **Hidden-node removal into derived render** — if `Compiler` doesn't already strip `data-hidden`,
   add it *in the compile step only* (never in canonical). Verify: hidden node present in
   canonical/Git, absent in served render, round-trip still byte-clean on the canonical side.
2. **Move/duplicate/delete byte-surgery** — after any structure op on a page with NO other change,
   re-exporting canonical must differ only by the intended region; Step 3 harness must stay green
   for untouched pages. New Step-12 acceptance asserts this.
3. **`data-node` still authored** — structure ops never inject IDs. A duplicated node keeps the
   source's `data-node`; **duplicate must re-key the copy** (append `-2`, `-3`…) or it breaks the
   "unique node" assumption slot-writes rely on — decided: duplicate re-keys, recorded as a
   decision, asserted in acceptance.

---

## 7. Acceptance (new `tests/acceptance-editor-full.sh`, wired into the gate)

Proves BUILD-ORDER Step 12 lines 334–339 + §9:

1. **Undeclared text not editable** — a node without `data-edit` yields no Edit-lens field and a
   slot-write targeting it is refused.
2. **Declared field editable** — slot write lands through the door + revision (MVP-covered; re-run).
3. **Registered component insertable** via `mode:structure/insert`; **unregistered refused** at the door.
4. **Structure ops**: move / duplicate / hide / delete each produce the expected canonical, validate,
   persist, revision; duplicate re-keys `data-node`; hidden node absent from render, present in canonical.
5. **Unregistered structure cannot be invented** — no op creates a non-registered container; there is
   no add-div path (asserted by absence: the only insert is component insert).
6. **Capability boundary** — a Content-level user's `mode:canonical` (raw source) request is rejected
   403; a Developer-level user's succeeds. Per-user override beats role.
7. **Save = one revision path** — every op above creates exactly one WP revision via `persist_page`;
   restore a revision, render regenerates (reuses Step 5 restore).

---

## 8. What this pass deliberately does NOT build

- Component **Studio** (§7.3 authoring workbench) — separate future track.
- Tailwind CSS mode (§5.2), MCP (Step 13), Woo, S5 Dynamics (separate decisions).
- Media-**library** management UI — media *selection* for declared fields only.
- Migrations engine (§7.2) beyond what Step 11 already stubs.
- llms.txt / robots panel (§13.1a) — tracked in GAPS.md, not Step 12.

---

## 9. Build order (commit per numbered item)

1. `CanonicalOps` service (extract `splice`; add move/duplicate/hide/delete/outline) + unit-level CLI probe.
2. `Capabilities` service (level resolution, per-user override, config role map).
3. REST: `mode:structure` + `GET /components` + `can_do` server-side enforcement.
4. Compiler: hidden-node strip in derive step (only if not present) + fidelity verify.
5. React shell re-home (wp-element) + structure tree + component palette panels.
6. Edit-lens/Code-lens/undo-redo under React; capability-filtered rendering.
7. `acceptance-editor-full.sh` + gate wiring + `DECISIONS.md`/`GAPS.md` updates + memory note.

**STOP triggers (standing rule):** any change that would (a) touch canonical verbatim / the
sanctioned-transform list / ship framework attrs in served output, or (b) force a second save
pipeline. Hidden-strip (§6.1) and duplicate re-keying (§6.3) are the two spots most likely to
surface one — both are flagged to pause and confirm rather than push through.
