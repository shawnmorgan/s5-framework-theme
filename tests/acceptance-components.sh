#!/usr/bin/env bash
#
# Component structure acceptance (Item 2) — folder-scan discovery + compiled CSS
# bundle + component-extended vocabulary.
#
# Exit condition proven: components live in components/<name>/ folders discovered by
# a directory scan; each ships component.html + manifest.json + style.css; the scoped
# CSS concatenates into ONE compiled bundle (site/dist/s5.css) that is enqueued on
# framework pages; a component's own class selectors auto-join the closed Authoring-
# Contract vocabulary (D18), so a component using its own scoped classes inserts and
# validates through the ONE door and renders on the served page. Restoring the corpus
# leaves round-trip fidelity byte-clean.
#
# Usage: npm run test:components   (or: bash tests/acceptance-components.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp() { $WP_ENV run cli wp "$@" 2>/dev/null | tr -d '\r'; }

# Fetch $1 (following redirects) and grep -qF for $2, retrying ~10s to absorb the
# write->read settle lag between a CLI persist and the web container serving it.
curl_until() {
	local url="$1" needle="$2" i
	for i in $(seq 1 20); do
		curl -sL "$url" | grep -qF "$needle" && return 0
		sleep 0.5
	done
	return 1
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
PAGES_C="wp-content/s5-pages"
COMPONENTS_DIR="$ROOT_DIR/s5-theme/site/components"

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

echo
info "S5 Item 2 — component folders + scan discovery + compiled CSS bundle"
echo

# ---------------------------------------------------------------------------
# 0. Env up; fresh import (import rebuilds the CSS bundle too).
# ---------------------------------------------------------------------------
info "0/6  Env up; import the corpus (import rebuilds the CSS bundle)…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp theme activate s5-theme >/dev/null 2>&1 || fail "s5-theme failed to activate"
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "corpus import failed"
pass "environment ready; corpus imported"

# ---------------------------------------------------------------------------
# 1. Folder scan discovers the starter components; each is a complete folder.
# ---------------------------------------------------------------------------
info "1/6  Directory scan discovers starter components; each folder is complete…"
LIST="$(wp s5 component list)"
for c in hero cta testimonial faq-item pricing-card embed; do
	printf '%s' "$LIST" | grep -qE "^${c}@" || fail "component '$c' not discovered by scan"
	for f in component.html manifest.json style.css; do
		[ -f "$COMPONENTS_DIR/$c/$f" ] || fail "component '$c' missing $f"
	done
done
pass "6 starter components discovered by scan; each has component.html + manifest.json + style.css"

# ---------------------------------------------------------------------------
# 2. Folder name = component name = block class (the root carries class="<name>").
# ---------------------------------------------------------------------------
info "2/6  Folder name = component name = block class on the component root…"
for c in hero cta testimonial faq-item pricing-card embed; do
	grep -qE "class=\"${c}\"" "$COMPONENTS_DIR/$c/component.html" \
		|| fail "component '$c' root does not carry the block class \"$c\""
done
pass "each component root carries its block class (name = folder = class)"

# ---------------------------------------------------------------------------
# 3. Scoped CSS concatenates at compile into ONE bundle (site/dist/s5.css).
# ---------------------------------------------------------------------------
info "3/6  Scoped CSS concatenates into the compiled bundle…"
BUNDLE_CHECK="$(wp eval '$f=get_stylesheet_directory()."/site/dist/s5.css"; if(!is_file($f)){echo "MISSING";exit;} $c=file_get_contents($f); foreach(["hero","cta","testimonial","faq-item","pricing-card","embed"] as $k){ if(strpos($c,$k)===false){echo "NO:$k";exit;} } echo "OK:".strlen($c);')"
case "$BUNDLE_CHECK" in
	OK:*) pass "compiled bundle present and contains every component's scoped CSS ($BUNDLE_CHECK bytes)" ;;
	MISSING) fail "compiled CSS bundle site/dist/s5.css was not built" ;;
	*) fail "compiled bundle missing component CSS ($BUNDLE_CHECK)" ;;
esac

# ---------------------------------------------------------------------------
# 4. The bundle is enqueued on framework pages (one S5 stylesheet <link>).
# ---------------------------------------------------------------------------
info "4/6  Compiled bundle is enqueued on a served framework page…"
ABOUT="$(wp post list --post_type=page --name=about --field=ID | head -n1)"
[ -n "$ABOUT" ] || fail "about page missing"
ABOUT_URL="$(wp post url "$ABOUT")"
curl -sL "$ABOUT_URL" | grep -qE "id='s5-bundle-css'|site/dist/s5\.css" \
	|| fail "served page does not enqueue the compiled S5 bundle"
pass "served framework page carries the one compiled S5 stylesheet"

# ---------------------------------------------------------------------------
# 5. A component's own scoped classes auto-join the vocabulary (D18): a scoped
#    component inserts through the one door, validates, and renders served.
# ---------------------------------------------------------------------------
info "5/6  Scoped component inserts (own classes validate); re-key is server-side + recursive…"
ANCHOR="$(wp post meta get "$ABOUT" _s5_canonical_html | grep -oE 'data-node="[^"]+"' | head -1 | sed 's/data-node="//;s/"$//')"
[ -n "$ANCHOR" ] || fail "no data-node anchor on the about page"
BEFORE="$(wp post meta get "$ABOUT" _s5_canonical_html | md5sum)"
# Insert the SAME component TWICE. The server must re-key every data-node in each
# inserted subtree to fresh page-unique ids (recursive, server-assigned) — two
# inserts must NOT collide (Item 4).
wp s5 component insert hero --page=about --at="$ANCHOR" >/dev/null 2>&1 \
	|| fail "insert of scoped 'hero' component failed (its own classes should validate)"
wp s5 component insert hero --page=about --at="$ANCHOR" >/dev/null 2>&1 \
	|| fail "second hero insert failed"
wp post meta get "$ABOUT" _s5_canonical_html | grep -qF 'data-s5-component="hero"' \
	|| fail "hero not stamped into canonical after insert"
# No duplicate data-node ids anywhere on the page (the compiler owns id uniqueness).
DUPES="$(wp post meta get "$ABOUT" _s5_canonical_html | grep -oE 'data-node="[^"]+"' | sort | uniq -d | grep -c . || true)"
[ "$DUPES" = "0" ] || fail "duplicate data-node ids after two inserts ($DUPES) — server re-key failed"
# Both hero instances carry distinct re-keyed ids for the same slot.
HERO_TITLES="$(wp post meta get "$ABOUT" _s5_canonical_html | grep -oE 'data-node="hero-[^"]*title[^"]*"' | sort -u | grep -c . || true)"
[ "$HERO_TITLES" -ge 2 ] || fail "two hero inserts did not produce distinct re-keyed title ids"
curl_until "$ABOUT_URL" 'class="hero"' \
	|| fail "inserted hero not rendered on the served page"
pass "component inserted twice through the one door; server-assigned recursive re-key gives unique ids; served"

# ---------------------------------------------------------------------------
# 6. Restore the corpus → round-trip fidelity byte-clean (no component residue).
# ---------------------------------------------------------------------------
info "6/6  Restore corpus; round-trip fidelity byte-clean…"
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "corpus re-import failed"
AFTER="$(wp post meta get "$ABOUT" _s5_canonical_html | md5sum)"
[ "$BEFORE" = "$AFTER" ] || fail "about canonical not restored after re-import (component residue)"
bash "$ROOT_DIR/tests/acceptance-step3.sh" >/tmp/s5-rt-components.log 2>&1 \
	|| { tail -20 /tmp/s5-rt-components.log >&2; fail "round-trip regressed after component insert/restore"; }
pass "corpus restored; round-trip fidelity byte-clean"

echo
pass "Component structure acceptance: ALL CHECKS PASSED (folder scan; CSS bundle; vocabulary extension)"
echo
