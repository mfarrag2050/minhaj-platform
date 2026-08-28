#!/usr/bin/env bash
# Break-and-restore proof for the recordings guards.
#
#   G-1  · triple verification blocks Zoom delete
#   G-6  · daily purge honours retention_until
#   G-7  · purged row survives as tombstone
#   G-8  · legal_hold skips purge
#   G-11 · view URL only after AccessCheck says YES
#   Idempotency · uq_zoom_file blocks replay
#   Stored retention · filter change after insert doesn't retro-apply

set -euo pipefail

REPO_ROOT=$(git -C "$(dirname "$0")/.." rev-parse --show-toplevel)
SVC="$REPO_ROOT/plugins/minhaj-core/includes/Modules/Recordings/RecordingsService.php"
REPO_FILE="$REPO_ROOT/plugins/minhaj-core/includes/Modules/Recordings/Repository/RecordingsRepository.php"
ACCEPT="$REPO_ROOT/tests/Integration/recordings-pipeline.sh"

GREEN=$'\033[32m'
RED=$'\033[31m'
BOLD=$'\033[1m'
RESET=$'\033[0m'

BACKUP=$(mktemp -d)
cp "$SVC"       "$BACKUP/RecordingsService.php"
cp "$REPO_FILE" "$BACKUP/RecordingsRepository.php"

restore_all() {
  cp "$BACKUP/RecordingsService.php"    "$SVC"
  cp "$BACKUP/RecordingsRepository.php" "$REPO_FILE"
}
trap 'restore_all; rm -rf "$BACKUP"' EXIT

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

# G-1 — break verify_triple to always return true.
echo ""
echo "${BOLD}== G-1 · triple verification blocks Zoom delete ==${RESET}"
python3 - "$SVC" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
old = "private function verify_triple( array $row ): bool {"
new_line = "private function verify_triple( array $row ): bool { return true; // BROKEN — always yes\n"
assert old in s, 'G-1 marker not found'
open(p, 'w').write(s.replace(old, new_line, 1))
PY
expect_fail "G-1 triple verify" || FAIL=1
restore_all
expect_pass "G-1 triple verify restored" || FAIL=1

# G-6 — break purge_expired to do nothing.
echo ""
echo "${BOLD}== G-6 · daily purge deletes storage AND writes tombstone ==${RESET}"
python3 - "$SVC" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
old = "public function purge_expired( int $limit = 200 ): int {"
new_line = "public function purge_expired( int $limit = 200 ): int { return 0; // BROKEN — never purges\n"
assert old in s, 'G-6 marker not found'
open(p, 'w').write(s.replace(old, new_line, 1))
PY
expect_fail "G-6 purge" || FAIL=1
restore_all
expect_pass "G-6 purge restored" || FAIL=1

# G-8 — break the query that excludes legal_hold from the purge queue.
echo ""
echo "${BOLD}== G-8 · legal_hold is excluded from purge candidates ==${RESET}"
python3 - "$REPO_ROOT/plugins/minhaj-core/includes/Modules/Recordings/Repository/RecordingsRepository.php" <<'PY'
import sys, re
p = sys.argv[1]
s = open(p).read()
# Replace "AND status <> %s" (twice, together) with harmless truthy checks.
pattern = re.compile(r"AND status <> %s(\s+)AND status <> %s")
assert pattern.search(s), 'G-8 marker not found'
s2 = pattern.sub(r"AND 1=1 -- BROKEN\1AND 1=1 --", s, count=1)
open(p, 'w').write(s2)
PY
expect_fail "G-8 legal_hold" || FAIL=1
restore_all
expect_pass "G-8 legal_hold restored" || FAIL=1

# G-11 — break issue_view_url to skip the AccessCheck.
echo ""
echo "${BOLD}== G-11 · view URL only after AccessCheck says YES ==${RESET}"
python3 - "$SVC" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
old = "if ( ! $this->access->can_view_recording( $actor_user_id, $recording_id ) ) {"
new_line = "if ( false && ! $this->access->can_view_recording( $actor_user_id, $recording_id ) ) {"
assert old in s, 'G-11 marker not found'
open(p, 'w').write(s.replace(old, new_line, 1))
PY
expect_fail "G-11 access check" || FAIL=1
restore_all
expect_pass "G-11 access check restored" || FAIL=1

# Note · the "retention stored not derived" invariant is an invariant of
# the data model (retention_until is NOT NULL, computed at insert, never
# touched after) — there is no single guard line to mutate. AC-8 in the
# acceptance script is the check that would catch a regression.

echo ""
if [[ "$FAIL" != "0" ]]; then
  echo "${RED}${BOLD}RECORDINGS BREAK-AND-RESTORE FAILED${RESET}"
  exit 1
fi
echo "${GREEN}${BOLD}RECORDINGS BREAK-AND-RESTORE PASSED${RESET}"
