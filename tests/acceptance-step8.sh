#!/usr/bin/env bash
#
# Step 8 acceptance — templates/parts + generic dynamics (Loops & Logic).
#
# Exit condition proven: normal dynamic WordPress marketing/content sites are
# practical without S5 rebuilding query logic.
#   - Reusable PARTS: header/footer authored once in parts/, referenced by pages
#     via <s5-part>, expanded at compile time; pages still round-trip byte-clean.
#   - Runtime DYNAMIC PASS: a page whose compiled render contains L&L tags is
#     flagged dynamic and its <Loop>/<Field> render at REQUEST time against live
#     data; static pages skip the pass and stay byte-clean.
#
# Usage: npm run test:step8   (or: bash tests/acceptance-step8.sh)

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
DYN_FIXTURE_C="wp-content/s5-repo/tests/fixtures/dynamic"

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

echo
info "S5 Step 8 — templates/parts + generic dynamics (L&L)"
echo

# ---------------------------------------------------------------------------
# 0. Env up, plugins active, fresh import of the static corpus.
# ---------------------------------------------------------------------------
info "0/5  Env up; L&L active; import the static corpus…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp plugin activate s5-plugin >/dev/null || fail "s5-plugin failed to activate"
wp plugin activate tangible-loops-and-logic >/dev/null 2>&1 || fail "Loops & Logic failed to activate"
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
for s in index about commerce apps manage faq book-a-call privacy-policy fix-my-store sitemap-archive; do
	pid="$(wp post list --post_type=page --name="$s" --field=ID | head -n1)"
	[ -n "$pid" ] && wp post delete "$pid" --force >/dev/null 2>&1 || true
done
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "static corpus import failed"
pass "environment ready; L&L active; static corpus imported"

# ---------------------------------------------------------------------------
# 1. Parts: header/footer come from a shared part; pages carry only a reference
#    in canonical, but serve the expanded markup.
# ---------------------------------------------------------------------------
info "1/5  Reusable parts: canonical holds <s5-part>, render holds expanded markup…"
IDX="$(wp post list --post_type=page --name=index --field=ID | head -n1)"
[ -n "$IDX" ] || fail "index page missing"
CANON="$(wp post meta get "$IDX" _s5_canonical_html)"
printf '%s' "$CANON" | grep -qF '<s5-part name="site-header">' || fail "canonical does not reference the header part"
printf '%s' "$CANON" | grep -qF '<header class="site-header"' && fail "canonical inlines the header (should be a part reference)"
# index.html is imported as the SITE FRONT PAGE, so its canonical URL is the site
# root ('/'), not '/index/'. Depending on rewrite state, `wp post url` may return
# either — and only the front-page URL serves it. Assert the expanded header on
# whichever of the two actually serves the page, so this check tests part
# expansion (the real subject) rather than front-page routing timing.
IDX_URL="$(wp post url "$IDX")"
HOME_URL="$(wp option get home)"
# Follow redirects: a front page's own slug ('/index/') 301-redirects to the site
# root, so curl must follow it (-L) to reach the served page. Try both the
# resolved post URL and the site root; either serving the header is a pass.
if ! { curl -sL "$IDX_URL" | grep -qF '<header class="site-header"'; } \
   && ! { curl -sL "$HOME_URL/" | grep -qF '<header class="site-header"'; }; then
	fail "served index page does not show the expanded header part"
fi
# The same part serves on another page (single source of truth).
ABOUT_URL="$(wp post url "$(wp post list --post_type=page --name=about --field=ID | head -n1)")"
curl -s "$ABOUT_URL" | grep -qF '<nav class="nav" aria-label="Primary">' || fail "about page missing the shared header markup"
pass "parts expand at compile time; header/footer shared from one source"

# ---------------------------------------------------------------------------
# 2. Static pages are NOT flagged dynamic and still round-trip byte-clean.
# ---------------------------------------------------------------------------
info "2/5  Static pages are not dynamic; round-trip harness still green…"
for s in index about faq; do
	pid="$(wp post list --post_type=page --name="$s" --field=ID | head -n1)"
	flag="$(wp post meta get "$pid" _s5_dynamic)"
	[ "$flag" = "1" ] && fail "static page '$s' was wrongly flagged dynamic"
done
bash "$ROOT_DIR/tests/acceptance-step3.sh" >/tmp/s5-rt8.log 2>&1 || { tail -25 /tmp/s5-rt8.log >&2; fail "round-trip regressed with parts active"; }
pass "static pages stay static; round-trip fidelity green across the corpus"

# ---------------------------------------------------------------------------
# 3. Import the dynamic L&L fixture; it is flagged dynamic.
# ---------------------------------------------------------------------------
info "3/5  Import the L&L archive fixture → flagged dynamic…"
# The round-trip harness re-imported the static corpus; import the dynamic page
# from its fixtures dir (kept OUT of pages/ so the byte-clean corpus is unaffected).
$WP_ENV run cli wp s5 import "$DYN_FIXTURE_C" >/dev/null 2>&1 || fail "dynamic fixture import failed"
SM="$(wp post list --post_type=page --name=sitemap-archive --field=ID | head -n1)"
[ -n "$SM" ] || fail "sitemap-archive page missing after import"
[ "$(wp post meta get "$SM" _s5_dynamic)" = "1" ] || fail "dynamic page was not flagged dynamic"
pass "L&L archive page imported and flagged dynamic"

# ---------------------------------------------------------------------------
# 4. The dynamic pass runs at request time: <Loop>/<Field> render to real data,
#    and no L&L tags leak into the served output.
# ---------------------------------------------------------------------------
info "4/5  Dynamic pass renders the loop at request time (conditions/fields)…"
SM_URL="$(wp post url "$SM")"
SERVED="$(curl -s "$SM_URL")"
printf '%s' "$SERVED" | grep -qE '<li class="card"><h3>[^<]+ — Series 5 Technology</h3></li>' \
	|| { printf '%s' "$SERVED" | awk '/data-s5="page-loop"/,/<\/ul>/' >&2; fail "L&L <Loop> did not render page items at request time"; }
# The loop must have produced MORE THAN ONE item (proof it iterated real data).
COUNT="$(printf '%s' "$SERVED" | grep -oE '<li class="card"><h3>[^<]*</h3></li>' | grep -c .)"
[ "$COUNT" -ge 3 ] || fail "L&L loop produced only $COUNT items; expected the page list"
# No raw L&L tags may remain in the served output.
printf '%s' "$SERVED" | grep -qE '<Loop|<Field|<If' && fail "raw L&L tags leaked into served output (dynamic pass did not run)"
# The shared header part is present on the dynamic page too.
printf '%s' "$SERVED" | grep -qF '<header class="site-header"' || fail "dynamic page missing the shared header part"
pass "dynamic pass rendered the loop ($COUNT items) at request time; no L&L tags leaked"

# ---------------------------------------------------------------------------
# 5. Determinism + cache-safety: the dynamic page renders identically on repeat
#    requests (conditions/fields render correctly under page-cache rules).
# ---------------------------------------------------------------------------
info "5/5  Dynamic output is stable across repeat requests (cache-safe)…"
A="$(curl -s "$SM_URL" | md5 2>/dev/null || curl -s "$SM_URL" | md5sum)"
B="$(curl -s "$SM_URL" | md5 2>/dev/null || curl -s "$SM_URL" | md5sum)"
[ "$A" = "$B" ] || fail "dynamic page output differs between identical requests (not cache-safe)"
pass "dynamic output is stable across requests"

echo
pass "Step 8 acceptance: ALL CHECKS PASSED (parts + generic L&L dynamics)"
echo
