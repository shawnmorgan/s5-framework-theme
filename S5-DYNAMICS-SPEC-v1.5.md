# S5-DYNAMICS-SPEC — v1.5

**Date:** 2026-08-11 · **Owner:** Shawn Morgan · **Status:** Approved for build (build trigger: first real dynamic page)
**Companion to:** PRD v1.5 · Authoring Contract v0.1 (this spec becomes Part B addendum "Dynamics" on ratification)
**Supersedes:** L&L as the dynamic layer. **L&L never appears in any real site's canonical HTML.** The Step 8 L&L integration is retired; its seam (dynamic-page flagging, runtime pipeline ordering, static byte-clean guarantee) is retained and is the execution substrate for this spec.

---

## 0. Laws

1. **Ownership:** every capability this spec advertises is owned 100% in-theme. No third-party dynamics engine, ever. Commercial gate: no external revenue on any dynamics not covered by this spec's implementation.
2. **Closed vocabulary:** tags, attributes, conditions, and formats are enumerated here. Unknown anything = **import ERROR** with diagnostics (rule, location, did-you-mean). Gaps escalate to islands/PHP/spec-revision — never to syntax growth.
3. **Loud at write, deterministic at read:** vocabulary errors are compile-time and block import. Runtime has no error class — only data-empty states, which are author-declared.
4. **No expression language. Ever.** No variables, math, string ops, user-defined tags, filter pipelines, third-party tag API, or PHP-in-templates.
5. **Static pages never enter the dynamic pass.** Byte-clean guarantee untouched.

## 1. Syntax

HTML-native custom elements, `s5-` namespace, attribute-parameterized, nestable per §4. Same convention as `<s5-part>`.

```html
<s5-loop type="post" count="5" orderby="date" order="desc">
  <article>
    <h3><s5-field name="title"></s5-field></h3>
    <s5-if field="subtitle">
      <p class="post_subtitle"><s5-field name="subtitle"></s5-field></p>
    </s5-if>
    <p><s5-field name="excerpt" words="30"></s5-field></p>
  </article>
  <s5-empty>
    <p>No posts yet.</p>
  </s5-empty>
</s5-loop>
```

Tags are always explicit open/close (no self-closing), lowercase, double-quoted attribute values. Validator enforces.

## 2. v0 tag set

### `<s5-loop>`
Runs an enumerated WP_Query slice; body = per-item template; pushes item context (§4).
Attributes (all optional except `type`):
| attr | values | notes |
|---|---|---|
| `type` | `post`, `page`, any registered CPT slug | validated against registered types at import |
| `count` | int 1–100 | default 10 |
| `orderby` | `date`, `title`, `menu_order`, `rand`, `modified` | default `date` |
| `order` | `asc`, `desc` | default `desc` |
| `taxonomy` + `term` | registered taxonomy slug + term slug | both or neither; validated at import |
| `offset` | int ≥ 0 | |
| `exclude-current` | `true` | omit the context post (related-posts case) |
Not v0, design-reserved: `paged` (pagination — loop design must not preclude it), `meta-key`/`meta-value` queries, post__in lists.

### `<s5-field>`
Resolves a named field against current context (§4) via the binding waterfall (§5); output is escaped, inert text/markup (§7).
Attributes:
| attr | notes |
|---|---|
| `name` | required. Field name as registered — core, meta, or custom-field tool |
| `source` | optional disambiguation: `core`, `meta`, `acf`, `metabox`, `pods`. Required by validator only on collision (§5) |
| formatting | per-type, §6 |
Core names (always available): `title`, `excerpt`, `content`, `permalink`, `date`, `modified`, `author`, `featured-image`, `slug`, `id`.

### `<s5-if>` / `<s5-else>`
Renders body when condition true; optional single `<s5-else>` block.
Condition attributes (exactly one per tag):
| attr | true when | cache class |
|---|---|---|
| `field="x"` | field resolves non-empty in context | content |
| `field-equals="x:value"` | field's scalar value equals `value` | content |
| `has-term="taxonomy:term"` | context post has term | content |
| `is-front` / `is-single` / `is-archive` | current view matches | content |
| `logged-in="true|false"` | visitor auth state | **user-state** (§9) |
| `role="slug"` | logged-in visitor has role | **user-state** (§9) |
Not v0: and/or composition (nest `<s5-if>` instead, within depth cap), date-window conditions.

### `<s5-empty>`
Legal only as direct child of `<s5-loop>`. Body renders when the query matches zero items. Absent = loop renders nothing on empty. This is the author-declared empty state; the runtime has no other empty behavior.

## 3. Execution model

**Compile time (import/save):**
1. Validate every `s5-*` tag/attribute/value against this spec (types, taxonomies, and — where resolvable — field names checked against the live registry; unresolvable field names WARN, not ERROR, since fields may register later).
2. Auto-flag page/template/partial dynamic when any `s5-*` tag present (no manual flag).
3. Record tag inventory in sidecar: tags used, loop types, taxonomies, condition classes. Drives cache purge (§9) and future migration tooling.

**Request time (dynamic pass — existing Step 8 seam, engine swapped):**
Flagged documents only: walk render HTML → execute handlers innermost-context-outward → splice output in place → shortcode pass → serve. Deterministic: same data in, byte-identical out.

## 4. Context stack

The engine's core. Every `<s5-field>`/`<s5-if>` resolves against the top of the stack.

**Seeds (fixed per surface):**
| surface | seed context |
|---|---|
| page | the page itself |
| single template | the queried post |
| archive template | the main query (loop over it via `<s5-loop type="main">` — the one special loop type, archive templates only) |
| partial | the context of its expansion point (partials are context-transparent) |

**Stack ops:** `<s5-loop>` pushes each item as context for its body iteration, pops on close. Nested loop pushes on top. **Depth cap: 2** (loop-in-loop max; validator ERROR beyond). Partials may contain tags; loops may expand partials; partial→loop→same-partial cycles remain an import error under existing parts machinery.

## 5. Field resolution (binding adapters)

**Waterfall, in order:** core fields → post meta → registered custom-field adapters (ACF, MetaBox, Pods — detected at runtime, queried through one adapter interface).

- Author default: `name` only. The resolver finds it.
- **Collision = import ERROR** when a name resolves in >1 source and no `source=` given: *"`price` resolves in both acf and meta — add source="*. Loud disambiguation, never silent precedence.
- `source=` is optional targeting, required only on collision.
- Adapter interface ships v0 (design contract); **only core + meta adapters implement in v0.** ACF/MetaBox/Pods adapters are additive implementations against the same interface — zero authoring-surface change when they land.
- **v0 value rule:** scalar fields render; image-type fields render as `<img>` via `size=` (§6); **repeaters, relationships, groups, flexible content = out of v0** (they are loops-over-fields; reserved as a designed expansion, never a half-shipped default).

## 6. Formatting (enumerated attributes, per field type)

| field type | attrs | notes |
|---|---|---|
| text | `words="n"`, `chars="n"` | truncation adds ellipsis |
| date | `format="php-date-string"` | site timezone/locale; default = site date format |
| image | `size="thumbnail|medium|large|full|registered-size"` | emits full attachment-backed markup (srcset/dims/lazy) via the existing media machinery |
| permalink/url | none | escaped as URL |
No filter pipeline. New formatting need = spec revision.

## 7. Output inertness & escaping

Field output is **inert**: values containing shortcodes or `s5-*` markup render as text, never execute (splice happens post-execution, invisible to dynamic and shortcode passes). Escaping is context-aware by splice position: HTML-text default, URL context in `href`/`src`, attribute context inside attributes. Raw HTML output is a **per-field opt-in** `raw="true"`, valid only on `content` and fields whose adapter declares them HTML-safe; validator warns on every use.

## 8. Editor integration

- The editor preview iframe renders the real runtime — dynamic output appears with no editor-side render engine, ever.
- **Phase split:** vocabulary errors never reach a rendered page (blocked at save with code-lens diagnostics). Runtime-empty states render per author declaration.
- Logged-in editors see preview-layer placeholder chrome on empty dynamic regions ("s5-loop: 0 matches") **injected by the editor overlay only** — the render pipeline emits no chrome; served output identical for visitors; harness stays green.
- Edit lens: dynamic regions = locked block, badge "Dynamic — edit in code view." Full editing in code lens.

## 9. Cache semantics

- **Content-class dynamics** (loops, fields, content conditions): page remains full-page cacheable. Purge is targeted: content save → purge pages whose sidecar inventory matches (post type, taxonomy). 
- **User-state conditions** (`logged-in`, `role`): page becomes cache-exempt per visitor class. **Validator WARNING on every use:** "this page becomes cache-exempt." Author chooses the cost knowingly.

## 10. Harness contract

- Canonical fragments: byte-verbatim in storage, always — assertion unchanged.
- Static pages: full byte-diff, unchanged.
- Dynamic pages, served output: structural assertions — zero `s5-*` tags or attrs in output, expected content present for fixture data, declared empty-state renders on empty fixture — plus determinism (two renders, identical bytes).
- Sanctioned-transform list gains its reserved dynamics entry.

## 11. Reserved expansions (designed later, never improvised)

Pagination (`paged` + nav emission) · repeaters/relationships (loop-over-fields design) · meta-value queries · condition composition · date-window conditions · **the Woo set** (product loop, product fields, price/stock/availability, variation data — specced at Woo-track open, same laws, same adapter pattern against Store API/Woo data) · ACF/MetaBox/Pods adapter implementations.

## 12. Build plan

**v0 (trigger: first real dynamic page — shawnmorcan.co blog):** engine (context stack + splice), `s5-loop` (post/page/CPT + `main`), `s5-field` (core + meta adapters), `s5-if`/`s5-else` (full v0 condition set), `s5-empty`, formatting table, validator vocabulary, sidecar inventory, purge hooks, harness assertions, editor presentation. Estimate: days against the existing seam and tag processor, not weeks.
**Retire L&L:** remove vendor plugin + Step 8 fixture dependency once v0 green; Step 8 acceptance re-pointed at s5-dynamics; liability ledger closed with disposition "replaced before any real-site usage."

## 13. Explicitly never

Expression language · variables · math/string ops · user-defined tags · third-party tag API · filter pipelines · PHP in templates · unbounded nesting · silent field-source precedence · runtime vocabulary errors · editor chrome in served output.
