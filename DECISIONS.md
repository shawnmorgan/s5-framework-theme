# Editor MVP — Decisions Log

Every autonomous judgment call for the "WordCamp cut" editor: the choice, the
rejected alternative, and one line on why. The owner reviews after the fact.

Format: **D#** — choice · rejected · why.

---

**D1 — Vanilla JS app shell, not React.**
Rejected: React shell (PRD §8.1's stated direction for the *full* editor).
Why: the repo has no bundler/build step; a no-build vanilla ES-module admin page
is shippable-today and the §8.1 *state model* (iframe-not-authoritative, source+ops
authoritative, Update-through-compiler) is honored regardless of view layer. React
resumes with the full editor post-launch.

**D2 — One REST endpoint, three modes (slots|canonical|head), not three endpoints.**
Rejected: separate routes per save type.
Why: one door, one nonce/permission check, one place that calls `persist_page`.
Minimizes surface and makes "no second save pipeline" obvious.

**D3 — Slot writes via `WP_HTML_Tag_Processor`, server-side, keyed by `data-node`.**
Rejected: trusting client-serialized HTML from the iframe; regex canonical edits.
Why: byte-preserving surgical edits (fidelity), never trust the client DOM (§8.1),
and `data-node` (Contract B4) is the stable target across recompiles.

**D4 — `data-node` is AUTHORED alongside `data-edit`; the compiler does NOT
inject IDs into canonical (MVP).**
Rejected: compiler auto-assigning `data-node` where missing (Contract B4's eventual
behavior).
Why: **fidelity guarantee.** The round-trip harness diffs served body vs the source
FILE byte-for-byte. If the compiler injected IDs into canonical, canonical would
diverge from source and round-trip would break — that touches a STOP condition.
Instead, a slot-editable node carries BOTH `data-edit` and `data-node` in the
authored source (the MVP test corpus does this). Canonical stays verbatim; slot
writes target the authored `data-node`; round-trip stays byte-clean. Compiler
auto-assignment is deferred to the full editor (it will require the harness to
treat post-assignment canonical as source, a bigger change). This side-steps the
STOP condition cleanly rather than risking it.

**D5 — Add real `tokens.css` + `scales.css` (custom properties only).**
Rejected: hand-built Style-tab HTML; parsing a non-existent file.
Why: the Style tab must render from actual files (no drift). The repo lacks these;
minimal real token/scale files are on-thesis (Contract A2/A3) and make the tab
truthful. Values are placeholder-tier like the rest of the test corpus.

**D6 — Add `data-edit`/`data-node` declarations to the TEST corpus pages.**
Rejected: leaving pages declaration-free (Edit lens would select nothing anywhere).
Why: Contract B6 explicitly says launch pages "MAY carry declarations ... becomes
the editor's first test corpus." The MVP needs at least one page with declarations
to demo the panel, and one with none to demo props-off selectability.

**D7 — Preview iframe = the real canvas URL with `?s5-preview=1`.**
Rejected: a separate render path for preview.
Why: the demo must show the REAL page; reusing the canvas keeps one render path.
The flag only suppresses the admin bar / injects the selection overlay when the
request is from a logged-in editor — never for anonymous visitors.

**D8 — Editor assets enqueue on the editor admin page only.**
Rejected: registering editor JS/CSS globally.
Why: acceptance requires zero editor asset in the logged-out response. Admin-screen
enqueue guarantees it; asserted by grep in the acceptance test.

**D9 — "rich" slot allows only `<strong> <em> <a>`.**
Rejected: a full rich-text library.
Why: instruction says bold/italic/link ONLY; a tiny sanitizer (wp_kses with a
3-tag allowlist) is enough and safe. SLIP #2: collapses to plain text if time-boxed.

---

## Open / flagged for owner review

- **F1 — data-node is authored, not injected (resolved).** To protect the
  fidelity guarantee, the MVP does NOT let the compiler inject `data-node` into
  canonical (see D4). Node IDs are authored in source next to `data-edit`. This
  keeps canonical verbatim and round-trip byte-clean. The full editor can revisit
  compiler auto-assignment later with the harness change it requires.
- **F2 — Vanilla vs React (D1).** Biggest deviation from §8.1. Flagging explicitly.
- **F3 — Slip protocol status.** Nothing slipped yet. Any cut taken will be logged
  here with the item and the trigger.
