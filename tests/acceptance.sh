#!/usr/bin/env bash
#
# Step 0 acceptance test for the S5 framework.
#
# Exit condition proven: the repository installs and tests repeatably from a
# clean WordPress environment. Runs against the wp-env Docker environment
# (mounts s5-theme as a theme and s5-plugin as a plugin per .wp-env.json).
#
# Steps (any failure exits nonzero with a clear message):
#   1. wp-env start (idempotent)
#   2. activate s5-plugin
#   3. activate s5-theme
#   4. request the site root; assert no PHP errors/notices in debug.log
#   5. wp s5 hello -> assert output "s5 ok", exit 0
#
# Usage: npm test   (or: bash tests/acceptance.sh)

set -euo pipefail

# --- helpers ---------------------------------------------------------------

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'

pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

# wp-env is provided by the local devDependency.
WP_ENV="npx wp-env"
# Run WP-CLI inside the environment's cli container.
wp() { $WP_ENV run --quiet cli wp "$@"; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# --- preflight -------------------------------------------------------------

command -v npx >/dev/null 2>&1 || fail "npx not found. Run 'npm install' first (Node required)."
command -v docker >/dev/null 2>&1 || fail "docker not found. wp-env requires Docker Desktop installed and running."
docker info >/dev/null 2>&1 || fail "Docker daemon not reachable. Start Docker Desktop, then re-run."

echo
info "S5 Step 0 acceptance test"
echo

# --- 1. start environment (idempotent) -------------------------------------

info "1/5  Starting wp-env (idempotent)…"
$WP_ENV start >/dev/null
pass "wp-env is up"

# --- 2. activate plugin ----------------------------------------------------

info "2/5  Activating s5-plugin…"
wp plugin activate s5-plugin >/dev/null 2>&1 || fail "s5-plugin failed to activate"
wp plugin is-active s5-plugin >/dev/null 2>&1 || fail "s5-plugin is not active after activation"
pass "s5-plugin active"

# --- 3. activate theme -----------------------------------------------------

info "3/5  Activating s5-theme…"
wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
ACTIVE_THEME="$(wp theme list --status=active --field=name 2>/dev/null | tr -d '[:space:]')"
[ "$ACTIVE_THEME" = "s5-theme" ] || fail "active theme is '${ACTIVE_THEME}', expected 's5-theme'"
pass "s5-theme active"

# --- 4. front-page request: no PHP errors in debug.log ---------------------

info "4/5  Requesting site root and checking debug.log for PHP errors…"
# Truncate the debug log so we only inspect errors from THIS request.
wp eval 'if (defined("WP_DEBUG_LOG") && is_string(WP_DEBUG_LOG) && file_exists(WP_DEBUG_LOG)) { file_put_contents(WP_DEBUG_LOG, ""); } elseif (file_exists(WP_CONTENT_DIR . "/debug.log")) { file_put_contents(WP_CONTENT_DIR . "/debug.log", ""); }' >/dev/null 2>&1 || true

SITE_URL="$(wp option get siteurl 2>/dev/null)"
[ -n "$SITE_URL" ] || fail "could not resolve siteurl"

HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' "$SITE_URL/" || true)"
case "$HTTP_CODE" in
	2??|3??) pass "site root responded HTTP $HTTP_CODE" ;;
	*) fail "site root returned HTTP ${HTTP_CODE:-<none>} (expected 2xx/3xx)" ;;
esac

# Read whatever debug log exists and look for PHP-level problems.
DEBUG_CONTENT="$(wp eval '$p = (defined("WP_DEBUG_LOG") && is_string(WP_DEBUG_LOG)) ? WP_DEBUG_LOG : WP_CONTENT_DIR . "/debug.log"; echo file_exists($p) ? file_get_contents($p) : "";' 2>/dev/null || true)"
if printf '%s' "$DEBUG_CONTENT" | grep -Eiq 'PHP (Fatal error|Parse error|Warning|Notice|Deprecated)'; then
	echo "----- debug.log -----" >&2
	printf '%s\n' "$DEBUG_CONTENT" >&2
	echo "---------------------" >&2
	fail "PHP errors/notices detected on front-page request"
fi
pass "no PHP errors/notices on front-page request"

# --- 5. wp s5 hello --------------------------------------------------------

info "5/5  Running 'wp s5 hello'…"
HELLO_OUT="$(wp s5 hello 2>/dev/null)" || fail "'wp s5 hello' exited nonzero"
HELLO_OUT="$(printf '%s' "$HELLO_OUT" | tr -d '[:space:]')"
[ "$HELLO_OUT" = "s5ok" ] || fail "'wp s5 hello' printed '${HELLO_OUT}', expected 's5 ok'"
pass "'wp s5 hello' → s5 ok"

echo
pass "Step 0 acceptance: ALL CHECKS PASSED"
echo
