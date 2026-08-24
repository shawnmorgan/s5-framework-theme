#!/usr/bin/env bash
#
# Preflight guards — the v0.9.2-preflight fix pass, locked into the default gate.
#
# The Amin handoff (S5-AMIN-HANDOFF §4) requires: "a branch must not be able to
# drop a fix and still show green." Three of the five preflight fixes had no
# guarding suite in `npm test` (the XSS on*/javascript:/<script> vectors are
# already guarded by acceptance-step4.sh; roles by acceptance-roles-login.sh).
# This suite closes the remaining three so a regression fails the gate:
#
#   Fix #5 — bare `wp s5 import` (no dir arg) resolves an ABSOLUTE default dir and
#            works from any CWD; the old bug errored "directory not found: pages".
#   Fix #4 — the editor's beforeunload dirty-state guard exists and is wired to
#            dirty state (a static source assertion catches its deletion).
#   Fix #3 — responsive CSS: the compiled bundle carries @media/clamp() (the
#            "responsive severe" regression check, cheap and deterministic here).
#
# The viewport MATRIX (real-browser responsive proof) is wired into run-all.sh
# separately via tests/playwright/viewport-matrix.mjs.
#
# Usage: npm run test:preflight-guards   (or: bash tests/acceptance-preflight-guards.sh)

set -uo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
THEME_DIR="$ROOT_DIR/s5-theme"

command -v npx >/dev/null 2>&1 || fail "npx not found."

echo
info "S5 preflight guards — beforeunload · responsive bundle · bare-import"
echo

# ---------------------------------------------------------------------------------
# STATIC checks first (no Docker / no wp-env). Two of the three fixes are pure
# source/artifact assertions, so they must run on ANY checkout — including a path
# Docker cannot bind-mount (macOS ~/Desktop TCC block). Only fix #5 needs a live
# CLI, so the env is brought up AFTER the static checks (and can be skipped with
# S5_SKIP_ENV=1 to run the static half alone anywhere).
# ---------------------------------------------------------------------------------

# --- Fix #4. editor beforeunload dirty-state guard present + wired ----------------
# Static source assertion: the guard is a small, deterministic piece of editor.js.
# A full browser test for a beforeunload dialog is flaky (the dialog blocks the
# page), so we assert the handler exists and references dirty state — which catches
# exactly the "someone deleted the guard" regression this exists to prevent.
info "1/3  Editor beforeunload dirty-state guard present + wired (fix #4)…"
EDITOR_JS="$THEME_DIR/assets/editor/editor.js"
[ -f "$EDITOR_JS" ] || fail "editor.js not found at $EDITOR_JS"
grep -q "addEventListener( 'beforeunload'" "$EDITOR_JS" || grep -q 'addEventListener("beforeunload"' "$EDITOR_JS" \
	|| fail "no beforeunload listener in editor.js (dirty-state guard missing — fix #4 regressed)"
# The handler must be gated on dirty state, not fire unconditionally.
awk '/beforeunload/{f=1} f&&/dirty/{print "found"; exit}' "$EDITOR_JS" | grep -q found \
	|| fail "beforeunload handler does not reference dirty state (guard not wired — fix #4)"
pass "beforeunload dirty-state guard present and gated on dirty state"

# --- Fix #3. responsive CSS survives into the compiled bundle --------------------
# The "responsive severe" regression was zero @media / zero clamp() reaching the
# served bundle. Assert both are present in the compiled dist bundle (or, if the
# bundle isn't compiled in this env, in scales.css which feeds it).
info "2/3  Responsive CSS (@media/clamp) present in the compiled bundle (fix #3)…"
BUNDLE="$THEME_DIR/site/dist/s5.css"
SCALES="$THEME_DIR/site/styles/scales.css"
SRC="$BUNDLE"; [ -f "$BUNDLE" ] || SRC="$SCALES"
[ -f "$SRC" ] || fail "neither compiled bundle nor scales.css found"
grep -qE '@media' "$SRC"  || fail "no @media in $SRC (responsive reflow gone — fix #3 regressed)"
grep -qE 'clamp\(' "$SRC" || fail "no clamp() in $SRC (fluid scales gone — fix #3 regressed)"
pass "responsive CSS present (@media + clamp) in $(basename "$SRC")"

# ---------------------------------------------------------------------------------
# LIVE check — needs wp-env. Skippable with S5_SKIP_ENV=1 (the two static guards
# above have already run and passed; only fix #5 is deferred).
# ---------------------------------------------------------------------------------
if [ "${S5_SKIP_ENV:-0}" = "1" ]; then
	echo
	info "3/3  Bare 'wp s5 import' (fix #5) SKIPPED — S5_SKIP_ENV=1 (static guards passed)"
	echo
	pass "Preflight guards: STATIC CHECKS PASSED (beforeunload · responsive); bare-import skipped"
	echo
	exit 0
fi

command -v npx >/dev/null 2>&1 || fail "npx not found (needed for the live bare-import check; set S5_SKIP_ENV=1 to run static-only)."
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null 2>&1 || fail "wp-env start failed"
$WP_ENV run cli wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"

# --- Fix #5. bare `wp s5 import` (no dir arg) does NOT fail "directory not found" ----
# The regression was a bare import resolving a RELATIVE 'pages' against the CWD and
# erroring. default_dir()=ValidateCommand::pages_root() is absolute, so a bare import
# must resolve a real dir. We assert the error string never appears (it may report
# "0 created" if the default corpus is already imported — that's success, not failure).
info "3/3  Bare 'wp s5 import' resolves an absolute default dir (fix #5)…"
BARE_OUT="$($WP_ENV run cli wp s5 import 2>&1 | grep -vE '^ℹ Starting|Ran `wp' || true)"
if printf '%s\n' "$BARE_OUT" | grep -qiE 'directory not found|not found: pages|no such file'; then
	printf '%s\n' "$BARE_OUT" >&2
	fail "bare 'wp s5 import' errored on a missing directory (fix #5 regressed)"
fi
pass "bare 'wp s5 import' resolves an absolute default dir (no directory-not-found)"

echo
pass "Preflight guards: ALL CHECKS PASSED (beforeunload · responsive · bare-import)"
echo
