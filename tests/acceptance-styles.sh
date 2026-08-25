#!/usr/bin/env bash
#
# Phase 3 acceptance — three-part Styles tab + editable Element styles (v1.x).
#
# S5-ATOMIC-DESIGN-SPEC-v0.1 §4 + PRD v1.6 §8.5 + CC-PROMPT Phase 3. The Styles
# settings screen must present the design system as THREE editable layers —
# Tokens -> Scales -> Element styles — and the Element-styles part must let a
# Developer edit a primitive's REAL CSS rule, persist it, and recompile so the
# change reaches the served bundle. Before this suite that surface did not exist;
# this proves it is real and testable (the gap that shipped an "identical to v14"
# build).
#
# What it proves:
#   1. The Styles screen renders three parts (Tokens / Scales / Element styles).
#   2. The Element-styles part lists the registered primitives with editable CSS.
#   3. Saving an element edit WRITES the real components.css rule (surgically —
#      neighbour rules untouched) AND lands in the compiled bundle (site/dist/s5.css).
#   4. Malformed / injection / unknown saves are REFUSED (WP_Error), file untouched.
#
# Runs against wp-env via `wp eval` (the render + save paths are PHP; a full browser
# click is covered by the manual walkthrough — this guards the engine deterministically).
#
# Usage: npm run test:styles   (or: bash tests/acceptance-styles.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp() { $WP_ENV run cli wp "$@" 2>/dev/null | tr -d '\r'; }
# wp eval as the admin user (element editing is Developer-tier / settings.css gated).
wp_admin_eval() {
	local uid
	uid="$( wp user list --role=administrator --field=ID | grep -oE '^[0-9]+' | head -1 )"
	[ -n "$uid" ] || fail "no administrator user to run the Developer-gated render/save as"
	$WP_ENV run cli wp --user="$uid" eval "$1" 2>/dev/null | tr -d '\r'
}

# Grep a theme file's contents (read via wp eval) for a literal needle, retrying up
# to ~10s. StyleBundle::build() writes in one wp-env container exec and the read is a
# SEPARATE exec; the write is not always visible to the next exec instantly (the same
# cross-exec settle lag acceptance-step8.sh absorbs with curl_until). The bytes are
# deterministic once settled — this tolerates the timing, it does not mask an absence:
# after the retries it still fails loudly.
grep_theme_file_until() {
	local rel="$1" needle="$2" i out
	for i in $(seq 1 20); do
		out="$( wp eval 'echo file_get_contents(get_stylesheet_directory()."/'"$rel"'");' )"
		if printf '%s' "$out" | grep -qF "$needle"; then
			return 0
		fi
		sleep 0.5
	done
	return 1
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

echo
info "S5 Phase 3 — three-part Styles tab + editable Element styles"
echo

info "0/4  Env up; theme active…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
# The class must autoload (regression guard: a new src/ class that doesn't load = fatal).
LOADS="$( wp eval 'echo class_exists("S5\\ElementStyles") ? "yes" : "no";' )"
[ "$LOADS" = "yes" ] || fail "S5\\ElementStyles does not autoload (fatal on the Styles screen)"
pass "environment ready; ElementStyles autoloads"

# ---------------------------------------------------------------------------
# 1. The Styles screen renders three parts, with the Element-styles editors.
# ---------------------------------------------------------------------------
info "1/4  Styles screen renders Tokens · Scales · Element styles (editable)…"
RENDER="$( wp_admin_eval '
$m = new ReflectionMethod("S5\\Settings","render_styleguide");
$m->setAccessible(true);
ob_start(); $m->invoke(null); $h = ob_get_clean();
$parts = (strpos($h,"data-sg-part=\"tokens\"")!==false)
      && (strpos($h,"data-sg-part=\"scales\"")!==false)
      && (strpos($h,"data-sg-part=\"elements\"")!==false);
echo "parts=".($parts?"1":"0");
echo " textareas=".substr_count($h,"sg-el-code");
echo " forms=".substr_count($h,"s5_save_element");
' )"
echo "$RENDER" | grep -q "parts=1"     || fail "Styles screen is missing one of the three parts (Tokens/Scales/Elements): $RENDER"
echo "$RENDER" | grep -qE "textareas=[1-9]" || fail "Element-styles part rendered no editable CSS textareas: $RENDER"
echo "$RENDER" | grep -qE "forms=[1-9]"     || fail "Element-styles part rendered no save forms: $RENDER"
pass "three parts present; element CSS is editable ($RENDER)"

# ---------------------------------------------------------------------------
# 2. Saving an element edit writes the real rule AND reaches the compiled bundle.
# ---------------------------------------------------------------------------
info "2/4  Editing the Button element persists to file + compiled bundle…"
MARK="s5tpTEST-$$"   # unique property value to search for end-to-end.
SAVE="$( wp_admin_eval '
$r = \S5\ElementStyles::update_element("button", "display:inline-flex;\npadding:.7em 1.2em;\nborder-radius:var(--s5-radius-md);\noutline-color:'"$MARK"';");
if (is_wp_error($r)) { echo "ERR ".$r->get_error_message(); return; }
\S5\StyleBundle::build();
echo "OK";
' )"
echo "$SAVE" | grep -q "OK" || fail "element save/recompile failed: $SAVE"
# Source file carries the edit…
grep_theme_file_until "site/styles/components.css" "$MARK" \
	|| fail "the edit did not reach the components.css source"
# …and so does the compiled bundle the site serves (retry: cross-exec settle lag).
grep_theme_file_until "site/dist/s5.css" "$MARK" \
	|| fail "the edit did not reach the compiled bundle (site/dist/s5.css)"
# Neighbour rule .btn--primary must be intact (surgical write, not a clobber).
grep_theme_file_until "site/styles/components.css" ".btn--primary" \
	|| fail "neighbour rule .btn--primary was lost (write was not surgical)"
pass "element edit reached both the source rule and the compiled bundle; neighbours intact"

# ---------------------------------------------------------------------------
# 3. Restore the Button rule so the suite is idempotent + leaves a clean tree.
# ---------------------------------------------------------------------------
info "3/4  Restoring the Button rule (idempotent)…"
RESTORE="$( wp_admin_eval '
$r = \S5\ElementStyles::update_element("button", "display:inline-block;\npadding:0.6rem 1rem;\nborder-radius:0.375rem;\ntext-decoration:none;");
if (is_wp_error($r)) { echo "ERR ".$r->get_error_message(); return; }
\S5\StyleBundle::build();
echo "OK";
' )"
echo "$RESTORE" | grep -q "OK" || fail "could not restore the Button rule: $RESTORE"
# Wait for the marker to disappear from the bundle (same cross-exec settle window).
gone=0
for _ in $(seq 1 20); do
	if ! wp eval 'echo file_get_contents(get_stylesheet_directory()."/site/dist/s5.css");' | grep -qF "$MARK"; then
		gone=1; break
	fi
	sleep 0.5
done
[ "$gone" = "1" ] || fail "restore did not remove the test marker from the bundle"
pass "Button rule restored; test marker gone from the bundle"

# ---------------------------------------------------------------------------
# 4. Bad saves are refused (WP_Error) and never touch the file.
# ---------------------------------------------------------------------------
info "4/4  Malformed / injection / unknown saves are refused, file untouched…"
GUARD="$( wp_admin_eval '
$before = md5(file_get_contents(get_stylesheet_directory()."/site/styles/components.css"));
$cases = [
  ["button","color:red; } body{display:none","brace"],
  ["nope","color:red","unknown"],
  ["button","not css at all","malformed"],
  ["button","","empty"],
  ["button","background:<script>x</script>","markup"],
];
$allrefused = true;
foreach ($cases as [$k,$d,$n]) { if (!is_wp_error(\S5\ElementStyles::update_element($k,$d))) { echo "LEAK:$n "; $allrefused=false; } }
$after = md5(file_get_contents(get_stylesheet_directory()."/site/styles/components.css"));
echo ($allrefused?"ALLREFUSED":"SOMEACCEPTED")." ".($before===$after?"UNTOUCHED":"MODIFIED");
' )"
echo "$GUARD" | grep -q "ALLREFUSED" || fail "a malformed/injection save was ACCEPTED: $GUARD"
echo "$GUARD" | grep -q "UNTOUCHED"  || fail "the file changed despite all saves being refused: $GUARD"
pass "all bad saves refused; components.css byte-identical after the attempts"

echo
pass "Phase 3 acceptance: ALL CHECKS PASSED (three-part Styles · editable element CSS · file+bundle · guards)"
echo
