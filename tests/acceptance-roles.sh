#!/usr/bin/env bash
#
# Phase C — Roles / R1 access enforcement acceptance.
#
# Proves the §4 matrix is enforced SERVER-SIDE (hidden AND blocked), at the S5 role
# level — not merely current_user_can():
#   - WP-role mapping seeds defaults (Administrator→developer, Editor→marketer, Author→client);
#   - a per-user override WINS over the WP role;
#   - the client-holds-Administrator case is actually restricted (a WP Administrator pinned
#     to Client resolves to Client and is DENIED developer/marketer capabilities);
#   - Roles::can() is fail-closed for unknown capabilities.
#
# Usage: npm run test:roles   (or: bash tests/acceptance-roles.sh)

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

echo
info "S5 Phase C — Roles / R1 access enforcement"
echo

info "0/5  Env up; theme active…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
$WP_ENV run cli wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
pass "environment ready"

# One eval drives the whole matrix of assertions and prints KEY=VALUE lines.
OUT="$($WP_ENV run cli wp eval '
// Seed test users.
$aid = username_exists("s5author") ? get_user_by("login","s5author")->ID : wp_create_user("s5author","pw123456xyz","s5author@test.test");
(new WP_User($aid))->set_role("author");
$eid = username_exists("s5editor") ? get_user_by("login","s5editor")->ID : wp_create_user("s5editor","pw123456xyz","s5editor@test.test");
(new WP_User($eid))->set_role("editor");
$admin = get_users(array("role"=>"administrator","number"=>1,"fields"=>"ID")); $admin = $admin ? (int)$admin[0] : 1;

// Clean any prior overrides.
\S5\Roles::save_override($aid,""); \S5\Roles::save_override($eid,""); \S5\Roles::save_override($admin,"");

echo "MAP_ADMIN=".\S5\Roles::role_for_user($admin)."\n";
echo "MAP_EDITOR=".\S5\Roles::role_for_user($eid)."\n";
echo "MAP_AUTHOR=".\S5\Roles::role_for_user($aid)."\n";

// Author (client) is denied advanced / structure / embed / settings; allowed content.
echo "CLIENT_CONTENT=".(\S5\Roles::can("editor.content",$aid)?"1":"0")."\n";
echo "CLIENT_ADVANCED=".(\S5\Roles::can("editor.advanced",$aid)?"1":"0")."\n";
echo "CLIENT_EMBED=".(\S5\Roles::can("editor.embed",$aid)?"1":"0")."\n";
echo "CLIENT_SETTINGS=".(\S5\Roles::can("settings.view",$aid)?"1":"0")."\n";

// Editor (marketer): advanced yes, embed no, roles-edit no.
echo "MARKETER_ADVANCED=".(\S5\Roles::can("editor.advanced",$eid)?"1":"0")."\n";
echo "MARKETER_EMBED=".(\S5\Roles::can("editor.embed",$eid)?"1":"0")."\n";
echo "MARKETER_ROLES=".(\S5\Roles::can("settings.roles",$eid)?"1":"0")."\n";

// Per-user override wins: pin the editor to developer.
\S5\Roles::save_override($eid,"developer");
echo "OVERRIDE_EDITOR=".\S5\Roles::role_for_user($eid)."\n";
echo "OVERRIDE_EDITOR_EMBED=".(\S5\Roles::can("editor.embed",$eid)?"1":"0")."\n";
\S5\Roles::save_override($eid,"");

// Client-holds-Administrator: pin an admin to client → must be RESTRICTED.
\S5\Roles::save_override($admin,"client");
echo "ADMIN_PINNED=".\S5\Roles::role_for_user($admin)."\n";
echo "ADMIN_PINNED_SETTINGS=".(\S5\Roles::can("settings.view",$admin)?"1":"0")."\n";
echo "ADMIN_PINNED_EMBED=".(\S5\Roles::can("editor.embed",$admin)?"1":"0")."\n";
echo "ADMIN_STILL_MANAGE_OPTIONS=".(user_can($admin,"manage_options")?"1":"0")."\n";
\S5\Roles::save_override($admin,"");

// Fail-closed on unknown capability.
echo "UNKNOWN_CAP=".(\S5\Roles::can("does.not.exist",$admin)?"1":"0")."\n";

// Developer is always all-granted even if a matrix save tried to strip it.
\S5\Roles::save_matrix(array("editor.embed"=>array(false,false,false)));
echo "DEV_EMBED_LOCKED_ON=".(\S5\Roles::can("editor.embed",$admin)?"1":"0")."\n";
delete_option(\S5\Roles::OPTION_MATRIX);
' 2>/dev/null | tr -d '\r')"

get() { printf '%s\n' "$OUT" | grep "^$1=" | head -n1 | cut -d= -f2; }

info "1/5  WP-role mapping seeds S5 defaults…"
[ "$(get MAP_ADMIN)" = "developer" ] || fail "Administrator did not map to developer (got $(get MAP_ADMIN))"
[ "$(get MAP_EDITOR)" = "marketer" ] || fail "Editor did not map to marketer (got $(get MAP_EDITOR))"
[ "$(get MAP_AUTHOR)" = "client" ]   || fail "Author did not map to client (got $(get MAP_AUTHOR))"
pass "Administrator→developer · Editor→marketer · Author→client"

info "2/5  Client is denied advanced/structure/embed/settings; allowed content…"
[ "$(get CLIENT_CONTENT)" = "1" ]  || fail "client wrongly denied Content"
[ "$(get CLIENT_ADVANCED)" = "0" ] || fail "client wrongly ALLOWED Advanced"
[ "$(get CLIENT_EMBED)" = "0" ]    || fail "client wrongly ALLOWED Embed"
[ "$(get CLIENT_SETTINGS)" = "0" ] || fail "client wrongly ALLOWED Theme Settings"
pass "client = Content only; Advanced/Embed/Settings blocked"

info "3/5  Marketer: advanced yes, embed no, roles-edit no…"
[ "$(get MARKETER_ADVANCED)" = "1" ] || fail "marketer denied Advanced"
[ "$(get MARKETER_EMBED)" = "0" ]    || fail "marketer wrongly ALLOWED Embed"
[ "$(get MARKETER_ROLES)" = "0" ]    || fail "marketer wrongly ALLOWED Roles edit"
pass "marketer resolves per the §4 matrix"

info "4/5  Per-user override wins (editor→developer gains Embed)…"
[ "$(get OVERRIDE_EDITOR)" = "developer" ]   || fail "override did not resolve to developer"
[ "$(get OVERRIDE_EDITOR_EMBED)" = "1" ]      || fail "overridden developer denied Embed"
pass "per-user override wins over the WP role"

info "5/5  Client-holds-Administrator is actually restricted…"
[ "$(get ADMIN_PINNED)" = "client" ]            || fail "admin pinned to client did not resolve to client"
[ "$(get ADMIN_PINNED_SETTINGS)" = "0" ]         || fail "admin-pinned-client wrongly ALLOWED settings (hide-only, not blocked!)"
[ "$(get ADMIN_PINNED_EMBED)" = "0" ]            || fail "admin-pinned-client wrongly ALLOWED Embed"
[ "$(get ADMIN_STILL_MANAGE_OPTIONS)" = "1" ]    || fail "test invalid: admin lost manage_options"
[ "$(get UNKNOWN_CAP)" = "0" ]                    || fail "unknown capability was not fail-closed"
[ "$(get DEV_EMBED_LOCKED_ON)" = "1" ]            || fail "developer got locked out of a capability (must always be all-granted)"
pass "Roles::can() gates by S5 role, not manage_options; fail-closed; developer never locked out"

echo
pass "Roles / R1 acceptance: ALL CHECKS PASSED (hidden AND blocked, server-side)"
