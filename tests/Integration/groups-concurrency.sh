#!/usr/bin/env bash
# Concurrency proof for GroupService::add_member — spec-groups-v1 §6.
#
# Two parallel wp-env cli processes race for the last seat in a
# capacity_max=3 group. Expected: exactly one succeeds, the other returns
# WP_Error code=group_full, and the final active-member count equals
# capacity_max exactly.

set -euo pipefail

WP_ENV=${WP_ENV:-wp-env}
LOGDIR=$(mktemp -d)
GREEN=$'\033[32m'
RED=$'\033[31m'
BOLD=$'\033[1m'
RESET=$'\033[0m'

trap 'rm -rf "$LOGDIR"' EXIT

run_wp() {
  "$WP_ENV" run cli wp "$@" 2>/dev/null
}

echo "${BOLD}== Reset test tables ==${RESET}"
run_wp db query "DELETE FROM wp_minhaj_group_audit; DELETE FROM wp_minhaj_group_members; DELETE FROM wp_minhaj_groups;" >/dev/null

echo "${BOLD}== Seed group (capacity_max=3) with two active members — one seat left ==${RESET}"
SEED_CODE='
$svc = new \Minhaj\Modules\Groups\GroupService(new \Minhaj\Modules\Groups\Repository\GroupRepository());
$id = $svc->create(1, ["code"=>"RACE-01","type"=>"group","capacity_min"=>3,"capacity_max"=>3]);
if (is_wp_error($id)) { echo "seed_failed:" . $id->get_error_code(); exit(1); }
foreach ([100, 101] as $sid) {
    $r = $svc->add_member(1, $id, $sid);
    if (is_wp_error($r)) { echo "seed_member_failed:" . $r->get_error_code(); exit(1); }
}
echo "GROUP_ID=" . $id;
'
SEED_OUT=$(run_wp eval "$SEED_CODE" | tr -d '\r')
echo "  $SEED_OUT"

GROUP_ID=$(printf '%s' "$SEED_OUT" | grep -oE 'GROUP_ID=[0-9]+' | head -1 | cut -d= -f2 || true)
if [[ -z "${GROUP_ID:-}" ]]; then
  echo "${RED}✗ could not parse group id from seed output${RESET}"
  exit 1
fi

echo
echo "${BOLD}== Launch two parallel add_member calls for the last seat ==${RESET}"

# Each worker is an independent wp-env run cli process → separate PHP
# process → separate DB connection. That's a genuine race.
build_worker() {
  local student=$1
  printf '
$svc = new \Minhaj\Modules\Groups\GroupService(new \Minhaj\Modules\Groups\Repository\GroupRepository());
$r = $svc->add_member(1, %d, %d);
if (is_wp_error($r)) {
    echo "ERR:" . $r->get_error_code();
} else {
    echo "OK:" . $r . ":student=%d";
}
' "$GROUP_ID" "$student" "$student"
}

WORKER_A_CODE=$(build_worker 200)
WORKER_B_CODE=$(build_worker 201)

( run_wp eval "$WORKER_A_CODE" | tr -d '\r' ) >"$LOGDIR/worker-A.log" 2>&1 &
PID_A=$!
( run_wp eval "$WORKER_B_CODE" | tr -d '\r' ) >"$LOGDIR/worker-B.log" 2>&1 &
PID_B=$!

wait "$PID_A" || true
wait "$PID_B" || true

RESULT_A=$(grep -oE '^(OK:[0-9]+:student=[0-9]+|ERR:[a-z_]+)' "$LOGDIR/worker-A.log" | head -1 || echo "<none>")
RESULT_B=$(grep -oE '^(OK:[0-9]+:student=[0-9]+|ERR:[a-z_]+)' "$LOGDIR/worker-B.log" | head -1 || echo "<none>")

echo "  worker A → $RESULT_A"
echo "  worker B → $RESULT_B"

echo
echo "${BOLD}== Final membership table ==${RESET}"
run_wp db query "SELECT id, group_id, student_id, status, seat_index, active_seat_index, active_student_id FROM wp_minhaj_group_members WHERE group_id=$GROUP_ID ORDER BY id"

ACTIVE_COUNT=$(run_wp db query "SELECT COUNT(*) FROM wp_minhaj_group_members WHERE group_id=$GROUP_ID AND status='active'" --skip-column-names | tr -d '[:space:]')

echo
echo "${BOLD}== Audit trail (chronological) ==${RESET}"
run_wp db query "SELECT action, actor_user_id, subject_id, payload_json FROM wp_minhaj_group_audit WHERE group_id=$GROUP_ID ORDER BY id"

echo
echo "${BOLD}== Assertions ==${RESET}"
FAIL=0

if [[ "$ACTIVE_COUNT" != "3" ]]; then
  echo "  ${RED}✗ active count must equal capacity_max (3), got: $ACTIVE_COUNT${RESET}"
  FAIL=1
else
  echo "  ${GREEN}✓ active membership count = capacity_max = 3${RESET}"
fi

OK_COUNT=0
FULL_COUNT=0
for R in "$RESULT_A" "$RESULT_B"; do
  [[ "$R" == OK:* ]] && OK_COUNT=$((OK_COUNT+1))
  [[ "$R" == ERR:group_full ]] && FULL_COUNT=$((FULL_COUNT+1))
done

if [[ "$OK_COUNT" != "1" || "$FULL_COUNT" != "1" ]]; then
  echo "  ${RED}✗ expected exactly 1 OK + 1 ERR:group_full (got OK=$OK_COUNT, group_full=$FULL_COUNT)${RESET}"
  FAIL=1
else
  echo "  ${GREEN}✓ one worker succeeded, the other got WP_Error(group_full)${RESET}"
fi

if [[ "$FAIL" != "0" ]]; then
  echo
  echo "${RED}${BOLD}CONCURRENCY PROOF FAILED${RESET}"
  echo "-- worker A raw --"; cat "$LOGDIR/worker-A.log"
  echo "-- worker B raw --"; cat "$LOGDIR/worker-B.log"
  exit 1
fi

echo
echo "${GREEN}${BOLD}CONCURRENCY PROOF PASSED${RESET}"

# ---------------------------------------------------------------------------
# Extra live-DB checks: seat reuse after withdrawal, and idempotent retry.
# ---------------------------------------------------------------------------

echo
echo "${BOLD}== Live-DB check: withdraw + re-add reuses the freed seat ==${RESET}"

REUSE_CODE='
$repo = new \Minhaj\Modules\Groups\Repository\GroupRepository();
$svc  = new \Minhaj\Modules\Groups\GroupService($repo);
$row  = $repo->find_active_member('"$GROUP_ID"', 100);
if (null === $row) { echo "no_row"; exit(1); }
$membership_id = (int) $row["id"];
$freed_seat = (int) $row["seat_index"];
$svc->remove_member(1, $membership_id, "test-withdraw");
$new = $svc->add_member(1, '"$GROUP_ID"', 300);
if (is_wp_error($new)) { echo "add_failed:" . $new->get_error_code(); exit(1); }
$new_row = $repo->find_active_member('"$GROUP_ID"', 300);
echo "freed_seat=" . $freed_seat . " new_seat=" . (int) $new_row["seat_index"];
'
REUSE_OUT=$(run_wp eval "$REUSE_CODE" | tr -d '\r')
echo "  $REUSE_OUT"

FREED_SEAT=$(printf '%s' "$REUSE_OUT" | grep -oE 'freed_seat=[0-9]+' | cut -d= -f2)
NEW_SEAT=$(printf '%s' "$REUSE_OUT" | grep -oE 'new_seat=[0-9]+' | cut -d= -f2)
if [[ -n "$FREED_SEAT" && "$FREED_SEAT" == "$NEW_SEAT" ]]; then
  echo "  ${GREEN}✓ new member took over the freed seat ($FREED_SEAT)${RESET}"
else
  echo "  ${RED}✗ freed=$FREED_SEAT reused=$NEW_SEAT${RESET}"
  exit 1
fi

echo
echo "${BOLD}== Live-DB check: idempotent duplicate add_member returns same id ==${RESET}"

IDEMP_CODE='
$svc = new \Minhaj\Modules\Groups\GroupService(new \Minhaj\Modules\Groups\Repository\GroupRepository());
$first  = $svc->add_member(1, '"$GROUP_ID"', 300);
$second = $svc->add_member(1, '"$GROUP_ID"', 300);
$third  = $svc->add_member(1, '"$GROUP_ID"', 300);
echo "ids=" . $first . "," . $second . "," . $third;
'
IDEMP_OUT=$(run_wp eval "$IDEMP_CODE" | tr -d '\r')
echo "  $IDEMP_OUT"

IDS=$(printf '%s' "$IDEMP_OUT" | grep -oE 'ids=[0-9]+,[0-9]+,[0-9]+' | cut -d= -f2)
uniq_count=$(echo "$IDS" | tr ',' '\n' | sort -u | wc -l | tr -d ' ')
if [[ "$uniq_count" == "1" ]]; then
  echo "  ${GREEN}✓ three calls returned the same membership id — no second seat created${RESET}"
else
  echo "  ${RED}✗ expected all three ids identical, got: $IDS${RESET}"
  exit 1
fi

ACTIVE_AFTER=$(run_wp db query "SELECT COUNT(*) FROM wp_minhaj_group_members WHERE group_id=$GROUP_ID AND status='active'" --skip-column-names | tr -d '[:space:]')
if [[ "$ACTIVE_AFTER" != "3" ]]; then
  echo "  ${RED}✗ active count changed to $ACTIVE_AFTER — idempotent path leaked a seat${RESET}"
  exit 1
fi

echo
echo "${GREEN}${BOLD}ALL INTEGRATION CHECKS PASSED${RESET}"
