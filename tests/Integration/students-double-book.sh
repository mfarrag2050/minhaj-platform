#!/usr/bin/env bash
# Decision 18 + spec-people-v1 §S-11..S-13 · live-DB proof of three
# invariants on wp-env MariaDB:
#
#   1. create_student() does NOT create a wp_users row for a child.
#      A count-before / count-after check reads directly from wp_users.
#
#   2. Student-level double-book (R-6) blocks generation when a student
#      already has a session that overlaps in UTC in a different group.
#
#   3. Family-level overlap (R-7) fires minhaj_family_overlap_warning
#      but does NOT block — a two-parent household is allowed to have
#      two screens.
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
  DELETE FROM wp_minhaj_person_audit;
  DELETE FROM wp_minhaj_guardianship;
  DELETE FROM wp_minhaj_students;
" >/dev/null

FAIL=0

echo
echo "${BOLD}== S-11 · create_student does NOT create a wp_users row for the child ==${RESET}"

STEP1_CODE=$(cat <<'PHP'
global $wpdb;
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

$guardian_id = wp_insert_user( [
    'user_login' => 'guardian_s11_' . uniqid(),
    'user_pass'  => wp_generate_password(),
    'role'       => 'minhaj_parent',
] );
if ( is_wp_error( $guardian_id ) ) { echo "guardian_failed:" . $guardian_id->get_error_code(); exit(1); }

$after_guardian = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

$svc = new \Minhaj\Modules\People\PeopleService( new \Minhaj\Modules\People\Repository\PeopleRepository() );
$student_id = $svc->create_student( 1, $guardian_id, [ 'first_name' => 'Sara', 'family_name_initial' => 'A' ] );
if ( is_wp_error( $student_id ) ) { echo "student_failed:" . $student_id->get_error_code(); exit(1); }

$after_student = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, first_name FROM wp_minhaj_students WHERE id = %d', $student_id ), ARRAY_A );

printf(
    "WP_USERS_BEFORE=%d WP_USERS_AFTER_GUARDIAN=%d WP_USERS_AFTER_STUDENT=%d STUDENT_ID=%d STUDENT_USER_ID=%s STUDENT_NAME=%s GUARDIAN_ID=%d\n",
    $before,
    $after_guardian,
    $after_student,
    (int) $row['id'],
    null === $row['user_id'] ? 'null' : (string) $row['user_id'],
    (string) $row['first_name'],
    $guardian_id
);
PHP
)

STEP1_OUT=$(run_wp eval "$STEP1_CODE" | tr -d '\r')
echo "  $STEP1_OUT"

BEFORE=$(printf '%s' "$STEP1_OUT" | grep -oE 'WP_USERS_BEFORE=[0-9]+' | cut -d= -f2)
AFTER_GUARDIAN=$(printf '%s' "$STEP1_OUT" | grep -oE 'WP_USERS_AFTER_GUARDIAN=[0-9]+' | cut -d= -f2)
AFTER_STUDENT=$(printf '%s' "$STEP1_OUT" | grep -oE 'WP_USERS_AFTER_STUDENT=[0-9]+' | cut -d= -f2)
STUDENT_ID_A=$(printf '%s' "$STEP1_OUT" | grep -oE 'STUDENT_ID=[0-9]+' | cut -d= -f2)
STUDENT_USER_ID=$(printf '%s' "$STEP1_OUT" | grep -oE 'STUDENT_USER_ID=[a-z0-9]+' | cut -d= -f2)
GUARDIAN_ID=$(printf '%s' "$STEP1_OUT" | grep -oE 'GUARDIAN_ID=[0-9]+' | cut -d= -f2)

# Guardian inserted → wp_users went up by exactly 1.
if [[ "$AFTER_GUARDIAN" == "$((BEFORE + 1))" ]]; then
  echo "  ${GREEN}✓ guardian inserted +1 in wp_users${RESET}"
else
  echo "  ${RED}✗ wp_users delta after guardian was $((AFTER_GUARDIAN - BEFORE)), expected 1${RESET}"
  FAIL=1
fi

# Student created → wp_users delta must be ZERO.
if [[ "$AFTER_STUDENT" == "$AFTER_GUARDIAN" ]]; then
  echo "  ${GREEN}✓ create_student did NOT create a wp_users row (delta=0) — decision 18${RESET}"
else
  echo "  ${RED}✗ wp_users delta after create_student was $((AFTER_STUDENT - AFTER_GUARDIAN)) — must be 0${RESET}"
  FAIL=1
fi

# students.user_id is NULL for the newly-created child.
if [[ "$STUDENT_USER_ID" == "null" ]]; then
  echo "  ${GREEN}✓ students.user_id IS NULL for the new child${RESET}"
else
  echo "  ${RED}✗ students.user_id = $STUDENT_USER_ID, expected null${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== Seed a second student (Bilal), a teacher, two groups, calendars ==${RESET}"

STEP2_CODE=$(cat <<PHP
\$svc = new \\Minhaj\\Modules\\People\\Repository\\PeopleRepository();

// Second sibling — same guardian.
global \$wpdb;
\$now = current_time( 'mysql', true );
\$wpdb->insert( 'wp_minhaj_students', [
    'user_id'             => null,
    'first_name'          => 'Bilal',
    'family_name_initial' => 'B',
    'created_at'          => \$now,
] );
\$student_b = (int) \$wpdb->insert_id;
\$wpdb->insert( 'wp_minhaj_guardianship', [
    'guardian_id'  => $GUARDIAN_ID,
    'student_id'   => \$student_b,
    'relationship' => 'parent',
    'is_primary'   => 1,
    'can_view'     => 1,
    'can_manage'   => 1,
    'started_at'   => \$now,
    'created_at'   => \$now,
] );

// Two DIFFERENT teachers, one per group. If both groups shared a teacher,
// R-5 (teacher double-book) would fire first and the R-6/R-7 rules we are
// trying to prove would never get a chance.
\$teacher_a = wp_insert_user( [ 'user_login' => 'teacher_a_' . uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'minhaj_teacher' ] );
\$teacher_b = wp_insert_user( [ 'user_login' => 'teacher_b_' . uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'minhaj_teacher' ] );

// Two groups. Group A: student A. Group B: student B (family case).
\$groups_svc = new \\Minhaj\\Modules\\Groups\\GroupService( new \\Minhaj\\Modules\\Groups\\Repository\\GroupRepository() );
\$g_a = \$groups_svc->create( 1, [ 'code' => 'DBOOK-A', 'type' => 'group', 'capacity_min' => 1, 'capacity_max' => 5, 'total_sessions' => 3, 'session_duration_minutes' => 60 ] );
\$g_b = \$groups_svc->create( 1, [ 'code' => 'DBOOK-B', 'type' => 'group', 'capacity_min' => 1, 'capacity_max' => 5, 'total_sessions' => 3, 'session_duration_minutes' => 60 ] );
if ( is_wp_error( \$g_a ) ) { echo "gA_failed:" . \$g_a->get_error_code(); exit(1); }
if ( is_wp_error( \$g_b ) ) { echo "gB_failed:" . \$g_b->get_error_code(); exit(1); }

\$wpdb->update( 'wp_minhaj_groups', [ 'teacher_id' => \$teacher_a ], [ 'id' => \$g_a ] );
\$wpdb->update( 'wp_minhaj_groups', [ 'teacher_id' => \$teacher_b ], [ 'id' => \$g_b ] );

// Capacity_min=1 → single member sufficient for the R-2 check downstream.
\$groups_svc->add_member( 1, \$g_a, $STUDENT_ID_A );
\$groups_svc->add_member( 1, \$g_b, \$student_b );

// C-2 ack so the calendar gate lets generation through.
\$cal_svc = new \\Minhaj\\Modules\\Calendar\\CalendarService( new \\Minhaj\\Modules\\Calendar\\Repository\\CalendarRepository() );
\$cal_svc->acknowledge_no_calendar( 1, \$g_a, 'test-no-calendar' );
\$cal_svc->acknowledge_no_calendar( 1, \$g_b, 'test-no-calendar' );

// Availability for Monday and Tuesday in UTC (matches our patterns).
\$tt = new \\Minhaj\\Modules\\Timetable\\TimetableService( new \\Minhaj\\Modules\\Timetable\\Repository\\TimetableRepository() );
foreach ( [ \$teacher_a, \$teacher_b ] as \$tid ) {
    foreach ( [ 1, 2 ] as \$wd ) {
        \$tt->set_availability( 1, \$tid, [ [
            'weekday' => \$wd, 'start_local' => '09:00', 'end_local' => '12:00',
            'timezone' => 'UTC', 'effective_from' => '2027-01-01', 'effective_to' => null,
        ] ] );
    }
}

printf( "TEACHER_A=%d TEACHER_B=%d GROUP_A=%d GROUP_B=%d STUDENT_B=%d\n", \$teacher_a, \$teacher_b, \$g_a, \$g_b, \$student_b );
PHP
)

STEP2_OUT=$(run_wp eval "$STEP2_CODE" | tr -d '\r')
echo "  $STEP2_OUT"
TEACHER_A=$(printf '%s' "$STEP2_OUT" | grep -oE 'TEACHER_A=[0-9]+' | cut -d= -f2)
TEACHER_B=$(printf '%s' "$STEP2_OUT" | grep -oE 'TEACHER_B=[0-9]+' | cut -d= -f2)
GROUP_A=$(printf '%s' "$STEP2_OUT" | grep -oE 'GROUP_A=[0-9]+' | cut -d= -f2)
GROUP_B=$(printf '%s' "$STEP2_OUT" | grep -oE 'GROUP_B=[0-9]+' | cut -d= -f2)
STUDENT_ID_B=$(printf '%s' "$STEP2_OUT" | grep -oE 'STUDENT_B=[0-9]+' | cut -d= -f2)

echo
echo "${BOLD}== Generate group A on Mondays 10:00 UTC ==${RESET}"

GEN_A_CODE=$(cat <<PHP
\$tt = new \\Minhaj\\Modules\\Timetable\\TimetableService( new \\Minhaj\\Modules\\Timetable\\Repository\\TimetableRepository() );
\$out = \$tt->generate_for_group( 1, $GROUP_A, [
    'anchor_timezone'  => 'UTC',
    'weekdays'         => [ 1 ],
    'start_local'      => '10:00',
    'duration_minutes' => 60,
    'weeks_count'      => 3,
    'first_week_start' => '2027-01-04',
] );
printf( "GEN_A=%s\n", is_wp_error( \$out ) ? ( 'err:' . \$out->get_error_code() ) : ( 'ok count=' . count( \$out ) ) );
PHP
)

GEN_A_OUT=$(run_wp eval "$GEN_A_CODE" | tr -d '\r')
echo "  $GEN_A_OUT"

GEN_A_RESULT=$(printf '%s' "$GEN_A_OUT" | grep -oE 'GEN_A=[a-z: _=0-9]+')
if [[ "$GEN_A_RESULT" == *"ok count=3"* ]]; then
  echo "  ${GREEN}✓ group A generated 3 sessions on Mondays${RESET}"
else
  echo "  ${RED}✗ group A generation failed: $GEN_A_RESULT${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== S-12 · move student A into group B and try to generate overlapping sessions ==${RESET}"

DBOOK_CODE=$(cat <<PHP
\$groups_svc = new \\Minhaj\\Modules\\Groups\\GroupService( new \\Minhaj\\Modules\\Groups\\Repository\\GroupRepository() );
\$add = \$groups_svc->add_member( 1, $GROUP_B, $STUDENT_ID_A );
if ( is_wp_error( \$add ) ) { echo "add_failed:" . \$add->get_error_code(); exit(1); }

\$tt = new \\Minhaj\\Modules\\Timetable\\TimetableService( new \\Minhaj\\Modules\\Timetable\\Repository\\TimetableRepository() );
\$out = \$tt->generate_for_group( 1, $GROUP_B, [
    'anchor_timezone'  => 'UTC',
    'weekdays'         => [ 1 ],                // same Mondays as group A
    'start_local'      => '10:30',              // overlaps the 10:00-11:00 window of group A
    'duration_minutes' => 60,
    'weeks_count'      => 3,
    'first_week_start' => '2027-01-04',
] );
printf( "DBOOK=%s\n", is_wp_error( \$out ) ? ( 'err:' . \$out->get_error_code() ) : 'ok' );
PHP
)

DBOOK_OUT=$(run_wp eval "$DBOOK_CODE" | tr -d '\r')
echo "  $DBOOK_OUT"

DBOOK_RESULT=$(printf '%s' "$DBOOK_OUT" | grep -oE 'DBOOK=[a-z:_]+' | cut -d= -f2)
if [[ "$DBOOK_RESULT" == "err:student_double_book" ]]; then
  echo "  ${GREEN}✓ generation refused with err:student_double_book — R-6 blocked the overlap${RESET}"
else
  echo "  ${RED}✗ generation returned $DBOOK_RESULT — expected err:student_double_book${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== S-13 · remove student A from group B, keep student B (family case), overlap Tuesday ==${RESET}"

FAMILY_CODE=$(cat <<PHP
// Remove student A from group B so the R-6 double-book no longer fires.
global \$wpdb;
\$row = \$wpdb->get_row( \$wpdb->prepare( "SELECT id FROM wp_minhaj_group_members WHERE group_id = %d AND student_id = %d AND status = 'active'", $GROUP_B, $STUDENT_ID_A ), ARRAY_A );
if ( \$row ) {
    \$svc = new \\Minhaj\\Modules\\Groups\\GroupService( new \\Minhaj\\Modules\\Groups\\Repository\\GroupRepository() );
    \$svc->remove_member( 1, (int) \$row['id'], 'test-family-case' );
}

// Add student A to group A on Tuesday too — no wait, we want overlap
// between SIBLINGS in DIFFERENT groups, not the same student.
//
// Setup: student A in group A on Tuesdays. Student B in group B on
// Tuesdays. Same guardian. Overlap → family warning.
\$tt = new \\Minhaj\\Modules\\Timetable\\TimetableService( new \\Minhaj\\Modules\\Timetable\\Repository\\TimetableRepository() );

// First: put group A on Tuesdays instead of Mondays. Re-generate is not
// available yet, so we simulate: delete existing sessions and generate
// afresh on Tuesdays.
\$wpdb->query( \$wpdb->prepare( 'DELETE FROM wp_minhaj_sessions WHERE group_id = %d', $GROUP_A ) );
\$wpdb->query( \$wpdb->prepare( 'DELETE FROM wp_minhaj_schedule_patterns WHERE group_id = %d', $GROUP_A ) );

\$ga = \$tt->generate_for_group( 1, $GROUP_A, [
    'anchor_timezone'  => 'UTC',
    'weekdays'         => [ 2 ],
    'start_local'      => '10:00',
    'duration_minutes' => 60,
    'weeks_count'      => 3,
    'first_week_start' => '2027-01-05',
] );
if ( is_wp_error( \$ga ) ) { echo "gA_regen_failed:" . \$ga->get_error_code(); exit(1); }

// Register a listener BEFORE generating group B so we can count the
// family-overlap warnings.
\$warnings = 0;
add_action( 'minhaj_family_overlap_warning', function () use ( &\$warnings ) {
    \$warnings++;
}, 10, 5 );

// Group B (student B, same guardian) at Tuesday 10:00 — overlap with
// group A. Same student is NOT in both groups so R-6 does not fire;
// R-7 warns and generation proceeds.
\$gb = \$tt->generate_for_group( 1, $GROUP_B, [
    'anchor_timezone'  => 'UTC',
    'weekdays'         => [ 2 ],
    'start_local'      => '10:00',
    'duration_minutes' => 60,
    'weeks_count'      => 3,
    'first_week_start' => '2027-01-05',
] );

printf( "FAMILY_GEN=%s WARNINGS=%d\n",
    is_wp_error( \$gb ) ? ( 'err:' . \$gb->get_error_code() ) : ( 'ok count=' . count( \$gb ) ),
    \$warnings
);
PHP
)

FAMILY_OUT=$(run_wp eval "$FAMILY_CODE" | tr -d '\r')
echo "  $FAMILY_OUT"

FAMILY_RESULT=$(printf '%s' "$FAMILY_OUT" | grep -oE 'FAMILY_GEN=[a-z: _=0-9]+')
WARNINGS=$(printf '%s' "$FAMILY_OUT" | grep -oE 'WARNINGS=[0-9]+' | cut -d= -f2)

if [[ "$FAMILY_RESULT" == *"ok count=3"* ]]; then
  echo "  ${GREEN}✓ family case does NOT block — group B still generated 3 sessions${RESET}"
else
  echo "  ${RED}✗ family case blocked generation: $FAMILY_RESULT${RESET}"
  FAIL=1
fi

if [[ "${WARNINGS:-0}" -gt 0 ]]; then
  echo "  ${GREEN}✓ minhaj_family_overlap_warning fired ${WARNINGS} time(s) — admin sees the collision${RESET}"
else
  echo "  ${RED}✗ family warning did not fire (WARNINGS=$WARNINGS) — the R-7 signal is silent${RESET}"
  FAIL=1
fi

echo
if [[ "$FAIL" != "0" ]]; then
  echo "${RED}${BOLD}STUDENTS DOUBLE-BOOK PROOF FAILED${RESET}"
  exit 1
fi

echo "${GREEN}${BOLD}STUDENTS DOUBLE-BOOK PROOF PASSED${RESET}"
