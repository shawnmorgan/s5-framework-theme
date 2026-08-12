#!/usr/bin/env bash
#
# Step 11 acceptance — components as registered human capabilities.
#
# Exit condition proven: registered components define what humans may compose
# WITHOUT becoming the page representation.
#   - An agent registers a component (register validates it + reports its version).
#   - A component can be inserted and EXPANDS into real canonical HTML (copy-on-
#     insert), carrying its version stamp; it renders on the served page.
#   - An UNREGISTERED component cannot be inserted through the human-facing surface.
#   - Insert lands through the SAME door as import (Compiler::persist_page →
#     revision); the result is validated against the SAME Authoring Contract gate,
#     so a component that would break the contract is rejected, canonical unchanged.
#   - Copy-on-insert leaves the page as plain canonical HTML — after restoring the
#     source corpus, round-trip fidelity is byte-clean again.
#
# Usage: npm run test:step11   (or: bash tests/acceptance-step11.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp() { $WP_ENV run cli wp "$@" 2>/dev/null | tr -d '\r'; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
PAGES_C="wp-content/s5-pages"

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

echo
info "S5 Step 11 — components as registered human capabilities"
echo

# ---------------------------------------------------------------------------
# 0. Env up, plugin active, fresh import.
# ---------------------------------------------------------------------------
info "0/6  Env up; import the corpus…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "corpus import failed"
pass "environment ready; corpus imported"

# ---------------------------------------------------------------------------
# 1. Agent registers a component (validate + version stamp).
# ---------------------------------------------------------------------------
info "1/6  Register the 'card' component (validates + reports version)…"
REG="$(wp s5 component register card)"
printf '%s' "$REG" | grep -qi 'version 1.0.0' || fail "register did not report the component version"
wp s5 component list | grep -qi 'card@1.0.0' || fail "registered component not listed with its version"
pass "component registered and listed with its version stamp"

# ---------------------------------------------------------------------------
# 2. Insert expands into REAL canonical HTML (copy-on-insert) + renders.
# ---------------------------------------------------------------------------
info "2/6  Insert the card → expands into canonical, renders on the page…"
ABOUT="$(wp post list --post_type=page --name=about --field=ID | head -n1)"
[ -n "$ABOUT" ] || fail "about page missing"
wp post meta get "$ABOUT" _s5_canonical_html | grep -qF 'data-s5-component="card"' && fail "card already in canonical before insert"
wp s5 component insert card --page=about >/dev/null || fail "insert failed"
CANON="$(wp post meta get "$ABOUT" _s5_canonical_html)"
printf '%s' "$CANON" | grep -qF 'data-s5-component="card"' || fail "card did not expand into canonical HTML"
printf '%s' "$CANON" | grep -qF 'data-s5-component-version="1.0.0"' || fail "inserted card missing its version stamp"
ABOUT_URL="$(wp post url "$ABOUT")"
curl -sL "$ABOUT_URL" | grep -qF 'class="card"' || fail "inserted card does not render on the served page"
pass "copy-on-insert expanded into real canonical HTML (with version stamp) and renders"

# ---------------------------------------------------------------------------
# 3. Targeted --at insert places the component after the anchor node.
# ---------------------------------------------------------------------------
info "3/6  Targeted insert (--at) places the component after the anchor…"
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "re-import failed"
wp s5 component insert card --page=about --at=about-hero-lede >/dev/null || fail "targeted insert failed"
CANON="$(wp post meta get "$ABOUT" _s5_canonical_html)"
LEDE_POS="$(printf '%s' "$CANON" | grep -bo 'about-hero-lede' | head -1 | cut -d: -f1)"
CARD_POS="$(printf '%s' "$CANON" | grep -bo 'data-s5-component=' | head -1 | cut -d: -f1)"
[ -n "$LEDE_POS" ] && [ -n "$CARD_POS" ] && [ "$CARD_POS" -gt "$LEDE_POS" ] || fail "card was not inserted after the --at anchor"
pass "targeted insert placed the component after the anchor node"

# ---------------------------------------------------------------------------
# 4. Unregistered component + bad anchor are refused (human-facing surface).
# ---------------------------------------------------------------------------
info "4/6  Unregistered component and bad anchor are refused…"
if wp s5 component insert nope --page=about >/dev/null 2>&1; then
	fail "an UNREGISTERED component was inserted (must be refused)"
fi
if wp s5 component insert card --page=about --at=does-not-exist >/dev/null 2>&1; then
	fail "insert with a nonexistent --at anchor was accepted (must be refused)"
fi
pass "unregistered component and bad anchor both refused"

# ---------------------------------------------------------------------------
# 5. Insert result is validated against the Authoring Contract (one gate).
# ---------------------------------------------------------------------------
info "5/6  Inserting a bad component is rejected; canonical unchanged…"
# Author a throwaway bad component whose markup uses an out-of-vocabulary class.
BAD_DIR="$ROOT_DIR/s5-theme/site/components/__bad_test__"
mkdir -p "$BAD_DIR"
printf '<div class="zzz-not-a-real-vocab-class">bad</div>\n' > "$BAD_DIR/component.html"
printf '{"name":"__bad_test__","version":"0.0.1"}\n' > "$BAD_DIR/manifest.json"
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "re-import failed"
BEFORE="$(wp post meta get "$ABOUT" _s5_canonical_html | md5sum)"
if wp s5 component insert __bad_test__ --page=about >/dev/null 2>&1; then
	rm -rf "$BAD_DIR"
	fail "a component that breaks the Authoring Contract was inserted (must be rejected)"
fi
AFTER="$(wp post meta get "$ABOUT" _s5_canonical_html | md5sum)"
rm -rf "$BAD_DIR"
[ "$BEFORE" = "$AFTER" ] || fail "canonical changed after a rejected insert"
pass "contract-breaking insert rejected; canonical left unchanged"

# ---------------------------------------------------------------------------
# 6. Restore the source corpus → round-trip fidelity is byte-clean again.
# ---------------------------------------------------------------------------
info "6/6  Restore corpus; round-trip fidelity byte-clean (no component residue)…"
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "final re-import failed"
bash "$ROOT_DIR/tests/acceptance-step3.sh" >/tmp/s5-rt11.log 2>&1 || { tail -25 /tmp/s5-rt11.log >&2; fail "round-trip regressed after component work"; }
pass "corpus restored; round-trip fidelity byte-clean"

echo
pass "Step 11 acceptance: ALL CHECKS PASSED (registered capabilities; copy-on-insert; no page-state abstraction)"
echo
