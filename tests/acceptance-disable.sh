#!/usr/bin/env bash
#
# Phase B — Disable (R4 / HANDOFF §2.4) acceptance.
#
# Proves "Disabled ≠ hidden": a node disabled through the ONE door (mode:disable)
#   - keeps data-disabled="true" IN canonical (source of truth; re-enable is possible),
#   - is DROPPED from the derived render (absent from what visitors get — not display:none),
#   - is DROPPED from the SEO/search mirror (post_content) too,
#   - and re-enabling restores it to the render.
#
# The corpus itself carries no disabled nodes, so the compile fast-path leaves every
# existing page byte-identical — this suite exercises the transform explicitly.
#
# Usage: npm run test:disable   (or: bash tests/acceptance-disable.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
PAGES_C="wp-content/s5-pages"

command -v npx >/dev/null 2>&1    || fail "npx not found."
command -v docker >/dev/null 2>&1 || fail "docker not found."

echo
info "S5 Phase B — Disable (R4) acceptance"
echo

info "0/4  Env up; theme active; corpus imported…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
$WP_ENV run cli wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "corpus import failed"
ABOUT="$($WP_ENV run cli wp post list --post_type=page --name=about --field=ID 2>/dev/null | tr -d '\r' | head -n1)"
[ -n "$ABOUT" ] || fail "about page missing"
# An administrator context: mode:disable is a STRUCTURE-tree op, gated by
# Roles::can('editor.structure') since Phase C-4b (R1). An Administrator resolves to the
# Developer S5 role, which is always granted — so the disable save is authorized. Without a
# user the gate correctly refuses (s5_role_structure); the test proves the WRITE path, so it
# must run as a real capable user, exactly like the Phase A / Roles suites.
ADMIN_ID="$($WP_ENV run cli wp user list --role=administrator --field=ID 2>/dev/null | tr -d '\r' | head -n1)"
[ -n "$ADMIN_ID" ] || fail "no administrator user"
pass "environment ready (about #$ABOUT, admin #$ADMIN_ID)"

# Drive the whole disable → assert → re-enable flow inside one eval so state is
# consistent, and print machine-checkable KEY=VALUE lines.
OUT="$($WP_ENV run cli wp eval '
wp_set_current_user('"$ADMIN_ID"');
$id='"$ABOUT"';
$c=\S5\Storage::get_canonical_html($id);
$p=new WP_HTML_Tag_Processor($c); $node="";
while($p->next_tag()){ $n=$p->get_attribute("data-node"); if($n){ $node=$n; break; } }
if(!$node){ echo "NODE=\n"; return; }
echo "NODE=$node\n";
$req=new WP_REST_Request("POST","/s5/v1/pages/$id/save");
$req->set_url_params(array("id"=>$id));
$req->set_body_params(array("mode"=>"disable","node"=>$node,"disabled"=>true));
$res=\S5\Rest::handle_save($req);
$data=is_object($res)?$res->get_data():$res;
echo "OK=".(!empty($data["ok"])?"1":"0")."\n";
$canon=\S5\Storage::get_canonical_html($id);
$render=\S5\Storage::get_render_html($id);
$mirror=get_post_field("post_content",$id);
echo "CANON_DISABLED=".(strpos($canon,"data-disabled")!==false?"1":"0")."\n";
echo "CANON_NODE=".(strpos($canon,$node)!==false?"1":"0")."\n";
echo "RENDER_NODE=".(strpos($render,$node)!==false?"1":"0")."\n";
echo "MIRROR_NODE=".(strpos($mirror,$node)!==false?"1":"0")."\n";
$req2=new WP_REST_Request("POST","/s5/v1/pages/$id/save");
$req2->set_url_params(array("id"=>$id));
$req2->set_body_params(array("mode"=>"disable","node"=>$node,"disabled"=>false));
\S5\Rest::handle_save($req2);
$render2=\S5\Storage::get_render_html($id);
echo "REENABLE_RENDER_NODE=".(strpos($render2,$node)!==false?"1":"0")."\n";
' 2>/dev/null | tr -d '\r')"

get() { printf '%s\n' "$OUT" | grep "^$1=" | head -n1 | cut -d= -f2; }

NODE="$(get NODE)";           [ -n "$NODE" ] || fail "no data-node found in about canonical"
info "1/4  Disabling node '$NODE' through mode:disable…"
[ "$(get OK)" = "1" ]        || fail "disable save did not return ok"
pass "disable persisted through the one door"

info "2/4  Canonical keeps the node + data-disabled (re-enable possible)…"
[ "$(get CANON_DISABLED)" = "1" ] || fail "canonical lost data-disabled"
[ "$(get CANON_NODE)" = "1" ]     || fail "canonical dropped the node (it must stay)"
pass "canonical retains data-disabled=\"true\" on the node"

info "3/4  Render AND mirror drop the disabled node (absent, not hidden)…"
[ "$(get RENDER_NODE)" = "0" ] || fail "disabled node STILL present in the served render"
[ "$(get MIRROR_NODE)" = "0" ] || fail "disabled node STILL present in the SEO mirror"
pass "disabled node absent from render and mirror (R4)"

info "4/4  Re-enable restores it to the render…"
[ "$(get REENABLE_RENDER_NODE)" = "1" ] || fail "re-enabled node did not return to the render"
pass "re-enable restores the node to the render"

echo
pass "Disable (R4) acceptance: ALL CHECKS PASSED (disabled ≠ hidden; canonical is truth)"
