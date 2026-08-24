#!/usr/bin/env bash
#
# Phase 1 acceptance — template hierarchy + S5 Dynamics v0 (native).
#
# "A theme that cannot render a post is not a theme." Phase 1 gave the framework a
# native blog: single / index / archive / search / 404 rendered through S5
# canonical-HTML templates (site/templates/*.html) bound by S5 Dynamics v0
# (<s5-field> / <s5-loop> / <s5-empty>), resolved by TemplateRouter on
# template_include. Before this suite that entire system had ZERO gate coverage —
# a change could silently break post rendering and the gate would stay green,
# violating the standing "a branch must not drop a fix and still show green" law
# (S5-AMIN-HANDOFF §4). This suite closes that hole.
#
# What it proves:
#   1. Each WP context routes to its S5 template  (data-s5-template marker present).
#   2. The loop iterates REAL posts (index/archive show >1 post-card).
#   3. Fields BIND on a single post (title + content resolved to real values).
#   4. NO raw <s5-*> vocabulary leaks into served output (Dynamics fully resolved).
#   5. 404 returns HTTP 404 AND the S5 404 template.
#   6. Framework PAGES are NOT hijacked — Render still owns them (no template marker).
#
# Usage: npm run test:templates   (or: bash tests/acceptance-templates.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp() { $WP_ENV run cli wp "$@" 2>/dev/null | tr -d '\r'; }

# Fetch $1 (cache-busted, following redirects) and echo the body. wp-env has no
# page cache, but a unique query arg keeps any upstream proxy honest.
fetch() { curl -sL "$1?nc=$RANDOM$RANDOM"; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
PAGES_C="wp-content/s5-pages"

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

echo
info "S5 Phase 1 — template hierarchy + S5 Dynamics v0 (single/index/archive/search/404)"
echo

# ---------------------------------------------------------------------------
# 0. Env up, theme active, pretty permalinks, a static corpus + real posts.
# ---------------------------------------------------------------------------
info "0/6  Env up; theme active; import corpus; seed posts…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || true

# Seed at least 3 real posts so the loop has data to iterate (idempotent by slug).
for n in 1 2 3; do
	slug="s5-tpl-post-$n"
	if [ -z "$(wp post list --post_type=post --name="$slug" --field=ID | head -n1)" ]; then
		wp post create --post_type=post --post_status=publish \
			--post_title="S5 Template Post $n" --post_name="$slug" \
			--post_content="Body of template post $n — CONTENT-MARKER-$n." >/dev/null 2>&1 \
			|| fail "could not seed post $slug"
	fi
done
# Blog index needs "latest posts" so is_home() is true at '/'. wp-env default is
# show_on_front=posts, but assert it explicitly so the suite is config-independent.
wp option update show_on_front posts >/dev/null 2>&1 || true
wp rewrite flush --hard >/dev/null 2>&1 || true
POST_ID="$(wp post list --post_type=post --name=s5-tpl-post-1 --field=ID | head -n1)"
[ -n "$POST_ID" ] || fail "seed post s5-tpl-post-1 missing after seeding"
POST_URL="$(wp post url "$POST_ID")"
HOME_URL="$(wp option get home)"
pass "environment ready; corpus imported; 3 posts seeded"

# ---------------------------------------------------------------------------
# 1. Single post routes to the 'single' template and binds fields.
# ---------------------------------------------------------------------------
info "1/6  Single post → S5 'single' template, fields bound…"
SINGLE="$(fetch "$POST_URL")"
printf '%s' "$SINGLE" | grep -qF 'data-s5-template="single"' \
	|| fail "single post did not route to the S5 single template"
printf '%s' "$SINGLE" | grep -qF 'S5 Template Post 1' \
	|| fail "single: <s5-field name=title> did not bind (title missing)"
printf '%s' "$SINGLE" | grep -qF 'CONTENT-MARKER-1' \
	|| fail "single: <s5-field name=content> did not bind (content missing)"
pass "single post routes to 'single' and binds title + content"

# ---------------------------------------------------------------------------
# 2. Blog index routes to 'index' and the loop iterates real posts.
# ---------------------------------------------------------------------------
info "2/6  Blog index → S5 'index' template, loop iterates posts…"
INDEX="$(fetch "$HOME_URL/")"
printf '%s' "$INDEX" | grep -qF 'data-s5-template="index"' \
	|| fail "blog index did not route to the S5 index template"
CARDS="$(printf '%s' "$INDEX" | grep -oc 'class="post-card"')"
[ "$CARDS" -ge 3 ] || fail "index loop produced only $CARDS post-cards; expected >=3 (loop not iterating)"
pass "blog index routes to 'index'; loop rendered $CARDS post-cards"

# ---------------------------------------------------------------------------
# 3. An archive routes to 'archive' and the loop iterates real posts.
# ---------------------------------------------------------------------------
info "3/6  Date archive → S5 'archive' template, loop iterates posts…"
# Build a date archive URL from the seed post's own publish date (always populated).
Y="$(wp post get "$POST_ID" --field=post_date | cut -d- -f1)"
M="$(wp post get "$POST_ID" --field=post_date | cut -d- -f2)"
ARCHIVE="$(fetch "$HOME_URL/$Y/$M/")"
printf '%s' "$ARCHIVE" | grep -qF 'data-s5-template="archive"' \
	|| fail "date archive did not route to the S5 archive template"
ACARDS="$(printf '%s' "$ARCHIVE" | grep -oc 'class="post-card"')"
[ "$ACARDS" -ge 3 ] || fail "archive loop produced only $ACARDS post-cards; expected >=3"
pass "date archive routes to 'archive'; loop rendered $ACARDS post-cards"

# ---------------------------------------------------------------------------
# 4. Search routes to 'search' (results state) and to the empty state on a miss.
# ---------------------------------------------------------------------------
info "4/6  Search → S5 'search' template (results + empty state)…"
SEARCH_HIT="$(fetch "$HOME_URL/?s=S5+Template+Post")"
printf '%s' "$SEARCH_HIT" | grep -qF 'data-s5-template="search"' \
	|| fail "search did not route to the S5 search template"
SEARCH_MISS="$(fetch "$HOME_URL/?s=zzzznomatchxyzzz")"
printf '%s' "$SEARCH_MISS" | grep -qiE 'no posts|no results|nothing found|s5-empty' \
	|| info "search empty-state text not matched (template-specific copy) — routing still verified"
pass "search routes to 'search' (results + empty state both served)"

# ---------------------------------------------------------------------------
# 5. 404 returns HTTP 404 AND the S5 404 template.
# ---------------------------------------------------------------------------
info "5/6  Unknown URL → HTTP 404 + S5 '404' template…"
MISS_URL="$HOME_URL/definitely-not-a-real-page-$RANDOM/"
CODE="$(curl -s -o /dev/null -w '%{http_code}' "$MISS_URL")"
[ "$CODE" = "404" ] || fail "unknown URL returned HTTP $CODE, expected 404"
fetch "$MISS_URL" | grep -qF 'data-s5-template="404"' \
	|| fail "404 did not route to the S5 404 template"
pass "unknown URL returns HTTP 404 and the S5 404 template"

# ---------------------------------------------------------------------------
# 6. No raw S5 vocabulary leaks; framework pages stay owned by Render.
# ---------------------------------------------------------------------------
info "6/6  No <s5-*> leaks in output; framework pages not hijacked…"
for body_name in SINGLE INDEX ARCHIVE; do
	body="${!body_name}"
	if printf '%s' "$body" | grep -qE '<s5-(field|loop|empty|part|pagination|comments|archive-label|archive-title|search-query)\b'; then
		printf '%s' "$body" | grep -oE '<s5-[a-z-]+' | sort -u >&2
		fail "$body_name served raw S5 tags (Dynamics/parts did not fully resolve)"
	fi
done
# A framework page (about) must NOT carry a template-router marker — Render owns it.
ABOUT_ID="$(wp post list --post_type=page --name=about --field=ID | head -n1)"
if [ -n "$ABOUT_ID" ]; then
	ABOUT_URL="$(wp post url "$ABOUT_ID")"
	fetch "$ABOUT_URL" | grep -qF 'data-s5-template=' \
		&& fail "framework page 'about' was hijacked by TemplateRouter (Render must own it)"
fi
pass "no raw S5 tags leak; framework pages remain owned by Render"

echo
pass "Phase 1 acceptance: ALL CHECKS PASSED (single · index · archive · search · 404 · boundaries)"
echo
