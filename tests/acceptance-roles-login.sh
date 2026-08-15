#!/usr/bin/env bash
#
# Phase C+ — Roles & Access, REAL BROWSER LOGINS (post-sweep v2 item 1 standing
# gate — tests/playwright/roles-login.spec.mjs).
#
# tests/acceptance-roles.sh proves Roles::can() resolves correctly as PHP
# logic, but it PASSED even while every entrance was hard-gated on
# manage_options ahead of Roles::can() ever running — it never drove a real
# browser session, so it could not catch that class of bug. This suite seeds
# three real WordPress users (Author/Editor/Administrator), logs in as each
# through the real wp-login.php form via Playwright, and asserts what a
# human in that seat actually sees and can reach — including direct
# URL-jumps to blocked routes, which must be refused server-side.
#
# Usage: npm run test:roles-login   (or: bash tests/acceptance-roles-login.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

command -v npx >/dev/null 2>&1    || fail "npx not found."
command -v docker >/dev/null 2>&1 || fail "docker not found."
command -v node >/dev/null 2>&1   || fail "node not found."
[ -d node_modules/playwright ]    || fail "playwright not installed — run 'npm install' first."

echo
info "S5 Phase C+ — Roles & Access, real browser logins"
echo

info "0/3  Env up; theme active; corpus imported…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
$WP_ENV run cli wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
$WP_ENV run cli wp s5 import wp-content/s5-pages >/dev/null 2>&1 || fail "corpus import failed"
pass "environment ready; corpus imported"

info "1/3  Seeding three real users (Author/Editor/Administrator)…"
seed_user() {
	local login="$1" role="$2"
	if $WP_ENV run cli wp user get "$login" --field=ID >/dev/null 2>&1; then
		$WP_ENV run cli wp user update "$login" --user_pass='PwLogin123!' --role="$role" >/dev/null 2>&1
	else
		$WP_ENV run cli wp user create "$login" "${login}@example.test" --role="$role" --user_pass='PwLogin123!' >/dev/null 2>&1
	fi
}
seed_user s5pw_client author
seed_user s5pw_marketer editor
seed_user s5pw_developer administrator
ABOUT_ID="$($WP_ENV run cli wp post list --post_type=page --name=about --field=ID 2>/dev/null | tr -d '\r' | head -n1)"
[ -n "$ABOUT_ID" ] || fail "could not resolve the About page's post ID"
pass "seeded s5pw_client/s5pw_marketer/s5pw_developer (About page id=$ABOUT_ID)"

info "2/3  Resolving the site URL…"
SITE_URL="$($WP_ENV run cli wp option get siteurl 2>/dev/null | tr -d '\r')"
[ -n "$SITE_URL" ] || fail "could not resolve siteurl"
pass "target: $SITE_URL"

info "3/3  Running Playwright: log in AS each role, assert surfaces + blocked URL-jumps…"
if SITE_URL="$SITE_URL" ABOUT_POST_ID="$ABOUT_ID" node "$ROOT_DIR/tests/playwright/roles-login.spec.mjs"; then
	pass "all real-browser role-login checks passed"
else
	fail "one or more real-browser role-login checks failed (see output above)"
fi

echo
pass "Roles & Access (real browser logins): ALL CHECKS PASSED"
echo
