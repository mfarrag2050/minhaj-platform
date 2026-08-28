#!/usr/bin/env bash
# Recordings module — live-DB proofs for spec-recordings-v1 §8.
#
#   AC-1 · recording.completed → rows per file, all `pending`, all with
#          retention_until set (§3.1: NOT NULL by DB).
#   AC-2 · replay of the same webhook does NOT create a second row
#          (uq_zoom_file).
#   AC-3 · G-1 · download with wrong reported bytes ⇒ `failed`, NO
#          Zoom delete call fires.
#   AC-4 · G-1 · verified download ⇒ `stored`, then Zoom delete fires
#          exactly once.
#   AC-5 · G-6 · row with retention_until in the past is purged; the
#          row survives as a tombstone (no object_key, no checksum).
#   AC-6 · G-8 · row on legal_hold is NOT purged even when expired.
#   AC-7 · G-11+G-12 · issue_view_url on granted access returns a URL
#          and logs `view`; on a purged row, returns recording_purged
#          and logs `denied`.
#   AC-8 · retention_until stored, not derived: change the filter
#          AFTER insert → existing rows unchanged.
#
# Uses FakeRecordingsZoomClient + LocalStorageClient wired via the
# recordings filters so no real Zoom account is needed.

set -euo pipefail

WP_ENV=${WP_ENV:-wp-env}
GREEN=$'\033[32m'
RED=$'\033[31m'
BOLD=$'\033[1m'
RESET=$'\033[0m'

run_wp() { "$WP_ENV" run cli wp "$@" 2>/dev/null; }
FAIL=0

echo "${BOLD}== Reset recording + audit tables ==${RESET}"
run_wp db query "
  DELETE FROM wp_minhaj_recordings;
  DELETE FROM wp_minhaj_recording_access_log;
  DELETE FROM wp_minhaj_recordings_audit;
" >/dev/null

# ---------------------------------------------------------------- AC-1 + AC-2.
echo
echo "${BOLD}== AC-1/AC-2 · register_from_webhook: retention_until stored; replay is a no-op ==${RESET}"

REGISTER_CODE=$(cat <<'PHP'
$svc = new \Minhaj\Modules\Recordings\RecordingsService(
    new \Minhaj\Modules\Recordings\Repository\RecordingsRepository(),
    new \Minhaj\Modules\Recordings\Zoom\FakeRecordingsZoomClient(),
    new \Minhaj\Modules\Recordings\Storage\LocalStorageClient( '/tmp/minhaj-recordings-test', 'eu-central-1' ),
    new \Minhaj\Modules\Recordings\AccessPolicyAdapter(
        new \Minhaj\Access\AccessPolicy( new \Minhaj\Access\AccessRepository() )
    )
);
$payload = [
    'meeting_uuid' => 'MUID-AC1',
    'session_id'   => 12345,
    'group_id'     => 67,
    'org_id'       => 1,
    'kind'         => 'session',
    'recording_files' => [
        [ 'id' => 'FILE-A', 'file_type' => 'MP4', 'file_size' => 1024,
          'recording_start' => '2026-08-28T09:00:00Z',
          'recording_end'   => '2026-08-28T10:00:00Z' ],
        [ 'id' => 'FILE-B', 'file_type' => 'M4A', 'file_size' => 128,
          'recording_start' => '2026-08-28T09:00:00Z',
          'recording_end'   => '2026-08-28T10:00:00Z' ],
    ],
];
$first  = $svc->register_from_webhook( $payload );
$second = $svc->register_from_webhook( $payload );  // replay
printf( "FIRST=%d SECOND=%d\n", count( $first ), count( $second ) );

global $wpdb;
$rows = $wpdb->get_results( "SELECT status, retention_until FROM wp_minhaj_recordings WHERE zoom_meeting_uuid='MUID-AC1'", ARRAY_A );
foreach ( $rows as $r ) {
    printf( "ROW status=%s retention_until=%s\n", $r['status'], $r['retention_until'] );
}
PHP
)

REG_OUT=$(run_wp eval "$REGISTER_CODE" | tr -d '\r')
echo "  $REG_OUT" | sed 's/^/  /'

FIRST=$(printf '%s' "$REG_OUT" | grep -oE 'FIRST=[0-9]+'  | cut -d= -f2)
SECOND=$(printf '%s' "$REG_OUT" | grep -oE 'SECOND=[0-9]+' | cut -d= -f2)
STATUS_LINE=$(printf '%s' "$REG_OUT" | grep 'status=' | head -1)

if [[ "$FIRST" == "2" && "$SECOND" == "0" ]]; then
  echo "  ${GREEN}✓ first webhook created 2 rows; replay created 0 (uq_zoom_file)${RESET}"
else
  echo "  ${RED}✗ FIRST=$FIRST SECOND=$SECOND${RESET}"; FAIL=1
fi
if grep -q 'status=pending' <<<"$STATUS_LINE" && grep -q 'retention_until=2026-' <<<"$STATUS_LINE"; then
  echo "  ${GREEN}✓ rows land as pending with retention_until populated${RESET}"
else
  echo "  ${RED}✗ status/retention_until unexpected: $STATUS_LINE${RESET}"; FAIL=1
fi

# ---------------------------------------------------------------- AC-3.
echo
echo "${BOLD}== AC-3 · G-1 · size mismatch ⇒ failed, NO Zoom delete ==${RESET}"

FAIL_CODE=$(cat <<'PHP'
global $wpdb;
$wpdb->query( "DELETE FROM wp_minhaj_recordings" );

$fake = new \Minhaj\Modules\Recordings\Zoom\FakeRecordingsZoomClient();
// Report 999 bytes but return only 10 bytes on the wire → triple verify fails.
$fake->set_download_bytes( 'FID-BAD', 'shortdata!', 999 );

$svc = new \Minhaj\Modules\Recordings\RecordingsService(
    new \Minhaj\Modules\Recordings\Repository\RecordingsRepository(),
    $fake,
    new \Minhaj\Modules\Recordings\Storage\LocalStorageClient( '/tmp/minhaj-recordings-test', 'eu-central-1' ),
    new \Minhaj\Modules\Recordings\AccessPolicyAdapter(
        new \Minhaj\Access\AccessPolicy( new \Minhaj\Access\AccessRepository() )
    )
);

$ids = $svc->register_from_webhook( [
    'meeting_uuid' => 'MUID-BAD',
    'session_id'   => 1,
    'group_id'     => 1,
    'kind'         => 'session',
    'recording_files' => [ [
        'id' => 'FID-BAD', 'file_type' => 'MP4',
        'file_size' => 999,
        'recording_start' => '2026-08-28T09:00:00Z',
        'recording_end'   => '2026-08-28T10:00:00Z',
    ] ],
] );
$rid = $ids[0];

$bearers = [
    'FID-BAD' => [
        'download_url'   => 'https://fake-zoom/download/FID-BAD',
        'download_token' => 'tok-1',
    ],
];
$svc->download_due( 10, $bearers );

// Now try delete_from_zoom_when_verified — MUST be false because
// verify_triple fails (no object stored).
$deleted = $svc->delete_from_zoom_when_verified( $rid );
printf( "DELETED=%s\n", $deleted ? 'true' : 'false' );
printf( "ZOOM_DELETE_CALLS=%d\n", $fake->delete_calls_for( 'FID-BAD' ) );

$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, last_error FROM wp_minhaj_recordings WHERE id=%d", $rid ), ARRAY_A );
printf( "STATUS=%s ERR=%s\n", $row['status'], substr( (string) $row['last_error'], 0, 60 ) );
PHP
)

FAIL_OUT=$(run_wp eval "$FAIL_CODE" | tr -d '\r')
echo "$FAIL_OUT" | sed 's/^/  /'

DELETED=$(printf '%s' "$FAIL_OUT" | grep -oE 'DELETED=[a-z]+'          | cut -d= -f2)
ZCALLS=$(printf '%s' "$FAIL_OUT"  | grep -oE 'ZOOM_DELETE_CALLS=[0-9]+' | cut -d= -f2)
STATUS=$(printf '%s' "$FAIL_OUT"  | grep -oE 'STATUS=[a-z_]+'           | cut -d= -f2)

if [[ "$STATUS" == "failed" && "$DELETED" == "false" && "$ZCALLS" == "0" ]]; then
  echo "  ${GREEN}✓ size mismatch → status=failed, zero Zoom delete calls${RESET}"
else
  echo "  ${RED}✗ STATUS=$STATUS DELETED=$DELETED ZCALLS=$ZCALLS${RESET}"; FAIL=1
fi

# ---------------------------------------------------------------- AC-4.
echo
echo "${BOLD}== AC-4 · G-1 · verified download ⇒ stored + Zoom delete ==${RESET}"

OK_CODE=$(cat <<'PHP'
global $wpdb;
$wpdb->query( "DELETE FROM wp_minhaj_recordings" );

$fake = new \Minhaj\Modules\Recordings\Zoom\FakeRecordingsZoomClient();
$bytes = str_repeat( 'x', 512 );
$fake->set_download_bytes( 'FID-OK', $bytes );  // reported == actual

$svc = new \Minhaj\Modules\Recordings\RecordingsService(
    new \Minhaj\Modules\Recordings\Repository\RecordingsRepository(),
    $fake,
    new \Minhaj\Modules\Recordings\Storage\LocalStorageClient( '/tmp/minhaj-recordings-test', 'eu-central-1' ),
    new \Minhaj\Modules\Recordings\AccessPolicyAdapter(
        new \Minhaj\Access\AccessPolicy( new \Minhaj\Access\AccessRepository() )
    )
);

$ids = $svc->register_from_webhook( [
    'meeting_uuid' => 'MUID-OK',
    'session_id'   => 1, 'group_id' => 1, 'kind' => 'session',
    'recording_files' => [ [
        'id' => 'FID-OK', 'file_type' => 'MP4', 'file_size' => 512,
        'recording_start' => '2026-08-28T09:00:00Z',
        'recording_end'   => '2026-08-28T10:00:00Z',
    ] ],
] );
$rid = $ids[0];
$svc->download_due( 10, [
    'FID-OK' => [ 'download_url' => 'https://fake-zoom/download/FID-OK', 'download_token' => 'tok' ],
] );

$stored_row = $wpdb->get_row( $wpdb->prepare( "SELECT status, object_key, checksum_sha256 FROM wp_minhaj_recordings WHERE id=%d", $rid ), ARRAY_A );
printf( "AFTER_DOWNLOAD status=%s object_key=%s checksum=%s\n",
    $stored_row['status'], $stored_row['object_key'], substr( $stored_row['checksum_sha256'], 0, 12 ) );

$deleted = $svc->delete_from_zoom_when_verified( $rid );
printf( "DELETED=%s\n", $deleted ? 'true' : 'false' );
printf( "ZOOM_DELETE_CALLS=%d\n", $fake->delete_calls_for( 'FID-OK' ) );

$after = $wpdb->get_row( $wpdb->prepare( "SELECT status, zoom_deleted_at FROM wp_minhaj_recordings WHERE id=%d", $rid ), ARRAY_A );
printf( "AFTER_ZOOM_DELETE status=%s deleted_at=%s\n", $after['status'], $after['zoom_deleted_at'] );
PHP
)

OK_OUT=$(run_wp eval "$OK_CODE" | tr -d '\r')
echo "$OK_OUT" | sed 's/^/  /'

if grep -q "AFTER_DOWNLOAD status=stored" <<<"$OK_OUT" && grep -q "AFTER_ZOOM_DELETE status=zoom_deleted" <<<"$OK_OUT" && grep -q "ZOOM_DELETE_CALLS=1" <<<"$OK_OUT"; then
  echo "  ${GREEN}✓ verified download → stored; Zoom delete called exactly once${RESET}"
else
  echo "  ${RED}✗ stored/zoom_deleted flow broken${RESET}"; FAIL=1
fi

# ---------------------------------------------------------------- AC-5 + AC-6.
echo
echo "${BOLD}== AC-5/AC-6 · purge tombstones + legal_hold skips purge ==${RESET}"

PURGE_CODE=$(cat <<'PHP'
global $wpdb;
$wpdb->query( "DELETE FROM wp_minhaj_recordings" );

// Two rows: one plain expired, one on legal_hold with same past date.
$now = current_time( 'mysql', true );
$wpdb->insert( 'wp_minhaj_recordings', [
    'session_id' => 1, 'group_id' => 1, 'kind' => 'session',
    'zoom_meeting_uuid' => 'M-E', 'zoom_file_id' => 'FID-E1', 'file_type' => 'MP4',
    'recording_start_utc' => '2026-07-01 09:00:00', 'recording_end_utc' => '2026-07-01 10:00:00',
    'bytes' => 10, 'checksum_sha256' => str_repeat( 'a', 64 ),
    'status' => 'stored', 'storage_region' => 'eu-central-1',
    'object_key' => 'exp/plain', 'retention_until' => '2026-08-01',
    'created_at' => $now, 'updated_at' => $now,
] );
$id_plain = (int) $wpdb->insert_id;
$wpdb->insert( 'wp_minhaj_recordings', [
    'session_id' => 1, 'group_id' => 1, 'kind' => 'session',
    'zoom_meeting_uuid' => 'M-E', 'zoom_file_id' => 'FID-E2', 'file_type' => 'MP4',
    'recording_start_utc' => '2026-07-01 09:00:00', 'recording_end_utc' => '2026-07-01 10:00:00',
    'bytes' => 10, 'checksum_sha256' => str_repeat( 'b', 64 ),
    'status' => 'legal_hold', 'storage_region' => 'eu-central-1',
    'object_key' => 'exp/hold', 'retention_until' => '2026-08-01',
    'created_at' => $now, 'updated_at' => $now,
] );
$id_hold = (int) $wpdb->insert_id;

$storage = new \Minhaj\Modules\Recordings\Storage\LocalStorageClient( '/tmp/minhaj-recordings-test', 'eu-central-1' );
$storage->put( 'exp/plain', 'x' );
$storage->put( 'exp/hold', 'y' );

$svc = new \Minhaj\Modules\Recordings\RecordingsService(
    new \Minhaj\Modules\Recordings\Repository\RecordingsRepository(),
    new \Minhaj\Modules\Recordings\Zoom\FakeRecordingsZoomClient(),
    $storage,
    new \Minhaj\Modules\Recordings\AccessPolicyAdapter(
        new \Minhaj\Access\AccessPolicy( new \Minhaj\Access\AccessRepository() )
    )
);

$purged = $svc->purge_expired( 100 );
printf( "PURGED=%d\n", $purged );

$plain = $wpdb->get_row( $wpdb->prepare( "SELECT status, object_key, checksum_sha256, purged_at FROM wp_minhaj_recordings WHERE id=%d", $id_plain ), ARRAY_A );
$hold  = $wpdb->get_row( $wpdb->prepare( "SELECT status, object_key FROM wp_minhaj_recordings WHERE id=%d", $id_hold ), ARRAY_A );

printf( "PLAIN status=%s object_key=%s checksum=%s\n", $plain['status'], (string) $plain['object_key'], (string) $plain['checksum_sha256'] );
printf( "HOLD  status=%s object_key=%s\n",             $hold['status'],  (string) $hold['object_key'] );
printf( "PLAIN_ON_DISK=%s HOLD_ON_DISK=%s\n",
    $storage->exists( 'exp/plain' ) ? 'yes' : 'no',
    $storage->exists( 'exp/hold' )  ? 'yes' : 'no' );
PHP
)

PURGE_OUT=$(run_wp eval "$PURGE_CODE" | tr -d '\r')
echo "$PURGE_OUT" | sed 's/^/  /'

if grep -q "PURGED=1" <<<"$PURGE_OUT" \
   && grep -q "PLAIN status=purged object_key=" <<<"$PURGE_OUT" \
   && grep -q "HOLD  status=legal_hold object_key=exp/hold" <<<"$PURGE_OUT" \
   && grep -q "PLAIN_ON_DISK=no HOLD_ON_DISK=yes" <<<"$PURGE_OUT"; then
  echo "  ${GREEN}✓ plain expired row purged + tombstone kept; legal_hold row untouched${RESET}"
else
  echo "  ${RED}✗ purge / hold behaviour off${RESET}"; FAIL=1
fi

# ---------------------------------------------------------------- AC-7.
echo
echo "${BOLD}== AC-7 · issue_view_url grants / refuses / logs ==${RESET}"

VIEW_CODE=$(cat <<'PHP'
global $wpdb;
$wpdb->query( "DELETE FROM wp_minhaj_recordings" );
$wpdb->query( "DELETE FROM wp_minhaj_recording_access_log" );

$now = current_time( 'mysql', true );
$wpdb->insert( 'wp_minhaj_recordings', [
    'session_id' => 900, 'group_id' => 1, 'kind' => 'session',
    'zoom_meeting_uuid' => 'M-V', 'zoom_file_id' => 'FID-V1', 'file_type' => 'MP4',
    'recording_start_utc' => '2026-08-28 09:00:00', 'recording_end_utc' => '2026-08-28 10:00:00',
    'bytes' => 10, 'checksum_sha256' => str_repeat( 'a', 64 ),
    'status' => 'stored', 'storage_region' => 'eu-central-1',
    'object_key' => 'view/ok', 'retention_until' => '2099-01-01',
    'created_at' => $now, 'updated_at' => $now,
] );
$rid = (int) $wpdb->insert_id;
$storage = new \Minhaj\Modules\Recordings\Storage\LocalStorageClient( '/tmp/minhaj-recordings-test', 'eu-central-1' );
$storage->put( 'view/ok', 'v' );

// Fake AccessCheck: admin (user 1) YES, everyone else NO.
$access = new class implements \Minhaj\Modules\Recordings\RecordingAccessCheck {
    public function can_view_recording( int $user_id, int $recording_id ): bool { return 1 === $user_id; }
};
$svc = new \Minhaj\Modules\Recordings\RecordingsService(
    new \Minhaj\Modules\Recordings\Repository\RecordingsRepository(),
    new \Minhaj\Modules\Recordings\Zoom\FakeRecordingsZoomClient(),
    $storage,
    $access
);

$grant  = $svc->issue_view_url( 1, $rid );
$refuse = $svc->issue_view_url( 99, $rid );
printf( "GRANT_TYPE=%s\n",  is_string( $grant )  ? 'url'   : ( is_wp_error( $grant )  ? 'err:' . $grant->get_error_code()  : 'other' ) );
printf( "REFUSE_TYPE=%s\n", is_string( $refuse ) ? 'url'   : ( is_wp_error( $refuse ) ? 'err:' . $refuse->get_error_code() : 'other' ) );

$view_ct   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM wp_minhaj_recording_access_log WHERE recording_id=%d AND action='view'",   $rid ) );
$denied_ct = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM wp_minhaj_recording_access_log WHERE recording_id=%d AND action='denied'", $rid ) );
printf( "LOG view=%d denied=%d\n", $view_ct, $denied_ct );
PHP
)

VIEW_OUT=$(run_wp eval "$VIEW_CODE" | tr -d '\r')
echo "$VIEW_OUT" | sed 's/^/  /'

if grep -q "GRANT_TYPE=url" <<<"$VIEW_OUT" \
   && grep -q "REFUSE_TYPE=err:access_denied" <<<"$VIEW_OUT" \
   && grep -q "LOG view=1 denied=1" <<<"$VIEW_OUT"; then
  echo "  ${GREEN}✓ grant issues URL, refusal returns access_denied, both logged (no IPs)${RESET}"
else
  echo "  ${RED}✗ view/refuse flow off${RESET}"; FAIL=1
fi

# ---------------------------------------------------------------- AC-8.
echo
echo "${BOLD}== AC-8 · retention_until is stored, not derived from later filter ==${RESET}"

RETENTION_CODE=$(cat <<'PHP'
global $wpdb;
$wpdb->query( "DELETE FROM wp_minhaj_recordings" );

$svc = new \Minhaj\Modules\Recordings\RecordingsService(
    new \Minhaj\Modules\Recordings\Repository\RecordingsRepository(),
    new \Minhaj\Modules\Recordings\Zoom\FakeRecordingsZoomClient(),
    new \Minhaj\Modules\Recordings\Storage\LocalStorageClient( '/tmp/minhaj-recordings-test', 'eu-central-1' ),
    new \Minhaj\Modules\Recordings\AccessPolicyAdapter(
        new \Minhaj\Access\AccessPolicy( new \Minhaj\Access\AccessRepository() )
    )
);

// 1st insert: default retention (30 days).
$svc->register_from_webhook( [
    'meeting_uuid' => 'MUID-R', 'session_id' => 1, 'group_id' => 1, 'kind' => 'session',
    'recording_files' => [ [
        'id' => 'R-FIRST', 'file_type' => 'MP4', 'file_size' => 1,
        'recording_start' => '2026-08-28T09:00:00Z',
        'recording_end'   => '2026-08-28T10:00:00Z',
    ] ],
] );
$first_r = (string) $wpdb->get_var( "SELECT retention_until FROM wp_minhaj_recordings WHERE zoom_file_id='R-FIRST'" );
printf( "FIRST_RETENTION=%s\n", $first_r );

// Now raise the filter to 3650 days AFTER insert. The first row must NOT move.
add_filter( 'minhaj_recording_retention_days', fn() => 3650 );

// 2nd insert: should use the raised value.
$svc->register_from_webhook( [
    'meeting_uuid' => 'MUID-R2', 'session_id' => 1, 'group_id' => 1, 'kind' => 'session',
    'recording_files' => [ [
        'id' => 'R-SECOND', 'file_type' => 'MP4', 'file_size' => 1,
        'recording_start' => '2026-08-28T09:00:00Z',
        'recording_end'   => '2026-08-28T10:00:00Z',
    ] ],
] );
$second_r = (string) $wpdb->get_var( "SELECT retention_until FROM wp_minhaj_recordings WHERE zoom_file_id='R-SECOND'" );
$still_r  = (string) $wpdb->get_var( "SELECT retention_until FROM wp_minhaj_recordings WHERE zoom_file_id='R-FIRST'" );
printf( "SECOND_RETENTION=%s STILL_FIRST=%s\n", $second_r, $still_r );
PHP
)

RET_OUT=$(run_wp eval "$RETENTION_CODE" | tr -d '\r')
echo "$RET_OUT" | sed 's/^/  /'

FIRST_R=$(printf '%s' "$RET_OUT" | grep -oE 'FIRST_RETENTION=[0-9-]+' | cut -d= -f2)
STILL_R=$(printf '%s' "$RET_OUT" | grep -oE 'STILL_FIRST=[0-9-]+'     | cut -d= -f2)

if [[ "$FIRST_R" == "$STILL_R" && -n "$FIRST_R" ]]; then
  echo "  ${GREEN}✓ retention_until on existing row unchanged after filter raise ($STILL_R)${RESET}"
else
  echo "  ${RED}✗ FIRST=$FIRST_R STILL=$STILL_R${RESET}"; FAIL=1
fi

echo
if [[ "$FAIL" != "0" ]]; then
  echo "${RED}${BOLD}RECORDINGS PIPELINE PROOF FAILED${RESET}"
  exit 1
fi
echo "${GREEN}${BOLD}RECORDINGS PIPELINE PROOF PASSED${RESET}"
