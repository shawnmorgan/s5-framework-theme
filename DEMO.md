# S5 — WordCamp Demo Click-Script

A ~6-minute live walkthrough. The thesis: **HTML is the source of truth. One
theme, one compiler, one save door. The editor only exposes what the author
declared editable — and never breaks byte-for-byte fidelity.**

Everything below runs on the wp-env stack in this repo. The framework ships as a
**single theme** — there is no companion plugin.

---

## Install on a real site (the uploadable zip)

The framework installs like any other WordPress theme — no CLI, no composer, no
build tools on the target.

```bash
npm run build:zip          # produces dist/s5-theme.zip (self-contained)
```

Then, in wp-admin:

1. **Appearance → Themes → Add Theme → Upload Theme** → choose `dist/s5-theme.zip`
   → **Install** → **Activate**.
2. On activation the design system is compiled and the demo pages are seeded, so
   the site is styled and has content immediately. If you ever see the notice
   *"the design system is not compiled yet"*, click **Compile styles now** — it is
   a one-click rebuild.
3. Open the top-level **S5** menu:
   - **Setup & Status** — environment health (code, design system, compiled CSS,
     framework-page count) and, on a site with existing content, an
     *"N existing pages found — rendering via the styled fallback until adopted"*
     list with per-page and bulk **Adopt into S5**.
   - **Add S5 Page** — creates a framework page and opens it straight in the S5
     editor.
   - **Style** / **Components** — jump into the editor's Style / Components panels.
4. **Existing content is safe.** Menus, front-page settings, and existing pages/
   posts survive activation untouched. Non-framework pages render through a styled
   fallback (the design system's base typography), never browser-default. Adopting
   a page is explicit and reversible via the page's revisions.
5. **Edit with S5** appears as a row action on framework pages (and in the admin
   bar when viewing one); framework pages open the S5 editor, not Gutenberg.

> The permanent guarantee that this all works from the zip with **no repo mount**
> is the `distribution` acceptance suite (`npm run test:dist`), which installs the
> built zip into a clean, mount-free WordPress and asserts styled serving for both
> a fresh site and a site that already had content.

---

## 0. Setup (before you present)

```bash
npm install
npx wp-env start
npx wp-env run cli wp theme activate s5-theme     # the framework IS the theme
npx wp-env run cli wp rewrite structure '/%postname%/' --hard
npx wp-env run cli wp s5 import wp-content/s5-pages
```

> **macOS note:** if `wp-env start` fails with `error while creating mount source
> path … operation not permitted`, run the env from a Docker-mountable mirror
> (e.g. rsync the repo to `~/s5-work/…` and start wp-env there) or grant Docker
> file-sharing access to the project folder.

- Admin: <http://localhost:8888/wp-admin/> (user `admin`, pass `password`)
- Demo page: **About** (`/about/`) — it carries editable declarations.

---

## 1. One theme, real page, editor is a projection  (30s)

1. In wp-admin, open **Appearance → Themes**: there is one S5 theme, and **no S5
   plugin** under Plugins. All the framework — compiler, CLI, REST, editor — lives
   in the theme; the site's own content lives in the theme's fenced `site/` subtree.
2. Open **Pages** → hover **About** → **Edit with S5**.
3. The editor opens: **left** = components palette, **center** = live preview of the
   *real* page, **right** = panel with the page structure + fields, top bar
   (back · viewport · Edit/Code · dirty · Update).

> *The iframe is a preview, never the state store. The truth is the canonical HTML;
> the editor is a projection over it.*

## 2. Glass edit — declared, live, one door  (45s)

1. In **Edit** lens, hover the page: only **declared** elements outline (hero title,
   lede, the button). Click the hero title → the panel shows exactly its field.
2. Type a new title — the preview updates live, **dirty** lights, **Update** enables.
3. Click **Update**: `POST /pages/{id}/save` → written into canonical server-side →
   `Validator::validate` → `Compiler::persist_page` → a WP revision. Preview reloads
   from the server.

## 3. Code edit — same speed, same door  (45s)

1. Switch to **Code** lens. CodeMirror shows the **full canonical fragment** (real
   authored HTML). Edit the same title in code — save through `mode:"canonical"`.
2. Plant a bogus class (`class="totally-not-real"`) → **Save canonical** → the save
   is **rejected** with an in-editor diagnostic (rule/line/token) and canonical is
   unchanged. Remove it, save → green. Show the **Head** sidebar too (title /
   description / canonical, `mode:"head"`).

> *Glass and code are two lenses on the SAME canonical, saved through the SAME door.*

## 4. Drag a component in  (45s)

1. From the **left palette**, drag **hero** (or **cta**) onto a section in the
   preview — drop it. The server expands the component, assigns **fresh recursive
   `data-node` ids** (drag it in twice — no id collision), validates, and persists
   through the one door. The preview reloads with the new block.
2. The inserted block's own scoped CSS is already live — it rode in through the
   compiled stylesheet bundle.

> *Insert is copy-on-insert: the component expands into plain canonical HTML and
> stops being a separate abstraction. The compiler owns id uniqueness — the client
> never assigns ids.*

## 5. Edit the inserted content  (30s)

1. Click the freshly-inserted hero's title in the glass (or find it in the **right-
   panel structure list** and click the row — selection syncs both ways with the
   preview). Edit its text → **Update**. It is immediately editable, like any slot.

## 6. Edit the component ITSELF  (45s)

1. Open the **Components** tab. Grid of every registered component (from the folder
   scan). Click **cta**.
2. Edit its `style.css` (e.g. change a color) — the **live preview** updates. Switch
   to `component.html` / `manifest.json` as needed. Click **Save component**.
3. The server writes the files and **rebuilds the CSS bundle** — the style change is
   now global. (Existing inserted instances keep their HTML — copy-on-insert
   independence — but pick up the new scoped styles.)

## 7. Style tab — tokens + CSS mode  (30s)

1. Open the **Style** tab: color swatches, type ramp, spacing, radius — all rendered
   **live from `tokens.css` / `scales.css`**, read-only, so they can't drift.
2. Point at the **CSS MODE** switch: **Semantic (active)** vs **Tailwind (coming
   soon)** — disabled, with a tooltip stating the plan. Click **View compiled
   stylesheet** to show the real `site/dist/s5.css` that ships.

## 8. Props-off element is untouchable  (20s)

1. Back in **Edit** lens, hover a body paragraph with no `data-edit` — **nothing
   outlines, nothing selects.** It's absent from the editor by construction, not
   greyed out by a permission check.

## 9. Revision restore — the undo answer  (20s)

1. **Pages → About → Revisions** (or `wp post list --post_type=revision`). Restore
   an earlier revision → the served page reverts, the render recompiles on restore.
   Revisions ARE the undo story for this cut.

---

## The one-sentence close

> "One theme, HTML in and HTML out byte-for-byte, one compiler and one save door —
> drag a component in, edit it, edit the component itself, and every keystroke went
> through the exact same pipeline as a command-line import."

---

## Fidelity proof (optional, technical audience)

```bash
npm test                  # full suite, single clean-slate environment
npm run test:editor       # the editor contract
npm run test:components    # component folders, CSS bundle, server re-key insert
```

Each acceptance suite passes in isolation. The full single-command run is
occasionally flaky under Docker load (a write→read serving race that moves between
suites; every suite is green on its own) — see `DECISIONS.md` D20.

## What this cut deliberately leaves out (post-WordCamp)

Undo/redo (revisions are the answer) · full component studio (props-panel UI,
variant switcher) · move/duplicate/delete polish beyond insert · Tailwind mode
function · MCP · the React editor rehome (D19). See `DECISIONS.md` for every
scope call.

Capability-levels UI (Client/Marketer/Developer) shipped: every entrance — the
editor, Theme Settings, and the REST save door — resolves through
`S5\Roles::can()` (per-user override → WP-role seed → matrix), not just a raw
WP capability. See DECISIONS.md D45/D46 and `tests/acceptance-roles.sh`.
