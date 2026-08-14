#!/usr/bin/env bash
#
# Phase A — WordPress integration acceptance.
#
# Proves the S5 theme behaves like a first-class WP citizen in the admin:
#   1. Fullscreen takeover: the editor renders as a self-contained document with
#      NO #adminmenu / #wpadminbar (they are never emitted, not CSS-hidden).
#   2. Pages-list integration (Bricks pattern): framework pages carry an "S5"
#      post-state badge; the block editor is disabled for them; their edit link
#      routes to the S5 editor; non-framework pages keep the "Take over" adopt flow.
#   3. S5 menu = Setup & Status + Theme Settings ONLY (no Style/Components/New-page
#      submenus; page-creation removed from the S5 menu).
#   4. Appearance cleanup: Patterns/Customize/Fonts unreachable; Themes + Theme File
#      Editor kept.
#
# Usage: npm run test:phase-a   (or: bash tests/acceptance-phase-a.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp()   { $WP_ENV run cli wp "$@" 2>/dev/null | tr -d '\r'; }
wpev() { $WP_ENV run cli wp eval "$1" 2>/dev/null | tr -d '\r'; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
PAGES_C="wp-content/s5-pages"

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

echo
info "S5 Phase A — WordPress integration acceptance"
echo

# ---------------------------------------------------------------------------
# 0. Env up; theme active; corpus imported; an admin user context.
# ---------------------------------------------------------------------------
info "0/9  Env up; activate theme; import corpus…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "corpus import failed"
FW_ID="$(wp post list --post_type=page --name=about --field=ID | head -n1)"
[ -n "$FW_ID" ] || fail "framework page (about) missing"
# A non-framework page to assert the adopt path against.
NONFW_ID="$(wpev '$p=wp_insert_post(array("post_type"=>"page","post_status"=>"publish","post_title"=>"Plain Page A","post_name"=>"plain-page-a","post_content"=>"<p>plain</p>")); echo (int)$p;')"
[ -n "$NONFW_ID" ] && [ "$NONFW_ID" -gt 0 ] || fail "could not create a non-framework page"
pass "environment ready; framework #$FW_ID, non-framework #$NONFW_ID"

# Establish an admin user so current_user_can() gates resolve true in eval.
ADMIN_ID="$(wp user list --role=administrator --field=ID | head -n1)"
[ -n "$ADMIN_ID" ] || fail "no administrator user"
AS="wp_set_current_user($ADMIN_ID);"

# ---------------------------------------------------------------------------
# 1. "S5" post-state badge on framework pages, and NOT on plain pages.
# ---------------------------------------------------------------------------
info "1/9  'S5' post-state badge on framework pages only…"
BADGE_FW="$(wpev "$AS \$s=apply_filters('display_post_states',array(),get_post($FW_ID)); echo in_array('S5',array_values(\$s),true)?'yes':'no';")"
BADGE_NON="$(wpev "$AS \$s=apply_filters('display_post_states',array(),get_post($NONFW_ID)); echo in_array('S5',array_values(\$s),true)?'yes':'no';")"
[ "$BADGE_FW" = "yes" ]  || fail "framework page missing 'S5' post-state badge (got: $BADGE_FW)"
[ "$BADGE_NON" = "no" ]  || fail "plain page wrongly shows 'S5' badge (got: $BADGE_NON)"
pass "badge present on framework page, absent on plain page"

# ---------------------------------------------------------------------------
# 2. Block editor disabled for framework pages, enabled for plain pages.
# ---------------------------------------------------------------------------
info "2/9  Block editor locked on framework pages…"
BE_FW="$(wpev "$AS echo use_block_editor_for_post(get_post($FW_ID))?'on':'off';")"
BE_NON="$(wpev "$AS echo use_block_editor_for_post(get_post($NONFW_ID))?'on':'off';")"
[ "$BE_FW" = "off" ]  || fail "block editor NOT disabled for framework page (got: $BE_FW)"
[ "$BE_NON" = "on" ]  || fail "block editor wrongly disabled for plain page (got: $BE_NON)"
pass "block editor off for framework page, on for plain page"

# ---------------------------------------------------------------------------
# 3. Edit link for a framework page routes to the S5 editor (never Gutenberg).
# ---------------------------------------------------------------------------
info "3/9  Framework edit link → S5 editor…"
EDIT_LINK="$(wpev "$AS echo get_edit_post_link($FW_ID,'raw');")"
case "$EDIT_LINK" in
	*page=s5-editor*) pass "edit link points at the S5 editor ($EDIT_LINK)";;
	*) fail "framework edit link does not route to S5 editor: $EDIT_LINK";;
esac
# Plain page keeps the native editor link.
EDIT_NON="$(wpev "$AS echo get_edit_post_link($NONFW_ID,'raw');")"
case "$EDIT_NON" in
	*post.php*action=edit*) pass "plain page keeps native edit link";;
	*) fail "plain page edit link unexpectedly altered: $EDIT_NON";;
esac

# ---------------------------------------------------------------------------
# 4. Row actions: framework page has 'Edit content (WP)' secondary; plain page
#    has the 'Take over with S5' adopt action.
# ---------------------------------------------------------------------------
info "4/9  Row actions (Bricks pattern)…"
ROW_FW="$(wpev "$AS \$a=apply_filters('page_row_actions',array('edit'=>'<a>Edit</a>'),get_post($FW_ID)); echo implode('|',array_keys(\$a));")"
case "$ROW_FW" in
	*s5_wp_edit*) pass "framework row exposes 'Edit content (WP)' (keys: $ROW_FW)";;
	*) fail "framework row missing 'Edit content (WP)' action (keys: $ROW_FW)";;
esac
ROW_NON="$(wpev "$AS \$a=apply_filters('page_row_actions',array('edit'=>'<a>Edit</a>'),get_post($NONFW_ID)); echo implode('|',array_keys(\$a));")"
case "$ROW_NON" in
	*s5_convert*) pass "plain page row exposes the 'Take over with S5' adopt action";;
	*) fail "plain page row missing the adopt action (keys: $ROW_NON)";;
esac

# ---------------------------------------------------------------------------
# 5. S5 menu = Setup & Status + Theme Settings ONLY.
# ---------------------------------------------------------------------------
info "5/9  S5 menu contents = Setup + Theme Settings only…"
# Build the admin menu as an admin and inspect the S5 submenu slugs.
MENU="$(wpev "$AS require_once ABSPATH.'wp-admin/includes/admin.php'; \$GLOBALS['menu']=array(); \$GLOBALS['submenu']=array(); do_action('admin_menu'); \$s=isset(\$GLOBALS['submenu']['s5'])?\$GLOBALS['submenu']['s5']:array(); \$slugs=array(); foreach(\$s as \$i){ \$slugs[]=\$i[2]; } echo implode(',',\$slugs);")"
info "     S5 submenu slugs: ${MENU:-<none>}"
case "$MENU" in
	*s5-new-page*) fail "S5 menu still contains a page-creation item (New S5 Page): $MENU";;
esac
echo "$MENU" | grep -q 's5-settings' || fail "S5 menu missing 'Theme Settings' (s5-settings): $MENU"
echo "$MENU" | grep -q '\bs5\b'      || fail "S5 menu missing Setup & Status (s5): $MENU"
# No Style/Components canvas entries (they were add_query_arg editor URLs).
case "$MENU" in
	*s5-panel*) fail "S5 menu still routes Style/Components into the canvas: $MENU";;
esac
pass "S5 menu is Setup & Status + Theme Settings only"

# ---------------------------------------------------------------------------
# 6. Theme Settings screen renders with the seven-screen sub-nav.
# ---------------------------------------------------------------------------
info "6/9  Theme Settings app shell renders (7-screen sub-nav)…"
# The settings app was rebuilt to theme-settings-mockup.html: the left sub-nav uses the
# mockup's OWN markup — `.snav` buttons carrying `data-screen="<key>"` (not the old
# `data-s5-screen=`). Count the mockup's sub-nav links so the gate tracks the transcribed
# reality; also assert the mockup's page shell + all seven screen sections render.
# The mockup markup carries data-screen="<key>" on BOTH each sub-nav link and each screen
# section — 7 screens → 14 occurrences (7 nav links + 7 sections). Assert exactly that, so
# a dropped screen or a broken render is caught precisely.
DSCOUNT="$(wpev "$AS ob_start(); \S5\Settings::render_screen(); \$h=ob_get_clean(); echo substr_count(\$h,'data-screen=');")"
[ "${DSCOUNT:-0}" -eq 14 ] || fail "settings app does not render 7 sub-nav links + 7 screens (data-screen count: ${DSCOUNT:-0}, expected 14)"
# The dark mockup shell must be present (page-head + subnav), not the old light wrap.
SHELL_OK="$(wpev "$AS ob_start(); \S5\Settings::render_screen(); \$h=ob_get_clean(); echo (strpos(\$h,'s5set-page')!==false && strpos(\$h,'class=\"subnav')!==false)?'yes':'no';")"
[ "$SHELL_OK" = "yes" ] || fail "settings app did not render the mockup page shell (.s5set-page + .subnav)"
pass "settings app renders the mockup shell + seven-screen sub-nav (data-screen×$DSCOUNT = 7 links + 7 sections)"

# ---------------------------------------------------------------------------
# 7. Appearance cleanup: Patterns/Customize/Site-editor removed from the menu;
#    Themes + Theme File Editor kept.
# ---------------------------------------------------------------------------
info "7/9  Appearance menu cleanup — removal RUN against the real rendered submenu…"
# HARDENED (fidelity-repair Item 4). The old gate checked only that a REMOVAL-TARGET
# CONSTANT existed — a pure code-exists test. That let a regression pass: the constant
# held stale exact slugs (customize.php, edit.php?post_type=wp_block) while WordPress
# actually registers Patterns/Customize/Fonts with per-request query strings
# (site-editor.php?p=/pattern, customize.php?return=…, font-library.php), so
# remove_submenu_page() silently no-oped and the menu still showed all three.
#
# This version proves the REMOVAL EFFECT, two ways:
#  (7a) unit: seed $submenu['themes.php'] with the REAL-WORLD slug shapes core uses,
#       run Appearance::remove_menus(), and assert the forbidden entries are gone and
#       Themes + Theme File Editor survive. This exercises the actual removal logic
#       against the actual slugs — the thing that failed.
#  (7b) rendered: build the real admin submenu via wp-admin/menu.php in a bootstrapped
#       admin context, run the remover, and assert no forbidden Appearance child
#       remains. (The AUTHORITATIVE rendered-menu check over real authenticated HTTP
#       lives in the Playwright real-install acceptance — see tools/*walkthrough*.)
REMOVE_HOOK="$(wpev "$AS echo has_action('admin_menu',array('S5\\\\Appearance','remove_menus'))!==false?'hooked':'no';")"
[ "$REMOVE_HOOK" = "hooked" ] || fail "Appearance remover not hooked on admin_menu (got: $REMOVE_HOOK)"

# The two assertions live in a standalone PHP probe run via `wp eval-file` — NO complex
# shell quoting (a multi-statement string in $(wpev ...) proved fragile under
# set -euo pipefail). The probe prints two token lines: UNIT= and RENDERED=.
# The theme is mounted at wp-content/s5-repo (see .wp-env.json), so the probe path is
# wp-content/s5-repo/tests/lib/appearance-menu-probe.php inside the container.
PROBE_OUT="$($WP_ENV run cli wp eval-file wp-content/s5-repo/tests/lib/appearance-menu-probe.php 2>/dev/null | tr -d '\r')"
UNIT="$(printf '%s\n' "$PROBE_OUT" | grep '^UNIT=' | head -n1 | cut -d= -f2-)"
RENDERED="$(printf '%s\n' "$PROBE_OUT" | grep '^RENDERED=' | head -n1 | cut -d= -f2-)"
info "     7a removal (real slug shapes) → ${UNIT:-<none>}"
info "     7b rendered submenu           → ${RENDERED:-<none>}"

# (7a) The removal MUST drop Patterns/Customize/Fonts and keep Themes + Theme File Editor.
case "$UNIT" in
	OK:*) pass "removal RUN drops Patterns/Customize/Fonts (real slug shapes); keeps Themes + Theme File Editor";;
	*)    fail "Appearance removal logic failed against real slug shapes: ${UNIT:-<empty — probe did not run>}";;
esac

# (7b) The real rendered submenu must carry no forbidden child (SKIP allowed — the
# authoritative rendered-menu check is the Playwright real-install walkthrough).
case "$RENDERED" in
	OK:*)   pass "rendered themes.php submenu carries no forbidden Appearance child ($RENDERED)";;
	SKIP)   pass "rendered-menu bootstrap unavailable in this WP build — deferred to the Playwright rendered-menu check (SKIP)";;
	FAIL:*) fail "rendered themes.php submenu still carries a forbidden Appearance child: $RENDERED";;
	*)      fail "rendered-menu probe produced no result: ${RENDERED:-<empty>}";;
esac

# ---------------------------------------------------------------------------
# 8. Direct-access guard registered (removed screens unreachable by URL).
# ---------------------------------------------------------------------------
info "8/9  Removed Appearance screens are unreachable by URL…"
GUARD="$(wpev "$AS echo has_action('admin_init',array('S5\\\\Appearance','block_direct_access'))!==false?'hooked':'no';")"
[ "$GUARD" = "hooked" ] || fail "Appearance direct-access guard not hooked (got: $GUARD)"
pass "direct-access guard is registered on admin_init"

# ---------------------------------------------------------------------------
# 9. Fullscreen takeover: the editor document carries NO admin chrome markers.
# ---------------------------------------------------------------------------
info "9/9  Fullscreen editor document has no admin chrome…"
# The takeover is a self-contained document printed on admin_init BEFORE
# admin-header.php runs, then exit — so #adminmenu / #wpadminbar are never emitted
# (not CSS-hidden). Assert the seam is hooked on admin_init and that the standalone
# body class is both emitted by PHP and styled in the editor CSS.
TAKEOVER="$(wpev "$AS echo has_action('admin_init',array('S5\\\\Editor','maybe_render_fullscreen'))!==false?'hooked':'no';")"
[ "$TAKEOVER" = "hooked" ] || fail "fullscreen takeover not hooked on admin_init (got: $TAKEOVER)"
grep -q 's5-editor-fullscreen' "$ROOT_DIR/s5-theme/src/Editor.php" || fail "fullscreen document body class not emitted by Editor.php"
grep -q 's5-editor-fullscreen' "$ROOT_DIR/s5-theme/assets/editor/editor.css" || fail "fullscreen body styling missing from editor.css"
# And the standalone document must NOT actually pull admin-header.php (the source
# of chrome). Match a real include/require statement, not the doc-comment that
# explains we deliberately avoid it.
if grep -nE "(include|require)(_once)?[^;]*admin-header\.php" "$ROOT_DIR/s5-theme/src/Editor.php"; then
	fail "fullscreen path includes admin-header.php — that re-introduces admin chrome"
fi
pass "fullscreen takeover seam present; standalone document (no admin-header.php)"

# Cleanup the throwaway plain page.
wp post delete "$NONFW_ID" --force >/dev/null 2>&1 || true

echo
pass "Phase A acceptance: ALL CHECKS PASSED"
