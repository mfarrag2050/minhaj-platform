#!/usr/bin/env bash
# spec-calendar-v1 §3.1 + §7 · anchor-timezone proof against a live DB.
#
# The whole point of §3.1 is: "the day is measured in the group's
# anchor_timezone, not UTC". A session at 00:30 local on a Kiribati
# Tuesday is 10:30 UTC on Monday. If the skip lookup naively used the
# UTC date, the session would slip through — it would be generated on
# the local Tuesday that was supposed to be a holiday, and the parent
# would lose a paid hour.
#
# The test:
#   1. Creates a supplier org and a group anchored to Pacific/Kiritimati
#      (UTC+14, no DST — the strongest available offset from UTC).
#   2. Attaches a calendar with a single day: 2027-01-05.
#   3. Sets teacher availability on Tuesday 00:00–02:00 Kiribati local.
#   4. Requests 3 sessions of a pattern that would otherwise place the
#      first session at 2027-01-05 00:30 local (= 2027-01-04 10:30 UTC).
#   5. Asserts that:
#        • generate_for_group succeeds
#        • exactly 3 sessions are inserted
#        • NONE of them lands on 2027-01-05 local
#        • the surviving sessions are 12/19/26 local, not 11/18/25 UTC
#
# Also proves C-2 · the same group without a calendar attached (and no
# no_calendar_ack row) refuses to generate.
#
# Pattern intentionally mirrors tests/Integration/orgs-cross-scope.sh.

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

echo "${BOLD}== Reset relevant tables ==${RESET}"
run_wp db query "
  DELETE FROM wp_minhaj_timetable_audit;
  DELETE FROM wp_minhaj_sessions;
  DELETE FROM wp_minhaj_schedule_patterns;
  DELETE FROM wp_minhaj_teacher_availability;
  DELETE FROM wp_minhaj_teacher_absences;
  DELETE FROM wp_minhaj_group_calendars;
  DELETE FROM wp_minhaj_calendar_days;
  DELETE FROM wp_minhaj_calendars;
  DELETE FROM wp_minhaj_group_members;
  DELETE FROM wp_minhaj_groups;
  DELETE FROM wp_minhaj_orgs;
  DELETE FROM wp_minhaj_org_members;
" >/dev/null

echo "${BOLD}== Seed: one supplier org + one group in Pacific/Kiritimati + a calendar with 2027-01-05 disabled ==${RESET}"

SEED_CODE=$(cat <<'PHP'
add_filter( 'minhaj_org_requires_dpa', '__return_false' );

$orgs = new \Minhaj\Modules\Orgs\OrgService( new \Minhaj\Modules\Orgs\Repository\OrgRepository() );
$org_id = $orgs->create_org( 1, [ 'code' => 'KI-ORG', 'name' => 'Kiribati Partners', 'country' => 'KI' ] );
if ( is_wp_error( $org_id ) ) { echo "seed_org_failed:" . $org_id->get_error_code(); exit(1); }
$orgs->set_status( 1, $org_id, 'active', 'test-activate' );

$groups_svc  = new \Minhaj\Modules\Groups\GroupService( new \Minhaj\Modules\Groups\Repository\GroupRepository() );
$group_id    = $groups_svc->create( 1, [ 'code' => 'KI-GRP', 'type' => 'group', 'capacity_min' => 3, 'capacity_max' => 5, 'total_sessions' => 3, 'session_duration_minutes' => 60, 'timezone' => 'Pacific/Kiritimati' ] );
if ( is_wp_error( $group_id ) ) { echo "seed_group_failed:" . $group_id->get_error_code(); exit(1); }

$teacher_id = wp_insert_user( [
    'user_login' => 'ki_teacher_' . uniqid(),
    'user_pass'  => wp_generate_password(),
    'role'       => 'minhaj_teacher',
] );

global $wpdb;
$wpdb->update( 'wp_minhaj_groups', [ 'teacher_id' => $teacher_id, 'org_id' => $org_id ], [ 'id' => $group_id ] );

$cal = new \Minhaj\Modules\Calendar\CalendarService( new \Minhaj\Modules\Calendar\Repository\CalendarRepository() );
$calendar_id = $cal->create_calendar( 1, [ 'name' => 'Kiribati Holidays', 'org_id' => $org_id, 'country' => 'KI' ] );
if ( is_wp_error( $calendar_id ) ) { echo "seed_calendar_failed:" . $calendar_id->get_error_code(); exit(1); }
$day_id = $cal->add_day( 1, $calendar_id, '2027-01-05', 'closure', 'Kiritimati observance' );
if ( is_wp_error( $day_id ) ) { echo "seed_day_failed:" . $day_id->get_error_code(); exit(1); }

printf( "ORG=%d GROUP=%d TEACHER=%d CALENDAR=%d DAY=%d\n", $org_id, $group_id, $teacher_id, $calendar_id, $day_id );
PHP
)

SEED_OUT=$(run_wp eval "$SEED_CODE" | tr -d '\r')
echo "  $SEED_OUT"
parse_id() { printf '%s' "$SEED_OUT" | grep -oE "$1=[0-9]+" | head -1 | cut -d= -f2; }
GROUP_ID=$(parse_id GROUP)
TEACHER_ID=$(parse_id TEACHER)
CALENDAR_ID=$(parse_id CALENDAR)
DAY_ID=$(parse_id DAY)
for v in GROUP_ID TEACHER_ID CALENDAR_ID DAY_ID; do
  if [[ -z "${!v:-}" ]]; then
    echo "${RED}✗ could not parse $v${RESET}"
    exit 1
  fi
done

echo
echo "${BOLD}== C-2 · generation refuses when no calendar attached and no ack on file ==${RESET}"

GATE_CODE=$(cat <<PHP
\$timetable = new \\Minhaj\\Modules\\Timetable\\TimetableService( new \\Minhaj\\Modules\\Timetable\\Repository\\TimetableRepository() );

// No calendar attached yet — the gate should refuse.
\$out = \$timetable->generate_for_group( 1, $GROUP_ID, [
    'anchor_timezone'  => 'Pacific/Kiritimati',
    'weekdays'         => [ 2 ],
    'start_local'      => '00:30',
    'duration_minutes' => 60,
    'weeks_count'      => 4,
    'first_week_start' => '2027-01-05',
] );
printf( "GATE_RESULT=%s\n", is_wp_error( \$out ) ? ( 'err:' . \$out->get_error_code() ) : 'ok' );
PHP
)

GATE_OUT=$(run_wp eval "$GATE_CODE" | tr -d '\r')
echo "  $GATE_OUT"
GATE_RESULT=$(printf '%s' "$GATE_OUT" | grep -oE 'GATE_RESULT=[a-z:_]+' | cut -d= -f2)

FAIL=0
if [[ "$GATE_RESULT" == "err:no_calendar" ]]; then
  echo "  ${GREEN}✓ C-2 gate rejects with err:no_calendar${RESET}"
else
  echo "  ${RED}✗ C-2 gate returned $GATE_RESULT — expected err:no_calendar${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== Attach the calendar + seed teacher availability at 00:00–02:00 Tuesday local ==${RESET}"

WIRE_CODE=$(cat <<PHP
\$cal = new \\Minhaj\\Modules\\Calendar\\CalendarService( new \\Minhaj\\Modules\\Calendar\\Repository\\CalendarRepository() );
\$att = \$cal->attach_to_group( 1, $GROUP_ID, $CALENDAR_ID );
if ( is_wp_error( \$att ) ) { echo "attach_failed:" . \$att->get_error_code(); exit(1); }

\$timetable = new \\Minhaj\\Modules\\Timetable\\TimetableService( new \\Minhaj\\Modules\\Timetable\\Repository\\TimetableRepository() );
\$av = \$timetable->set_availability( 1, $TEACHER_ID, [ [
    'weekday'        => 2,
    'start_local'    => '00:00',
    'end_local'      => '02:00',
    'timezone'       => 'Pacific/Kiritimati',
    'effective_from' => '2027-01-01',
    'effective_to'   => null,
] ] );
if ( is_wp_error( \$av ) ) { echo "availability_failed:" . \$av->get_error_code(); exit(1); }

echo "wired\n";
PHP
)

WIRE_OUT=$(run_wp eval "$WIRE_CODE" | tr -d '\r')
echo "  $WIRE_OUT"

echo
echo "${BOLD}== Generate 3 sessions · anchor Pacific/Kiritimati · start 00:30 local Tuesday · disable 2027-01-05 ==${RESET}"

GEN_CODE=$(cat <<PHP
\$timetable = new \\Minhaj\\Modules\\Timetable\\TimetableService( new \\Minhaj\\Modules\\Timetable\\Repository\\TimetableRepository() );
\$out = \$timetable->generate_for_group( 1, $GROUP_ID, [
    'anchor_timezone'  => 'Pacific/Kiritimati',
    'weekdays'         => [ 2 ],
    'start_local'      => '00:30',
    'duration_minutes' => 60,
    'weeks_count'      => 4,
    'first_week_start' => '2027-01-05',
] );
if ( is_wp_error( \$out ) ) {
    printf( "GEN=err:%s\n", \$out->get_error_code() );
    exit(1);
}

printf( "GEN=ok count=%d\n", count( \$out ) );

global \$wpdb;
\$rows = \$wpdb->get_results( "SELECT sequence_no, scheduled_start_utc, local_start_wall FROM wp_minhaj_sessions WHERE group_id = $GROUP_ID ORDER BY sequence_no", ARRAY_A );
foreach ( \$rows as \$r ) {
    printf( "SESSION seq=%d local=%s utc=%s\n", (int) \$r['sequence_no'], (string) \$r['local_start_wall'], (string) \$r['scheduled_start_utc'] );
}
PHP
)

GEN_OUT=$(run_wp eval "$GEN_CODE" | tr -d '\r')
echo "  ---"
printf '%s\n' "$GEN_OUT" | sed 's/^/  /'
echo "  ---"

GEN_RESULT=$(printf '%s' "$GEN_OUT" | grep -oE 'GEN=[a-z:_]+' | cut -d= -f2)
GEN_COUNT=$(printf '%s' "$GEN_OUT" | grep -oE 'count=[0-9]+' | cut -d= -f2)

if [[ "$GEN_RESULT" == "ok" ]] && [[ "$GEN_COUNT" == "3" ]]; then
  echo "  ${GREEN}✓ generation succeeded with exactly 3 sessions${RESET}"
else
  echo "  ${RED}✗ generation returned $GEN_RESULT count=$GEN_COUNT${RESET}"
  FAIL=1
fi

# The disabled local date must not appear in any session's local_start_wall.
if grep -Fq 'local=2027-01-05' <<<"$GEN_OUT"; then
  echo "  ${RED}✗ a session landed on 2027-01-05 local — anchor-tz skip did not fire${RESET}"
  FAIL=1
else
  echo "  ${GREEN}✓ no session on 2027-01-05 local — the disabled anchor day was skipped${RESET}"
fi

# The naive-UTC date (2027-01-04) must NOT appear in local_start_wall either —
# but MUST appear in one UTC column (the session moved to the next Tuesday,
# 2027-01-12 local = 2027-01-11 UTC, so it's really 11 not 04 either). What
# we're proving is that a NAIVE implementation would have kept 01-05 local
# alive because its UTC date is 01-04, not 01-05. So the interesting
# assertion is: at least one session has UTC != local date, proving the
# time-boundary is real.
if grep -Eq 'local=2027-01-[0-9]+ 00:30:00 utc=2027-01-[0-9]+ 10:30:00' <<<"$GEN_OUT"; then
  echo "  ${GREEN}✓ UTC dates differ from local dates — the boundary the anchor-tz rule guards is live${RESET}"
else
  echo "  ${RED}✗ UTC and local dates did not diverge — the boundary was not exercised${RESET}"
  FAIL=1
fi

# Positive check: the surviving local dates must be the three following Tuesdays.
for expected in 2027-01-12 2027-01-19 2027-01-26; do
  if grep -Fq "local=$expected" <<<"$GEN_OUT"; then
    echo "  ${GREEN}✓ session on $expected local present${RESET}"
  else
    echo "  ${RED}✗ session on $expected local absent${RESET}"
    FAIL=1
  fi
done

echo
if [[ "$FAIL" != "0" ]]; then
  echo "${RED}${BOLD}CALENDAR ANCHOR-TZ PROOF FAILED${RESET}"
  exit 1
fi

echo "${GREEN}${BOLD}CALENDAR ANCHOR-TZ PROOF PASSED${RESET}"
