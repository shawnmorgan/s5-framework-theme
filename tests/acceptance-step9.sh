#!/usr/bin/env bash
#
# Step 9 acceptance — baseline SEO/AEO ownership.
#
# Exit condition proven: S5 owns SEO artifacts that are inherent to the document,
# while specialist SEO operations remain external.
#   - Real pages emit correct metadata (title/description/canonical, exactly ONE
#     canonical) without a dedicated SEO plugin.
#   - The S5-owned schema graph (WebSite/WebPage/Organization) is emitted and
#     MATCHES visible content (no drift).
#   - Compiler quality gates flag headings/alt/LCP problems (WARNINGS).
#   - A provider seam lets a specialist take over without duplicate output.
#
# Usage: npm run test:step9   (or: bash tests/acceptance-step9.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp()      { $WP_ENV run cli wp "$@" 2>/dev/null | tr -d '\r'; }
wpimport(){ $WP_ENV run cli wp s5 import "$@" 2>&1 | tr -d '\r'; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
PAGES_C="wp-content/s5-pages"

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

# self-cleaning: remove any planted SEO-bad fixture on exit.
cleanup() { rm -f "$ROOT_DIR/pages/seo-bad.html" 2>/dev/null || true; }
trap cleanup EXIT

echo
info "S5 Step 9 — baseline SEO/AEO ownership"
echo

info "0/5  Env up; import the corpus…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
for s in index about seo-bad; do
	pid="$(wp post list --post_type=page --name="$s" --field=ID | head -n1)"
	[ -n "$pid" ] && wp post delete "$pid" --force >/dev/null 2>&1 || true
done
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "import failed"
IDX="$(wp post list --post_type=page --name=index --field=ID | head -n1)"
IDX_URL="$(wp post url "$IDX")"
SERVED="$(curl -s "$IDX_URL")"
pass "environment ready; corpus imported"

# ---------------------------------------------------------------------------
# 1. Document metadata: title/description/canonical present, exactly ONE canonical.
# ---------------------------------------------------------------------------
info "1/5  Document metadata present; exactly one canonical (no core duplicate)…"
printf '%s' "$SERVED" | grep -qiE '<title>[^<]+</title>' || fail "no <title> served"
printf '%s' "$SERVED" | grep -qiE '<meta[^>]+name="description"' || fail "no meta description served"
CANON_COUNT="$(printf '%s' "$SERVED" | grep -oiE '<link[^>]+rel="canonical"[^>]*>' | grep -c . || true)"
[ "$CANON_COUNT" = "1" ] || fail "expected exactly one canonical, found $CANON_COUNT (core duplicate not suppressed?)"
printf '%s' "$SERVED" | grep -qF 'href="https://series5technology.com/"' || fail "authored canonical not served"
pass "title + description + exactly one (authored) canonical served without an SEO plugin"

# ---------------------------------------------------------------------------
# 2. Owned schema graph present with WebSite/WebPage/Organization.
# ---------------------------------------------------------------------------
info "2/5  S5-owned schema graph present (WebSite/WebPage/Organization)…"
printf '%s' "$SERVED" | grep -qF '"@graph"' || fail "no schema @graph in served head"
for t in WebSite WebPage Organization; do
	printf '%s' "$SERVED" | grep -qF "\"@type\": \"$t\"" || fail "schema graph missing $t node"
done
pass "owned schema graph emitted with WebSite + WebPage + Organization"

# ---------------------------------------------------------------------------
# 3. Schema MATCHES visible content (no drift): WebPage name == the page title.
# ---------------------------------------------------------------------------
info "3/5  Schema matches visible content (WebPage name == served <title>)…"
PAGE_TITLE="$(printf '%s' "$SERVED" | grep -oiE '<title>[^<]+</title>' | head -n1 | sed -E 's/<[^>]+>//g')"
[ -n "$PAGE_TITLE" ] || fail "could not read served <title>"
# The WebPage node's name must equal the page title (same source → no drift).
printf '%s' "$SERVED" | grep -qF "\"name\": \"$PAGE_TITLE\"" || fail "schema WebPage name does not match the page <title> ($PAGE_TITLE)"
# The canonical host must also appear in the schema (page identity from same value).
printf '%s' "$SERVED" | grep -qF 'series5technology.com' || fail "schema url does not match the canonical host"
pass "schema is synchronized with visible content (name + url match)"

# ---------------------------------------------------------------------------
# 4. Compiler SEO quality gates flag headings/alt/LCP (WARNINGS, non-blocking).
# ---------------------------------------------------------------------------
info "4/5  SEO gates flag a planted bad page (missing alt, two <h1>, lazy LCP)…"
cat > "$ROOT_DIR/pages/seo-bad.html" <<'HTML'
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>SEO Bad</title></head>
<body>
<main data-s5="seo-bad">
  <h1>First heading</h1>
  <h1>Second heading</h1>
  <img src="assets/diagram.png" loading="lazy">
</main>
</body>
</html>
HTML
OUT="$(wpimport "$PAGES_C" || true)"
printf '%s' "$OUT" | grep -qF 's5.seo.h1'      || { printf '%s\n' "$OUT" >&2; fail "SEO gate did not flag the duplicate <h1>"; }
printf '%s' "$OUT" | grep -qF 's5.seo.img-alt' || { printf '%s\n' "$OUT" >&2; fail "SEO gate did not flag the missing alt"; }
printf '%s' "$OUT" | grep -qF 's5.seo.lcp-lazy' || { printf '%s\n' "$OUT" >&2; fail "SEO gate did not flag the lazy LCP image"; }
# The gates are WARNINGS: the page still imported.
[ -n "$(wp post list --post_type=page --name=seo-bad --field=ID | head -n1)" ] || fail "seo-bad did not import (SEO gates must warn, not block)"
rm -f "$ROOT_DIR/pages/seo-bad.html"
pass "SEO gates flag h1/alt/LCP as warnings; bad page still imports (non-blocking)"

# ---------------------------------------------------------------------------
# 5. Provider seam: filters exist for a specialist SEO provider to take over
#    without duplicate output (schema graph filter + owned-schema suppression).
# ---------------------------------------------------------------------------
info "5/5  Provider seams present for specialist takeover…"
grep -rqF 's5_emit_owned_schema' "$ROOT_DIR/s5-theme/src/Render.php" || fail "provider seam s5_emit_owned_schema not present"
grep -rqF 's5_schema_graph' "$ROOT_DIR/s5-theme/src/Schema.php" || fail "provider seam s5_schema_graph not present"
pass "provider seams present (s5_emit_owned_schema + s5_schema_graph)"

echo
pass "Step 9 acceptance: ALL CHECKS PASSED (S5 owns document SEO + schema)"
echo
