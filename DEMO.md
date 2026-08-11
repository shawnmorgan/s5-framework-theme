# S5 Editor MVP — Demo Script ("WordCamp cut")

A 5-minute live walkthrough of the S5 editor. The thesis: **one compiler, one
save door, HTML as the source of truth — an editor that only exposes what the
author declared editable, and never breaks byte-for-byte fidelity.**

Target: demo-ready. Everything below runs on the wp-env stack in this repo.

---

## 0. Setup (before you present)

```bash
npm install
npx wp-env start
npx wp-env run cli wp plugin activate s5-plugin tangible-loops-and-logic
npx wp-env run cli wp theme activate s5-theme
npx wp-env run cli wp rewrite structure '/%postname%/' --hard
npx wp-env run cli wp s5 import wp-content/s5-pages
```

> **macOS note:** if `wp-env start` fails with
> `error while creating mount source path … operation not permitted`, Docker
> Desktop lacks file-sharing permission for the project folder (common under
> `~/Desktop`). Grant it in **System Settings → Privacy & Security → Files and
> Folders** (or move the repo outside `~/Desktop`), then retry.

- Admin: <http://localhost:8888/wp-admin/>  (user `admin`, pass `password`)
- The demo page: **About** (`/about/`) — it carries editable declarations.

---

## 1. The page is real, the editor is a projection  (30s)

1. Open **Pages** in wp-admin. Hover **About** → click **Edit with S5**.
2. The editor opens: live preview of the *real* page on the left, panel on the
   right, top bar (back · viewport · Edit/Code · dirty · Update).
3. Point out: this is the **real rendered page** in the iframe (same canvas URL
   the public sees, with `?s5-preview=1`), not a mock. Resize with the viewport
   toggle — those are **real CSS breakpoints**.

> Talking point: *the iframe is a preview, never the state store. The source of
> truth is the canonical HTML; the editor is a projection over it.*

## 2. Props-driven selectability — the core idea  (60s)

1. In **Edit** lens, hover the page. Only **declared** elements outline: the
   hero title, the lede, the "Book a call" button.
2. Hover the body paragraphs under "How we work" — **nothing happens.** They
   carry no `data-edit`, so they are **absent from the editor, not greyed out.**
3. Click the hero title → the panel shows exactly one field (its declared type).

> Talking point: *the editor renders declarations and nothing else. If the
> author didn't declare it editable, the operator can't touch it — by
> construction, not by permission checks.*

## 3. Edit + save through the one door  (60s)

1. Edit the hero title text. The preview updates live (feedback), the **dirty**
   indicator lights, **Update** enables.
2. Click **Update**. Behind it: `POST /s5/v1/pages/{id}/save` → the value is
   written into canonical **server-side** via the tag processor →
   `Validator::validate` → `Compiler::persist_page` → a WP revision. The preview
   reloads from the server.
3. Show **Pages → About → Revisions** (or `wp post list --post_type=revision`):
   the edit created a revision.

> Talking point: *this is the SAME save door the CLI importer uses —
> `Compiler::persist_page`. The editor is a new caller, not a second pipeline.
> No "editor format" exists.*

## 4. Code lens + loud validation  (60s)

1. Switch to the **Code** lens. CodeMirror (shipped in WP core) shows the **full
   canonical fragment** — the real authored HTML.
2. Add a bogus class, e.g. `class="totally-not-real"`, to an element. Click
   **Save canonical**.
3. The save is **rejected**: a diagnostic list appears (rule, line, token,
   what's permitted) and **canonical is unchanged.** Loud, never silent.
4. Remove the bogus class, save again — green.
5. Show the **Head** form (title / description / canonical URL) — saved through
   the same endpoint (`mode:head`), separate from the body.

> Talking point: *the same Authoring Contract gate that guards import guards the
> editor. A bad edit can't land silently.*

## 5. Style tab reads the real tokens  (30s)

1. Click the **Style** tab. Swatches, type ramp, spacing steps, radius samples —
   all rendered **live from `tokens.css` / `scales.css`**, not hand-built HTML.
2. Note the **Components** and **Tailwind** tabs: present but disabled — scope
   markers for what comes after the MVP.

## 6. Loops & Logic: locked where it must be  (45s)

1. (If showing the dynamic fixture) open the **sitemap-archive** page in the
   editor. The static hero title is editable; the `<Loop>` list region shows a
   **locked "Dynamic — edit in code view"** block in the Edit lens.
2. Switch to **Code** lens: the L&L `<Loop>` / `<Field>` source is right there,
   fully editable.

> Talking point: *dynamic regions aren't faked into a form — they're honestly
> presented as code, because that's what they are.*

---

## The one-sentence close

> "HTML in, HTML out, byte-for-byte — one compiler, one save door — and an
> editor that only ever exposes what the author declared. Everything you just
> saw went through the exact same pipeline as a command-line import."

---

## Fidelity proof (optional, for a technical audience)

```bash
# Every check green, single clean-slate environment (Steps 0–10 + the editor):
npm test

# Just the editor contract:
npm run test:editor
```

`npm run test:editor` proves, in real WordPress: the screen is gated behind
`manage_options`; **no editor asset ships to logged-out visitors** (front-end or
`?s5-preview=1`); a slot save round-trips through `persist_page`; an invalid
Code-lens save is rejected with diagnostics and canonical is untouched; a head
save updates `_s5_head`; the Style tab reads real tokens; and authored
declarations round-trip **byte-clean**.

## What this cut deliberately leaves out

Component palette/studio, structure operations (insert/move/duplicate/hide),
editing-levels UI, MCP, Tailwind mode, undo/redo (we demo **revisions**),
`contenteditable`, media-library management, and any Style-tab editing. See
`DECISIONS.md` for every scope call and `EDITOR-DESIGN.md` for the full design.
