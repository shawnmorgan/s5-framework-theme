#!/usr/bin/env bash
#
# Step 6 acceptance — media resolution.
#
# Exit condition proven: media no longer forces environment-specific data into
# canonical HTML. Canonical references assets by portable relative path
# (`assets/hero.png`); the compiler resolves each <img>/<source> into this
# environment's attachment-backed markup (upload URL + srcset + dimensions +
# lazy/LCP) in the DERIVED render only. Same bytes re-import to the same
# attachment (hash dedupe); changed bytes re-sideload; an unresolvable path is a
# loud compile error; recompile stays deterministic.
#
# Usage: npm run test:step6   (or: bash tests/acceptance-step6.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp()      { $WP_ENV run cli wp "$@" 2>/dev/null | tr -d '\r'; }
wpimport(){ $WP_ENV run cli wp s5 import "$@" 2>&1 | tr -d '\r'; }
md5of()   { md5 -q 2>/dev/null || md5sum | awk '{print $1}'; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
PAGES_C="wp-content/s5-pages"
S5T_SRC="$ROOT_DIR/pages/series-5-technology.html"
DIAGRAM_SRC="$ROOT_DIR/assets/diagram.png"

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

# self-cleaning: restore the diagram asset (mutated in test 3) on any exit.
DIAGRAM_BACKUP="$(mktemp)"; cp "$DIAGRAM_SRC" "$DIAGRAM_BACKUP"
cleanup() {
	cp "$DIAGRAM_BACKUP" "$DIAGRAM_SRC" 2>/dev/null || true
	rm -f "$DIAGRAM_BACKUP" 2>/dev/null || true
	# Remove any planted bad-path fixture from test 5.
	rm -f "$ROOT_DIR/pages/media-broken.html" 2>/dev/null || true
}
trap cleanup EXIT

echo
info "S5 Step 6 — media resolution"
echo

# ---------------------------------------------------------------------------
# 0. Clean import.
# ---------------------------------------------------------------------------
info "0/6  Starting wp-env, activating plugin, fresh import…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp plugin activate s5-plugin >/dev/null || fail "s5-plugin failed to activate"
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
for s in index about fix-my-store series-5-technology media-broken; do
	pid="$(wp post list --post_type=page --name="$s" --field=ID | head -n1)"
	[ -n "$pid" ] && wp post delete "$pid" --force >/dev/null 2>&1 || true
done
# Start from a clean media library so attachment-count assertions are meaningful.
for aid in $(wp post list --post_type=attachment --field=ID 2>/dev/null); do
	wp post delete "$aid" --force >/dev/null 2>&1 || true
done
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "import failed"
S5T="$(wp post list --post_type=page --name=series-5-technology --field=ID | head -n1)"
[ -n "$S5T" ] || fail "series-5-technology page missing after import"
S5T_URL="$(wp post url "$S5T")"
pass "environment ready; corpus imported (S5T #$S5T)"

# ---------------------------------------------------------------------------
# 1. Real images import; served page has attachment-backed <img> with srcset +
#    dimensions; round-trip harness green.
# ---------------------------------------------------------------------------
info "1/6  Served page shows attachment-backed images with srcset + dimensions…"
SERVED="$(curl -s "$S5T_URL")"
HERO_IMG="$(printf '%s' "$SERVED" | grep -oE '<img[^>]*hero[^>]*>' | head -n1)"
[ -n "$HERO_IMG" ] || fail "no hero <img> in served page"
printf '%s' "$HERO_IMG" | grep -qE 'src="https?://[^"]+/wp-content/uploads/[^"]+"' || fail "hero <img> src is not an attachment upload URL"
printf '%s' "$HERO_IMG" | grep -qF 'srcset='  || fail "hero <img> has no srcset"
printf '%s' "$HERO_IMG" | grep -qE 'width="[0-9]+"'  || fail "hero <img> has no width"
printf '%s' "$HERO_IMG" | grep -qE 'height="[0-9]+"' || fail "hero <img> has no height"
# LCP heuristic: hero is first content image + author-set loading="eager" — NOT lazy.
printf '%s' "$HERO_IMG" | grep -qF 'loading="eager"' || fail "hero <img> author loading=\"eager\" not preserved"
printf '%s' "$HERO_IMG" | grep -qF 'loading="lazy"'  && fail "hero (LCP) <img> was wrongly made lazy"
# A non-first image with no author attrs must be lazy + get injected dimensions.
DIAG_IMG="$(printf '%s' "$SERVED" | grep -oE '<img[^>]*diagram[^>]*>' | head -n1)"
[ -n "$DIAG_IMG" ] || fail "no diagram <img> in served page"
printf '%s' "$DIAG_IMG" | grep -qF 'loading="lazy"' || fail "non-LCP diagram <img> not lazy-loaded"
printf '%s' "$DIAG_IMG" | grep -qE 'width="[0-9]+"' || fail "diagram <img> missing injected width"
# <source> in <picture> resolved to an upload URL, structure preserved.
printf '%s' "$SERVED" | grep -oE '<source[^>]*>' | grep -qE 'srcset="https?://[^"]+/wp-content/uploads/[^"]+"' || fail "<source> srcset not resolved to upload URL"
# NO framework-internal attribute may ship in the served render. Served media
# markup carries only runtime-purposed attributes.
printf '%s' "$SERVED" | grep -qiE 'data-s5-orig|data-s5-' && fail "served render leaks a framework-internal data-s5-* attribute"
RENDER_META="$(wp post meta get "$S5T" _s5_render_html)"
printf '%s' "$RENDER_META" | grep -qiE 'data-s5-orig|data-s5-' && fail "_s5_render_html stores a framework-internal data-s5-* attribute"
# The reversal manifest MUST live out of band in page meta (not in the render).
MAP_JSON="$(wp post meta get "$S5T" _s5_media_map --format=json 2>/dev/null || true)"
printf '%s' "$MAP_JSON" | grep -qE '\[' || fail "_s5_media_map manifest is missing/empty (needed to reverse media for the harness)"
# Canonical MUST remain portable: no ENV-SPECIFIC media data baked in. A logical
# srcset with a relative path (e.g. srcset="assets/hero-wide.avif") is fine — that
# is the portable form; what must never appear is a resolved upload URL, an
# attachment id, or the data-s5-orig back-reference (render-only).
CANON="$(wp post meta get "$S5T" _s5_canonical_html)"
printf '%s' "$CANON" | grep -qF 'wp-content/uploads' && fail "canonical contains an environment upload URL (not portable)"
printf '%s' "$CANON" | grep -qF 'data-s5-orig' && fail "canonical contains the render-only media back-reference (not portable)"
printf '%s' "$CANON" | grep -qE 'srcset="[^"]*wp-content/uploads' && fail "canonical contains a resolved srcset (not portable)"
printf '%s' "$CANON" | grep -qF 'assets/hero.png' || fail "canonical lost its logical asset path"
pass "images attachment-backed with srcset + dimensions; canonical stays portable"

info "      round-trip harness still green (media transform normalized)…"
bash "$ROOT_DIR/tests/acceptance-step3.sh" >/tmp/s5-rt6.log 2>&1 || { tail -25 /tmp/s5-rt6.log >&2; fail "round-trip regressed under media resolution"; }
# The harness re-imported the corpus; refresh ids.
S5T="$(wp post list --post_type=page --name=series-5-technology --field=ID | head -n1)"
S5T_URL="$(wp post url "$S5T")"
pass "round-trip fidelity green with media resolution active"

# ---------------------------------------------------------------------------
# 2. Re-import unchanged -> no duplicate attachments (hash match).
# ---------------------------------------------------------------------------
info "2/6  Re-import unchanged source → no duplicate attachments (hash dedupe)…"
BEFORE_ATT="$(wp post list --post_type=attachment --field=ID | grep -c . || true)"
$WP_ENV run cli wp s5 recompile --all >/dev/null 2>&1 || fail "recompile failed"
AFTER_ATT="$(wp post list --post_type=attachment --field=ID | grep -c . || true)"
[ "$BEFORE_ATT" = "$AFTER_ATT" ] || fail "recompile created duplicate attachments ($BEFORE_ATT → $AFTER_ATT)"
# And a full re-import must not duplicate either.
$WP_ENV run cli wp s5 import "$PAGES_C" >/dev/null 2>&1 || fail "re-import failed"
REIMPORT_ATT="$(wp post list --post_type=attachment --field=ID | grep -c . || true)"
[ "$BEFORE_ATT" = "$REIMPORT_ATT" ] || fail "re-import created duplicate attachments ($BEFORE_ATT → $REIMPORT_ATT)"
pass "unchanged re-import/recompile reused attachments by hash (no duplicates)"

# ---------------------------------------------------------------------------
# 3. Change one source image file (same filename) -> new attachment resolves,
#    page serves the new image.
# ---------------------------------------------------------------------------
info "3/6  Change a source image (same name) → new attachment, page serves it…"
OLD_DIAG_URL="$(curl -s "$S5T_URL" | grep -oE 'src="[^"]*diagram[^"]*"' | head -n1)"
[ -n "$OLD_DIAG_URL" ] || fail "could not read current diagram URL"
# Replace diagram.png bytes with a visibly different image (same filename).
ffmpeg -v error -y -f lavfi -i "color=c=0xef4444:s=640x480" -frames:v 1 -update 1 "$DIAGRAM_SRC" || fail "could not regenerate diagram.png"
NEW_HASH="$(md5of < "$DIAGRAM_SRC")"
OLD_HASH="$(md5of < "$DIAGRAM_BACKUP")"
[ "$NEW_HASH" != "$OLD_HASH" ] || fail "diagram.png did not actually change"
$WP_ENV run cli wp s5 recompile series-5-technology >/dev/null 2>&1 || fail "recompile after image change failed"
NEW_DIAG_URL="$(curl -s "$S5T_URL" | grep -oE 'src="[^"]*diagram[^"]*"' | head -n1)"
[ -n "$NEW_DIAG_URL" ] || fail "diagram <img> missing after change"
[ "$NEW_DIAG_URL" != "$OLD_DIAG_URL" ] || fail "changed image did NOT re-sideload (served URL unchanged: $OLD_DIAG_URL)"
pass "changed source image re-sideloaded to a new attachment; page serves it"

# ---------------------------------------------------------------------------
# 4. Portability: canonical bytes are environment-independent; a fresh media
#    library re-resolves to THIS env's attachment URLs from the same canonical.
#    (A literal second env is the same source file → identical canonical bytes
#    by construction; the load-bearing property is that canonical carries no
#    env-specific media data and render is regenerated per-env.)
# ---------------------------------------------------------------------------
info "4/6  Portability: canonical is env-independent; render re-derives per env…"
# canonical on disk keeps its logical asset path (portable source is the .html body).
DISK_HAS="$(grep -c 'assets/hero.png' "$S5T_SRC" || true)"
[ "$DISK_HAS" -ge 1 ] || fail "source file lost its logical asset path"
CANON="$(wp post meta get "$S5T" _s5_canonical_html)"
printf '%s' "$CANON" | grep -qF 'wp-content/uploads' && fail "canonical carries env upload URLs (not portable)"
# Simulate a fresh environment: wipe the media library, then recompile. The same
# canonical must re-sideload and serve working upload URLs again.
for aid in $(wp post list --post_type=attachment --field=ID 2>/dev/null); do
	wp post delete "$aid" --force >/dev/null 2>&1 || true
done
$WP_ENV run cli wp s5 recompile --all >/dev/null 2>&1 || fail "recompile in fresh media library failed"
FRESH_SERVED="$(curl -s "$S5T_URL")"
printf '%s' "$FRESH_SERVED" | grep -oE '<img[^>]*hero[^>]*>' | grep -qE 'src="https?://[^"]+/wp-content/uploads/[^"]+"' \
	|| fail "fresh-env recompile did not produce working attachment URLs"
# Canonical bytes unchanged by any of this.
CANON2="$(wp post meta get "$S5T" _s5_canonical_html)"
[ "$CANON" = "$CANON2" ] || fail "canonical bytes changed across re-resolution (not portable/stable)"
pass "canonical is portable; render re-derives to this env's URLs from the same source"

# ---------------------------------------------------------------------------
# 5. Planted unresolvable path -> compile ERROR naming file + path.
# ---------------------------------------------------------------------------
info "5/6  Unresolvable asset path → loud compile error naming file + path…"
cat > "$ROOT_DIR/pages/media-broken.html" <<'HTML'
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Broken media</title></head>
<body>
<main data-s5="broken">
  <img src="assets/does-not-exist.png" alt="missing">
</main>
</body>
</html>
HTML
BROKEN_OUT="$(wpimport "$PAGES_C" || true)"
printf '%s' "$BROKEN_OUT" | grep -qi 'does-not-exist.png' || { printf '%s\n' "$BROKEN_OUT" >&2; fail "compile error did not name the offending asset path"; }
printf '%s' "$BROKEN_OUT" | grep -qiE 'media resolution failed|not found' || { printf '%s\n' "$BROKEN_OUT" >&2; fail "compile error is not a clear media-resolution failure"; }
# The broken page must not persist as a framework page: the create path deletes
# the orphan, so no media-broken page should exist at all after the failed import.
BROKEN_ID="$(wp post list --post_type=page --name=media-broken --field=ID | head -n1 || true)"
if [ -n "$BROKEN_ID" ]; then
	BROKEN_RENDER="$(wp post meta get "$BROKEN_ID" _s5_render_html || true)"
	[ -z "$BROKEN_RENDER" ] || fail "broken page got a render despite unresolvable media"
fi
rm -f "$ROOT_DIR/pages/media-broken.html"
pass "unresolvable path failed loudly, naming file + path; no bad render persisted"

# ---------------------------------------------------------------------------
# 6. Recompile determinism holds with media active (byte-identical twice).
# ---------------------------------------------------------------------------
info "6/6  Recompile determinism holds with media transform active…"
$WP_ENV run cli wp s5 recompile series-5-technology >/dev/null 2>&1 || fail "recompile #1 failed"
R1="$(wp post meta get "$S5T" _s5_render_html | md5of)"
M1="$(wp post get "$S5T" --field=post_content | md5of)"
$WP_ENV run cli wp s5 recompile series-5-technology >/dev/null 2>&1 || fail "recompile #2 failed"
R2="$(wp post meta get "$S5T" _s5_render_html | md5of)"
M2="$(wp post get "$S5T" --field=post_content | md5of)"
[ "$R1" = "$R2" ] || fail "render not byte-identical across recompiles ($R1 vs $R2)"
[ "$M1" = "$M2" ] || fail "mirror not byte-identical across recompiles ($M1 vs $M2)"
pass "recompile is deterministic (render + mirror byte-identical) with media active"

echo
pass "Step 6 acceptance: ALL CHECKS PASSED (media resolution; canonical stays portable)"
echo
