#!/usr/bin/env bash
# Break-and-restore proof for the four new guards added by this task.
#
# For each guard: mutate the code so the guard cannot fire, re-run the
# acceptance script, verify it fails RED. Then restore the code (from a
# byte-for-byte backup, NOT git — the previous work is not committed
# yet) and verify it goes GREEN again. Anchors each assertion in the
# acceptance script to real behaviour, not to a passing side-effect.

set -euo pipefail

REPO_ROOT=$(git -C "$(dirname "$0")/.." rev-parse --show-toplevel)
GS="$REPO_ROOT/plugins/minhaj-core/includes/Modules/Groups/GroupService.php"
CLI="$REPO_ROOT/plugins/minhaj-core/includes/Modules/Timetable/Cli/UnscheduledMakeupsCommand.php"
ACCEPT="$REPO_ROOT/tests/Integration/groups-ui-fixes.sh"

GREEN=$'\033[32m'
RED=$'\033[31m'
BOLD=$'\033[1m'
RESET=$'\033[0m'

BACKUP_DIR=$(mktemp -d)
cp "$GS" "$BACKUP_DIR/GroupService.php"
cp "$CLI" "$BACKUP_DIR/UnscheduledMakeupsCommand.php"

restore_all() {
  cp "$BACKUP_DIR/GroupService.php" "$GS"
  cp "$BACKUP_DIR/UnscheduledMakeupsCommand.php" "$CLI"
}

cleanup() {
  restore_all
  rm -rf "$BACKUP_DIR"
}
trap cleanup EXIT

FAIL=0

expect_fail() {
  local name="$1"
  if bash "$ACCEPT" >/dev/null 2>&1; then
    echo "  ${RED}✗ $name did NOT go red after breaking the guard${RESET}"
    return 1
  fi
  echo "  ${GREEN}✓ $name went red after breaking the guard${RESET}"
}

expect_pass() {
  local name="$1"
  if ! bash "$ACCEPT" >/dev/null 2>&1; then
    echo "  ${RED}✗ $name did NOT go green after restoring the guard${RESET}"
    return 1
  fi
  echo "  ${GREEN}✓ $name went green again after restoring the guard${RESET}"
}

# G1 — retry-on-collision. Break by capping max_attempts to 1.
echo ""
echo "${BOLD}== G1 · auto-generated code retry-on-collision ==${RESET}"
python3 - "$GS" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
new = s.replace('$max_attempts = 5;', '$max_attempts = 1;', 1)
assert new != s, 'break marker not found for G1'
open(p, 'w').write(new)
PY
expect_fail "G1 retry" || FAIL=1
restore_all
expect_pass "G1 retry restored" || FAIL=1

# G2 — capacity_over_promise pre-save gate. Break by shorting the outer if.
echo ""
echo "${BOLD}== G2 · capacity_over_promise pre-save gate ==${RESET}"
python3 - "$GS" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
old = 'if ( $capacity_max > $default_ceiling ) {'
new_line = 'if ( false && $capacity_max > $default_ceiling ) {'
assert old in s, 'break marker not found for G2'
open(p, 'w').write(s.replace(old, new_line, 1))
PY
expect_fail "G2 capacity gate" || FAIL=1
restore_all
expect_pass "G2 capacity gate restored" || FAIL=1

# G3 — language coverage gate. Break by shorting the outer if.
echo ""
echo "${BOLD}== G3 · language coverage pre-save gate ==${RESET}"
python3 - "$GS" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
old = "if ( '' !== $teaching_language ) {"
new_line = "if ( false && '' !== $teaching_language ) {"
assert old in s, 'break marker not found for G3'
open(p, 'w').write(s.replace(old, new_line, 1))
PY
expect_fail "G3 language gate" || FAIL=1
restore_all
expect_pass "G3 language gate restored" || FAIL=1

# G4 — no_show reconciliation CLI. Break by nulling the orphan lookup.
echo ""
echo "${BOLD}== G4 · no_show reconciliation CLI ==${RESET}"
python3 - "$CLI" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
old = '$orphans = $this->repo->list_no_show_sessions_without_makeup( $limit );'
new_line = '$orphans = array();'
assert old in s, 'break marker not found for G4'
open(p, 'w').write(s.replace(old, new_line, 1))
PY
expect_fail "G4 no_show reconciliation" || FAIL=1
restore_all
expect_pass "G4 no_show reconciliation restored" || FAIL=1

echo ""
if [[ "$FAIL" != "0" ]]; then
  echo "${RED}${BOLD}BREAK-AND-RESTORE PROOF FAILED${RESET}"
  exit 1
fi
echo "${GREEN}${BOLD}BREAK-AND-RESTORE PROOF PASSED${RESET}"
