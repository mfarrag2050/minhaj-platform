#!/usr/bin/env bash
# Groups UI hardening — live-DB proof for the fixes surfaced by
# first + second human use of the admin form.
#
#   AC-1 · auto-generated group code (NL-B2609-A1-01)
#   AC-2 · retry-on-collision reserves the next slot from the counter
#   AC-3 · capacity_max > 5 refused pre-save without a reason
#   AC-4 · language with zero teacher coverage refused pre-save
#   AC-5 · unscheduled-makeups CLI catches a no_show session that
#          has no make-up row (post-commit listener drop scenario)
#   AC-6 · sequence NEVER reuses a released slot — create 3, delete
#          the third, create a fourth: fourth must be -04 not -03
#   AC-7 · level not in the curriculum is refused (invalid_level)
#   AC-8 · passing `code` to the service is refused (code_arg_not_allowed)

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

echo "${BOLD}== Reset relevant tables + seed one batch ==${RESET}"
run_wp db query "
  DELETE FROM wp_minhaj_group_audit;
  DELETE FROM wp_minhaj_group_members;
  DELETE FROM wp_minhaj_groups;
  DELETE FROM wp_minhaj_group_code_counters;
  DELETE FROM wp_minhaj_batches;
  DELETE FROM wp_minhaj_sessions;
  DELETE FROM wp_minhaj_schedule_patterns;
" >/dev/null

SEED_CODE=$(cat <<'PHP'
global $wpdb;
$now = current_time( 'mysql', true );
$wpdb->insert( 'wp_minhaj_batches', [
    'code'       => 'B2609',
    'org_id'     => null,
    'market'     => 'NL',
    'starts_on'  => '2026-09-01',
    'status'     => 'open',
    'created_at' => $now,
    'updated_at' => $now,
] );
printf( "BATCH=%d\n", (int) $wpdb->insert_id );
PHP
)
SEED_OUT=$(run_wp eval "$SEED_CODE" | tr -d '\r')
echo "  $SEED_OUT"
BATCH_ID=$(printf '%s' "$SEED_OUT" | grep -oE 'BATCH=[0-9]+' | cut -d= -f2)

echo
echo "${BOLD}== AC-1 · create three groups back-to-back and see NL-B2609-A1-{01,02,03} ==${RESET}"

CREATE_CODE=$(cat <<PHP
\$svc = new \\Minhaj\\Modules\\Groups\\GroupService( new \\Minhaj\\Modules\\Groups\\Repository\\GroupRepository() );

// The People module answers minhaj_group_teaching_language_coverage;
// there are no teachers in wp-env so answer 1 to bypass the S-8 gate
// for this AC (S-8 is exercised separately in AC-4 below).
add_filter( 'minhaj_group_teaching_language_coverage', fn() => 1 );

for ( \$i = 0; \$i < 3; \$i++ ) {
    \$id = \$svc->create( 1, [
        'type'              => 'group',
        'batch_id'          => $BATCH_ID,
        'level'             => 'A1',
        'teaching_language' => 'nl',
    ] );
    if ( is_wp_error( \$id ) ) { printf( "CREATE_%d=err:%s\n", \$i, \$id->get_error_code() ); continue; }

    global \$wpdb;
    \$code = (string) \$wpdb->get_var( \$wpdb->prepare( 'SELECT code FROM wp_minhaj_groups WHERE id = %d', \$id ) );
    printf( "CREATE_%d=%s\n", \$i, \$code );
}
PHP
)

CREATE_OUT=$(run_wp eval "$CREATE_CODE" | tr -d '\r')
echo "  $CREATE_OUT"

CODE_0=$(printf '%s' "$CREATE_OUT" | grep -oE 'CREATE_0=[A-Z0-9-]+' | cut -d= -f2)
CODE_1=$(printf '%s' "$CREATE_OUT" | grep -oE 'CREATE_1=[A-Z0-9-]+' | cut -d= -f2)
CODE_2=$(printf '%s' "$CREATE_OUT" | grep -oE 'CREATE_2=[A-Z0-9-]+' | cut -d= -f2)

if [[ "$CODE_0" == "NL-B2609-A1-01" ]] && [[ "$CODE_1" == "NL-B2609-A1-02" ]] && [[ "$CODE_2" == "NL-B2609-A1-03" ]]; then
  echo "  ${GREEN}✓ codes generated in sequence: $CODE_0, $CODE_1, $CODE_2${RESET}"
else
  echo "  ${RED}✗ codes: $CODE_0 / $CODE_1 / $CODE_2${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== AC-2 · retry-on-collision reserves the next slot from the counter ==${RESET}"

# Force attempt 0 to collide on an existing code. The retry has to
# reserve a FRESH seq from the counter (not spin on the same one).
# After AC-1 the counter is at 4 (three used → next_seq=4).
COLLISION_CODE=$(cat <<PHP
add_filter( 'minhaj_group_teaching_language_coverage', fn() => 1 );

\$svc = new \\Minhaj\\Modules\\Groups\\GroupService( new \\Minhaj\\Modules\\Groups\\Repository\\GroupRepository() );

// Pre-seed a group at seq 4 so the first counter reservation collides
// against uq_code. The persistent counter will bump on each attempt,
// so the retry reserves seq 5.
global \$wpdb;
\$now = current_time( 'mysql', true );
\$wpdb->insert( 'wp_minhaj_groups', [
    'code'         => 'NL-B2609-A1-04',
    'type'         => 'group',
    'status'       => 'draft',
    'batch_id'     => $BATCH_ID,
    'level'        => 'A1',
    'capacity_min' => 3,
    'capacity_max' => 5,
    'session_duration_minutes' => 60,
    'created_at'   => \$now,
    'updated_at'   => \$now,
] );

\$id = \$svc->create( 1, [
    'type'              => 'group',
    'batch_id'          => $BATCH_ID,
    'level'             => 'A1',
    'teaching_language' => 'nl',
] );
if ( is_wp_error( \$id ) ) { printf( "RESULT=err:%s\n", \$id->get_error_code() ); exit; }

\$code = (string) \$wpdb->get_var( \$wpdb->prepare( 'SELECT code FROM wp_minhaj_groups WHERE id = %d', \$id ) );
printf( "RESULT=%s\n", \$code );
PHP
)

COLLISION_OUT=$(run_wp eval "$COLLISION_CODE" | tr -d '\r')
echo "  $COLLISION_OUT"

COLLISION_CODE_OUT=$(printf '%s' "$COLLISION_OUT" | grep -oE 'RESULT=[A-Z0-9-]+' | cut -d= -f2)
if [[ "$COLLISION_CODE_OUT" == "NL-B2609-A1-05" ]]; then
  echo "  ${GREEN}✓ retry reserved next counter slot: $COLLISION_CODE_OUT${RESET}"
else
  echo "  ${RED}✗ retry returned $COLLISION_CODE_OUT (expected NL-B2609-A1-05)${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== AC-3 · capacity_max > 5 refused pre-save without a written reason ==${RESET}"

CAPACITY_CODE=$(cat <<PHP
add_filter( 'minhaj_group_teaching_language_coverage', fn() => 1 );

\$svc = new \\Minhaj\\Modules\\Groups\\GroupService( new \\Minhaj\\Modules\\Groups\\Repository\\GroupRepository() );

\$without = \$svc->create( 1, [
    'type'              => 'group',
    'batch_id'          => $BATCH_ID,
    'level'             => 'A2',
    'teaching_language' => 'nl',
    'capacity_min'      => 3,
    'capacity_max'      => 6,
] );
printf( "WITHOUT=%s\n", is_wp_error( \$without ) ? ( 'err:' . \$without->get_error_code() ) : 'ok:' . \$without );

\$with = \$svc->create( 1, [
    'type'                         => 'group',
    'batch_id'                     => $BATCH_ID,
    'level'                        => 'A2',
    'teaching_language'            => 'nl',
    'capacity_min'                 => 3,
    'capacity_max'                 => 6,
    'capacity_over_promise_reason' => 'Legal signed off',
] );
printf( "WITH=%s\n", is_wp_error( \$with ) ? ( 'err:' . \$with->get_error_code() ) : 'ok' );
PHP
)

CAPACITY_OUT=$(run_wp eval "$CAPACITY_CODE" | tr -d '\r')
echo "  $CAPACITY_OUT"

WITHOUT=$(printf '%s' "$CAPACITY_OUT" | grep -oE 'WITHOUT=[a-z:0-9_]+' | cut -d= -f2)
WITH=$(printf '%s' "$CAPACITY_OUT" | grep -oE 'WITH=[a-z:_0-9]+' | cut -d= -f2)

if [[ "$WITHOUT" == "err:capacity_over_promise" ]]; then
  echo "  ${GREEN}✓ capacity>5 without a reason refused with err:capacity_over_promise${RESET}"
else
  echo "  ${RED}✗ WITHOUT=$WITHOUT (expected err:capacity_over_promise)${RESET}"
  FAIL=1
fi

if [[ "$WITH" == "ok" ]]; then
  echo "  ${GREEN}✓ capacity>5 with a reason accepted${RESET}"
else
  echo "  ${RED}✗ WITH=$WITH (expected ok)${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== AC-4 · language with zero coverage refused pre-save ==${RESET}"

LANG_CODE=$(cat <<PHP
// Return 0 coverage — the People gate would return this on a locale with
// no active + valid-check + declared-language teacher.
add_filter( 'minhaj_group_teaching_language_coverage', fn( \$existing, \$locale ) => 'xh' === \$locale ? 0 : 1, 10, 2 );

\$svc = new \\Minhaj\\Modules\\Groups\\GroupService( new \\Minhaj\\Modules\\Groups\\Repository\\GroupRepository() );

\$out = \$svc->create( 1, [
    'type'              => 'group',
    'batch_id'          => $BATCH_ID,
    'level'             => 'A1',
    'teaching_language' => 'xh',
] );
printf( "LANG=%s\n", is_wp_error( \$out ) ? ( 'err:' . \$out->get_error_code() ) : 'ok' );

\$override = \$svc->create( 1, [
    'type'                                 => 'group',
    'batch_id'                             => $BATCH_ID,
    'level'                                => 'A1',
    'teaching_language'                    => 'xh',
    'language_coverage_override_reason'    => 'Piloting with external teacher',
] );
printf( "OVERRIDE=%s\n", is_wp_error( \$override ) ? ( 'err:' . \$override->get_error_code() ) : 'ok' );
PHP
)

LANG_OUT=$(run_wp eval "$LANG_CODE" | tr -d '\r')
echo "  $LANG_OUT"

LANG_RESULT=$(printf '%s' "$LANG_OUT" | grep -oE 'LANG=[a-z:_0-9]+' | cut -d= -f2)
OVERRIDE_RESULT=$(printf '%s' "$LANG_OUT" | grep -oE 'OVERRIDE=[a-z:_0-9]+' | cut -d= -f2)

if [[ "$LANG_RESULT" == "err:no_assignable_teacher_for_language" ]]; then
  echo "  ${GREEN}✓ zero-coverage locale refused with err:no_assignable_teacher_for_language${RESET}"
else
  echo "  ${RED}✗ LANG=$LANG_RESULT${RESET}"; FAIL=1
fi
if [[ "$OVERRIDE_RESULT" == "ok" ]]; then
  echo "  ${GREEN}✓ zero-coverage locale accepted with an override reason${RESET}"
else
  echo "  ${RED}✗ OVERRIDE=$OVERRIDE_RESULT${RESET}"; FAIL=1
fi

echo
echo "${BOLD}== AC-5 · unscheduled-makeups CLI catches a no_show session that has no make-up row ==${RESET}"

# Seed a no_show session, then bypass the listener by NOT calling the
# make-up path — write the row directly with status='no_show' and
# nothing pointing at it via makeup_for_id. The CLI should surface it.
GAP_CODE=$(cat <<'PHP'
global $wpdb;
$now = current_time( 'mysql', true );
$wpdb->insert( 'wp_minhaj_sessions', [
    'group_id'            => 42,
    'pattern_id'          => 42,
    'sequence_no'         => 1,
    'lesson_no'           => null,
    'scheduled_start_utc' => '2027-06-01 09:00:00',
    'scheduled_end_utc'   => '2027-06-01 10:00:00',
    'local_start_wall'    => '2027-06-01 09:00:00',
    'anchor_timezone'     => 'UTC',
    'teacher_id'          => 42,
    'status'              => 'no_show',
    'created_at'          => $now,
    'updated_at'          => $now,
] );
printf( "ORPHAN_SESSION=%d\n", (int) $wpdb->insert_id );
PHP
)

GAP_OUT=$(run_wp eval "$GAP_CODE" | tr -d '\r')
echo "  $GAP_OUT"
ORPHAN_SESSION=$(printf '%s' "$GAP_OUT" | grep -oE 'ORPHAN_SESSION=[0-9]+' | cut -d= -f2)

CLI_OUT=$(run_wp minhaj timetable unscheduled-makeups 2>&1 | tr -d '\r' || true)
echo "  --- CLI output ---"
printf '%s\n' "$CLI_OUT" | sed 's/^/  /'
echo "  ---"

if grep -q "no_show sessions with NO make-up row" <<<"$CLI_OUT" && grep -q "$ORPHAN_SESSION" <<<"$CLI_OUT"; then
  echo "  ${GREEN}✓ CLI reported the orphaned no_show session ($ORPHAN_SESSION)${RESET}"
else
  echo "  ${RED}✗ CLI did not surface the reconciliation gap${RESET}"; FAIL=1
fi

echo
echo "${BOLD}== AC-6 · sequence never reuses a released slot ==${RESET}"

# Fresh reset so this AC starts from an empty counter.
run_wp db query "
  DELETE FROM wp_minhaj_group_audit;
  DELETE FROM wp_minhaj_group_members;
  DELETE FROM wp_minhaj_groups;
  DELETE FROM wp_minhaj_group_code_counters;
  DELETE FROM wp_minhaj_batches;
" >/dev/null

SEED2=$(cat <<'PHP'
global $wpdb;
$now = current_time( 'mysql', true );
$wpdb->insert( 'wp_minhaj_batches', [
    'code'       => 'B2701',
    'org_id'     => null,
    'market'     => 'NL',
    'starts_on'  => '2027-01-01',
    'status'     => 'open',
    'created_at' => $now,
    'updated_at' => $now,
] );
printf( "BATCH=%d\n", (int) $wpdb->insert_id );
PHP
)
SEED2_OUT=$(run_wp eval "$SEED2" | tr -d '\r')
echo "  $SEED2_OUT"
BATCH2_ID=$(printf '%s' "$SEED2_OUT" | grep -oE 'BATCH=[0-9]+' | cut -d= -f2)

REUSE_CODE=$(cat <<PHP
add_filter( 'minhaj_group_teaching_language_coverage', fn() => 1 );

\$svc = new \\Minhaj\\Modules\\Groups\\GroupService( new \\Minhaj\\Modules\\Groups\\Repository\\GroupRepository() );
global \$wpdb;

\$ids = [];
for ( \$i = 0; \$i < 3; \$i++ ) {
    \$id = \$svc->create( 1, [
        'type'              => 'group',
        'batch_id'          => $BATCH2_ID,
        'level'             => 'B2',
        'teaching_language' => 'nl',
    ] );
    if ( is_wp_error( \$id ) ) { printf( "CREATE_%d=err:%s\n", \$i, \$id->get_error_code() ); exit; }
    \$ids[] = (int) \$id;
    \$code   = (string) \$wpdb->get_var( \$wpdb->prepare( 'SELECT code FROM wp_minhaj_groups WHERE id = %d', \$id ) );
    printf( "CREATE_%d=%s\n", \$i, \$code );
}

// Soft-delete the third (via deleted_at) AND hard-delete it (via DELETE)
// — both must NOT free the slot.
\$wpdb->query( \$wpdb->prepare( 'UPDATE wp_minhaj_groups SET deleted_at = %s WHERE id = %d', current_time( 'mysql', true ), \$ids[2] ) );
\$wpdb->query( \$wpdb->prepare( 'DELETE FROM wp_minhaj_groups WHERE id = %d', \$ids[2] ) );

// Create the fourth. Must be -04, not -03.
\$id4 = \$svc->create( 1, [
    'type'              => 'group',
    'batch_id'          => $BATCH2_ID,
    'level'             => 'B2',
    'teaching_language' => 'nl',
] );
if ( is_wp_error( \$id4 ) ) { printf( "FOURTH=err:%s\n", \$id4->get_error_code() ); exit; }
\$code4 = (string) \$wpdb->get_var( \$wpdb->prepare( 'SELECT code FROM wp_minhaj_groups WHERE id = %d', \$id4 ) );
printf( "FOURTH=%s\n", \$code4 );
PHP
)

REUSE_OUT=$(run_wp eval "$REUSE_CODE" | tr -d '\r')
echo "  $REUSE_OUT"

FOURTH=$(printf '%s' "$REUSE_OUT" | grep -oE 'FOURTH=[A-Za-z0-9:_-]+' | cut -d= -f2)
if [[ "$FOURTH" == "NL-B2701-B2-04" ]]; then
  echo "  ${GREEN}✓ deleted seq NOT reused — fourth group got $FOURTH${RESET}"
else
  echo "  ${RED}✗ FOURTH=$FOURTH (expected NL-B2701-B2-04)${RESET}"; FAIL=1
fi

echo
echo "${BOLD}== AC-7 · level not in the curriculum is refused ==${RESET}"

LEVEL_CODE=$(cat <<PHP
add_filter( 'minhaj_group_teaching_language_coverage', fn() => 1 );
\$svc = new \\Minhaj\\Modules\\Groups\\GroupService( new \\Minhaj\\Modules\\Groups\\Repository\\GroupRepository() );

\$bad = \$svc->create( 1, [
    'type'              => 'group',
    'batch_id'          => $BATCH2_ID,
    'level'             => 'ZZ',        // Not in curriculum 1 (A1..C2)
    'teaching_language' => 'nl',
] );
printf( "BAD=%s\n", is_wp_error( \$bad ) ? ( 'err:' . \$bad->get_error_code() ) : 'ok' );

\$good = \$svc->create( 1, [
    'type'              => 'group',
    'batch_id'          => $BATCH2_ID,
    'level'             => 'B1',        // Actual curriculum level
    'teaching_language' => 'nl',
] );
printf( "GOOD=%s\n", is_wp_error( \$good ) ? ( 'err:' . \$good->get_error_code() ) : 'ok' );
PHP
)

LEVEL_OUT=$(run_wp eval "$LEVEL_CODE" | tr -d '\r')
echo "  $LEVEL_OUT"

BAD_LEVEL=$(printf '%s' "$LEVEL_OUT" | grep -oE 'BAD=[a-z:_0-9]+' | cut -d= -f2)
GOOD_LEVEL=$(printf '%s' "$LEVEL_OUT" | grep -oE 'GOOD=[a-z:_0-9]+' | cut -d= -f2)

if [[ "$BAD_LEVEL" == "err:invalid_level" ]]; then
  echo "  ${GREEN}✓ level 'ZZ' refused with err:invalid_level${RESET}"
else
  echo "  ${RED}✗ BAD=$BAD_LEVEL (expected err:invalid_level)${RESET}"; FAIL=1
fi
if [[ "$GOOD_LEVEL" == "ok" ]]; then
  echo "  ${GREEN}✓ level 'B1' accepted (in curriculum)${RESET}"
else
  echo "  ${RED}✗ GOOD=$GOOD_LEVEL (expected ok)${RESET}"; FAIL=1
fi

echo
echo "${BOLD}== AC-8 · passing 'code' to the service is refused ==${RESET}"

CODE_CODE=$(cat <<PHP
add_filter( 'minhaj_group_teaching_language_coverage', fn() => 1 );
\$svc = new \\Minhaj\\Modules\\Groups\\GroupService( new \\Minhaj\\Modules\\Groups\\Repository\\GroupRepository() );

\$out = \$svc->create( 1, [
    'type'              => 'group',
    'batch_id'          => $BATCH2_ID,
    'level'             => 'B1',
    'teaching_language' => 'nl',
    'code'              => 'WHATEVER',
] );
printf( "CODE_ARG=%s\n", is_wp_error( \$out ) ? ( 'err:' . \$out->get_error_code() ) : 'ok' );

\$out2 = \$svc->create( 1, [
    'type'                 => 'group',
    'batch_id'             => $BATCH2_ID,
    'level'                => 'B1',
    'teaching_language'    => 'nl',
    'code_override_reason' => 'Because I said so',
] );
printf( "REASON_ARG=%s\n", is_wp_error( \$out2 ) ? ( 'err:' . \$out2->get_error_code() ) : 'ok' );
PHP
)

CODE_OUT=$(run_wp eval "$CODE_CODE" | tr -d '\r')
echo "  $CODE_OUT"

CODE_ARG=$(printf '%s' "$CODE_OUT" | grep -oE 'CODE_ARG=[a-z:_0-9]+' | cut -d= -f2)
REASON_ARG=$(printf '%s' "$CODE_OUT" | grep -oE 'REASON_ARG=[a-z:_0-9]+' | cut -d= -f2)

if [[ "$CODE_ARG" == "err:code_arg_not_allowed" ]]; then
  echo "  ${GREEN}✓ passing 'code' refused with err:code_arg_not_allowed${RESET}"
else
  echo "  ${RED}✗ CODE_ARG=$CODE_ARG (expected err:code_arg_not_allowed)${RESET}"; FAIL=1
fi
if [[ "$REASON_ARG" == "err:code_arg_not_allowed" ]]; then
  echo "  ${GREEN}✓ passing 'code_override_reason' also refused${RESET}"
else
  echo "  ${RED}✗ REASON_ARG=$REASON_ARG (expected err:code_arg_not_allowed)${RESET}"; FAIL=1
fi

echo
if [[ "$FAIL" != "0" ]]; then
  echo "${RED}${BOLD}GROUPS UI HARDENING PROOF FAILED${RESET}"
  exit 1
fi

echo "${GREEN}${BOLD}GROUPS UI HARDENING PROOF PASSED${RESET}"
