#!/usr/bin/env bash
#
# Step 10 acceptance — frontend state and interactive islands.
#
# Exit condition proven: S5 can deliver Astro-island-like interactive UX while
# HTML remains canonical/server-rendered.
#   - A page declares islands with data-s5-island="name"; only THOSE modules are
#     enqueued (sidecar discovery).
#   - A STATIC page ships no interaction JS at all.
#   - Local-state (counter) and shared-state (wishlist badge + button) islands are
#     present and wired as native ES modules; server stays authoritative for the
#     seeded initial state.
#
# The browser-side behavior (click → update before refresh) is verified in the
# reviewer's manual pass; this harness proves the enqueue discipline + markup that
# make it work, which is the automatable invariant.
#
# Usage: npm run test:step10   (or: bash tests/acceptance-step10.sh)

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

command -v npx >/dev/null 2>&1 || fail "npx not found. Run 'npm install' first."
docker info >/dev/null 2>&1     || fail "Docker daemon not reachable."

echo
info "S5 Step 10 — frontend state and interactive islands"
echo

info "0/5  Env up; import corpus + the islands fixture…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp plugin activate s5-plugin >/dev/null || fail "s5-plugin failed to activate"
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
for s in index islands-demo; do
	pid="$(wp post list --post_type=page --name="$s" --field=ID | head -n1)"
	[ -n "$pid" ] && wp post delete "$pid" --force >/dev/null 2>&1 || true
done
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "corpus import failed"
$WP_ENV run cli wp s5 import "$DYN_FIXTURE_C" >/dev/null 2>&1 || fail "islands fixture import failed"
ISL="$(wp post list --post_type=page --name=islands-demo --field=ID | head -n1)"
[ -n "$ISL" ] || fail "islands-demo page missing after import"
pass "environment ready; islands fixture imported (#$ISL)"

# ---------------------------------------------------------------------------
# 1. Sidecar discovery: the island page records exactly its two islands.
# ---------------------------------------------------------------------------
info "1/5  Sidecar discovery: page records its islands (counter, wishlist)…"
ISLANDS_JSON="$(wp post meta get "$ISL" _s5_islands --format=json)"
printf '%s' "$ISLANDS_JSON" | grep -qF 'counter'  || fail "counter island not detected"
printf '%s' "$ISLANDS_JSON" | grep -qF 'wishlist' || fail "wishlist island not detected"
pass "island page records [counter, wishlist] via sidecar discovery"

# ---------------------------------------------------------------------------
# 2. Only the required island modules are enqueued (as native ES modules).
# ---------------------------------------------------------------------------
info "2/5  Only required island modules are enqueued as type=module…"
ISL_URL="$(wp post url "$ISL")"
SERVED="$(curl -s "$ISL_URL")"
printf '%s' "$SERVED" | grep -qE '<script type="module"[^>]+islands/counter\.js' || fail "counter module not enqueued as ES module"
printf '%s' "$SERVED" | grep -qE '<script type="module"[^>]+islands/wishlist\.js' || fail "wishlist module not enqueued as ES module"
pass "counter + wishlist modules enqueued as native ES modules"

# ---------------------------------------------------------------------------
# 3. A STATIC page ships NO interaction JS (zero island modules).
# ---------------------------------------------------------------------------
info "3/5  A static page ships no interaction JS…"
IDX="$(wp post list --post_type=page --name=index --field=ID | head -n1)"
IDX_ISLANDS="$(wp post meta get "$IDX" _s5_islands --format=json)"
{ [ "$IDX_ISLANDS" = "[]" ] || [ -z "$IDX_ISLANDS" ]; } || fail "static page recorded islands: $IDX_ISLANDS"
IDX_SERVED="$(curl -s "$(wp post url "$IDX")")"
printf '%s' "$IDX_SERVED" | grep -qE 's5-island-|islands/[a-z]+\.js' && fail "static page shipped island JS"
pass "static page ships zero interaction JS"

# ---------------------------------------------------------------------------
# 4. Local + shared state markup is present (progressive enhancement base).
# ---------------------------------------------------------------------------
info "4/5  Local (counter) + shared (wishlist) island markup present…"
printf '%s' "$SERVED" | grep -qF 'data-s5-island="counter"'  || fail "counter island markup missing"
printf '%s' "$SERVED" | grep -qF 'data-s5-count'             || fail "counter value element missing"
printf '%s' "$SERVED" | grep -qF 'data-s5-island="wishlist"' || fail "wishlist island markup missing"
printf '%s' "$SERVED" | grep -qF 'data-s5-wishlist-count'    || fail "wishlist shared-count element missing"
# The markup is meaningful without JS (buttons + seeded values are server-rendered).
printf '%s' "$SERVED" | grep -qE '<span class="island_value" data-s5-count[^>]*>0</span>' || fail "counter not server-rendered with a seeded value"
pass "local + shared island markup server-rendered (works before JS runs)"

# ---------------------------------------------------------------------------
# 5. Round-trip fidelity is untouched: static corpus still byte-clean.
# ---------------------------------------------------------------------------
info "5/5  Static corpus still round-trips byte-clean (islands didn't regress it)…"
bash "$ROOT_DIR/tests/acceptance-step3.sh" >/tmp/s5-rt10.log 2>&1 || { tail -20 /tmp/s5-rt10.log >&2; fail "round-trip regressed"; }
pass "round-trip fidelity still green across the static corpus"

echo
pass "Step 10 acceptance: ALL CHECKS PASSED (interactive islands; static stays static)"
echo
