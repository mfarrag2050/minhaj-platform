#!/usr/bin/env bash
# spec-zoom-sessions-v1 §8 live-DB acceptance:
#
#   AC-1  create-flow yields one meeting per session (uq_session)
#   AC-2  raw INSERT of a second host row is rejected by uq_session_host
#   AC-3  bad-signature webhook → 401; good-signature → 200 + one row
#   AC-4  same event delivered three times → one row (uq_dedup)
#   AC-6  concurrency cap fires as RuleViolationException on the fifth slot
#   AC-11 grep repo for start_url / join_url in insert/update queries
#
# Uses FakeZoomClient via the `minhaj_zoom_client` filter so no real Zoom
# account is required. The DB constraints are the point of the test —
# those are enforced by MariaDB regardless of the client.

set -euo pipefail

WP_ENV=${WP_ENV:-wp-env}
GREEN=$'\033[32m'
RED=$'\033[31m'
BOLD=$'\033[1m'
RESET=$'\033[0m'

run_wp() {
  "$WP_ENV" run cli wp "$@" 2>/dev/null
}

# For MINHAJ_ZOOM_WEBHOOK_SECRET the webhook verifier reads the constant.
# We inject it via wp-cli --define once per invocation.
WEBHOOK_SECRET="test-webhook-secret-$(date +%s)"

FAIL=0

echo "${BOLD}== Reset meetings tables + seed one active license + one session ==${RESET}"
run_wp db query "
  DELETE FROM wp_minhaj_meetings_audit;
  DELETE FROM wp_minhaj_zoom_events;
  DELETE FROM wp_minhaj_session_participants;
  DELETE FROM wp_minhaj_session_meetings;
  DELETE FROM wp_minhaj_zoom_licenses;
  DELETE FROM wp_minhaj_sessions;
  DELETE FROM wp_minhaj_schedule_patterns;
  DELETE FROM wp_minhaj_teacher_availability;
  DELETE FROM wp_minhaj_group_members;
  DELETE FROM wp_minhaj_groups;
  DELETE FROM wp_minhaj_students;
  DELETE FROM wp_minhaj_guardianship;
" >/dev/null

SEED_CODE=$(cat <<'PHP'
global $wpdb;
$now = current_time( 'mysql', true );

// Active Zoom license.
$wpdb->insert( 'wp_minhaj_zoom_licenses', [
    'zoom_user_id'        => 'u-host-' . uniqid(),
    'email'               => 'host@example.com',
    'concurrent_capacity' => 2,
    'status'              => 'active',
    'created_at'          => $now,
    'updated_at'          => $now,
] );

// Session in the future.
$wpdb->insert( 'wp_minhaj_sessions', [
    'group_id'            => 999,
    'pattern_id'          => 999,
    'sequence_no'         => 1,
    'lesson_no'           => 1,
    'scheduled_start_utc' => '2027-06-01 10:00:00',
    'scheduled_end_utc'   => '2027-06-01 11:00:00',
    'local_start_wall'    => '2027-06-01 10:00:00',
    'anchor_timezone'     => 'UTC',
    'teacher_id'          => 42,
    'status'              => 'scheduled',
    'created_at'          => $now,
    'updated_at'          => $now,
] );

printf( "SESSION=%d\n", (int) $wpdb->insert_id );
PHP
)

SEED_OUT=$(run_wp eval "$SEED_CODE" | tr -d '\r')
echo "  $SEED_OUT"
SESSION_ID=$(printf '%s' "$SEED_OUT" | grep -oE 'SESSION=[0-9]+' | cut -d= -f2)

echo
echo "${BOLD}== AC-1 · create meeting for session; second call must not duplicate ==${RESET}"

CREATE_CODE=$(cat <<PHP
add_filter( 'minhaj_zoom_client', fn() => new \\Minhaj\\Modules\\Meetings\\Zoom\\FakeZoomClient() );

\$repo = new \\Minhaj\\Modules\\Meetings\\Repository\\MeetingsRepository();
\$zoom = new \\Minhaj\\Modules\\Meetings\\Zoom\\FakeZoomClient();
\$access = new \\Minhaj\\Access\\AccessPolicy( new \\Minhaj\\Access\\AccessRepository() );
\$svc  = new \\Minhaj\\Modules\\Meetings\\MeetingsService( \$repo, \$zoom, \$access );

\$m1 = \$svc->create_meeting_for_session( 1, $SESSION_ID );
\$m2 = \$svc->create_meeting_for_session( 1, $SESSION_ID );

printf( "M1=%s M2=%s\n", is_wp_error( \$m1 ) ? ( 'err:' . \$m1->get_error_code() ) : \$m1, is_wp_error( \$m2 ) ? ( 'err:' . \$m2->get_error_code() ) : \$m2 );

global \$wpdb;
\$count = (int) \$wpdb->get_var( "SELECT COUNT(*) FROM wp_minhaj_session_meetings WHERE session_id = $SESSION_ID" );
printf( "MEETINGS_COUNT=%d\n", \$count );
PHP
)

CREATE_OUT=$(run_wp eval "$CREATE_CODE" | tr -d '\r')
echo "  $CREATE_OUT"

MEETINGS_COUNT=$(printf '%s' "$CREATE_OUT" | grep -oE 'MEETINGS_COUNT=[0-9]+' | cut -d= -f2)
if [[ "$MEETINGS_COUNT" == "1" ]]; then
  echo "  ${GREEN}✓ exactly one meeting row for the session (uq_session enforced)${RESET}"
else
  echo "  ${RED}✗ MEETINGS_COUNT=$MEETINGS_COUNT — expected 1${RESET}"
  FAIL=1
fi

# Grab meeting_id for later.
MEETING_ID=$(printf '%s' "$CREATE_OUT" | grep -oE 'M1=[0-9]+' | cut -d= -f2)

echo
echo "${BOLD}== AC-2 · a second host row for the same session must be rejected by the DB ==${RESET}"

HOST_DUP_CODE=$(cat <<PHP
global \$wpdb;

\$now = current_time( 'mysql', true );
\$expires = current_time( 'mysql', true );

// First host row — legal.
\$wpdb->insert( 'wp_minhaj_session_participants', [
    'session_id'    => $SESSION_ID,
    'actor_user_id' => 42,
    'role'          => 'host',
    'issued_at'     => \$now,
    'expires_at'    => \$expires,
] );

\$first_ok = \$wpdb->last_error;

// Second host row — must be refused by uq_session_host on the STORED
// active_host_flag column.
\$wpdb->suppress_errors( true );
\$second = \$wpdb->insert( 'wp_minhaj_session_participants', [
    'session_id'    => $SESSION_ID,
    'actor_user_id' => 43,
    'role'          => 'host',
    'issued_at'     => \$now,
    'expires_at'    => \$expires,
] );
\$err = (string) \$wpdb->last_error;
\$wpdb->suppress_errors( false );

printf( "FIRST_ERR=[%s] SECOND=%s SECOND_ERR=[%s]\n", \$first_ok, false === \$second ? 'refused' : 'accepted', \$err );
PHP
)

HOST_DUP_OUT=$(run_wp eval "$HOST_DUP_CODE" | tr -d '\r')
echo "  $HOST_DUP_OUT"

if grep -q "SECOND=refused" <<<"$HOST_DUP_OUT" && grep -q "uq_session_host" <<<"$HOST_DUP_OUT"; then
  echo "  ${GREEN}✓ second host row refused by uq_session_host (STORED generated column)${RESET}"
else
  echo "  ${RED}✗ second host row was not refused by the DB${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== AC-3 · unsigned webhook → 401; signed → 200 + one event row ==${RESET}"

WEBHOOK_CODE=$(cat <<PHP
if ( ! defined( 'MINHAJ_ZOOM_WEBHOOK_SECRET' ) ) {
    define( 'MINHAJ_ZOOM_WEBHOOK_SECRET', '$WEBHOOK_SECRET' );
}

\$verifier = \\Minhaj\\Modules\\Meetings\\Zoom\\WebhookVerifier::from_config();
\$svc      = new \\Minhaj\\Modules\\Meetings\\MeetingsService(
    new \\Minhaj\\Modules\\Meetings\\Repository\\MeetingsRepository(),
    new \\Minhaj\\Modules\\Meetings\\Zoom\\FakeZoomClient(),
    new \\Minhaj\\Access\\AccessPolicy( new \\Minhaj\\Access\\AccessRepository() )
);
\$controller = new \\Minhaj\\Modules\\Meetings\\Rest\\WebhookController( \$svc, \$verifier );

\$body = json_encode( [
    'event'    => 'meeting.started',
    'event_ts' => time(),
    'payload'  => [
        'object' => [ 'id' => 'm-test-1', 'uuid' => 'uuid-test-1' ],
    ],
] );

// Bad signature attempt.
\$req_bad = new \\WP_REST_Request( 'POST', '/minhaj/v1/zoom/webhook' );
\$req_bad->set_body( \$body );
\$req_bad->set_headers( [
    'x-zm-signature'         => 'v0=bogus',
    'x-zm-request-timestamp' => (string) time(),
] );
printf( "BAD_ALLOWED=%s\n", \$controller->permission_callback( \$req_bad ) ? 'yes' : 'no' );

// Good signature.
\$ts   = time();
\$sig  = 'v0=' . hash_hmac( 'sha256', 'v0:' . \$ts . ':' . \$body, MINHAJ_ZOOM_WEBHOOK_SECRET );
\$req  = new \\WP_REST_Request( 'POST', '/minhaj/v1/zoom/webhook' );
\$req->set_body( \$body );
\$req->set_headers( [
    'x-zm-signature'         => \$sig,
    'x-zm-request-timestamp' => (string) \$ts,
] );

printf( "GOOD_ALLOWED=%s\n", \$controller->permission_callback( \$req ) ? 'yes' : 'no' );

\$resp = \$controller->handle( \$req );
printf( "STATUS=%d\n", \$resp->get_status() );

global \$wpdb;
\$rows = (int) \$wpdb->get_var( "SELECT COUNT(*) FROM wp_minhaj_zoom_events" );
printf( "EVENTS_ROWS=%d\n", \$rows );
PHP
)

WEBHOOK_OUT=$(run_wp eval "$WEBHOOK_CODE" | tr -d '\r')
echo "  $WEBHOOK_OUT"

BAD_ALLOWED=$(printf '%s' "$WEBHOOK_OUT" | grep -oE 'BAD_ALLOWED=[a-z]+' | cut -d= -f2)
GOOD_ALLOWED=$(printf '%s' "$WEBHOOK_OUT" | grep -oE 'GOOD_ALLOWED=[a-z]+' | cut -d= -f2)
STATUS=$(printf '%s' "$WEBHOOK_OUT" | grep -oE 'STATUS=[0-9]+' | cut -d= -f2)
EVENTS_ROWS=$(printf '%s' "$WEBHOOK_OUT" | grep -oE 'EVENTS_ROWS=[0-9]+' | cut -d= -f2)

if [[ "$BAD_ALLOWED" == "no" ]]; then
  echo "  ${GREEN}✓ bad signature → permission_callback returned false (would 401)${RESET}"
else
  echo "  ${RED}✗ bad signature was accepted${RESET}"; FAIL=1
fi
if [[ "$GOOD_ALLOWED" == "yes" ]] && [[ "$STATUS" == "200" ]]; then
  echo "  ${GREEN}✓ good signature → 200 with event row inserted${RESET}"
else
  echo "  ${RED}✗ good signature returned $STATUS or was refused${RESET}"; FAIL=1
fi
if [[ "$EVENTS_ROWS" == "1" ]]; then
  echo "  ${GREEN}✓ one event row in the DB${RESET}"
else
  echo "  ${RED}✗ EVENTS_ROWS=$EVENTS_ROWS${RESET}"; FAIL=1
fi

echo
echo "${BOLD}== AC-4 · deliver the same event three more times → still one row (uq_dedup) ==${RESET}"

DEDUP_CODE=$(cat <<PHP
if ( ! defined( 'MINHAJ_ZOOM_WEBHOOK_SECRET' ) ) {
    define( 'MINHAJ_ZOOM_WEBHOOK_SECRET', '$WEBHOOK_SECRET' );
}

\$verifier = \\Minhaj\\Modules\\Meetings\\Zoom\\WebhookVerifier::from_config();
\$svc      = new \\Minhaj\\Modules\\Meetings\\MeetingsService(
    new \\Minhaj\\Modules\\Meetings\\Repository\\MeetingsRepository(),
    new \\Minhaj\\Modules\\Meetings\\Zoom\\FakeZoomClient(),
    new \\Minhaj\\Access\\AccessPolicy( new \\Minhaj\\Access\\AccessRepository() )
);
\$controller = new \\Minhaj\\Modules\\Meetings\\Rest\\WebhookController( \$svc, \$verifier );

// Same event_ts + same object.uuid as the previous call — dedup_key
// collides so the 2nd and 3rd inserts silently return "already ingested".
\$fixed_ts = 1725000000;
\$body = json_encode( [
    'event'    => 'meeting.ended',
    'event_ts' => \$fixed_ts,
    'payload'  => [
        'object' => [ 'id' => 'm-test-1', 'uuid' => 'uuid-test-1' ],
    ],
] );
\$sig  = 'v0=' . hash_hmac( 'sha256', 'v0:' . time() . ':' . \$body, MINHAJ_ZOOM_WEBHOOK_SECRET );

for ( \$i = 0; \$i < 3; \$i++ ) {
    \$req  = new \\WP_REST_Request( 'POST', '/minhaj/v1/zoom/webhook' );
    \$req->set_body( \$body );
    \$req->set_headers( [
        'x-zm-signature'         => 'v0=' . hash_hmac( 'sha256', 'v0:' . time() . ':' . \$body, MINHAJ_ZOOM_WEBHOOK_SECRET ),
        'x-zm-request-timestamp' => (string) time(),
    ] );
    \$resp = \$controller->handle( \$req );
}

global \$wpdb;
\$rows = (int) \$wpdb->get_var( "SELECT COUNT(*) FROM wp_minhaj_zoom_events WHERE event_type = 'meeting.ended'" );
printf( "MEETING_ENDED_ROWS=%d\n", \$rows );
PHP
)

DEDUP_OUT=$(run_wp eval "$DEDUP_CODE" | tr -d '\r')
echo "  $DEDUP_OUT"

MEETING_ENDED_ROWS=$(printf '%s' "$DEDUP_OUT" | grep -oE 'MEETING_ENDED_ROWS=[0-9]+' | cut -d= -f2)
if [[ "$MEETING_ENDED_ROWS" == "1" ]]; then
  echo "  ${GREEN}✓ three deliveries → one row (uq_dedup enforced)${RESET}"
else
  echo "  ${RED}✗ MEETING_ENDED_ROWS=$MEETING_ENDED_ROWS — expected 1${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== AC-6 · concurrency cap: license capacity 2, four seeded meetings, fifth candidate fails ==${RESET}"

CONCURRENCY_CODE=$(cat <<PHP
global \$wpdb;

// Reset meetings for a clean count.
\$wpdb->query( 'DELETE FROM wp_minhaj_session_meetings' );

// Four in-flight meetings, all in the same one-hour window.
\$now = current_time( 'mysql', true );
for ( \$i = 0; \$i < 4; \$i++ ) {
    \$wpdb->insert( 'wp_minhaj_session_meetings', [
        'session_id'          => 1000 + \$i,
        'license_id'          => 1,
        'zoom_meeting_id'     => 'm-cap-' . \$i,
        'state'               => 'created',
        'scheduled_start_utc' => '2027-06-01 10:00:00',
        'duration_minutes'    => 60,
        'created_at'          => \$now,
        'updated_at'          => \$now,
    ] );
}

// Cap is total concurrent_capacity across active licenses; one license × 2 = 2.
// (The test seed above already put ONE active license with capacity=2.)
\$svc = new \\Minhaj\\Modules\\Meetings\\MeetingsService(
    new \\Minhaj\\Modules\\Meetings\\Repository\\MeetingsRepository(),
    new \\Minhaj\\Modules\\Meetings\\Zoom\\FakeZoomClient(),
    new \\Minhaj\\Access\\AccessPolicy( new \\Minhaj\\Access\\AccessRepository() )
);

try {
    \$svc->assert_concurrency_within_cap( [ [
        'start_utc' => '2027-06-01 10:30:00',
        'end_utc'   => '2027-06-01 11:30:00',
    ] ] );
    echo "CAP_RESULT=accepted\n";
} catch ( \\Minhaj\\Modules\\Meetings\\Domain\\RuleViolationException \$e ) {
    printf( "CAP_RESULT=refused rule=%s\n", \$e->rule_code() );
}
PHP
)

CONCURRENCY_OUT=$(run_wp eval "$CONCURRENCY_CODE" | tr -d '\r')
echo "  $CONCURRENCY_OUT"

CAP_RESULT=$(printf '%s' "$CONCURRENCY_OUT" | grep -oE 'CAP_RESULT=[a-z]+' | cut -d= -f2)
CAP_RULE=$(printf '%s' "$CONCURRENCY_OUT" | grep -oE 'rule=[A-Z]-[0-9]+' | cut -d= -f2)
if [[ "$CAP_RESULT" == "refused" ]] && [[ "$CAP_RULE" == "M-6" ]]; then
  echo "  ${GREEN}✓ concurrency cap fires on the fifth slot with rule=M-6${RESET}"
else
  echo "  ${RED}✗ CAP_RESULT=$CAP_RESULT rule=$CAP_RULE${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== AC-11 · grep the module for start_url / join_url in INSERT / UPDATE queries ==${RESET}"

OFFENDERS=$(grep -rEn '\\$wpdb->insert|\\$wpdb->update' plugins/minhaj-core/includes/Modules/Meetings 2>/dev/null | grep -Ei 'start_url|join_url' || true)
if [[ -z "$OFFENDERS" ]]; then
  echo "  ${GREEN}✓ no start_url / join_url in any Meetings INSERT / UPDATE${RESET}"
else
  echo "  ${RED}✗ offenders:${RESET}"
  echo "$OFFENDERS"
  FAIL=1
fi

echo
if [[ "$FAIL" != "0" ]]; then
  echo "${RED}${BOLD}MEETINGS ZOOM PROOF FAILED${RESET}"
  exit 1
fi

echo "${GREEN}${BOLD}MEETINGS ZOOM PROOF PASSED${RESET}"
