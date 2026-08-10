#!/usr/bin/env bash
#
# Step 3 acceptance — round-trip fidelity harness for the S5 framework.
#
# Exit condition proven: render fidelity is executable infrastructure, not a
# written promise. For every page in the corpus (pages/), this harness:
#
#   import  ->  HTTP request  ->  normalize SANCTIONED transforms only  ->  diff
#
# and fails (nonzero exit + readable delta report) on ANY unexplained difference.
#
# It also proves it can FAIL: it arms a mutation in the render path, asserts the
# harness goes red with a delta, disarms it, and asserts green. That negative
# test runs automatically here — it is not a manual demo.
#
# Standing command: `npm run test:roundtrip`. Usable against any pages/ corpus.
# This is Ship Gate check #1.
#
# Usage: npm run test:roundtrip   (or: bash tests/acceptance-step3.sh)

set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; NC=$'\033[0m'
pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
fail() { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

WP_ENV="npx wp-env"
wp() { $WP_ENV run cli wp "$@" 2>/dev/null; }

PAGES_DIR_CONTAINER="wp-content/s5-pages"   # pages/ as mapped inside the container
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
SRC_PAGES="$ROOT_DIR/pages"
EXTRACT_HEAD="php $ROOT_DIR/tests/lib/extract-head.php"

command -v npx >/dev/null 2>&1    || fail "npx not found. Run 'npm install' first."
command -v docker >/dev/null 2>&1 || fail "docker not found."
command -v php >/dev/null 2>&1     || fail "php (host) not found — needed to parse source head."
docker info >/dev/null 2>&1        || fail "Docker daemon not reachable."

# ---------------------------------------------------------------------------
# SANCTIONED TRANSFORMS — the ONLY normalizations this harness tolerates.
#
# This list is deliberately enumerated in code (not scattered through the
# assertions) because it GROWS in later steps and must stay auditable. Adding a
# transform must be ONE entry here plus its handling, never a rewrite.
#
#   1. HEAD ASSEMBLY. The source <head>'s extracted artifacts (title, meta
#      description, canonical, OG tags, JSON-LD) are re-emitted via wp_head(),
#      interleaved with WordPress-generated head output. Because WP adds its own
#      head markup, the harness does NOT byte-diff the <head>. Instead it diffs
#      the <body> byte-for-byte, and separately asserts the head CONTRACT:
#      title once & matching; description once & matching; canonical matching;
#      each source OG tag present; each source JSON-LD block present verbatim.
#
#   2. BODY WRAPPER. The authored body fragment is served verbatim, wrapped by
#      the canvas shell: `<body ...>` + fragment + wp_footer() + `</body>`.
#      The fragment itself receives ZERO transforms, so the sanctioned body
#      normalization is: locate the fragment as an EXACT byte substring of the
#      served <body>, and require everything around it to be wrapper-only
#      (leading/trailing whitespace + empty wp_footer output for these pages).
#
#   3. MEDIA RESOLUTION (Step 6). Canonical references assets by portable
#      relative path (`assets/hero.png`). The compiler resolves each <img>/
#      <source> into environment-specific attachment markup: rewritten src/
#      srcset to the upload URL, added srcset/sizes/width/height and lazy/LCP
#      loading. Because that markup is environment-specific it can NOT be
#      byte-diffed against source. The served markup carries ONLY runtime
#      attributes — NO framework-internal attribute ships. The reversal map lives
#      OUT OF BAND in page meta `_s5_media_map` (an ordered list of
#      [resolved_bytes, original_bytes] pairs the compiler recorded). The
#      normalizer below reads that manifest and swaps each resolved element back
#      to its logical source BEFORE the body diff, so the authored fragment
#      reappears verbatim and everything non-media is still diffed byte-for-byte.
#      Imgs are never skipped.
#
#   ---- future entries (NOT active yet; listed so the seam is visible) --------
#   4. DYNAMIC PASSES    (Step 8): declared runtime substitutions.
#
# The per-page checks below apply (1)+(2)+(3) and nothing else.
# ---------------------------------------------------------------------------

# SANCTIONED TRANSFORM 3 (reverse): map resolved media markup in a served body
# back to its logical source bytes using the compile-time manifest — NOT anything
# in the served markup. Args: $1 = the page's _s5_media_map as JSON (an array of
# [resolved, original] pairs, or empty). Reads a body on stdin, prints normalized.
normalize_media() {
	local map_json="${1:-[]}"
	MAP_JSON="$map_json" php -r '
		$b   = stream_get_contents(STDIN);
		$map = json_decode( (string) getenv("MAP_JSON"), true );
		if ( is_array( $map ) ) {
			foreach ( $map as $pair ) {
				if ( ! is_array( $pair ) || count( $pair ) < 2 ) { continue; }
				list( $resolved, $original ) = $pair;
				// Byte-exact swap of the resolved element back to its logical source.
				$pos = strpos( $b, $resolved );
				if ( $pos !== false ) {
					$b = substr( $b, 0, $pos ) . $original . substr( $b, $pos + strlen( $resolved ) );
				}
			}
		}
		echo $b;
	'
}

# Extract the inner HTML of the FIRST <body>..last </body> in file $1 (verbatim
# bytes), using the SAME boundary rule the importer uses. Prints to stdout.
extract_body_inner() {
	php -r '
		$h = file_get_contents($argv[1]);
		if (!preg_match("/<body\b[^>]*>/i", $h, $m, PREG_OFFSET_CAPTURE)) { fwrite(STDERR,"no <body>\n"); exit(3); }
		$o = $m[0][1] + strlen($m[0][0]);
		$c = strripos($h, "</body>");
		if ($c === false || $c < $o) { fwrite(STDERR,"no </body>\n"); exit(3); }
		echo substr($h, $o, $c - $o);
	' "$1"
}

# Extract inner HTML of <body> from an HTTP response passed on stdin.
extract_served_body_inner() {
	php -r '
		$h = stream_get_contents(STDIN);
		if (!preg_match("/<body\b[^>]*>/i", $h, $m, PREG_OFFSET_CAPTURE)) { exit(0); }
		$o = $m[0][1] + strlen($m[0][0]);
		$c = strripos($h, "</body>");
		if ($c === false || $c < $o) { exit(0); }
		echo substr($h, $o, $c - $o);
	'
}

# ---------------------------------------------------------------------------
# 0. Clean-ish slate: env up, plugin on, pretty permalinks, import corpus.
# ---------------------------------------------------------------------------
echo
info "S5 Step 3 — round-trip fidelity harness"
echo
info "0/4  Starting wp-env, activating plugin, importing corpus…"
[ "${S5_ENV_READY:-0}" = "1" ] || $WP_ENV start >/dev/null
wp plugin activate s5-plugin >/dev/null || fail "s5-plugin failed to activate"
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
wp rewrite flush --hard >/dev/null 2>&1 || true
wp option update s5_roundtrip_mutate 0 >/dev/null 2>&1 || true  # disarm before we begin

# Remove any pages from prior runs so slugs resolve to our fresh imports.
for f in "$SRC_PAGES"/*.html; do
	slug="$(basename "$f" .html)"
	pid="$(wp post list --post_type=page --name="$slug" --field=ID 2>/dev/null | head -n1 || true)"
	[ -n "$pid" ] && wp post delete "$pid" --force >/dev/null 2>&1 || true
done

IMPORT_OUT="$($WP_ENV run cli wp s5 import "$PAGES_DIR_CONTAINER" 2>&1)" || { echo "$IMPORT_OUT" >&2; fail "import exited nonzero"; }
pass "environment ready; corpus imported"

# ---------------------------------------------------------------------------
# Core per-page round-trip. Sets ROUNDTRIP_DELTA (a readable report) and returns
# 0 on full fidelity, 1 on any unexplained delta. Never exits the script itself,
# so the negative test can call it with the mutation armed and inspect the delta.
# ---------------------------------------------------------------------------
ROUNDTRIP_DELTA=""

roundtrip_one() {
	local file="$1" slug url served src_body served_body remainder delta pid media_map
	slug="$(basename "$file" .html)"

	# Always resolve the page id (needed for the media manifest), including the
	# front page (index.html).
	pid="$(wp post list --post_type=page --name="$slug" --field=ID 2>/dev/null | head -n1)"
	[ -n "$pid" ] || { ROUNDTRIP_DELTA="page '$slug' not found after import"; return 1; }

	if [ "$slug" = "index" ]; then
		url="$(wp option get home 2>/dev/null)/"
	else
		url="$(wp post url "$pid" 2>/dev/null)"
	fi

	served="$(curl -s "$url" || true)"
	[ -n "$served" ] || { ROUNDTRIP_DELTA="empty HTTP response from $url"; return 1; }

	# The compile-time media manifest for THIS page (out-of-band; not in served HTML).
	media_map="$(wp post meta get "$pid" _s5_media_map --format=json 2>/dev/null || true)"
	[ -n "$media_map" ] || media_map="[]"

	# --- SANCTIONED TRANSFORM 2: BODY (byte-for-byte) ----------------------
	src_body="$(extract_body_inner "$file")"
	# SANCTIONED TRANSFORM 3: reverse media resolution using the manifest so
	# resolved <img>/<source> markup maps back to the authored logical source
	# before the byte diff. Nothing in the served markup drives this.
	served_body="$(printf '%s' "$served" | extract_served_body_inner | normalize_media "$media_map")"

	# The fragment must appear as an EXACT byte substring of the served body.
	if ! printf '%s' "$served_body" | grep -qF -- "$src_body"; then
		delta="$(diff <(printf '%s' "$src_body") <(printf '%s' "$served_body") || true)"
		ROUNDTRIP_DELTA="BODY delta for /$slug/ — served body does not contain the source fragment verbatim:
--- source <body> (expected, verbatim)
+++ served <body> (actual, fragment + sanctioned wrapper)
$delta"
		return 1
	fi

	# The fragment appears verbatim — now verify the bytes AROUND it are only the
	# sanctioned wrapper (SANCTIONED TRANSFORM 2), and that the fragment was not
	# corrupted at its edges. We split the served body at the fragment:
	#
	#   PREFIX = bytes between `<body ...>` and the fragment.
	#   SUFFIX = bytes after the fragment (up to `</body>`).
	#
	# Sanctioned rules:
	#   - PREFIX must be whitespace-only. NOTHING may be injected ahead of the
	#     authored fragment — that would shift/precede the authored bytes.
	#   - The fragment must be immediately followed by a whitespace boundary
	#     (the canvas emits `fragment` + "\n"). A non-whitespace byte glued to the
	#     fragment means something was appended INTO the fragment region — e.g.
	#     the negative-test mutation sentinel — which is an unexplained delta.
	#   - SUFFIX may then contain wp_footer() output (core scripts/markup live
	#     OUTSIDE the fragment by design). It must NOT contain the mutation
	#     sentinel.
	local edges
	edges="$(printf '%s' "$served_body" | php -r '
		$b    = stream_get_contents(STDIN);
		$frag = file_get_contents($argv[1]);
		$pos  = strpos($b, $frag);
		if ($pos === false) { echo "MISSING"; exit(0); }
		$prefix = substr($b, 0, $pos);
		$suffix = substr($b, $pos + strlen($frag));
		// First byte after the fragment (empty if none).
		$boundary = strlen($suffix) > 0 ? substr($suffix, 0, 1) : "\n";
		$prefix_ws = (trim($prefix) === "") ? "1" : "0";
		$boundary_ws = (trim($boundary) === "") ? "1" : "0";
		echo $prefix_ws . $boundary_ws;
	' <(printf '%s' "$src_body"))"

	if [ "${edges:0:1}" != "1" ]; then
		ROUNDTRIP_DELTA="BODY delta for /$slug/ — non-whitespace bytes were injected BEFORE the authored fragment (prefix is not sanctioned wrapper)."
		return 1
	fi
	if [ "${edges:1:1}" != "1" ]; then
		ROUNDTRIP_DELTA="BODY delta for /$slug/ — the authored fragment is immediately followed by non-whitespace bytes (something was appended into the fragment region, not sanctioned wrapper)."
		return 1
	fi
	# Belt-and-suspenders: the mutation sentinel must never appear anywhere.
	if printf '%s' "$served_body" | grep -qF 's5-roundtrip-mutation'; then
		ROUNDTRIP_DELTA="BODY delta for /$slug/ — render-path mutation sentinel present in served body:
    | $(printf '%s' "$served_body" | grep -oF 's5-roundtrip-mutation' | head -n1)"
		return 1
	fi

	# --- SANCTIONED TRANSFORM 1: HEAD CONTRACT ------------------------------
	# title: exactly once, matching source.
	local title_count src_title
	title_count="$(printf '%s' "$served" | grep -oiE '<title>[^<]*</title>' | grep -c . || true)"
	[ "$title_count" = "1" ] || { ROUNDTRIP_DELTA="HEAD delta for /$slug/ — expected exactly one <title>, found $title_count"; return 1; }
	src_title="$($EXTRACT_HEAD "$file" title)"
	if [ -n "$src_title" ] && ! printf '%s' "$served" | grep -qF -- "<title>$src_title</title>"; then
		ROUNDTRIP_DELTA="HEAD delta for /$slug/ — <title> does not match source. expected: <title>$src_title</title>"
		return 1
	fi

	# meta description: exactly once, matching source content (if source has one).
	local src_desc desc_count
	src_desc="$($EXTRACT_HEAD "$file" description)"
	if [ -n "$src_desc" ]; then
		desc_count="$(printf '%s' "$served" | grep -oiE '<meta[^>]+name="description"[^>]*>' | grep -c . || true)"
		[ "$desc_count" = "1" ] || { ROUNDTRIP_DELTA="HEAD delta for /$slug/ — expected exactly one meta description, found $desc_count"; return 1; }
		printf '%s' "$served" | grep -qF -- "$src_desc" || { ROUNDTRIP_DELTA="HEAD delta for /$slug/ — meta description content does not match source: $src_desc"; return 1; }
	fi

	# canonical: matching source (if source has one).
	local src_canon
	src_canon="$($EXTRACT_HEAD "$file" canonical)"
	if [ -n "$src_canon" ]; then
		printf '%s' "$served" | grep -qF -- "$src_canon" || { ROUNDTRIP_DELTA="HEAD delta for /$slug/ — canonical URL not found in served head: $src_canon"; return 1; }
	fi

	# each source OG tag present (by content value).
	local prop val
	while IFS=$'\t' read -r prop val; do
		[ -n "$prop" ] || continue
		printf '%s' "$served" | grep -qF -- "$val" || { ROUNDTRIP_DELTA="HEAD delta for /$slug/ — OG tag '$prop' content not present in served head: $val"; return 1; }
	done < <($EXTRACT_HEAD "$file" og)

	# each source JSON-LD block present verbatim.
	local jsonld_raw block
	jsonld_raw="$($EXTRACT_HEAD "$file" jsonld)"
	if [ -n "$jsonld_raw" ]; then
		while IFS= read -r -d '' block; do
			[ -n "$block" ] || continue
			printf '%s' "$served" | grep -qF -- "$block" || { ROUNDTRIP_DELTA="HEAD delta for /$slug/ — JSON-LD block not present verbatim in served head:
$block"; return 1; }
		done < <(printf '%s\0' "$jsonld_raw")
	fi

	ROUNDTRIP_DELTA=""
	return 0
}

# Run the whole corpus. Prints per-page result; returns 0 iff all pages green.
roundtrip_corpus() {
	local any_fail=0 f
	for f in "$SRC_PAGES"/*.html; do
		if roundtrip_one "$f"; then
			pass "  /$(basename "$f" .html)/  round-trips with zero unexplained deltas"
		else
			printf '%s✘%s  /%s/  UNEXPLAINED DELTA\n' "$RED" "$NC" "$(basename "$f" .html)" >&2
			printf '%s\n' "$ROUNDTRIP_DELTA" >&2
			any_fail=1
		fi
	done
	return $any_fail
}

# ---------------------------------------------------------------------------
# 1. Positive: whole corpus must round-trip clean.
# ---------------------------------------------------------------------------
info "1/4  Round-tripping the corpus (must be green)…"
wp option update s5_roundtrip_mutate 0 >/dev/null 2>&1 || true
roundtrip_corpus || fail "corpus did not round-trip cleanly (see deltas above)"
pass "corpus round-trips with zero unexplained deltas"

# ---------------------------------------------------------------------------
# 2. Negative (self-verifying): arm the render-path mutation -> harness MUST go
#    red with a delta report. This proves the harness can actually fail.
# ---------------------------------------------------------------------------
info "2/4  Arming render-path mutation — harness MUST now detect a delta…"
wp option update s5_roundtrip_mutate 1 >/dev/null 2>&1 || fail "could not arm mutation"
if roundtrip_one "$SRC_PAGES/series-5-technology.html"; then
	wp option update s5_roundtrip_mutate 0 >/dev/null 2>&1 || true
	fail "MUTATION NOT DETECTED — the harness passed a corrupted render. It cannot be trusted."
fi
printf '%s' "$ROUNDTRIP_DELTA" | grep -qF 's5-roundtrip-mutation' \
	|| fail "harness went red but the delta report does not name the mutation — report unreliable"
pass "mutation detected — harness went red with a delta report naming it"

# ---------------------------------------------------------------------------
# 3. Disarm -> harness MUST be green again (proves it was the mutation, not flake).
# ---------------------------------------------------------------------------
info "3/4  Disarming mutation — harness MUST be green again…"
wp option update s5_roundtrip_mutate 0 >/dev/null 2>&1 || fail "could not disarm mutation"
roundtrip_one "$SRC_PAGES/series-5-technology.html" || { printf '%s\n' "$ROUNDTRIP_DELTA" >&2; fail "still red after disarming — negative test is not clean"; }
pass "green again after disarming — fidelity is self-verifying"

# ---------------------------------------------------------------------------
# 4. No PHP errors during any of the above.
# ---------------------------------------------------------------------------
info "4/4  Checking debug.log for PHP errors…"
DEBUG_CONTENT="$(wp eval '$p=(defined("WP_DEBUG_LOG")&&is_string(WP_DEBUG_LOG))?WP_DEBUG_LOG:WP_CONTENT_DIR."/debug.log"; echo file_exists($p)?file_get_contents($p):"";' 2>/dev/null || true)"
if printf '%s' "$DEBUG_CONTENT" | grep -Eiq 'PHP (Fatal error|Parse error|Warning|Notice)'; then
	echo "----- debug.log -----" >&2; printf '%s\n' "$DEBUG_CONTENT" >&2; echo "---------------------" >&2
	fail "PHP errors/notices during round-trip"
fi
pass "no PHP errors during round-trip"

echo
pass "Step 3 acceptance: ALL CHECKS PASSED (round-trip fidelity is executable)"
echo
