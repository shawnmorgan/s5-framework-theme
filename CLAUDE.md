# Authoring Contract — operational rules for agents

You are authoring pages for an agent-native WordPress framework. **What you
write is what WordPress serves, byte-for-byte.** There is no builder, no block
markup, no translation layer. Two transforms touch your HTML and nothing else:
head assembly and media rewrite. Everything else is served verbatim.

That is the whole product thesis. Violating it is the only unrecoverable error.

Canonical specs (read when a rule here is ambiguous, not before every task):
`Authoring-Contract-v0.1.md` (rules) · `PRD-0-S5T-Launch-Sprint.md` (v0 scope) ·
`PRD-WP-Framework-v1.md` (destination). **Where they disagree, the Authoring
Contract wins, then this file, then the PRDs.**

---

## 1. Repo layout

```
/pages     one full HTML document per page. THE source of truth.
/parts     header.html, footer.html — shared chrome fragments.
/theme     shell theme: canvas template, styles/, parts/ loader.
/plugin    storage, render pipeline, importer, validator.
/assets    images referenced by relative path from pages/.
/tools     diff harness, validator, build scripts.
/dist      GENERATED. What `wp s5 import` consumes. Never hand-edit.
```

## 2. Page file format

Every file in `/pages` is a **complete HTML document** — not a fragment. The
importer splits it:

- `<head>` contents → stored page meta, re-emitted by the theme.
- `<body>` contents → the fragment, stored and served **verbatim**.

Write real `<title>`, `<meta name="description">`, OG tags, canonical, and
JSON-LD in the head. You are authoring the site's actual SEO surface; nothing
downstream fills these in for you.

## 3. The class vocabulary is CLOSED

Every class you write must already exist in the enumerated vocabulary
(`manifest.json`). **An unknown class is a hard import failure, never a silent
pass.** If you need a class that does not exist, STOP and escalate — never
invent one inline, never add it to the stylesheet yourself.

Grammar (machine-parseable — the validator checks the pattern, not your intent):

| Layer | Pattern | Example |
|---|---|---|
| Block | dashes | `.pricing-card` |
| Element | single underscore | `.pricing-card_title` |
| Modifier | double dash | `.pricing-card--dark` |
| Utility | `u-` prefix | `.u-text-center` |
| Generated scale | `{category}-{step}` | `.text-xl` |

## 4. Tokens carry values. Classes carry decisions.

- **No raw values in markup or component CSS.** No hex, no px font-sizes, no
  arbitrary spacing. Reference tokens.
- **rem/em only.** Pixels only for: 1px hairlines, -1px sr-only, 2px
  micro-adjust, and inside `clamp()`/`calc()`.
- **Step ladder is `xxs / xs / sm / md / lg / xl / xxl / xxxl`.** No numeric
  steps — `2xl` and `4xl` do not exist and never will.
- **Nobody adds scale slots.** Values are per-brand; slots are theme law.
- **No inline `style=` attributes.** Ever. This is a hard gate.

## 5. Parents own layout. Children own appearance.

Alignment, distribution, flow and spacing-between live on **wrappers**.
Typography classes never carry placement. If you find yourself putting a margin
on a heading, the wrapper is wrong.

**Spacing (A7, ruled):** section padding step or parent gap step. Do NOT reach
for `.space-top-{step}` / `.space-bottom-{step}` as a general tool — they are
reserved for standalone elements in prose flow. Getting this wrong means
refactoring live pages when the editor ships.

## 6. Fluid first

Type and rhythm scale via `clamp()`. Breakpoints are for **structural reflow
only** — column collapse, nav swap. Never size stepping. Do not author fixed
`48/60/80px` headline sizes; use a `--text-*` step.

## 7. SEO / AEO gates (enforced at import — failing these blocks the build)

- Exactly **one** `<h1>` per page.
- Heading hierarchy does not skip levels.
- `alt` on every content image.
- `<title>` + meta description present; single canonical; OG tags present.
- Internal links resolve.
- The LCP element is **not** hidden and **not** lazy-loaded.
- **Content is visible in initial CSS.** Never `opacity: 0` as an initial state
  on content. Hide-states only under an `html.js` scope, or animate from
  visible. A visitor with JS off must see everything.

## 8. Media

Reference assets by **relative path** (`assets/hero.jpg`). The importer sideloads
them and rewrites to attachment-backed markup with srcset, dimensions, lazy
loading, and `fetchpriority="high"` on the LCP image. Never hardcode a WordPress
attachment ID or an absolute uploads URL — it breaks portability across
local/staging/prod.

## 9. Declarations (inert in v0 — author them anyway where obvious)

Content editability is **opt-in**; structure is **opt-out**.

- `data-edit="{type}"` marks an editable slot. Six types, closed set:
  `text` · `rich` · `img` · `bg-img` · `link` · `embed`.
- `data-lock` freezes structure. `data-hidden="true"` is stored but stripped at
  render (genuinely absent from served HTML, not `display:none`).

These do nothing in v0. They become the editor's first test corpus, so marking
the obvious slots now is free; guessing at exotic ones is not.

## 10. Before you say a page is done

1. Run the validator. **A green lint only counts if your file is in the linted
   set** — a hardcoded file list has already shipped one real bug here. Confirm
   your new file is actually covered before trusting a PASS.
2. Run the round-trip diff. **Zero unexplained deltas** modulo the two sanctioned
   transforms. This is the definition of done, not a formality.
3. Do **not** claim a mobile pass from a screenshot tool — the browser tool
   cannot change the captured viewport, so it silently reports desktop. Mobile
   is verified by a human at 390px.

## 11. Escalate, never improvise

Stop and ask when: a class you need does not exist · a token slot is missing · a
page needs a construct the vocabulary cannot express · the diff shows a delta you
cannot explain. **A gap stops the build and escalates; it is never filled
inline.** Silent workarounds are worse than a blocked build.
