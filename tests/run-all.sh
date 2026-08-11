#!/usr/bin/env bash
#
# Single-environment full acceptance run — Ship Gate check #1's execution vehicle.
#
# Destroys and starts wp-env EXACTLY ONCE, then runs every acceptance script
# against that one running environment. The individual scripts do NOT restart
# wp-env: this runner exports S5_ENV_READY=1, which each script honors by skipping
# its own `wp-env start`. The result is a single, clean-slate, verifiable run that
# completes inside the command time limit.
#
# Usage: npm test                 (clean slate: destroy → start → all suites)
#        SKIP_DESTROY=1 npm test   (reuse a running env; still runs all suites)
#
# Exit status is nonzero if ANY suite fails.

set -uo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; BOLD=$'\033[1m'; NC=$'\033[0m'
info() { printf '%s•%s %s\n' "$YELLOW" "$NC" "$1"; }
ok()   { printf '%s✔%s %s\n' "$GREEN" "$NC" "$1"; }
bad()  { printf '%s✘%s %s\n' "$RED" "$NC" "$1" >&2; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
WP_ENV="npx wp-env"

command -v npx >/dev/null 2>&1    || { bad "npx not found. Run 'npm install' first."; exit 1; }
command -v docker >/dev/null 2>&1 || { bad "docker not found."; exit 1; }
docker info >/dev/null 2>&1        || { bad "Docker daemon not reachable."; exit 1; }

# ---------------------------------------------------------------------------
# ONE environment lifecycle for the whole suite.
# ---------------------------------------------------------------------------
if [ "${SKIP_DESTROY:-0}" != "1" ]; then
	info "Clean slate: wp-env destroy…"
	yes | $WP_ENV destroy --scripts >/dev/null 2>&1 || true
fi
info "Starting wp-env once for the whole suite…"
$WP_ENV start >/dev/null 2>&1 || { bad "wp-env start failed"; exit 1; }
$WP_ENV run cli wp plugin activate s5-plugin >/dev/null 2>&1 || { bad "s5-plugin failed to activate"; exit 1; }
ok "environment up; running all acceptance suites against it"
echo

# Each suite honors S5_ENV_READY=1 by NOT restarting wp-env itself.
export S5_ENV_READY=1

# Ordered suites: step 0..6. Round-trip (step3) is the fidelity harness.
SUITES=(
	"tests/acceptance.sh|Step 0 — bootstrap"
	"tests/acceptance-step1.sh|Step 1 — serve canonical HTML"
	"tests/acceptance-step2.sh|Step 2 — wp s5 import"
	"tests/acceptance-step3.sh|Step 3 — round-trip fidelity (Ship Gate #1)"
	"tests/acceptance-step4.sh|Step 4 — Authoring Contract validation"
	"tests/acceptance-step5.sh|Step 5 — canonical vs derived render"
	"tests/acceptance-step6.sh|Step 6 — media resolution"
	"tests/acceptance-step8.sh|Step 8 — parts + generic dynamics (L&L)"
	"tests/acceptance-step9.sh|Step 9 — baseline SEO/AEO ownership"
)

fail_count=0
declare -a RESULTS
for entry in "${SUITES[@]}"; do
	script="${entry%%|*}"
	label="${entry##*|}"
	printf '%s──────────────────────────────────────────────────────────────%s\n' "$BOLD" "$NC"
	info "RUN  $label"
	if bash "$ROOT_DIR/$script"; then
		RESULTS+=("${GREEN}PASS${NC}  $label")
	else
		RESULTS+=("${RED}FAIL${NC}  $label")
		bad "SUITE FAILED: $label"
		fail_count=$((fail_count + 1))
	fi
	echo
done

printf '%s══════════════════════ SUITE SUMMARY ══════════════════════%s\n' "$BOLD" "$NC"
for r in "${RESULTS[@]}"; do printf '  %s\n' "$r"; done
echo
if [ "$fail_count" -eq 0 ]; then
	ok "ALL SUITES PASSED (single-environment clean-slate run)"
	exit 0
fi
bad "$fail_count suite(s) failed"
exit 1
