#!/usr/bin/env bash
#
# Step 4 acceptance — Authoring Contract validation for the S5 framework.
#
# Exit condition proven: strictness improves authoring reliability rather than
# creating opaque failures. Class vocabulary is the union of class selectors
# parsed from the theme stylesheet(s) declared in s5.config.json — no hardcoded
# lists. Unknown classes and inline styles fail loudly (ERROR, block import);
# grammar-nonconforming-but-defined classes and numeric scale steps WARN and
# proceed. Diagnostics are agent-readable (file·line·rule·token·permitted·repair·
# did-you-mean). A --format=json mode feeds agents.
#
# Usage: npm run test:step4   (or: bash tests/acceptance-step4.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp() { $WP_ENV run cli wp "$@" 2>/dev/null; }
# Run `wp s5 validate ...` capturing combined output; strip wp-env's own noise.
validate() { $WP_ENV run cli wp s5 validate "$@" 2>&1 | grep -vE '^ℹ Starting|Ran `wp|^Command '; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
UTIL_CSS="$ROOT_DIR/s5-theme/site/styles/utilities.css"
WORK="$ROOT_DIR/tests/_s4work"                 # host path (under the mounted repo)
CWORK="wp-content/s5-repo/tests/_s4work"        # same dir as seen in the container
PAGES_C="wp-content/s5-pages"                    # real corpus in the container

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

# --- self-cleaning: restore utilities.css and remove scratch on any exit -----
UTIL_BACKUP="$(mktemp)"
cp "$UTIL_CSS" "$UTIL_BACKUP"
cleanup() {
	cp "$UTIL_BACKUP" "$UTIL_CSS" 2>/dev/null || true
	rm -f "$UTIL_BACKUP" 2>/dev/null || true
	find "$WORK" -type f -delete 2>/dev/null || true
	rmdir "$WORK" 2>/dev/null || true
}
trap cleanup EXIT
mkdir -p "$WORK"

echo
info "S5 Step 4 — Authoring Contract validation"
echo

info "0/9  Starting wp-env and activating the plugin…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
pass "environment ready"

# --- 1. real corpus passes clean --------------------------------------------
info "1/9  Validating the real corpus (must be clean)…"
OUT="$(validate "$PAGES_C" || true)"
printf '%s\n' "$OUT" | grep -qiE 'file\(s\) valid' || { printf '%s\n' "$OUT" >&2; fail "real corpus did not validate clean"; }
printf '%s\n' "$OUT" | grep -qiE 'ERROR' && { printf '%s\n' "$OUT" >&2; fail "real corpus produced an ERROR (should be clean)"; }
pass "real corpus passes clean (0 errors)"

# --- 2. planted unknown class -> ERROR with location + did-you-mean ----------
info "2/9  Planted unknown class → ERROR + did-you-mean…"
printf '%s\n' '<!DOCTYPE html><html><head><title>Unknown</title></head>' '<body><section class="heroo">x</section></body></html>' > "$WORK/unknown.html"
OUT="$(validate "$CWORK" || true)"
printf '%s\n' "$OUT" | grep -qE '\[s5\.class\.unknown\]'   || { printf '%s\n' "$OUT" >&2; fail "unknown class did not raise s5.class.unknown"; }
printf '%s\n' "$OUT" | grep -qE 'unknown\.html:2'          || { printf '%s\n' "$OUT" >&2; fail "unknown class ERROR is missing file:line"; }
printf '%s\n' "$OUT" | grep -qiE 'did you mean: *hero\b'    || { printf '%s\n' "$OUT" >&2; fail "unknown class ERROR is missing did-you-mean 'hero'"; }
pass "unknown class → ERROR at unknown.html:2, did-you-mean 'hero'"
find "$WORK" -type f -delete

# --- 3. inline style outside svg -> ERROR; inside svg -> passes --------------
info "3/9  Inline style outside <svg> → ERROR; inside <svg> → passes…"
printf '%s\n' '<!DOCTYPE html><html><head><title>Inline</title></head>' '<body><div class="hero" style="color:red">x</div></body></html>' > "$WORK/inline.html"
printf '%s\n' '<!DOCTYPE html><html><head><title>Svg</title></head>' '<body><div class="hero"><svg viewBox="0 0 1 1"><rect style="fill:red" width="1" height="1"/></svg></div></body></html>' > "$WORK/svgok.html"
OUT="$(validate "$CWORK" || true)"
printf '%s\n' "$OUT" | grep -qE '\[s5\.style\.inline\]'          || { printf '%s\n' "$OUT" >&2; fail "inline style outside svg did not raise s5.style.inline"; }
printf '%s\n' "$OUT" | grep -qE 'inline\.html'                    || { printf '%s\n' "$OUT" >&2; fail "inline style ERROR missing filename"; }
# The svg file must NOT appear in any finding line.
if printf '%s\n' "$OUT" | grep -qE 'svgok\.html'; then printf '%s\n' "$OUT" >&2; fail "style inside <svg> wrongly flagged"; fi
pass "inline style outside svg → ERROR; style inside <svg> passes"
find "$WORK" -type f -delete

# --- 4. numeric scale class defined in CSS -> WARNING, proceeds --------------
info "4/9  Numeric scale class defined in CSS → WARNING (import proceeds)…"
printf '\n.text-2 { font-size: 2rem; } /* step-4 acceptance: numeric alias, temporary */\n' >> "$UTIL_CSS"
printf '%s\n' '<!DOCTYPE html><html><head><title>Numeric</title></head>' '<body><p class="text-2">x</p></body></html>' > "$WORK/numeric.html"
OUT="$(validate "$CWORK" || true)"
printf '%s\n' "$OUT" | grep -qE '\[s5\.class\.numeric-scale\]'   || { printf '%s\n' "$OUT" >&2; fail "numeric scale class did not raise s5.class.numeric-scale"; }
printf '%s\n' "$OUT" | grep -qiE 'WARN'                          || { printf '%s\n' "$OUT" >&2; fail "numeric scale finding is not a WARNING"; }
# WARNING must NOT fail validation: no ERROR present.
if printf '%s\n' "$OUT" | grep -qE 'ERROR'; then printf '%s\n' "$OUT" >&2; fail "numeric scale wrongly escalated to ERROR"; fi
printf '%s\n' "$OUT" | grep -qiE 'file\(s\) valid'               || { printf '%s\n' "$OUT" >&2; fail "numeric scale did not proceed (no success line)"; }
pass "numeric scale class → WARNING; validation proceeds"

# import gate: a WARNING-only page must still import (proceeds).
GATE_OUT="$($WP_ENV run cli wp s5 import "$CWORK" 2>&1 | grep -vE '^ℹ Starting|Ran `wp' || true)"
printf '%s\n' "$GATE_OUT" | grep -qiE 'created|updated|unchanged' || { printf '%s\n' "$GATE_OUT" >&2; fail "warning-only page did not import"; }
wp post delete "$(wp post list --post_type=page --name=numeric --field=ID | head -n1)" --force >/dev/null 2>&1 || true
cp "$UTIL_BACKUP" "$UTIL_CSS"   # restore vocabulary now (numeric alias gone)
find "$WORK" -type f -delete
pass "import gate: warning-only page proceeds and imports"

# --- 5. --format=json parses and contains every diagnostic field ------------
info "5/9  --format=json parses and carries every field…"
printf '%s\n' '<!DOCTYPE html><html><head><title>Json</title></head>' '<body><b class="totallybogus">x</b></body></html>' > "$WORK/jsoncase.html"
RAWJSON="$(validate "$CWORK" --format=json || true)"
# `wp s5 validate` prints the JSON object, then WP_CLI::error prints a summary
# line and exits nonzero; isolate the JSON (first line starting '{' through the
# last line that is exactly '}') before parsing.
JSON="$(printf '%s\n' "$RAWJSON" | awk '/^\{/{f=1} f{print} /^\}/{f=0}')"
printf '%s' "$JSON" | php -r '
	$in = stream_get_contents(STDIN);
	$d  = json_decode($in, true);
	if (!is_array($d)) { fwrite(STDERR, "not valid JSON\n"); exit(1); }
	foreach (["ok","summary","findings"] as $k) { if (!array_key_exists($k,$d)) { fwrite(STDERR,"missing $k\n"); exit(1); } }
	if (empty($d["findings"])) { fwrite(STDERR,"no findings\n"); exit(1); }
	$f = $d["findings"][0];
	foreach (["severity","rule","file","line","token","message","permitted","repair","did_you_mean"] as $k) {
		if (!array_key_exists($k,$f)) { fwrite(STDERR,"finding missing field: $k\n"); exit(1); }
	}
	if ($f["rule"] !== "s5.class.unknown") { fwrite(STDERR,"unexpected rule: ".$f["rule"]."\n"); exit(1); }
	if ($f["file"] !== "jsoncase.html") { fwrite(STDERR,"unexpected file\n"); exit(1); }
	echo "json-ok\n";
' >/dev/null 2>/tmp/s5-jsonerr || { echo "$JSON" >&2; cat /tmp/s5-jsonerr >&2; fail "--format=json missing a required field or invalid"; }
pass "--format=json parses; findings carry severity/rule/file/line/token/message/permitted/repair/did_you_mean"
find "$WORK" -type f -delete

# --- 6/7/8. security fixtures (D-fix #6 regression guard) --------------------
# Permanent fixtures for the re-audit XSS gap (event-handler attrs bypassed the
# Authoring Contract entirely) plus the two sibling injection vectors the same
# fix must also cover: javascript: URLs and mid-body <script>. Each is proven
# REJECTED-with-diagnostics at BOTH the validate and import entrances; the
# save-door entrance (S5\Rest::handle_save) is covered separately by
# tests/acceptance-editor.sh + tests/fixtures/editor-probe.php. See DECISIONS.md.

# --- 6. on* event-handler attribute -> ERROR at validate; blocked at import --
info "6/9  Planted onclick attribute → ERROR at validate; blocked at import…"
printf '%s\n' '<!DOCTYPE html><html><head><title>Onclick</title></head>' '<body><section class="hero"><button onclick="alert(1)">go</button></section></body></html>' > "$WORK/onclick.html"
OUT="$(validate "$CWORK" || true)"
printf '%s\n' "$OUT" | grep -qE '\[s5\.attr\.event-handler\]' || { printf '%s\n' "$OUT" >&2; fail "onclick attribute did not raise s5.attr.event-handler"; }
printf '%s\n' "$OUT" | grep -qE 'onclick\.html'                 || { printf '%s\n' "$OUT" >&2; fail "event-handler ERROR missing filename"; }
GATE_OUT="$($WP_ENV run cli wp s5 import "$CWORK" 2>&1 | grep -vE '^ℹ Starting|Ran `wp' || true)"
printf '%s\n' "$GATE_OUT" | grep -qiE '0 created, 0 updated, 0 unchanged; 1 blocked' || { printf '%s\n' "$GATE_OUT" >&2; fail "onclick page was not blocked at import"; }
pass "onclick attribute → ERROR at validate; blocked at import"
find "$WORK" -type f -delete

# --- 7. javascript: URL in href/src -> ERROR at validate; blocked at import --
info "7/9  Planted javascript: URL → ERROR at validate; blocked at import…"
printf '%s\n' '<!DOCTYPE html><html><head><title>JsUrl</title></head>' '<body><section class="hero"><a href="javascript:alert(1)">go</a></section></body></html>' > "$WORK/jsurl.html"
OUT="$(validate "$CWORK" || true)"
printf '%s\n' "$OUT" | grep -qE '\[s5\.attr\.javascript-url\]' || { printf '%s\n' "$OUT" >&2; fail "javascript: URL did not raise s5.attr.javascript-url"; }
printf '%s\n' "$OUT" | grep -qE 'jsurl\.html'                    || { printf '%s\n' "$OUT" >&2; fail "javascript-url ERROR missing filename"; }
GATE_OUT="$($WP_ENV run cli wp s5 import "$CWORK" 2>&1 | grep -vE '^ℹ Starting|Ran `wp' || true)"
printf '%s\n' "$GATE_OUT" | grep -qiE '0 created, 0 updated, 0 unchanged; 1 blocked' || { printf '%s\n' "$GATE_OUT" >&2; fail "javascript: URL page was not blocked at import"; }
pass "javascript: URL → ERROR at validate; blocked at import"
find "$WORK" -type f -delete

# --- 8. <script> injected mid-body -> ERROR at validate; blocked at import;
#        head-level application/ld+json structured data is exempt -----------
info "8/9  Planted mid-body <script> → ERROR at validate; blocked at import; head ld+json exempt…"
printf '%s\n' '<!DOCTYPE html><html><head><title>ScriptTag</title></head>' '<body><section class="hero">x</section><script>alert(1)</script></body></html>' > "$WORK/scripttag.html"
printf '%s\n' '<!DOCTYPE html><html><head><title>LdJson</title>' '<script type="application/ld+json">{"@context":"https://schema.org"}</script>' '</head><body><section class="hero">x</section></body></html>' > "$WORK/ldjsonok.html"
OUT="$(validate "$CWORK" || true)"
printf '%s\n' "$OUT" | grep -qE '\[s5\.html\.script-tag\]' || { printf '%s\n' "$OUT" >&2; fail "mid-body script tag did not raise s5.html.script-tag"; }
printf '%s\n' "$OUT" | grep -qE 'scripttag\.html'            || { printf '%s\n' "$OUT" >&2; fail "script-tag ERROR missing filename"; }
# Head-level ld+json must NOT appear in any finding line.
if printf '%s\n' "$OUT" | grep -qE 'ldjsonok\.html'; then printf '%s\n' "$OUT" >&2; fail "head-level application/ld+json wrongly flagged"; fi
find "$WORK" -type f -delete
# Import gate re-check in isolation (single-file dir, avoids the ld+json control interfering with the blocked-count assertion).
printf '%s\n' '<!DOCTYPE html><html><head><title>ScriptTag</title></head>' '<body><section class="hero">x</section><script>alert(1)</script></body></html>' > "$WORK/scripttag.html"
GATE_OUT="$($WP_ENV run cli wp s5 import "$CWORK" 2>&1 | grep -vE '^ℹ Starting|Ran `wp' || true)"
printf '%s\n' "$GATE_OUT" | grep -qiE '0 created, 0 updated, 0 unchanged; 1 blocked' || { printf '%s\n' "$GATE_OUT" >&2; fail "mid-body <script> page was not blocked at import"; }
pass "mid-body <script> → ERROR at validate; blocked at import; head ld+json exempt"
find "$WORK" -type f -delete

# --- 9. round-trip harness still green (validation must not touch render) ----
info "9/9  Round-trip harness still green (validation didn't touch render)…"
bash "$ROOT_DIR/tests/acceptance-step3.sh" >/tmp/s5-rt.log 2>&1 || { tail -30 /tmp/s5-rt.log >&2; fail "round-trip harness regressed"; }
pass "round-trip fidelity still green"

echo
pass "Step 4 acceptance: ALL CHECKS PASSED (Authoring Contract validation is live)"
echo
