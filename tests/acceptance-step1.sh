# Store via a base64 payload passed to `wp eval` — avoids shell/CLI quoting
# drift so the stored bytes exactly match $FRAGMENT.
B64=#!/usr/bin/env bash
#
# Step 1 acceptance test for the S5 framework.
#
# Exit condition proven: WordPress acts as a runtime for authored HTML without a
# builder representation. A known body-fragment is placed directly in framework
# storage (post meta), the page is requested over HTTP, and the served body
# contains that fragment byte-for-byte with no WordPress transformations
# (no wpautop <p>/<br>, no wptexturize smart-quotes, entities preserved).
#
# Steps:
#   1. wp-env start (idempotent) + activate s5-plugin
#   2. create a normal WP page
#   3. store a known canonical fragment in _s5_canonical_html (manual, no importer)
#   4. request the permalink; assert the fragment appears verbatim in the body
#   5. assert no WordPress transformation of the fragment; no PHP errors/notices
#
# Usage: npm run test:step1   (or: bash tests/acceptance-step1.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp() { $WP_ENV run cli wp "$@" 2>/dev/null; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

command -v npx >/dev/null 2>&1 || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found. wp-env requires Docker Desktop."
docker info >/dev/null 2>&1 || fail "Docker daemon not reachable. Start Docker Desktop."

echo
info "S5 Step 1 acceptance test"
echo

# --- 1. environment + plugin ----------------------------------------------
info "1/5  Starting wp-env and activating s5-plugin…"
$WP_ENV start >/dev/null
wp plugin activate s5-plugin >/dev/null || fail "s5-plugin failed to activate"
# Pretty permalinks so the front controller routes through template_include.
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
wp rewrite flush --hard >/dev/null 2>&1 || true
pass "environment ready"

# --- 2. create a page ------------------------------------------------------
info "2/5  Creating a WordPress page…"
PAGE_ID="$(wp post create --post_type=page --post_status=publish --post_title='S5 Canonical Page' --porcelain)"
[ -n "$PAGE_ID" ] || fail "could not create page"
pass "page created (ID $PAGE_ID)"

# --- 3. store a known canonical fragment (manual; no importer in Step 1) ----
info "3/5  Storing a known canonical fragment in _s5_canonical_html…"
# Sentinel chosen to expose WP transforms:
#   - a literal &amp; entity (wptexturize/kses must not alter)
#   - straight quotes "like this" (wptexturize would curl them)
#   - two blank-line-separated lines (wpautop would inject <p>/<br>)
#   - a [notashortcode] token (shortcode pass would strip an unknown shortcode)
read -r -d '' FRAGMENT <<'HTML' || true
<main data-s5="1"><h1>S5 CANON</h1>
<p>lorem &amp; ipsum "straight" quotes</p>

second line [notashortcode] end</main>
HTML

# Store via a base64 payload passed to `wp eval` — avoids shell/CLI quoting
# drift so the stored bytes exactly match $FRAGMENT.
B64="$(printf '%s' "$FRAGMENT" | base64)"
wp eval "update_post_meta( ${PAGE_ID}, '_s5_canonical_html', base64_decode('${B64}') );" >/dev/null \
  || fail "could not store canonical fragment"
STORED="$(wp eval "echo get_post_meta( ${PAGE_ID}, '_s5_canonical_html', true );")"
[ -n "$STORED" ] || fail "stored fragment came back empty"
pass "fragment stored"

# --- 4. request the permalink ---------------------------------------------
info "4/5  Requesting the page and capturing the served body…"
URL="$(wp post url "$PAGE_ID" 2>/dev/null)"
[ -n "$URL" ] || fail "could not resolve page URL"
BODY="$(curl -s "$URL" || true)"
[ -n "$BODY" ] || fail "empty response body from $URL"
pass "served $URL"

# --- 5. byte-clean assertions ---------------------------------------------
info "5/5  Asserting the fragment is served verbatim (no WP transforms)…"

contains() { printf '%s' "$BODY" | grep -qF -- "$1"; }

# The exact stored fragment must appear verbatim in the body.
contains '<main data-s5="1"><h1>S5 CANON</h1>' \
  || fail "fragment opening not found verbatim in body"
contains 'lorem &amp; ipsum "straight" quotes' \
  || fail "entity/quotes not preserved verbatim (wptexturize/kses transformed the fragment)"
contains 'second line [notashortcode] end</main>' \
  || fail "fragment tail/shortcode token not preserved verbatim"

# Negative checks: WordPress must NOT have transformed the fragment.
if printf '%s' "$BODY" | grep -qE '“|”|‘|’'; then
	fail "smart quotes detected — wptexturize transformed the fragment"
fi
# wpautop would wrap the blank-line-separated text in <p> or inject <br>.
if printf '%s' "$BODY" | grep -qF '<p>second line'; then
	fail "wpautop injected <p> into the fragment"
fi

# No PHP errors/notices from the render path.
DEBUG_CONTENT="$(wp eval '$p = (defined("WP_DEBUG_LOG") && is_string(WP_DEBUG_LOG)) ? WP_DEBUG_LOG : WP_CONTENT_DIR . "/debug.log"; echo file_exists($p) ? file_get_contents($p) : "";' 2>/dev/null || true)"
if printf '%s' "$DEBUG_CONTENT" | grep -Eiq 'PHP (Fatal error|Parse error|Warning|Notice)'; then
	echo "----- debug.log -----" >&2; printf '%s\n' "$DEBUG_CONTENT" >&2; echo "---------------------" >&2
	fail "PHP errors/notices during framework-page render"
fi
pass "fragment served byte-clean; no WP transforms; no PHP errors"

echo
pass "Step 1 acceptance: ALL CHECKS PASSED"
echo
