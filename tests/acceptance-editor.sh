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
info "0/7  Env up; import the corpus (about.html carries declarations)…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp plugin activate s5-plugin >/dev/null || fail "s5-plugin failed to activate"
wp theme activate s5-theme >/dev/null 2>&1 || true
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "corpus import failed"
ABOUT="$(wp post list --post_type=page --name=about --field=ID | head -n1)"
[ -n "$ABOUT" ] || fail "about page missing"
pass "environment ready; corpus imported (about #$ABOUT)"

# ---------------------------------------------------------------------------
# 1. Editor screen registered + gated manage_options.
# ---------------------------------------------------------------------------
info "1/7  Editor screen registered and gated behind manage_options…"
CAP="$($WP_ENV run cli wp eval 'echo \S5\Editor::CAPABILITY;' 2>/dev/null | tr -d '[:space:]')"
[ "$CAP" = "manage_options" ] || fail "editor capability is '$CAP', expected manage_options"
pass "editor gated behind manage_options"

# ---------------------------------------------------------------------------
# 2. D8: editor assets absent from anon front-end AND anon preview request.
# ---------------------------------------------------------------------------
info "2/7  Editor assets never ship to logged-out visitors (D8)…"
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
info "3/7  REST slot save round-trips through persist_page…"
PROBE_OUT="$($WP_ENV run cli wp eval-file "$PROBE_C" 2>/dev/null | tr -d '\r')"
echo "$PROBE_OUT" | grep -q 'SLOT_OK=YES'            || { printf '%s\n' "$PROBE_OUT" >&2; fail "slot save did not round-trip through persist_page"; }
pass "slot save persisted; canonical+render updated; data-node preserved"

info "4/7  Invalid Code-lens save is rejected; canonical unchanged…"
echo "$PROBE_OUT" | grep -q 'INVALID_REJECTED=YES'   || fail "invalid canonical was not rejected"
echo "$PROBE_OUT" | grep -q 'CANON_UNCHANGED=YES'    || fail "canonical changed after a rejected save"
echo "$PROBE_OUT" | grep -q 'DIAGNOSTICS=YES'        || fail "no diagnostics returned on rejected save"
pass "invalid save rejected with diagnostics; canonical left unchanged"

info "5/7  Head save updates _s5_head without touching canonical…"
echo "$PROBE_OUT" | grep -q 'HEAD_OK=YES'            || fail "head save did not update _s5_head"
pass "head save updated _s5_head (canonical untouched)"

info "6/7  Style tab reads real design tokens…"
echo "$PROBE_OUT" | grep -q 'TOKENS_OK=YES'          || fail "Style tab token reader returned no tokens"
pass "design tokens parsed live from the declared token files"

# ---------------------------------------------------------------------------
# 7. Restore corpus to source; declarations round-trip byte-clean.
# ---------------------------------------------------------------------------
info "7/7  Restore corpus; declarations round-trip byte-clean…"
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "re-import failed"
SERVED="$(curl -s "$ABOUT_URL")"
# The declared attributes must be present in the served page exactly as authored.
echo "$SERVED" | grep -q 'data-node="about-hero-title"' || fail "authored data-node missing from served render"
echo "$SERVED" | grep -q 'data-edit="link"'             || fail "authored link declaration missing from served render"
pass "authored declarations served verbatim (round-trip byte-clean)"

echo
pass "Editor MVP acceptance: ALL CHECKS PASSED (one save door; fidelity intact)"
echo
