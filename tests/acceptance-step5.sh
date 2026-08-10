#!/usr/bin/env bash
#
# Step 5 acceptance — split canonical source from derived render.
#
# Exit condition proven: source is portable truth; render output is disposable
# environment state. Import compiles canonical -> derived render (`_s5_render_html`,
# served) + a semantic post_content mirror (never served), atomically. Recompile
# is deterministic. Canonical source is revisioned; restoring a revision reverts
# the served page with no manual recompile. Deleting the derived artifacts and
# recompiling restores them from canonical alone.
#
# Usage: npm run test:step5   (or: bash tests/acceptance-step5.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp() { $WP_ENV run cli wp "$@" 2>/dev/null | tr -d '\r'; }
wpeval() { $WP_ENV run cli wp eval "$1" 2>/dev/null | tr -d '\r'; }
md5of() { md5 2>/dev/null || md5sum; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
PAGES_C="wp-content/s5-pages"
ABOUT_SRC="$ROOT_DIR/pages/about.html"

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

# self-cleaning: restore about.html fixture on any exit.
ABOUT_BACKUP="$(mktemp)"; cp "$ABOUT_SRC" "$ABOUT_BACKUP"
cleanup() { cp "$ABOUT_BACKUP" "$ABOUT_SRC" 2>/dev/null || true; rm -f "$ABOUT_BACKUP" 2>/dev/null || true; }
trap cleanup EXIT

echo
info "S5 Step 5 — canonical source vs derived render"
echo

info "0/6  Starting wp-env, activating plugin, fresh import…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp plugin activate s5-plugin >/dev/null || fail "s5-plugin failed to activate"
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
for s in index about fix-my-store series-5-technology; do
	pid="$(wp post list --post_type=page --name="$s" --field=ID | head -n1)"
	[ -n "$pid" ] && wp post delete "$pid" --force >/dev/null 2>&1 || true
done
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "import failed"
ABOUT="$(wp post list --post_type=page --name=about --field=ID | head -n1)"
[ -n "$ABOUT" ] || fail "about page missing after import"
pass "environment ready; corpus imported (about #$ABOUT)"

# --- 1. derived + mirror exist; served comes from render; round-trip green ----
info "1/6  Derived render + mirror exist; page served from _s5_render_html…"
RENDER="$(wp post meta get "$ABOUT" _s5_render_html)"
MIRROR="$(wp post get "$ABOUT" --field=post_content)"
[ -n "$RENDER" ] || fail "_s5_render_html is empty after import"
[ -n "$MIRROR" ] || fail "post_content mirror is empty after import"
ORIG_RENDER="$RENDER"
wp post meta update "$ABOUT" _s5_render_html '<article>RENDER-SENTINEL</article>' >/dev/null
curl -s "$(wp post url "$ABOUT")" | grep -qF 'RENDER-SENTINEL' || { wp post meta update "$ABOUT" _s5_render_html "$ORIG_RENDER" >/dev/null; fail "served output does not come from _s5_render_html"; }
wp post meta update "$ABOUT" _s5_render_html "$ORIG_RENDER" >/dev/null
pass "derived render + mirror exist; page is served from _s5_render_html"

info "      round-trip harness still green (identity compile)…"
bash "$ROOT_DIR/tests/acceptance-step3.sh" >/tmp/s5-rt5.log 2>&1 || { tail -20 /tmp/s5-rt5.log >&2; fail "round-trip regressed"; }
ABOUT="$(wp post list --post_type=page --name=about --field=ID | head -n1)"
pass "round-trip fidelity still green after the split"

# --- 2. mutate canonical via cli -> recompile -> deterministic ----------------
info "2/6  Mutate canonical (cli) → recompile → derived updates deterministically…"
wp post meta update "$ABOUT" _s5_canonical_html '<article data-s5="about"><h1>Recompile Target</h1><p>body &amp; text</p></article>' >/dev/null
$WP_ENV run cli wp s5 recompile about >/dev/null 2>&1 || fail "recompile failed"
R1="$(wp post meta get "$ABOUT" _s5_render_html | md5of)"
M1="$(wp post get "$ABOUT" --field=post_content | md5of)"
wp post meta get "$ABOUT" _s5_render_html | grep -qF 'Recompile Target' || fail "render did not update after canonical mutation"
$WP_ENV run cli wp s5 recompile about >/dev/null 2>&1 || fail "second recompile failed"
R2="$(wp post meta get "$ABOUT" _s5_render_html | md5of)"
M2="$(wp post get "$ABOUT" --field=post_content | md5of)"
[ "$R1" = "$R2" ] || fail "recompile not deterministic: render md5 differs"
[ "$M1" = "$M2" ] || fail "recompile not deterministic: mirror md5 differs"
pass "recompile is deterministic (render + mirror byte-identical twice)"

# --- 3. mirror is semantic: body text, no script/style/data-*; WP search finds it
info "3/6  post_content mirror is semantic (no script/style/data-*); WP search finds it…"
wp post meta update "$ABOUT" _s5_canonical_html '<section data-s5="mix" data-edit="text"><h1>Findable Heading</h1><p>unique-corpus-token alpha</p><style>.x{color:red}</style><script>console.log(1)</script><svg><rect/></svg></section>' >/dev/null
$WP_ENV run cli wp s5 recompile about >/dev/null 2>&1 || fail "recompile failed"
MIR="$(wp post get "$ABOUT" --field=post_content)"
printf '%s' "$MIR" | grep -qF 'Findable Heading'    || fail "mirror lost body heading"
printf '%s' "$MIR" | grep -qF 'unique-corpus-token' || fail "mirror lost body text"
printf '%s' "$MIR" | grep -qiE '<script|<style|<svg' && fail "mirror still contains script/style/svg"
printf '%s' "$MIR" | grep -qiE 'data-s5|data-edit'   && fail "mirror still contains data-* attributes"
FOUND="$(wp post list --post_type=page --s='unique-corpus-token' --field=post_name | head -n1)"
[ "$FOUND" = "about" ] || fail "WP search did not find the framework page by body text (got '$FOUND')"
pass "mirror has body text, zero script/style/data-*; WP search finds the page"

# --- 4. update via import -> revision -> restore reverts served (no recompile) -
info "4/6  Update via import → revision → restore reverts served output…"
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "re-import (v1) failed"
ABOUT="$(wp post list --post_type=page --name=about --field=ID | head -n1)"
REV_V1="$(wp post list --post_type=revision --post_parent="$ABOUT" --field=ID | head -n1)"
[ -n "$REV_V1" ] || fail "no revision captured after import"
wp post meta get "$REV_V1" _s5_canonical_html | grep -qF '<h1>About</h1>' || fail "v1 revision did not capture canonical source"
python3 - "$ABOUT_SRC" <<'PY'
import sys
p=sys.argv[1]; s=open(p).read()
s=s.replace("<h1>About</h1>","<h1>About v2</h1>").replace("One compiler, three doors","Changed body text")
open(p,"w").write(s)
PY
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "re-import (v2) failed"
curl -s "$(wp post url "$ABOUT")" | grep -qF '<h1>About v2</h1>' || fail "v2 not served after update import"
# WP-CLI has no `post revision restore`; use the core function.
wpeval "wp_restore_post_revision( $REV_V1 );" >/dev/null
curl -s "$(wp post url "$ABOUT")" | grep -qF '<h1>About</h1>' || fail "served output did NOT revert after restore (no manual recompile ran)"
wp post meta get "$ABOUT" _s5_render_html | grep -qF '<h1>About</h1>' || fail "render was not recompiled on restore"
pass "restore reverts served output automatically (render recompiled on restore)"

# --- 5. delete render + post_content -> recompile restores both ---------------
info "5/6  Delete _s5_render_html + post_content → recompile restores both…"
wp post meta delete "$ABOUT" _s5_render_html >/dev/null
wpeval "wp_update_post( array( 'ID' => $ABOUT, 'post_content' => '' ) );" >/dev/null
[ -z "$(wp post meta get "$ABOUT" _s5_render_html)" ] || fail "render not actually deleted"
$WP_ENV run cli wp s5 recompile about >/dev/null 2>&1 || fail "recompile failed"
[ -n "$(wp post meta get "$ABOUT" _s5_render_html)" ] || fail "recompile did not restore _s5_render_html"
[ -n "$(wp post get "$ABOUT" --field=post_content)" ] || fail "recompile did not restore post_content mirror"
pass "disposable: recompile restored render + mirror from canonical alone"

# --- 6. negative-mutation self-test still red/green ---------------------------
info "6/6  Round-trip negative self-test still red/green…"
bash "$ROOT_DIR/tests/acceptance-step3.sh" >/tmp/s5-rt6.log 2>&1 || { tail -20 /tmp/s5-rt6.log >&2; fail "round-trip self-test regressed"; }
grep -q 'mutation detected' /tmp/s5-rt6.log || fail "negative-mutation self-test did not run"
pass "negative-mutation self-test still red/green"

echo
pass "Step 5 acceptance: ALL CHECKS PASSED (canonical is truth; render is disposable)"
echo
