#!/usr/bin/env bash
#
# Phase B — Style class-swap (R3, axis-swap-only) acceptance.
#
# Proves the mode:class save is narrow by construction:
#   - the controls manifest exposes only axes the REAL vocabulary backs (≥2 classes);
#   - a swap WITHIN an axis applies the chosen class and drops the axis's other classes;
#   - a class NOT in the axis is REJECTED (s5_class_axis, 422) and canonical is unchanged;
#   - an unavailable axis (no vocabulary backing) is rejected — no invented classes.
#
# There is NO free-form class path through this endpoint at any level.
#
# Usage: npm run test:classswap   (or: bash tests/acceptance-classswap.sh)

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
info "S5 Phase B — Style class-swap (R3) acceptance"
echo

info "0/4  Env up; theme active; corpus imported…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
$WP_ENV run cli wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "corpus import failed"
ABOUT="$($WP_ENV run cli wp post list --post_type=page --name=about --field=ID 2>/dev/null | tr -d '\r' | head -n1)"
[ -n "$ABOUT" ] || fail "about page missing"
pass "environment ready (about #$ABOUT)"

OUT="$($WP_ENV run cli wp eval '
$id='"$ABOUT"';
// Manifest: at least the text-align axis must be available in the real vocabulary.
$axes=\S5\ControlsManifest::axes();
$keys=array_map(function($a){return $a["key"];},$axes);
echo "HAS_TEXTALIGN=".(in_array("text-align",$keys,true)?"1":"0")."\n";
// Item 5c shipped the text-scale / space ladders in utilities.css, so those axes now
// light up (were absent by construction before the ladders existed).
echo "HAS_TEXTSCALE=".(in_array("text-scale",$keys,true)?"1":"0")."\n";
echo "HAS_SPACETOP=".(in_array("space-top",$keys,true)?"1":"0")."\n";

$c=\S5\Storage::get_canonical_html($id);$p=new WP_HTML_Tag_Processor($c);$node="";
while($p->next_tag()){ $n=$p->get_attribute("data-node"); if($n){$node=$n;break;} }
echo "NODE=$node\n";

// Valid swap within the axis.
$r=new WP_REST_Request("POST","/s5/v1/pages/$id/save");$r->set_url_params(array("id"=>$id));
$r->set_body_params(array("mode"=>"class","node"=>$node,"axis"=>"text-align","class"=>"u-text-center"));
$res=\S5\Rest::handle_save($r);
$ok=is_wp_error($res)?0:(!empty($res->get_data()["ok"])?1:0);
echo "VALID_OK=$ok\n";
echo "NODE_HAS_CENTER=".(strpos(\S5\Storage::get_canonical_html($id),"u-text-center")!==false?"1":"0")."\n";

// Swap to a DIFFERENT class in the same axis: center should be gone, right present.
$r2=new WP_REST_Request("POST","/s5/v1/pages/$id/save");$r2->set_url_params(array("id"=>$id));
$r2->set_body_params(array("mode"=>"class","node"=>$node,"axis"=>"text-align","class"=>"u-text-right"));
\S5\Rest::handle_save($r2);
$canon=\S5\Storage::get_canonical_html($id);
echo "SWAPPED_OFF_CENTER=".(strpos($canon,"u-text-center")===false?"1":"0")."\n";
echo "SWAPPED_ON_RIGHT=".(strpos($canon,"u-text-right")!==false?"1":"0")."\n";

// Free-form (not in axis) → rejected, canonical unchanged.
$before=\S5\Storage::get_canonical_html($id);
$r3=new WP_REST_Request("POST","/s5/v1/pages/$id/save");$r3->set_url_params(array("id"=>$id));
$r3->set_body_params(array("mode"=>"class","node"=>$node,"axis"=>"text-align","class"=>"totally-made-up"));
$res3=\S5\Rest::handle_save($r3);
echo "FREEFORM_REJECTED=".(is_wp_error($res3)?"1":"0")."\n";
echo "FREEFORM_CODE=".(is_wp_error($res3)?$res3->get_error_code():"none")."\n";
echo "FREEFORM_UNCHANGED=".($before===\S5\Storage::get_canonical_html($id)?"1":"0")."\n";

// text-scale is now a REAL axis (5c): a valid swap within it must APPLY.
$r5=new WP_REST_Request("POST","/s5/v1/pages/$id/save");$r5->set_url_params(array("id"=>$id));
$r5->set_body_params(array("mode"=>"class","node"=>$node,"axis"=>"text-scale","class"=>"text-scale-lg"));
$res5=\S5\Rest::handle_save($r5);
echo "TEXTSCALE_APPLIED=".(is_wp_error($res5)?"0":(strpos(\S5\Storage::get_canonical_html($id),"text-scale-lg")!==false?"1":"0"))."\n";

// A genuinely UNAVAILABLE axis (one the vocabulary never defines) → rejected (no invented classes).
$r4=new WP_REST_Request("POST","/s5/v1/pages/$id/save");$r4->set_url_params(array("id"=>$id));
$r4->set_body_params(array("mode"=>"class","node"=>$node,"axis"=>"not-a-real-axis","class"=>"nope-nope"));
$res4=\S5\Rest::handle_save($r4);
echo "UNAVAIL_REJECTED=".(is_wp_error($res4)?"1":"0")."\n";
' 2>/dev/null | tr -d '\r')"

get() { printf '%s\n' "$OUT" | grep "^$1=" | head -n1 | cut -d= -f2; }

info "1/4  Manifest exposes only vocabulary-backed axes…"
[ "$(get HAS_TEXTALIGN)" = "1" ]  || fail "text-align axis missing from manifest"
[ "$(get HAS_TEXTSCALE)" = "1" ]  || fail "text-scale axis missing — the 5c utilities.css ladder should light it up"
[ "$(get HAS_SPACETOP)" = "1" ]   || fail "space-top axis missing — the 5c utilities.css ladder should light it up"
[ -n "$(get NODE)" ]              || fail "no data-node found"
pass "manifest = real axes (text-align + text-scale + space-top, all vocabulary-backed)"

info "2/4  Swap within the axis applies + replaces…"
[ "$(get VALID_OK)" = "1" ]          || fail "valid swap did not succeed"
[ "$(get NODE_HAS_CENTER)" = "1" ]   || fail "chosen class not applied"
[ "$(get SWAPPED_OFF_CENTER)" = "1" ] || fail "previous axis class not removed on re-swap"
[ "$(get SWAPPED_ON_RIGHT)" = "1" ]  || fail "new axis class not applied on re-swap"
pass "axis swap is mutually exclusive within the set"

info "3/4  Free-form class rejected; canonical unchanged…"
[ "$(get FREEFORM_REJECTED)" = "1" ]  || fail "a class outside the axis was NOT rejected (free-form leak!)"
[ "$(get FREEFORM_CODE)" = "s5_class_axis" ] || fail "reject code was not s5_class_axis (got: $(get FREEFORM_CODE))"
[ "$(get FREEFORM_UNCHANGED)" = "1" ] || fail "canonical changed on a rejected class swap"
pass "no free-form class passes (s5_class_axis, canonical untouched)"

info "4/4  text-scale swap applies; a truly-unavailable axis is rejected…"
[ "$(get TEXTSCALE_APPLIED)" = "1" ] || fail "text-scale-lg swap did NOT apply (the 5c ladder should make it a real axis)"
[ "$(get UNAVAIL_REJECTED)" = "1" ]  || fail "an unavailable axis was accepted"
pass "text-scale swap applies; a fabricated axis is rejected — the vocabulary is never invented"

echo
pass "Style class-swap (R3) acceptance: ALL CHECKS PASSED (axis-swap-only; loud; no free-form)"
