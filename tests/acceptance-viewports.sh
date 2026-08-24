#!/usr/bin/env bash
#
# Viewport matrix — real-browser responsive proof (fix #3 "responsive severe"
# regression guard, live half). acceptance-preflight-guards.sh asserts @media/clamp
# survive into the bundle statically; THIS suite proves the pages actually reflow at
# 375/768/1440 in a real browser context (Playwright), asserting window.innerWidth
# truly changed before trusting any render. Wraps tests/playwright/viewport-matrix.mjs
# so it runs in the default gate (run-all.sh) with the same env discipline as the
# roles-login standing gate.
#
# Usage: npm run test:viewports  (or: bash tests/acceptance-viewports.sh)

set -uo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

command -v npx >/dev/null 2>&1  || fail "npx not found."
command -v node >/dev/null 2>&1 || fail "node not found."
[ -d node_modules/playwright ]  || fail "playwright not installed — run 'npm install' first."

echo
info "S5 viewport matrix — real-browser responsive proof (375/768/1440)"
echo

info "0/1  Env up; theme active; corpus imported…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null 2>&1 || fail "wp-env start failed"
$WP_ENV run cli wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
$WP_ENV run cli wp s5 import wp-content/s5-pages >/dev/null 2>&1 || info "corpus import skipped/failed (may already be imported)"

# wp-env serves the dev site on :8888 by default.
SITE_URL="${SITE_URL:-http://localhost:8888}"
info "1/1  Running viewport matrix against ${SITE_URL} ..."
if SITE_URL="$SITE_URL" node tests/playwright/viewport-matrix.mjs; then
	pass "viewport matrix passed — pages reflow at 375/768/1440 (innerWidth verified)"
else
	fail "viewport matrix failed — a page did not reflow at a target width (fix #3 regressed)"
fi

echo
pass "Viewport matrix: ALL CHECKS PASSED"
echo
