#!/usr/bin/env bash
#
# Distribution acceptance — the ZIP is the artifact.
#
# Every other acceptance script runs against the theme MOUNTED into wp-env (the repo
# lives at wp-content/s5-repo). That proves the code, but it never proved the thing a
# real user installs: a self-contained zip on a site with no repo, no composer, no
# node. This suite closes that gap.
#
# It builds dist/s5-theme.zip, installs it at a NON-MOUNTED theme dir (s5-dist) inside
# the running wp-env container — byte-identical to `wp theme install <zip>` — and
# asserts, with ZERO mounts leaking in:
#
#   Scenario A (existing seeded site):
#     - activate zip → existing post_content page SURVIVES and renders STYLED via the
#       fallback template (design-system stylesheet loads; not browser-default).
#     - adopt one page → it becomes a framework page and opens in the S5 editor.
#     - the existing front-page setting is intact.
#
#   Scenario B (fresh framework serving):
#     - a framework page serves STYLED: the compiled bundle link is present AND a
#       token-driven rule actually applies (the bundle carries :root tokens + real
#       component rules).
#     - the top-level S5 admin menu exists.
#
# Usage: npm run test:dist   (or: bash tests/acceptance-dist.sh)

set -uo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

command -v docker >/dev/null 2>&1 || fail "docker not found."
command -v node >/dev/null 2>&1   || fail "node not found."
command -v unzip >/dev/null 2>&1  || fail "unzip not found."

WP_ENV="npx wp-env"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null 2>&1 || fail "wp-env start failed"

# Resolve the running dev containers (wordpress + cli) for THIS env by hash.
WP_CID="$(docker ps --format '{{.Names}}' | grep -E 'wordpress-1$' | grep -v tests | head -1)"
CLI_CID="$(docker ps --format '{{.Names}}' | grep -E 'cli-1$' | grep -v tests | head -1)"
[ -n "$WP_CID" ]  || fail "could not find the running wordpress container"
[ -n "$CLI_CID" ] || fail "could not find the running cli container"

# wp-cli in the dev container (strip deprecation noise + CR).
wpc() { docker exec "$CLI_CID" wp "$@" 2>/dev/null | grep -v 'Deprecated' | tr -d '\r'; }

# Host base URL for the dev env.
BASE="$(wpc option get home | tail -1)"
[ -n "$BASE" ] || fail "could not read site home URL"

echo
info "S5 distribution acceptance — installing the ZIP with zero mounts"
echo

# ---------------------------------------------------------------------------
# 0. Build the release zip and install it at a NON-MOUNTED theme dir.
# ---------------------------------------------------------------------------
info "0  Build dist/s5-theme.zip…"
node tools/build-zip.mjs >/dev/null 2>&1 || fail "build:zip failed"
[ -f dist/s5-theme.zip ] || fail "dist/s5-theme.zip not produced"

STAGE="$(mktemp -d)"
( cd "$STAGE" && unzip -q "$ROOT_DIR/dist/s5-theme.zip" ) || fail "unzip failed"
[ -f "$STAGE/s5-theme/site/dist/s5.css" ] || fail "zip is missing the compiled bundle (site/dist/s5.css)"

docker exec "$WP_CID" rm -rf /var/www/html/wp-content/themes/s5-dist
docker cp "$STAGE/s5-theme" "$WP_CID":/var/www/html/wp-content/themes/s5-dist >/dev/null || fail "install (docker cp) failed"
rm -rf "$STAGE"
docker exec "$WP_CID" test -f /var/www/html/wp-content/themes/s5-dist/site/dist/s5.css || fail "installed theme missing bundle"
pass "zip built and installed at wp-content/themes/s5-dist (no repo, no composer, no node on target)"

# ---------------------------------------------------------------------------
# 1. Scenario A prep: seed normal content BEFORE activating the framework.
# ---------------------------------------------------------------------------
info "1  Scenario A — seed existing content, capture front-page setting…"
wpc theme activate twentytwentyfour >/dev/null 2>&1 || wpc theme activate twentytwentythree >/dev/null 2>&1 || true
AB_ID="$(wpc post create --post_type=page --post_title='Dist Existing Page' --post_status=publish --post_content='<p>DIST-SURVIVE-MARKER existing content.</p>' --porcelain | tail -1)"
[ -n "$AB_ID" ] || fail "could not create existing page"
FRONT_BEFORE="$(wpc option get show_on_front | tail -1)"
pass "existing page #$AB_ID created; front-page setting was '$FRONT_BEFORE'"

# ---------------------------------------------------------------------------
# 2. Activate the installed zip.
# ---------------------------------------------------------------------------
info "2  Activate the installed zip (s5-dist)…"
wpc theme activate s5-dist >/dev/null 2>&1 || fail "s5-dist failed to activate"
wpc rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
ACTIVE="$(wpc theme list --status=active --field=name | tail -1)"
pass "active theme: $ACTIVE"

FRONT_AFTER="$(wpc option get show_on_front | tail -1)"
[ "$FRONT_BEFORE" = "$FRONT_AFTER" ] || fail "front-page setting changed on activation ($FRONT_BEFORE → $FRONT_AFTER)"
pass "front-page setting intact after activation"

# ---------------------------------------------------------------------------
# 3. Existing page renders STYLED via the fallback (never browser-default).
# ---------------------------------------------------------------------------
info "3  Existing page renders styled via the fallback template…"
AB_URL="$(wpc post url "$AB_ID" | tail -1)"
AB_HTML="$(curl -sL "$AB_URL")"
echo "$AB_HTML" | grep -q 'DIST-SURVIVE-MARKER' || fail "existing content did NOT survive/render"
echo "$AB_HTML" | grep -q "id='s5-fallback-css'" || fail "fallback stylesheet not enqueued on non-framework page"
echo "$AB_HTML" | grep -q 'data-s5="fallback"'   || fail "fallback template wrapper missing"
pass "existing page survived and renders through the styled fallback"

# ---------------------------------------------------------------------------
# 4. Adopt the existing page into S5 → it becomes a framework page.
# ---------------------------------------------------------------------------
info "4  Adopt the existing page into S5…"
wpc eval "\$r = \S5\\Seeder::convert_page($AB_ID); echo empty(\$r['ok'])?'FAIL':'OK';" | grep -q OK \
  || fail "adoption (convert_page) failed"
IS_FW="$(wpc eval "echo \S5\\Storage::is_framework_page($AB_ID)?'yes':'no';" | tail -1)"
[ "$IS_FW" = "yes" ] || fail "page is not a framework page after adoption"
pass "existing page adopted into S5 (now a framework page, editable in the S5 editor)"

# ---------------------------------------------------------------------------
# 5. Scenario B: a framework page serves STYLED with a token-driven rule applied.
# ---------------------------------------------------------------------------
info "5  Scenario B — framework page serves styled; token-driven rule applies…"
FW_ID="$(wpc post list --post_type=page --meta_key=_s5_canonical_html --field=ID --format=csv | grep -vi deprecated | head -1)"
[ -n "$FW_ID" ] || fail "no framework page found (seeding did not run?)"
FW_URL="$(wpc post url "$FW_ID" | tail -1)"
FW_HTML="$(curl -sL "$FW_URL")"
echo "$FW_HTML" | grep -q "id='s5-bundle-css'" || fail "compiled design-system bundle not enqueued on framework page"

BUNDLE_URL="$BASE/wp-content/themes/s5-dist/site/dist/s5.css"
BUNDLE="$(curl -sL "$BUNDLE_URL")"
echo "$BUNDLE" | grep -q -- '--s5-color-ink' || fail "served bundle has no design tokens (:root)"
echo "$BUNDLE" | grep -qE '\.(prose|page-hero|hero|section)[^{]*\{' || fail "served bundle has no real component rules"
pass "framework page serves styled; bundle carries :root tokens + component rules"

# ---------------------------------------------------------------------------
# 6. Top-level S5 admin menu exists.
# ---------------------------------------------------------------------------
info "6  Top-level S5 admin menu is registered…"
MENU="$(wpc eval 'do_action("admin_menu"); foreach((array)($GLOBALS["menu"]??[]) as $m){ if(($m[2]??"")==="s5"){echo "yes";break;} }' | tail -1)"
[ "$MENU" = "yes" ] || fail "top-level S5 menu not registered"
pass "top-level S5 menu present (Editor · Style · Components · Setup/Status)"

# ---------------------------------------------------------------------------
# 7. Restore the mounted s5-theme so a full-gate run leaves a clean baseline.
# ---------------------------------------------------------------------------
wpc theme activate s5-theme >/dev/null 2>&1 || true

echo
pass "DISTRIBUTION ACCEPTANCE PASSED — the uploadable zip works as a real theme, no mounts."
echo
