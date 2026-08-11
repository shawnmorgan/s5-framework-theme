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

**D10 — REST endpoint ships slots+canonical in B2; head mode lands with the head
sidebar in B3.**
Rejected: implementing all three modes before any UI consumes head.
Why: the endpoint is one route with a mode switch (D2). B2 needs slots (panel) and
canonical (used by the code lens in B3, but cheap to include now); the head mode's
only consumer is the B3 head sidebar, so its handler is written but exercised in B3.
No second door — still one route, one permission check, one persist_page call.

**D11 — Slot inner-text writes use a bounded splice, not full re-serialization.**
Rejected: DOMDocument round-trip (reformats/normalizes the whole fragment, breaking
byte fidelity); WP_HTML_Tag_Processor inner-HTML (not exposed in WP 6.7).
Why: the tag processor locates+validates the node and does attribute writes
surgically, but has no inner-HTML API. For text/rich we splice only between the
matched open tag's end and its balanced close tag — every other byte is untouched,
so round-trip stays clean (verified: about.html round-trips byte-clean with
declarations, and a REST edit changes only the target node's inner text).

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
- **F4 — Live gate blocked by Docker mount permission (infrastructure, NOT a
  code/fidelity STOP).** `wp-env start` fails with `error while creating mount
  source path '/host_mnt/Users/shawnmorgan/Desktop/...': operation not permitted`.
  Docker daemon is up and mounts `/tmp` fine; it cannot mount anything under
  `~/Desktop` — macOS TCC (Privacy & Security) is denying Docker Desktop access to
  the Desktop folder. Fix is a host grant only the owner can give: System Settings
  → Privacy & Security → Files and Folders / Full Disk Access → enable Docker
  Desktop; or move the project out of `~/Desktop`. Phase B code is validated by
  phpcs + `php -l` + `node --check` in the meantime; the full single-env gate
  (Steps 0–10 + editor acceptance) runs the moment the env is reachable.
