# S5 Theme — Engineering Handoff (Amin)

**Date:** 2026-08-21 · **Owner:** Shawn Morgan · **For:** Amin (+ Norman, L1)
**Purpose:** you take ownership of the theme's code health, security, and maintenance once Shawn + Claude Code bring it to ~80%. This document is your audit spec and your onboarding map. It is not a description of what the theme does (the PRD is that) — it is what you are accountable for and how to verify it.

---

## 0. What you own

- **Security pass** — the theme accepts agent-authored and human-authored HTML through REST/CLI/editor. That is a large input surface. You own proving it's safe.
- **Invariants audit** — the theme's value depends on a small set of architectural guarantees never breaking. You own the code-level verification that they hold.
- **Maintenance + updates** — post-launch, you own the release channel, the update mechanism, WordPress/PHP version compatibility, and the fix pipeline. Norman is L1 (triage/repro); you are the fix.

You are NOT redesigning the architecture. Shawn sets scope and architecture; you harden, audit, and maintain what's built. If you find an architectural problem, raise it — don't unilaterally rebuild.

---

## 1. Onboarding — read in this order

1. **PRD-Agent-Native-WordPress-Framework-v1_6.md** — what the product is and its laws. §0 (Product Law), §2.4 (control plane vs page canvas), §3.2 (canonical vs derived) are the load-bearing ones.
2. **Authoring-Contract-v0.1.md** — the closed CSS/declaration vocabulary the validator enforces. This is what "valid" means.
3. **S5-DYNAMICS-SPEC-v1.5.md** — the dynamic layer's laws (esp. §0 laws, §7 output inertness/escaping — that section is a security contract).
4. **S5-TEMPLATES-SPEC-v0.1.md** — the template layer (v1 launch requirement).
5. **S5-PLUGIN-EXTENSIBILITY-SPEC-v0.1.md** + **S5-ATOMIC-DESIGN-SPEC-v0.1.md** — the newer surfaces (component kinds, atoms). Mostly v1.x/v2; read for direction.
6. **DECISIONS.md** — every judgment call and why. This is the "why is it like this" reference; read it before questioning any odd-looking choice.
7. **BUILD-ORDER-Agent-Native-WP-v1.md** — the build sequence and per-step acceptance tests. The suites that guard each step are your regression net.

The specs + DECISIONS + the gate ARE your onboarding docs. The paper trail exists specifically so you're effective on day one.

---

## 2. The invariants you audit against

These are the guarantees the whole product rests on. Your audit confirms each holds at the code level and is covered by a suite that runs in `npm test`.

1. **One save door.** Every write path (CLI, editor REST, MCP/Abilities) flows through the same validate → persist → recompile → revision service. There is no second write path that skips validation. *Audit: grep for direct postmeta writes to `_s5_canonical_html` / `_s5_render_html` outside the save service.*
2. **HTML is canonical; derived is regenerable.** `_s5_canonical_html` is the source; render HTML, the post_content mirror, and the bundle are derived and reproducible by recompile. *Audit: delete derived, recompile, confirm identical.*
3. **Compile on write, never on read** (except flagged dynamic passes). Serving a static page runs no compiler. *Audit: static page serve path touches no validation/compile.*
4. **Loud validation, closed vocabulary.** Unknown class, inline style, event-handler attribute, malformed markup → rejected at the door with diagnostics. Nothing invalid reaches storage. *Audit: the planted-violation suite (see §3).*
5. **Byte-fidelity round-trip.** A canonical fragment stored is served byte-identical (static pages); dynamic pages assert structural + determinism. *Audit: the round-trip harness.*
6. **No framework internals in served output.** `data-s5-*`, `data-node`, editor assets never reach logged-out visitors. *Audit: grep the anonymous response.*
7. **Editor renders declarations only; page canvas adds no authoring capability.** The client can only edit exposed slots. *Audit: role-login tests (§3).*
8. **Control plane editable, page canvas constrained** (PRD §2.4). Settings surfaces are editable by humans and agents; the read-only posture is page-canvas-only.

---

## 3. Security surfaces — where agent-written WordPress code fails

This is the core of your first pass. Agent-generated WP code fails in predictable places. The XSS hole already found and fixed (on* event-handler attributes walking through the validator, saved with a 200) is the argument for this audit — it passed every behavioral gate because nothing tested what the validator should have *rejected*. Audit each surface below and confirm a suite guards it:

- **Validator completeness (input sanitization).** The validator must reject: unknown classes, inline `style=`, `on*` event-handler attributes, `javascript:` URLs in href/src, `<script>` injected outside sanctioned paths, unparseable markup. These are **planted-violation fixtures** — each must be rejected at validate AND import AND the save door (all three entrances), and each must run in the default gate. If a fixture is missing, that's the gap.
- **Capability checks on every REST route.** Every `register_rest_route` needs a real `permission_callback` that resolves the S5 editing level (per-user override → role seed), never a hard-coded `manage_options` and never `__return_true`. *This regressed once (Settings/Editor CAPABILITY hard-coded to manage_options, blocking the role model). The standing gate is Playwright logging in as three real users and asserting each sees only its surfaces and is 403'd from the rest.*
- **Nonces on state-changing admin actions.** Every form/action that mutates state verifies a nonce.
- **Escaping on all admin + served output.** Dynamic field output is inert/escaped per Dynamics §7 (context-aware: HTML-text, URL in href/src, attribute context). Admin screens escape all dynamic values (a prior build shipped `?>`-leak defects — PHP arrays printing as literal text; check for output-escaping gaps).
- **Path traversal in importers and file handlers.** `wp s5 import`, component/atom file reads, zip handling — confirm no user-influenced path escapes the site fence. Reject `../`, absolute paths, symlink escapes.
- **`unfiltered_html` seams.** Confirm the theme doesn't grant raw-HTML capability to roles that shouldn't have it, and that `raw="true"` field opt-in (Dynamics §7) is Developer-gated and warned.
- **Shortcode / `render_block()` execution (new — plugin extensibility).** When component kinds shortcode/block land, confirm the Embed atom (raw shortcode paste) is Developer-gated, and that block-injection can't be used to execute arbitrary code from a Content/Design-level user.
- **The foreign-class validator exception** (plugin styling, `.wc-block-*` in site CSS): confirm it's scoped to Developer-authored site CSS only, never atom/component definitions, and is flagged/auditable.

Deliverable for §3: a security report — each surface, finding, fix, and the guarding suite. This is a **security-and-invariants pass with the QA harness as the regression net**, not a vibe check.

---

## 4. Verification method (non-negotiable)

- **The gate is necessary, not sufficient.** wp-env suites catch broken behavior; they do NOT catch fidelity drift, dead clicks, or a hard-coded capability that passes a logic test while blocking the product. UI and role behavior are verified in a **real browser (Playwright) on a real install** (WP Studio/Local or `wp core download` + php -S — never repo-mounted wp-env, which masks packaging failures).
- **Guarding suites live with the code they guard and run in `npm test`.** A branch must not be able to drop a fix and still show green. If a fix exists without a suite that runs in the default gate, that's a defect to close.
- **The build produces the artifact you audit.** Audit the installed ZIP, cold, on a fresh WP with no prior theme dir — not a repo copy. (A stale copy-install once produced a false "fixes missing" audit. Install the zip via `wp theme install`; if that fails, the zip is malformed — fix it, don't fall back to copy.)
- **The build self-smoke-tests.** Build → install its own zip → assert activate + one canary (onclick save → 4xx) → only then is the zip "built." Cruft (.DS_Store/__MACOSX/.git/node_modules) is excluded.

---

## 5. Staged checkpoints (not one terminal audit)

Do NOT do a single giant audit in one pass — staged audits find cheap problems; terminal audits find expensive ones. Two checkpoints minimum:

- **Checkpoint 1 — when the current UI/feature phase lands (~80%).** Invariants audit (§2) + first security pass (§3) against the then-current build. Goal: catch structural/security problems while they're cheap to fix.
- **Checkpoint 2 — pre-LTD (before the beta launch).** Full security pass + confirm every §2 invariant has a guarding suite in the gate + confirm the build-self-smoke-test is wired. Goal: nothing ships to paying founders unaudited.

Report each checkpoint as a findings table: blocker / friction / cosmetic / backlog, each with root cause, fix, and the guarding suite.

---

## 6. Maintenance + updates (post-launch ownership)

- **Release channel + updater.** You own the mechanism that gets updates to installed sites (git-based release puller + license-key activation is the model). Founders on stale builds is how goodwill dies — the updater is a launch prerequisite, not a v1.1.
- **Version/compat matrix.** WordPress and PHP version compatibility; test the zip against current + prior WP and the supported PHP range.
- **Fix pipeline.** Norman L1 (triage/repro), you fix, every fix ships with a regression suite that runs in the gate. A fix without a test is not done.
- **Security response.** You're the security owner post-launch — CVEs in bundled deps (FontAwesome, any vendor), WP core security changes, reported issues.

---

## 7. Immediate first tasks (Checkpoint 1)

1. Read §1's list.
2. Confirm the five prior fixes (XSS on*, roles enforcement, responsive CSS, dirty-state, bare-import) are present on the shipped branch AND each has a suite in `npm test`. (These went missing once in a build-provenance scare — re-confirm on a clean zip install.)
3. Run the §2 invariants audit — one suite per invariant, confirm it's in the gate.
4. Run the §3 security pass — the planted-violation fixtures and the capability-check sweep first (highest risk).
5. Report Checkpoint 1 findings table + a list of any invariant or security surface lacking a guarding suite. Those gaps are the priority fixes before the theme goes to any external tester.
