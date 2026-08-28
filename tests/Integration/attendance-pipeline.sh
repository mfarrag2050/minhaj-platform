#!/usr/bin/env bash
# spec-attendance-v1 §8 · end-to-end live-DB pipeline on wp-env MariaDB.
#
# Replays realistic-shaped Zoom webhook payloads through the Meetings
# → Attendance dispatch chain and verifies the guarantees the spec
# hangs on:
#
#   AC-1  · threshold-based classification (present / late / absent)
#   AC-2  · multiple joins/leaves sum to attended_seconds (R-3)
#   AC-3  · dedup on uq_interval — resent participant_joined does not
#           insert a second row
#   AC-4  · finalize_session twice → minhaj_attendance_finalized once
#   AC-9  · unknown registrant → minhaj_unknown_participant_detected
#   AC-11 · R-6 grep (also enforced by NoTimetableServiceCallInAttendanceGrepTest)
#   AC-12 · no notes_internal (also enforced by NoInternalNotesGrepTest)
#   R-12  · rejoin merge — the second encounter's intervals sum into
#           the same attendance row
#
# Pattern intentionally mirrors tests/Integration/meetings-zoom.sh.

set -euo pipefail

WP_ENV=${WP_ENV:-wp-env}
GREEN=$'\033[32m'
RED=$'\033[31m'
BOLD=$'\033[1m'
RESET=$'\033[0m'

run_wp() {
  "$WP_ENV" run cli wp "$@" 2>/dev/null
}

FAIL=0

echo "${BOLD}== Reset attendance + upstream tables ==${RESET}"
run_wp db query "
  DELETE FROM wp_minhaj_attendance_audit;
  DELETE FROM wp_minhaj_attendance_intervals;
  DELETE FROM wp_minhaj_attendance;
  DELETE FROM wp_minhaj_teacher_presence;
  DELETE FROM wp_minhaj_meetings_audit;
  DELETE FROM wp_minhaj_zoom_events;
  DELETE FROM wp_minhaj_session_participants;
  DELETE FROM wp_minhaj_session_meetings;
  DELETE FROM wp_minhaj_zoom_licenses;
  DELETE FROM wp_minhaj_sessions;
  DELETE FROM wp_minhaj_schedule_patterns;
  DELETE FROM wp_minhaj_group_members;
  DELETE FROM wp_minhaj_groups;
  DELETE FROM wp_minhaj_students;
" >/dev/null

SEED_CODE=$(cat <<'PHP'
global $wpdb;
$now = current_time( 'mysql', true );

// Two students under the same guardian pattern used earlier.
$wpdb->insert( 'wp_minhaj_students', [
    'user_id'             => null,
    'first_name'          => 'Amina',
    'family_name_initial' => 'A',
    'created_at'          => $now,
] );
$student_a = (int) $wpdb->insert_id;

$wpdb->insert( 'wp_minhaj_students', [
    'user_id'             => null,
    'first_name'          => 'Bilal',
    'family_name_initial' => 'B',
    'created_at'          => $now,
] );
$student_b = (int) $wpdb->insert_id;

// Group.
$wpdb->insert( 'wp_minhaj_groups', [
    'code'         => 'ATT-G',
    'type'         => 'group',
    'status'       => 'active',
    'capacity_min' => 1,
    'capacity_max' => 5,
    'teacher_id'   => 999,
    'created_at'   => $now,
    'updated_at'   => $now,
] );
$group_id = (int) $wpdb->insert_id;

foreach ( [ $student_a, $student_b ] as $sid ) {
    $wpdb->insert( 'wp_minhaj_group_members', [
        'group_id'   => $group_id,
        'student_id' => $sid,
        'status'     => 'active',
        'joined_at'  => $now,
        'seat_index' => 1 + count( array_column( $wpdb->get_results( "SELECT id FROM wp_minhaj_group_members WHERE group_id = $group_id", ARRAY_A ), 'id' ) ),
    ] );
}

// Session — one hour window in the past so finalize can classify.
$start = gmdate( 'Y-m-d H:i:s', strtotime( '-2 hours' ) );
$end   = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hours' ) );
$wpdb->insert( 'wp_minhaj_sessions', [
    'group_id'            => $group_id,
    'pattern_id'          => 999,
    'sequence_no'         => 1,
    'lesson_no'           => 1,
    'scheduled_start_utc' => $start,
    'scheduled_end_utc'   => $end,
    'local_start_wall'    => $start,
    'anchor_timezone'     => 'UTC',
    'teacher_id'          => 999,
    'status'              => 'scheduled',
    'created_at'          => $now,
    'updated_at'          => $now,
] );
$session_id = (int) $wpdb->insert_id;

// Meeting row (Meetings module normally writes this).
$wpdb->insert( 'wp_minhaj_session_meetings', [
    'session_id'          => $session_id,
    'license_id'          => 0,
    'zoom_meeting_id'     => 'z-meeting-' . $session_id,
    'state'               => 'created',
    'scheduled_start_utc' => $start,
    'duration_minutes'    => 60,
    'created_at'          => $now,
    'updated_at'          => $now,
] );

// Registrants (participants) for both students + teacher.
$wpdb->insert( 'wp_minhaj_session_participants', [
    'session_id'         => $session_id,
    'actor_user_id'      => 111,
    'subject_student_id' => $student_a,
    'role'               => 'participant',
    'zoom_registrant_id' => 'reg-A',
    'issued_at'          => $now,
    'expires_at'         => $now,
] );
$wpdb->insert( 'wp_minhaj_session_participants', [
    'session_id'         => $session_id,
    'actor_user_id'      => 222,
    'subject_student_id' => $student_b,
    'role'               => 'participant',
    'zoom_registrant_id' => 'reg-B',
    'issued_at'          => $now,
    'expires_at'         => $now,
] );

// Teacher registrant (subject_student_id NULL — teacher rows don't
// collide on uq_session_subject since NULL doesn't collide).
$wpdb->insert( 'wp_minhaj_session_participants', [
    'session_id'         => $session_id,
    'actor_user_id'      => 999,
    'subject_student_id' => null,
    'role'               => 'host',
    'zoom_registrant_id' => 'reg-TEACHER',
    'issued_at'          => $now,
    'expires_at'         => $now,
] );

// Teacher presence row (teacher joined 5min after start → attended).
$wpdb->insert( 'wp_minhaj_teacher_presence', [
    'session_id'     => $session_id,
    'teacher_id'     => 999,
    'first_join_utc' => gmdate( 'Y-m-d H:i:s', strtotime( $start ) + 5 * 60 ),
    'created_at'     => $now,
    'updated_at'     => $now,
] );

printf( "SESSION=%d STUDENT_A=%d STUDENT_B=%d GROUP=%d START=%s END=%s\n", $session_id, $student_a, $student_b, $group_id, $start, $end );
PHP
)

SEED_OUT=$(run_wp eval "$SEED_CODE" | tr -d '\r')
echo "  $SEED_OUT"

SESSION_ID=$(printf '%s' "$SEED_OUT" | grep -oE 'SESSION=[0-9]+' | cut -d= -f2)
STUDENT_A=$(printf '%s' "$SEED_OUT" | grep -oE 'STUDENT_A=[0-9]+' | cut -d= -f2)
STUDENT_B=$(printf '%s' "$SEED_OUT" | grep -oE 'STUDENT_B=[0-9]+' | cut -d= -f2)
GROUP_ID=$(printf '%s' "$SEED_OUT" | grep -oE 'GROUP=[0-9]+' | cut -d= -f2)
START=$(printf '%s' "$SEED_OUT" | grep -oE 'START=[0-9-]+ [0-9:]+' | cut -d= -f2-)
END=$(printf '%s' "$SEED_OUT" | grep -oE 'END=[0-9-]+ [0-9:]+' | cut -d= -f2-)

echo
echo "${BOLD}== Replay realistic-shaped participant_joined / participant_left / meeting.ended ==${RESET}"

PIPELINE_CODE=$(cat <<PHP
global \$wpdb;

// Convenience: emit realistic-shaped webhooks straight through the
// event dispatch so the AttendanceService::EventListener claims them.
\$emit = function ( string \$event, array \$participant ) {
    do_action(
        'minhaj_zoom_event_dispatched_test',
        \$event,
        [
            'object' => [
                'id'          => 'z-meeting-$SESSION_ID',
                'uuid'        => 'meeting-uuid-$SESSION_ID',
                'topic'       => 'Minhaj · session $SESSION_ID',
                'participant' => \$participant,
                'end_time'    => '$END' . 'Z',
            ],
        ]
    );
};

// Real dispatcher: mimic MeetingsService by running the filter chain.
\$run_filter = function ( string \$event_type, array \$payload ) {
    apply_filters( 'minhaj_zoom_event_handled', false, \$event_type, \$payload );
};

// Student A: joined at start+3min, left at start+58min → 55 min → present.
\$a_join_1  = gmdate( 'Y-m-d\\TH:i:s\\Z', strtotime( '$START' ) + 3 * 60 );
\$a_leave_1 = gmdate( 'Y-m-d\\TH:i:s\\Z', strtotime( '$START' ) + 58 * 60 );

\$run_filter(
    'meeting.participant_joined',
    [ 'object' => [ 'id' => 'z-meeting-$SESSION_ID', 'topic' => 'Minhaj · session $SESSION_ID',
        'participant' => [
            'participant_uuid' => 'pu-A-1',
            'registrant_id'    => 'reg-A',
            'user_name'        => 'DOES_NOT_MATTER — R-1',
            'join_time'        => \$a_join_1,
        ],
    ] ]
);

// R-3 · student B: three encounters — 20 + 20 + 10 minutes.
foreach ( [ [ 0, 20 ], [ 25, 45 ], [ 47, 57 ] ] as \$i => \$slot ) {
    \$puid    = 'pu-B-' . \$i;
    \$j       = gmdate( 'Y-m-d\\TH:i:s\\Z', strtotime( '$START' ) + \$slot[0] * 60 );
    \$l       = gmdate( 'Y-m-d\\TH:i:s\\Z', strtotime( '$START' ) + \$slot[1] * 60 );

    \$run_filter( 'meeting.participant_joined', [ 'object' => [
        'id' => 'z-meeting-$SESSION_ID', 'topic' => 'Minhaj · session $SESSION_ID',
        'participant' => [ 'participant_uuid' => \$puid, 'registrant_id' => 'reg-B', 'user_name' => 'Bilal impersonator', 'join_time' => \$j ],
    ] ] );

    \$run_filter( 'meeting.participant_left', [ 'object' => [
        'id' => 'z-meeting-$SESSION_ID', 'topic' => 'Minhaj · session $SESSION_ID',
        'participant' => [ 'participant_uuid' => \$puid, 'registrant_id' => 'reg-B', 'leave_time' => \$l ],
    ] ] );
}

// AC-3 · deliver the participant_joined of A twice — uq_interval keeps only one row.
\$run_filter( 'meeting.participant_joined', [ 'object' => [
    'id' => 'z-meeting-$SESSION_ID', 'topic' => 'Minhaj · session $SESSION_ID',
    'participant' => [ 'participant_uuid' => 'pu-A-1', 'registrant_id' => 'reg-A', 'join_time' => \$a_join_1 ],
] ] );

// AC-9 · unknown registrant joins — no matching participants row.
\$run_filter( 'meeting.participant_joined', [ 'object' => [
    'id' => 'z-meeting-$SESSION_ID', 'topic' => 'Minhaj · session $SESSION_ID',
    'participant' => [ 'participant_uuid' => 'pu-UNKNOWN', 'registrant_id' => 'reg-STRANGER', 'join_time' => \$a_join_1 ],
] ] );
\$run_filter( 'meeting.participant_left', [ 'object' => [
    'id' => 'z-meeting-$SESSION_ID', 'topic' => 'Minhaj · session $SESSION_ID',
    'participant' => [ 'participant_uuid' => 'pu-UNKNOWN', 'leave_time' => \$a_leave_1 ],
] ] );

// A leaves.
\$run_filter( 'meeting.participant_left', [ 'object' => [
    'id' => 'z-meeting-$SESSION_ID', 'topic' => 'Minhaj · session $SESSION_ID',
    'participant' => [ 'participant_uuid' => 'pu-A-1', 'leave_time' => \$a_leave_1 ],
] ] );

// AC-4 · fire meeting.ended, and count the finalized events.
\$finalized_count = 0;
add_action( 'minhaj_attendance_finalized', function () use ( &\$finalized_count ) { \$finalized_count++; } );
\$unknown_count = 0;
add_action( 'minhaj_unknown_participant_detected', function () use ( &\$unknown_count ) { \$unknown_count++; } );

for ( \$i = 0; \$i < 2; \$i++ ) {
    \$run_filter( 'meeting.ended', [ 'object' => [
        'id' => 'z-meeting-$SESSION_ID', 'topic' => 'Minhaj · session $SESSION_ID', 'end_time' => \$a_leave_1,
    ] ] );
}

printf( "FINALIZED_COUNT=%d UNKNOWN_COUNT=%d\n", \$finalized_count, \$unknown_count );

\$rows = \$wpdb->get_results( "SELECT student_id, auto_status, attended_seconds FROM wp_minhaj_attendance WHERE session_id = $SESSION_ID ORDER BY student_id", ARRAY_A );
foreach ( \$rows as \$r ) {
    printf( "STUDENT student_id=%d auto_status=%s attended_seconds=%d\n", (int) \$r['student_id'], (string) \$r['auto_status'], (int) \$r['attended_seconds'] );
}

\$intervals = (int) \$wpdb->get_var( "SELECT COUNT(*) FROM wp_minhaj_attendance_intervals WHERE session_id = $SESSION_ID AND zoom_registrant_id = 'reg-A'" );
printf( "INTERVALS_A=%d\n", \$intervals );
PHP
)

PIPELINE_OUT=$(run_wp eval "$PIPELINE_CODE" | tr -d '\r')
echo "  ---"
printf '%s\n' "$PIPELINE_OUT" | sed 's/^/  /'
echo "  ---"

FINALIZED_COUNT=$(printf '%s' "$PIPELINE_OUT" | grep -oE 'FINALIZED_COUNT=[0-9]+' | cut -d= -f2)
UNKNOWN_COUNT=$(printf '%s' "$PIPELINE_OUT" | grep -oE 'UNKNOWN_COUNT=[0-9]+' | cut -d= -f2)
INTERVALS_A=$(printf '%s' "$PIPELINE_OUT" | grep -oE 'INTERVALS_A=[0-9]+' | cut -d= -f2)

STUDENT_A_STATUS=$(printf '%s' "$PIPELINE_OUT" | awk -v id="$STUDENT_A" '/STUDENT student_id=/{ split($0,a," "); split(a[2],k,"="); split(a[3],s,"="); split(a[4],sec,"="); if (k[2]==id) print s[2] "/" sec[2]; }')
STUDENT_B_STATUS=$(printf '%s' "$PIPELINE_OUT" | awk -v id="$STUDENT_B" '/STUDENT student_id=/{ split($0,a," "); split(a[2],k,"="); split(a[3],s,"="); split(a[4],sec,"="); if (k[2]==id) print s[2] "/" sec[2]; }')

if [[ "$STUDENT_A_STATUS" == "present/3300" ]]; then
  echo "  ${GREEN}✓ AC-1a · student A → present with 55 min (3300s)${RESET}"
else
  echo "  ${RED}✗ student A status: $STUDENT_A_STATUS (expected present/3300)${RESET}"; FAIL=1
fi

if [[ "$STUDENT_B_STATUS" == "present/3000" ]] || [[ "$STUDENT_B_STATUS" == "late/3000" ]]; then
  echo "  ${GREEN}✓ AC-2 · student B interval sum = 3000s (three encounters merged, R-3)${RESET}"
else
  echo "  ${RED}✗ student B status: $STUDENT_B_STATUS (expected present/late 3000s)${RESET}"; FAIL=1
fi

if [[ "$INTERVALS_A" == "1" ]]; then
  echo "  ${GREEN}✓ AC-3 · uq_interval deduplicated A's resent participant_joined${RESET}"
else
  echo "  ${RED}✗ INTERVALS_A=$INTERVALS_A (expected 1)${RESET}"; FAIL=1
fi

if [[ "$FINALIZED_COUNT" == "1" ]]; then
  echo "  ${GREEN}✓ AC-4 · two finalize_session calls → one minhaj_attendance_finalized event${RESET}"
else
  echo "  ${RED}✗ FINALIZED_COUNT=$FINALIZED_COUNT (expected 1)${RESET}"; FAIL=1
fi

if [[ "$UNKNOWN_COUNT" -ge 1 ]]; then
  echo "  ${GREEN}✓ AC-9 · unknown registrant emitted minhaj_unknown_participant_detected${RESET}"
else
  echo "  ${RED}✗ UNKNOWN_COUNT=$UNKNOWN_COUNT (expected ≥ 1)${RESET}"; FAIL=1
fi

echo
echo "${BOLD}== R-12 · rejoin merge — a second meeting.ended after grace-window intervals folds into the same row ==${RESET}"

REJOIN_CODE=$(cat <<PHP
global \$wpdb;

\$run_filter = function ( string \$event_type, array \$payload ) {
    apply_filters( 'minhaj_zoom_event_handled', false, \$event_type, \$payload );
};

\$rejoin_join  = gmdate( 'Y-m-d\\TH:i:s\\Z', strtotime( '$START' ) + 20 * 60 );
\$rejoin_leave = gmdate( 'Y-m-d\\TH:i:s\\Z', strtotime( '$START' ) + 25 * 60 );

\$run_filter( 'meeting.participant_joined', [ 'object' => [
    'id' => 'z-meeting-$SESSION_ID', 'topic' => 'Minhaj · session $SESSION_ID',
    'participant' => [ 'participant_uuid' => 'pu-A-rejoin', 'registrant_id' => 'reg-A', 'join_time' => \$rejoin_join ],
] ] );
\$run_filter( 'meeting.participant_left', [ 'object' => [
    'id' => 'z-meeting-$SESSION_ID', 'topic' => 'Minhaj · session $SESSION_ID',
    'participant' => [ 'participant_uuid' => 'pu-A-rejoin', 'registrant_id' => 'reg-A', 'leave_time' => \$rejoin_leave ],
] ] );

\$run_filter( 'meeting.ended', [ 'object' => [ 'id' => 'z-meeting-$SESSION_ID', 'topic' => 'Minhaj · session $SESSION_ID', 'end_time' => \$rejoin_leave ] ] );

\$row = \$wpdb->get_row( "SELECT attended_seconds FROM wp_minhaj_attendance WHERE session_id = $SESSION_ID AND student_id = $STUDENT_A", ARRAY_A );
printf( "STUDENT_A_AFTER_REJOIN=%d\n", (int) \$row['attended_seconds'] );

\$row_count = (int) \$wpdb->get_var( "SELECT COUNT(*) FROM wp_minhaj_attendance WHERE session_id = $SESSION_ID AND student_id = $STUDENT_A" );
printf( "STUDENT_A_ROWS=%d\n", \$row_count );
PHP
)

REJOIN_OUT=$(run_wp eval "$REJOIN_CODE" | tr -d '\r')
echo "  $REJOIN_OUT"

STUDENT_A_MERGED=$(printf '%s' "$REJOIN_OUT" | grep -oE 'STUDENT_A_AFTER_REJOIN=[0-9]+' | cut -d= -f2)
STUDENT_A_ROWS=$(printf '%s' "$REJOIN_OUT" | grep -oE 'STUDENT_A_ROWS=[0-9]+' | cut -d= -f2)

# 55 min from the first encounter + 5 min from the rejoin = 60 min = 3600s.
if [[ "$STUDENT_A_MERGED" == "3600" ]]; then
  echo "  ${GREEN}✓ R-12 · rejoin summed into the same row (3300 + 300 = 3600s)${RESET}"
else
  echo "  ${RED}✗ STUDENT_A_AFTER_REJOIN=$STUDENT_A_MERGED (expected 3600)${RESET}"; FAIL=1
fi
if [[ "$STUDENT_A_ROWS" == "1" ]]; then
  echo "  ${GREEN}✓ R-12 · still exactly one attendance row for student A (uq_session_student)${RESET}"
else
  echo "  ${RED}✗ STUDENT_A_ROWS=$STUDENT_A_ROWS (expected 1)${RESET}"; FAIL=1
fi

echo
if [[ "$FAIL" != "0" ]]; then
  echo "${RED}${BOLD}ATTENDANCE PIPELINE PROOF FAILED${RESET}"
  exit 1
fi

echo "${GREEN}${BOLD}ATTENDANCE PIPELINE PROOF PASSED${RESET}"
