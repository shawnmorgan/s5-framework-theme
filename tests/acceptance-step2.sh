#!/usr/bin/env bash
#
# Step 2 acceptance test for the S5 framework — `wp s5 import`.
#
# Exit condition proven: a source HTML file can become a real WordPress page
# with one command. Import is idempotent, matches by slug (never duplicates),
# stores <body> verbatim and the sanctioned <head> artifacts, sets index.html
# as the front page, and serves everything byte-clean.
#
# Usage: npm run test:step2   (or: bash tests/acceptance-step2.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp() { $WP_ENV run cli wp "$@" 2>/dev/null; }

# Import source dir as seen INSIDE the container (mapped in .wp-env.json).
PAGES_DIR="wp-content/s5-pages"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

command -v npx >/dev/null 2>&1 || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
docker info >/dev/null 2>&1 || fail "Docker daemon not reachable."

echo
info "S5 Step 2 acceptance test"
echo

# --- 0. env + plugin + clean slate ----------------------------------------
info "0/5  Starting wp-env, activating s5-plugin, resetting pages…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp plugin activate s5-plugin >/dev/null || fail "s5-plugin failed to activate"
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
wp rewrite flush --hard >/dev/null 2>&1 || true
# Remove any pages from prior runs so counts are deterministic.
for s in index about fix-my-store; do
	pid="$(wp post list --post_type=page --name="$s" --field=ID 2>/dev/null | head -n1 || true)"
	[ -n "$pid" ] && wp post delete "$pid" --force >/dev/null 2>&1 || true
done
wp option update show_on_front posts >/dev/null 2>&1 || true
pass "environment ready"

# --- 1. import → pages exist at expected slugs -----------------------------
info "1/5  Importing $PAGES_DIR (first run)…"
OUT1="$($WP_ENV run cli wp s5 import "$PAGES_DIR" 2>&1)" || { echo "$OUT1" >&2; fail "import exited nonzero"; }
printf '%s\n' "$OUT1" | grep -qiE 'created .*index.html'        || fail "index.html not reported created"
printf '%s\n' "$OUT1" | grep -qiE 'created .*about.html'        || fail "about.html not reported created"
printf '%s\n' "$OUT1" | grep -qiE 'created .*fix-my-store.html' || fail "fix-my-store.html not reported created"

ABOUT_ID="$(wp post list --post_type=page --name=about --field=ID 2>/dev/null | head -n1)"
FMS_ID="$(wp post list --post_type=page --name=fix-my-store --field=ID 2>/dev/null | head -n1)"
INDEX_ID="$(wp post list --post_type=page --name=index --field=ID 2>/dev/null | head -n1)"
[ -n "$ABOUT_ID" ] && [ -n "$FMS_ID" ] && [ -n "$INDEX_ID" ] || fail "expected pages missing after import"
pass "pages created (index #$INDEX_ID, about #$ABOUT_ID, fix-my-store #$FMS_ID)"

# --- 2. re-import twice → same IDs, no duplicates, all "unchanged" ----------
info "2/5  Re-importing twice (idempotence)…"
$WP_ENV run cli wp s5 import "$PAGES_DIR" >/dev/null 2>&1 || fail "second import failed"
OUT3="$($WP_ENV run cli wp s5 import "$PAGES_DIR" 2>&1)" || fail "third import failed"
for f in index.html about.html fix-my-store.html; do
	printf '%s\n' "$OUT3" | grep -qiE "unchanged .*$f" || { echo "$OUT3" >&2; fail "$f not reported unchanged on re-import"; }
done
# No duplicates: exactly one page per slug.
for s in index about fix-my-store; do
	n="$(wp post list --post_type=page --name="$s" --field=ID 2>/dev/null | grep -c . || true)"
	[ "$n" = "1" ] || fail "slug '$s' has $n pages (expected 1 — duplicate created)"
done
# Same IDs as first run.
[ "$(wp post list --post_type=page --name=about --field=ID 2>/dev/null | head -n1)" = "$ABOUT_ID" ] || fail "about page ID changed across imports"
pass "idempotent: same IDs, no duplicates, all unchanged"

# --- 3. curl a page: body byte-match, title + description exactly once ------
info "3/5  Requesting /about/ and checking body + head…"
ABOUT_URL="$(wp post url "$ABOUT_ID" 2>/dev/null)"
BODY="$(curl -s "$ABOUT_URL" || true)"
[ -n "$BODY" ] || fail "empty response from $ABOUT_URL"

# Source <body> inner HTML (extracted the same way the importer does).
SRC_BODY="$(php -r '$h=file_get_contents($argv[1]); preg_match("/<body\b[^>]*>/i",$h,$m,PREG_OFFSET_CAPTURE); $o=$m[0][1]+strlen($m[0][0]); $c=strripos($h,"</body>"); echo substr($h,$o,$c-$o);' pages/about.html)"
printf '%s' "$BODY" | grep -qF -- "$SRC_BODY" || { echo "--- expected body ---" >&2; printf '%s\n' "$SRC_BODY" >&2; fail "served body does not contain source <body> verbatim"; }

# <title> present exactly once, matching the SOURCE title (derived, not hardcoded,
# so the assertion survives copy changes in the corpus).
TITLE_COUNT="$(printf '%s' "$BODY" | grep -oiE '<title>[^<]*</title>' | grep -c . || true)"
[ "$TITLE_COUNT" = "1" ] || fail "expected exactly one <title>, found $TITLE_COUNT"
SRC_TITLE="$(php -r '$h=file_get_contents($argv[1]); preg_match("/<title>(.*?)<\/title>/is",$h,$m); echo isset($m[1])?trim($m[1]):"";' pages/about.html)"
[ -n "$SRC_TITLE" ] || fail "about.html has no <title> to check"
printf '%s' "$BODY" | grep -qF -- "<title>$SRC_TITLE</title>" || fail "source <title> not rendered (expected: <title>$SRC_TITLE</title>)"

# meta description present exactly once.
DESC_COUNT="$(printf '%s' "$BODY" | grep -oiE '<meta name="description"[^>]*>' | grep -c . || true)"
[ "$DESC_COUNT" = "1" ] || fail "expected exactly one meta description, found $DESC_COUNT"
pass "body verbatim; title + description each present exactly once"

# --- 4. index.html → front page at / ---------------------------------------
info "4/5  Checking index.html is the front page at /…"
[ "$(wp option get show_on_front 2>/dev/null)" = "page" ] || fail "show_on_front is not 'page'"
[ "$(wp option get page_on_front 2>/dev/null)" = "$INDEX_ID" ] || fail "page_on_front is not the index page"
HOME_URL="$(wp option get home 2>/dev/null)"
HOME_BODY="$(curl -s "$HOME_URL/" || true)"
printf '%s' "$HOME_BODY" | grep -qF 'data-s5="home"' || fail "front page does not serve index.html body"
pass "index.html serves at / as the front page"

# --- 5. fix-my-store.html → /fix-my-store/ ---------------------------------
info "5/5  Checking fix-my-store.html resolves at /fix-my-store/…"
FMS_URL="$(wp post url "$FMS_ID" 2>/dev/null)"
case "$FMS_URL" in
	*/fix-my-store/) : ;;
	*) fail "fix-my-store URL is '$FMS_URL', expected to end with /fix-my-store/" ;;
esac
FMS_CODE="$(curl -s -o /dev/null -w '%{http_code}' "$FMS_URL" || true)"
case "$FMS_CODE" in 2??|3??) : ;; *) fail "/fix-my-store/ returned HTTP $FMS_CODE" ;; esac
pass "fix-my-store.html resolves at /fix-my-store/ (HTTP $FMS_CODE)"

# --- no PHP errors from any of the above -----------------------------------
DEBUG_CONTENT="$(wp eval '$p=(defined("WP_DEBUG_LOG")&&is_string(WP_DEBUG_LOG))?WP_DEBUG_LOG:WP_CONTENT_DIR."/debug.log"; echo file_exists($p)?file_get_contents($p):"";' 2>/dev/null || true)"
if printf '%s' "$DEBUG_CONTENT" | grep -Eiq 'PHP (Fatal error|Parse error|Warning|Notice)'; then
	echo "----- debug.log -----" >&2; printf '%s\n' "$DEBUG_CONTENT" >&2; echo "---------------------" >&2
	fail "PHP errors/notices during import/render"
fi

echo
pass "Step 2 acceptance: ALL CHECKS PASSED"
echo
