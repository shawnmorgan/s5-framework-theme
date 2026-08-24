# S5-ATOMIC-DESIGN-SPEC — v0.1

**Date:** 2026-08-21 · **Owner:** Shawn Morgan · **Status:** Approved architecture; v1.x/v2 build (staged — see §9)
**Companion to:** PRD v1.5 (extends §6 Human Editing Model, §7 Components, §8.4 Style surface) · Authoring Contract v0.1 · BEM + Token Spec v0.9
**Governing rule this spec exists to protect:** the front-end page canvas stays light-touch and locked-by-default. All authoring sophistication lives in the theme-settings control plane (Styles, Elements, Component Builder), which is fully human- AND agent-editable. Nothing in this spec adds capability to the page canvas.

---

## 0. Why this exists

The product moved the "who can build" bar so far toward code/agents that a non-coder can compose but not author. This spec restores authoring capability for non-coders **without** reintroducing a page builder — by making composition happen inside the Component Builder from a registered palette of primitives, while the page stays canonical HTML receiving finished stamps.

The line, stated once:
- **Compose** (arrange finished components on a page, edit their exposed slots, style within sanctioned axes) — human, no agent, no code, on the page canvas.
- **Author** (create new components by assembling registered atoms, or write structure the vocabulary can't yet express) — Component Builder for non-coders, code lens for developers, agent for speed. Output becomes composable inventory.

The component library is the accumulating boundary between agent-needed and human-sufficient. A mature install's library makes the maintenance majority pure composition.

## 1. The atomic tiers (mapped to this architecture)

Atomic design is adopted as the frame. Two kinds of atom, then one composition tier.

**Token atoms — the value layer.** Color, typography scale, spacing ladder, radius. Abstract values. Live in the **Styles tab** (settings). Human-editable within contract. Imported from the design MD; the same source generates the stylesheet, the style guide, and (Woo track) theme.json.

**Element atoms — the primitive layer.** Button, heading, paragraph, eyebrow, label, input, icon, link, list. Each is a **semantic HTML element + bound token/class defaults + a declared prop contract**. Agent-registered (requires vocabulary knowledge). **Referenced, not copied** (Figma-main-component behavior — editing the atom updates every use). Live in the **Elements tab** (settings) as an editable registry that doubles as the agent's contract.

**Components — the composition layer (molecules AND organisms collapse here).** A composition of element atoms (and smaller components) with declared slots. Molecule vs organism is a description of complexity, not a difference in mechanism — there is **one component type**. A form field (input + label + icon) and a Woo pricing card are the same object at different sizes.

## 2. The reference boundary (keeps the compiler two-deep-max)

- Components reference atoms. Atoms reference nothing (they are the floor).
- "What uses this atom?" is a **flat query**, not a graph traversal.
- When an element atom changes, recompile everything referencing it — bounded, deterministic, no builder-rot / no Gutenberg-style deprecation hell.
- Component→component nesting is permitted but is **not** the primary mechanism; the guaranteed-bounded relationship is component→atom. Deep component nesting, if allowed, obeys the same copy-on-insert stamping as page insertion (the nested component is a stamp; its internal atoms stay referenced).

## 3. Props: capability vs editability (the Sanity/Builder discipline)

**An atom declares prop capabilities. Editability is decided at composition time. Default is frozen.**

- The atom's prop contract is its **capability surface** (a button *can* expose label, href, variant). Agent-authored. This is not a statement about who can edit.
- When an atom is placed into a component (in the Component Builder), the author decides, per placement, which capabilities become **exposed slots** and which are **frozen values**. Default posture: **frozen** — you opt into exposure, you never opt out of full editability. A component author who does nothing ships a locked component.
- On the page, among exposed slots, editing levels (PRD §9) still gate **who** reaches them. Two gates in series: the component author decides what's exposable; the role system decides who reaches it.

This is what preserves client discretion — the reason to prefer this model over an everything-is-editable builder. The same heading atom exposes its text in a Hero and freezes everything in a Legal Footer.

## 4. The three surfaces (settings = editable, page = constrained)

**Styles tab (settings) — token atoms.** Three parts in a hierarchy: **tokens** (raw values, from the design MD) → **scales** (type/spacing/radius presets consuming tokens) → **element styles** (a listing of the registered element atoms — heading, body, eyebrow, button, label, icon — with their bound values). Fully human- and agent-editable. The human edits values within contract; structural changes (element tag, new prop) are agent/Developer work.

**Elements tab (settings) — element-atom registry.** The catalog of registered element atoms: each atom's semantic element, bound classes, and prop capabilities. **Editable registry** (human or agent authors/edits atoms here) that is simultaneously the **agent's contract** — an agent handed the theme reads this to know what primitives exist and builds within them instead of inventing parallel ones. Machine-readable + human-visible, one source.

**Component Builder (settings) — composition.** The Etch-style surface where atoms become components. Drag registered atoms onto a canvas → converts to canonical HTML below (hand-editable too) → declare which placed-atom props are exposed slots vs frozen → save to palette. Block-specific and plugin-specific config UI also lives here (see S5-PLUGIN-EXTENSIBILITY-SPEC). **This is the only place a visual assembler exists, and it is safe here because its output is one validated component's HTML, not a freeform page.**

**Page canvas — composition/consumption.** Drag finished components. Edit only exposed slots, gated by role. Light-touch by construction. Never sees atoms, never assembles primitives, never becomes a builder. Protected.

## 5. Storage & compile (where atomic design becomes a compiler pass)

- Token atoms: `site/styles/tokens.css` (+ scales.css). Unchanged from today.
- Element atoms: a registry (`site/atoms/` folder of definitions + a manifest, or an atoms registry file — technical-design decision) storing element + class binding + prop contract.
- Components: as today (`site/components/<name>/`), but a component's definition may store **atom references** (element atoms resolve at compile) rather than fully inlined HTML.
- **New compile step:** component resolution expands atom references → validated HTML → stamp. Same compile-on-write discipline as pages, one level down. Canonical page HTML remains the page-level source of truth; component definitions gain a resolution pass.

## 6. What ships as native atoms

At minimum the theme registers, out of the box, the atoms an agent needs to build around: heading, paragraph/body, eyebrow, button, link, label, icon, list, image, input. This registered set is what makes "hand your Claude Code the theme" produce coherent output — the agent builds from a known vocabulary, visible in the Elements tab.

## 7. Relationship to copy-on-insert (PRD §7.1)

Copy-on-insert remains true for **components inserted onto pages** — the page gets a stamped HTML copy. The atomic-reference model applies to **atoms inside component definitions** — those stay referenced so design-system edits propagate. No contradiction: page instances are copies; the atoms composing a component definition are references. The PRD §7.2 migration pass is how component *markup* changes reach existing page stamps; atom edits reach component definitions via the §5 resolution pass.

## 8. What this spec explicitly does NOT do

- Does not add primitives to the page canvas (no Add Atom on a page).
- Does not create arbitrary-depth nesting (atoms are a floor; component→atom is the bounded relationship).
- Does not make atoms editable-by-default (frozen default, opt-in exposure).
- Does not make the Styles/Elements tabs read-only (settings is the editable control plane).
- Does not introduce free CSS, arbitrary values, or color pickers at any tier (closed vocabulary holds; overrides are sanctioned classes per §3 of the override model).

## 9. Build staging

- **v1.x (cheap, high-value, near-term):** expand the Styles tab to the three-part tokens→scales→elements view; ship "save selection as component" (flatten a code-lens/agent-authored section into a reusable stamp — the 80% of human-autonomy without the hard nesting); ship the starter atom set + starter component library.
- **v2 (flagship):** element-atom registration as referenced primitives; the Component Builder primitive canvas (drag atoms → HTML); slot-forwarding/exposure UI; the atom-reference resolution compile pass.

Rationale for staging (scope owner decides dates): v1 proves the thesis with a library rich enough that composition covers the maintenance majority; the full atomic authoring system is the "grow your own library without the agent" maturity feature, and the reference-nesting model deserves deliberate design, not a deadline.
