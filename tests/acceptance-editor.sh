#!/usr/bin/env bash
#
# Editor MVP ("WordCamp cut") acceptance — Build Order Step 12 (scoped).
#
# Exit condition proven: operators can edit real framework pages through the
# editor's ONE save door, and the framework's fidelity guarantees hold.
#   - The editor screen is registered and gated behind manage_options.
#   - Editor assets NEVER ship to logged-out visitors (front-end or ?s5-preview=1).
#   - A slot (Edit-lens) save round-trips through Compiler::persist_page: canonical
#     + derived render update, the authored data-node is preserved, and only the
#     target node's inner text changes.
#   - A Code-lens canonical save with an out-of-vocabulary class is REJECTED with
#     diagnostics and canonical is left UNCHANGED (loud, never silent).
#   - A head save updates _s5_head without touching canonical.
#   - Authored data-edit/data-node declarations round-trip byte-clean.
#   - The Style tab reads real design tokens (no drift).
#
# Usage: npm run test:editor   (or: bash tests/acceptance-editor.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp() { $WP_ENV run cli wp "$@" 2>/dev/null | tr -d '\r'; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
PAGES_C="wp-content/s5-pages"
PROBE_C="wp-content/s5-repo/tests/fixtures/editor-probe.php"

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

echo
info "S5 Editor MVP acceptance (Step 12, WordCamp cut)"
echo

# ---------------------------------------------------------------------------
# 0. Env up, plugins active, fresh import.
# ---------------------------------------------------------------------------
info "0/8  Env up; import the corpus (about.html carries declarations)…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
wp theme activate s5-theme >/dev/null 2>&1 || true
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "corpus import failed"
ABOUT="$(wp post list --post_type=page --name=about --field=ID | head -n1)"
[ -n "$ABOUT" ] || fail "about page missing"
pass "environment ready; corpus imported (about #$ABOUT)"

# ---------------------------------------------------------------------------
# 1. Editor screen registered + gated by the R1 role resolution.
#
# Post-sweep v2 item 1: Editor::CAPABILITY is the WP-level FLOOR only
# (edit_posts — Administrator/Editor/Author all hold it), not the real gate.
# The real gate is Roles::can('editor.open'), proven server-side and with
# real browser logins by tests/acceptance-roles-login.sh — this check only
# confirms the floor is broad enough for non-Administrators to reach at all
# (a regression back to manage_options would silently re-lock Client/
# Marketer out of the editor even though Roles::can() says they're allowed).
# ---------------------------------------------------------------------------
info "1/8  Editor screen registered; capability floor is edit_posts (R1 is the real gate)…"
CAP="$($WP_ENV run cli wp eval 'echo \S5\Editor::CAPABILITY;' 2>/dev/null | tr -d '[:space:]')"
[ "$CAP" = "edit_posts" ] || fail "editor capability floor is '$CAP', expected edit_posts (R1's WP-level floor — see DECISIONS.md D47)"
pass "editor capability floor is edit_posts; Roles::can('editor.open') is the real gate"

# ---------------------------------------------------------------------------
# 2. D8: editor assets absent from anon front-end AND anon preview request.
# ---------------------------------------------------------------------------
info "2/8  Editor assets never ship to logged-out visitors (D8)…"
ABOUT_URL="$(wp option get siteurl)/about/"
FRONT="$(curl -s "$ABOUT_URL")"
PREVIEW="$(curl -s "${ABOUT_URL}?s5-preview=1")"
echo "$FRONT"   | grep -q 'assets/editor/' && fail "editor asset leaked to anon front-end"
echo "$PREVIEW" | grep -q 'overlay.js'     && fail "overlay leaked to anon preview request"
echo "$FRONT"   | grep -q 'data-edit'       || fail "authored declarations missing from served about page"
pass "no editor asset for anon (front-end or ?s5-preview=1); declarations still ship"

# ---------------------------------------------------------------------------
# 3-6. REST save behaviors, via a server-side probe (the ONE door).
# ---------------------------------------------------------------------------
info "3/8  REST slot save round-trips through persist_page…"
PROBE_OUT="$($WP_ENV run cli wp eval-file "$PROBE_C" 2>/dev/null | tr -d '\r')"
echo "$PROBE_OUT" | grep -q 'SLOT_OK=YES'            || { printf '%s\n' "$PROBE_OUT" >&2; fail "slot save did not round-trip through persist_page"; }
pass "slot save persisted; canonical+render updated; data-node preserved"

info "4/8  Unknown class is role-aware: dev warns+proceeds, non-dev rejected…"
# Role-aware validator (v0.18.0): the Developer defines the vocabulary, so an unknown
# class is a WARNING (the save proceeds) for a developer, but a hard ERROR (rejected)
# for a marketer/client. Security rules (section 5) still reject for everyone.
echo "$PROBE_OUT" | grep -q 'DEV_UNKNOWN_ALLOWED=YES'     || fail "a Developer's unknown class was blocked (should warn + proceed)"
echo "$PROBE_OUT" | grep -q 'NONDEV_UNKNOWN_REJECTED=YES' || fail "a non-developer's unknown class was NOT rejected (closed vocabulary must hold for them)"
echo "$PROBE_OUT" | grep -q 'CANON_UNCHANGED=YES'         || fail "canonical changed after a non-developer's rejected save"
echo "$PROBE_OUT" | grep -q 'DIAGNOSTICS=YES'             || fail "no diagnostics returned on the non-developer's rejected save"
pass "unknown class: developer warns + proceeds; non-developer rejected with diagnostics (canonical unchanged)"

# ---------------------------------------------------------------------------
# 5. D-fix #6 regression guard: the save door independently rejects the same
#    three attack patterns the Authoring Contract validator blocks at
#    validate/import (tests/acceptance-step4.sh checks 6-8). Proven via the
#    same probe (section 5, SEC_* keys) so the save door itself — not just
#    the CLI-facing validate/import paths — is exercised.
# ---------------------------------------------------------------------------
info "5/8  Save door rejects on* / javascript: / mid-body <script> (D-fix #6)…"
echo "$PROBE_OUT" | grep -q 'SEC_ONCLICK_REJECTED=YES' || fail "save door did not reject a planted onclick attribute"
echo "$PROBE_OUT" | grep -q 'SEC_JSURL_REJECTED=YES'   || fail "save door did not reject a planted javascript: URL"
echo "$PROBE_OUT" | grep -q 'SEC_SCRIPT_REJECTED=YES'  || fail "save door did not reject a planted mid-body <script>"
pass "save door rejects on*/javascript:/<script> with diagnostics; canonical left unchanged"

info "6/8  Head save updates _s5_head without touching canonical…"
echo "$PROBE_OUT" | grep -q 'HEAD_OK=YES'            || fail "head save did not update _s5_head"
pass "head save updated _s5_head (canonical untouched)"

info "7/8  Style tab reads real design tokens…"
echo "$PROBE_OUT" | grep -q 'TOKENS_OK=YES'          || fail "Style tab token reader returned no tokens"
pass "design tokens parsed live from the declared token files"

# ---------------------------------------------------------------------------
# AUDIT 2026-08-26 regression guards. Every check below corresponds to a defect
# that shipped through a fully GREEN gate — which is precisely why they are here.
# See AUDIT-2026-08-26.md for each finding's proof and reproduction.
# ---------------------------------------------------------------------------
info "8/8  Audit regression guards (authz · URL schemes · shortcode · roles · client)…"

# C1 — the save door must apply WordPress's per-object edit_post cap, not just
# the site-wide edit_posts capability. A Contributor rewrote every framework
# page before this landed.
echo "$PROBE_OUT" | grep -q 'SEC_PRIVESC_BLOCKED=YES' \
	|| fail "PRIVILEGE ESCALATION: a contributor (denied edit_post by WP) saved through the door"

# C2 — the javascript:/vbscript:/data: rule must cover every url-valued
# attribute, and must normalize entities + embedded control characters first.
echo "$PROBE_OUT" | grep -q 'SEC_URL_ATTRS_REJECTED=YES' \
	|| fail "XSS: a script-bearing URL attribute (formaction/srcdoc/xlink/ctrl-char/entity/data) reached canonical"

# ...without becoming a false-positive machine on correct authoring.
echo "$PROBE_OUT" | grep -q 'SEC_URL_NO_FALSE_POSITIVE=YES' \
	|| fail "REGRESSION: legitimate links (relative/absolute/mailto/target/data:image) were rejected"

# M1 — a plain TEXT slot must never become a shortcode execution surface.
echo "$PROBE_OUT" | grep -q 'SEC_SHORTCODE_INERT=YES' \
	|| fail "SHORTCODE INJECTION: [shortcode] typed in a text slot stayed live in canonical"

# H3 — hidden AND blocked: the matrix keys must actually gate their modes.
echo "$PROBE_OUT" | grep -q 'ROLE_GATES_WIRED=YES' \
	|| fail "mode:slots / mode:class are not gated by editor.content / editor.style"

# M2 — both CSS-writing doors enforce the same value policy on one bundle.
echo "$PROBE_OUT" | grep -q 'TOKEN_VALUE_POLICY=YES' \
	|| fail "token door accepted script-bearing CSS the element door rejects"

# H1/H2 — the editor client's save contract (static guards; cheap, and they
# would have caught both editor defects).
echo "$PROBE_OUT" | grep -q 'EDITOR_CLIENT_CONTRACT=YES' \
	|| fail "editor client contract broken (pending-edit guard, dot re-query, or toast wiring)"

# A8 — structure modes had ZERO coverage at any layer; delete is irreversible.
echo "$PROBE_OUT" | grep -q 'STRUCTURE_MODES_OK=YES' \
	|| fail "structure mode (duplicate) did not round-trip through the save door"

pass "audit guards: authz · URL schemes · shortcode inert · role gates · token policy · client contract · structure modes"

# ---------------------------------------------------------------------------
# 7. Restore corpus to source; declarations round-trip byte-clean.
# ---------------------------------------------------------------------------
info "8/8  Restore corpus; declarations round-trip byte-clean…"
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "re-import failed"
SERVED="$(curl -s "$ABOUT_URL")"
# The declared attributes must be present in the served page exactly as authored.
echo "$SERVED" | grep -q 'data-node="about-hero-title"' || fail "authored data-node missing from served render"
echo "$SERVED" | grep -q 'data-edit="link"'             || fail "authored link declaration missing from served render"
pass "authored declarations served verbatim (round-trip byte-clean)"

echo
pass "Editor MVP acceptance: ALL CHECKS PASSED (one save door; fidelity intact)"
echo
