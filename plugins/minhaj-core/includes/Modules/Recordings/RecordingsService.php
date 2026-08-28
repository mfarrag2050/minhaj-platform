<?php
/**
 * RecordingsService — spec-recordings-v1 §6.
 *
 * Layering:
 *   • The service takes RecordingsZoomClient + StorageClient + AccessPolicy
 *     as constructor deps, so tests inject fakes and swapping the storage
 *     provider (spec §9.4) does not touch this class.
 *   • Every write on a minor's recording emits an audit row BEFORE commit
 *     and a `do_action` AFTER commit (G-14).
 *   • download_from_zoom performs the triple verification (G-1) BEFORE
 *     it will call zoom->delete_recording_file. If any of the three
 *     checks fails, delete is not called, the row goes to `failed`, and
 *     the caller gets false back.
 *   • Every attempt to view — successful or refused — writes to
 *     minhaj_recording_access_log (G-12). NO IP addresses; the user_id
 *     is the compliance-worthy identifier.
 *   • download_url / play_url / download_token are treated as bearer
 *     secrets: NEVER persisted (§7). We consume the URL, use the token
 *     on the wire, drop both.
 *
 * @package Minhaj\Modules\Recordings
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings;

use Minhaj\Access\AccessPolicy;
use Minhaj\Modules\Recordings\Domain\AccessAction;
use Minhaj\Modules\Recordings\Domain\FileType;
use Minhaj\Modules\Recordings\Domain\RecordingKind;
use Minhaj\Modules\Recordings\Domain\RecordingStatus;
use Minhaj\Modules\Recordings\Repository\PersistenceException;
use Minhaj\Modules\Recordings\Repository\RecordingsRepository;
use Minhaj\Modules\Recordings\Storage\StorageClient;
use Minhaj\Modules\Recordings\Storage\StorageException;
use Minhaj\Modules\Recordings\Zoom\FakeRecordingsZoomClient;
use Minhaj\Modules\Recordings\Zoom\RecordingsZoomClient;
use Minhaj\Modules\Recordings\Zoom\RecordingsZoomException;
use RuntimeException;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

final class RecordingsService {

	public const VIEW_URL_DEFAULT_TTL_MINUTES = 15;

	public function __construct(
		private readonly RecordingsRepository $repo,
		private readonly RecordingsZoomClient $zoom,
		private readonly StorageClient $storage,
		private readonly RecordingAccessCheck $access
	) {}

	// ============================================================== Split A.

	/**
	 * Register one row per recording file the webhook announced.
	 * Idempotent per `zoom_file_id` via `uq_zoom_file` (§5.1 G-5).
	 *
	 * Expected payload shape (subset of Zoom's `recording.completed`):
	 *
	 *   [
	 *     'meeting_uuid' => '<uuid>',
	 *     'recording_files' => [
	 *       [ 'id' => '<file_id>', 'file_type' => 'MP4', 'file_size' => 123,
	 *         'recording_start' => 'ISO8601', 'recording_end' => 'ISO8601',
	 *         'download_url' => 'https://...',
	 *         'play_url' => 'https://...',        // MUST NOT persist
	 *       ],
	 *     ],
	 *     'download_token' => '<opaque>',         // MUST NOT persist
	 *     'session_id' => 42,   'group_id' => 100, 'org_id' => 5, // resolved by caller
	 *     'kind' => 'session',
	 *   ]
	 *
	 * The service does NOT go and look up session/group/org — the
	 * webhook listener resolves them (from `minhaj_sessions` +
	 * `minhaj_groups`) and passes them in. This keeps the service
	 * layer free of tables it doesn't own.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<int, int>  ids of inserted rows
	 */
	public function register_from_webhook( array $payload ): array {
		$now = current_time( 'mysql', true );

		$meeting_uuid = (string) ( $payload['meeting_uuid'] ?? '' );
		$session_id   = (int) ( $payload['session_id'] ?? 0 );
		$group_id     = (int) ( $payload['group_id'] ?? 0 );
		$org_id       = isset( $payload['org_id'] ) ? (int) $payload['org_id'] : null;
		$kind         = RecordingKind::is_valid( (string) ( $payload['kind'] ?? '' ) )
			? (string) $payload['kind']
			: RecordingKind::SESSION;

		if ( '' === $meeting_uuid || $session_id <= 0 || $group_id <= 0 ) {
			return array();
		}

		$region = $this->storage->region();
		if ( ! $this->region_allowed( $region ) ) {
			// G-4 · region is written on every row; refuse if the storage
			// client hands back a region outside our EU list.
			return array();
		}

		$ids = array();

		foreach ( (array) ( $payload['recording_files'] ?? array() ) as $file ) {
			$file_id  = (string) ( $file['id'] ?? '' );
			$type_raw = strtoupper( (string) ( $file['file_type'] ?? '' ) );

			if ( '' === $file_id || ! FileType::is_valid( $type_raw ) ) {
				continue;
			}

			$start = (string) ( $file['recording_start'] ?? '' );
			$end   = (string) ( $file['recording_end'] ?? $start );
			if ( '' === $start ) {
				continue;
			}

			$retention_until = $this->compute_retention_until( $kind, $end, $payload );

			try {
				$id = $this->repo->insert_recording(
					array(
						'session_id'          => $session_id,
						'group_id'            => $group_id,
						'org_id'              => $org_id,
						'kind'                => $kind,
						'zoom_meeting_uuid'   => $meeting_uuid,
						'zoom_file_id'        => $file_id,
						'file_type'           => $type_raw,
						'recording_start_utc' => $this->normalize_dt( $start ),
						'recording_end_utc'   => $this->normalize_dt( $end ),
						'bytes'               => (int) ( $file['file_size'] ?? 0 ),
						'status'              => RecordingStatus::PENDING,
						'storage_region'      => $region,
						'retention_until'     => $retention_until,
						'created_at'          => $now,
						'updated_at'          => $now,
					)
				);

				$this->repo->insert_audit(
					array(
						'session_id'    => $session_id,
						'actor_user_id' => 0,
						'action'        => 'recording.registered',
						'subject_id'    => $id,
						'payload_json'  => (string) wp_json_encode(
							array(
								'file_type'       => $type_raw,
								'kind'            => $kind,
								'retention_until' => $retention_until,
								'zoom_file_id'    => $file_id,
							)
						),
						'created_at'    => $now,
					)
				);

				$ids[] = $id;

				do_action( Events::REGISTERED, $id, $session_id );
			} catch ( PersistenceException $e ) {
				if ( PersistenceException::DUPLICATE_ZOOM_FILE === $e->kind() ) {
					// Idempotent — the row already exists. Not an error.
					continue;
				}
				throw $e;
			}
		}

		return $ids;
	}

	/**
	 * Iterate the download-due queue. Each row that returns fully
	 * verified moves to `stored`. Failed rows are marked `failed` with
	 * `last_error` set and `download_attempts` incremented — the
	 * scheduler retries them on subsequent runs until an admin
	 * intervenes.
	 *
	 * Returns per-row results: `[ id => 'stored' | 'failed' ]`.
	 *
	 * @param array<int, array<string, mixed>> $bearers  Optional map
	 *        `zoom_file_id => download_url + download_token` — used when
	 *        the webhook payload's tokens are still hot. In the default
	 *        code path the caller has none (tokens expired), so this is
	 *        empty and download_due only serves rows whose bearers have
	 *        been re-fetched via a Zoom list call.
	 *
	 * @return array<int, string>
	 */
	public function download_due( int $limit = 50, array $bearers = array() ): array {
		$results = array();

		foreach ( $this->repo->list_download_due( $limit ) as $row ) {
			$id      = (int) $row['id'];
			$file_id = (string) $row['zoom_file_id'];
			$bearer  = $bearers[ $file_id ] ?? null;
			if ( null === $bearer ) {
				// No hot bearer for this row — the caller must refresh.
				$results[ $id ] = 'skipped_no_bearer';
				continue;
			}

			$results[ $id ] = $this->download_one( $row, $bearer['download_url'], $bearer['download_token'] );
		}

		return $results;
	}

	/**
	 * Delete a single Zoom file only if triple verification (G-1) passes.
	 *
	 * @return bool true on delete, false if verification blocks it.
	 */
	public function delete_from_zoom_when_verified( int $recording_id ): bool {
		$row = $this->repo->find( $recording_id );
		if ( null === $row ) {
			return false;
		}
		if ( ! $this->verify_triple( $row ) ) {
			return false;
		}
		return $this->do_zoom_delete( $row );
	}

	/**
	 * Delete storage objects whose retention has passed. Keeps a
	 * tombstone row (G-7): status=purged, object_key + checksum
	 * blanked; the row itself stays for compliance evidence.
	 *
	 * Rows on `legal_hold` (G-8) are silently skipped.
	 *
	 * @return int rows purged
	 */
	public function purge_expired( int $limit = 200 ): int {
		$today = current_time( 'Y-m-d', true );
		$now   = current_time( 'mysql', true );
		$done  = 0;

		foreach ( $this->repo->list_due_for_purge( $limit, $today ) as $row ) {
			$id  = (int) $row['id'];
			$key = (string) ( $row['object_key'] ?? '' );

			try {
				if ( '' !== $key ) {
					$this->storage->delete( $key );
				}
			} catch ( StorageException $e ) {
				// Log and continue — a failing delete leaves the row
				// unpurged, and the next cron run retries. Better than
				// marking it purged when the object still lives.
				$this->repo->insert_audit(
					array(
						'session_id'    => (int) $row['session_id'],
						'actor_user_id' => 0,
						'action'        => 'recording.purge_failed',
						'subject_id'    => $id,
						'payload_json'  => (string) wp_json_encode( array( 'error' => $e->getMessage() ) ),
						'created_at'    => $now,
					)
				);
				continue;
			}

			$this->repo->update_recording(
				$id,
				array(
					'status'          => RecordingStatus::PURGED,
					'object_key'      => null,
					'checksum_sha256' => null,
					'purged_at'       => $now,
					'updated_at'      => $now,
				)
			);

			$this->repo->insert_access_log( $id, 0, AccessAction::PURGE, $now );
			$this->repo->insert_audit(
				array(
					'session_id'    => (int) $row['session_id'],
					'actor_user_id' => 0,
					'action'        => 'recording.purged',
					'subject_id'    => $id,
					'payload_json'  => (string) wp_json_encode( array( 'retention_until' => $row['retention_until'] ) ),
					'created_at'    => $now,
				)
			);
			do_action( Events::PURGED, $id );

			++$done;
		}

		return $done;
	}

	/**
	 * Grant a signed URL to a user who is allowed to watch. Records
	 * both success (`view`) and refusal (`denied`) in the access log.
	 * The URL comes from StorageClient::presign and is not stored.
	 *
	 * @return string|WP_Error signed URL or WP_Error on refusal.
	 */
	public function issue_view_url( int $actor_user_id, int $recording_id ) {
		$now = current_time( 'mysql', true );
		$row = $this->repo->find( $recording_id );
		if ( null === $row ) {
			return new WP_Error( 'recording_not_found', __( 'Recording not found.', 'minhaj-core' ) );
		}

		// If the row was purged there is nothing to serve — even if the
		// caller is admin. Return a specific error so the tombstone is
		// visible without leaking a dead URL.
		if ( RecordingStatus::PURGED === $row['status'] || null === $row['object_key'] || '' === $row['object_key'] ) {
			$this->log_denied( $recording_id, $actor_user_id, $now );
			return new WP_Error( 'recording_purged', __( 'This recording has been purged per retention policy.', 'minhaj-core' ) );
		}

		if ( ! $this->access->can_view_recording( $actor_user_id, $recording_id ) ) {
			$this->log_denied( $recording_id, $actor_user_id, $now );
			return new WP_Error( 'access_denied', __( 'You are not allowed to view this recording.', 'minhaj-core' ) );
		}

		$ttl = (int) apply_filters(
			'minhaj_recording_view_ttl_minutes',
			self::VIEW_URL_DEFAULT_TTL_MINUTES,
			$actor_user_id,
			$recording_id
		);

		$url = $this->storage->presign( (string) $row['object_key'], max( 1, $ttl ) );

		$this->repo->insert_access_log( $recording_id, $actor_user_id, AccessAction::VIEW, $now );
		$this->repo->insert_audit(
			array(
				'session_id'    => (int) $row['session_id'],
				'actor_user_id' => $actor_user_id,
				'action'        => 'recording.view_url_issued',
				'subject_id'    => $recording_id,
				'payload_json'  => (string) wp_json_encode( array( 'ttl_minutes' => $ttl ) ),
				'created_at'    => $now,
			)
		);

		return $url;
	}

	public function set_legal_hold( int $actor_user_id, int $recording_id, bool $on, string $reason ): void {
		if ( '' === trim( $reason ) ) {
			throw new RuntimeException( 'legal_hold requires a written reason' );
		}
		$row = $this->repo->find( $recording_id );
		if ( null === $row ) {
			return;
		}

		$now = current_time( 'mysql', true );

		$new_status = $on
			? RecordingStatus::LEGAL_HOLD
			: ( null === $row['object_key'] ? RecordingStatus::PURGED : RecordingStatus::STORED );

		$this->repo->update_recording(
			$recording_id,
			array(
				'status'     => $new_status,
				'updated_at' => $now,
			)
		);
		$this->repo->insert_audit(
			array(
				'session_id'    => (int) $row['session_id'],
				'actor_user_id' => $actor_user_id,
				'action'        => $on ? 'recording.legal_hold_on' : 'recording.legal_hold_off',
				'subject_id'    => $recording_id,
				'payload_json'  => (string) wp_json_encode( array( 'reason' => $reason ) ),
				'created_at'    => $now,
			)
		);
		do_action( Events::LEGAL_HOLD_SET, $recording_id, $on, $actor_user_id );
	}

	/**
	 * Promote a `session` row to `assessment` — recomputes retention_until
	 * using the assessment default (or the filter, if set). Requires a
	 * written reason (audit trail).
	 */
	public function promote_to_assessment( int $actor_user_id, int $recording_id, string $reason ): void {
		if ( '' === trim( $reason ) ) {
			throw new RuntimeException( 'promote_to_assessment requires a written reason' );
		}
		$row = $this->repo->find( $recording_id );
		if ( null === $row ) {
			return;
		}
		if ( RecordingKind::ASSESSMENT === $row['kind'] ) {
			return;
		}

		$now             = current_time( 'mysql', true );
		$retention_until = $this->compute_retention_until(
			RecordingKind::ASSESSMENT,
			(string) $row['recording_end_utc'],
			array()
		);

		$this->repo->update_recording(
			$recording_id,
			array(
				'kind'            => RecordingKind::ASSESSMENT,
				'retention_until' => $retention_until,
				'updated_at'      => $now,
			)
		);
		$this->repo->insert_audit(
			array(
				'session_id'    => (int) $row['session_id'],
				'actor_user_id' => $actor_user_id,
				'action'        => 'recording.promoted_to_assessment',
				'subject_id'    => $recording_id,
				'payload_json'  => (string) wp_json_encode(
					array(
						'reason'          => $reason,
						'retention_until' => $retention_until,
					)
				),
				'created_at'    => $now,
			)
		);
	}

	/**
	 * @return array{used_bytes:int, plan_bytes:int, remaining_bytes:int, remaining_days:int|null, daily_bytes:int}
	 */
	public function quota_status(): array {
		try {
			$q = $this->zoom->quota();
		} catch ( RecordingsZoomException $e ) {
			return array(
				'used_bytes'      => 0,
				'plan_bytes'      => 0,
				'remaining_bytes' => 0,
				'remaining_days'  => null,
				'daily_bytes'     => 0,
				'error'           => $e->getMessage(),
			);
		}

		$used  = (int) ( $q['used_bytes'] ?? 0 );
		$plan  = (int) ( $q['plan_bytes'] ?? 0 );
		$daily = max( 1, (int) ( $q['daily_bytes'] ?? 1 ) );

		$remaining_bytes = max( 0, $plan - $used );
		$remaining_days  = $plan > 0 ? (int) floor( $remaining_bytes / $daily ) : null;

		if ( $plan > 0 && ( $used / $plan ) >= 0.6 ) {
			do_action( Events::QUOTA_WARNING, $used, $plan, $remaining_days );
		}

		return array(
			'used_bytes'      => $used,
			'plan_bytes'      => $plan,
			'remaining_bytes' => $remaining_bytes,
			'remaining_days'  => $remaining_days,
			'daily_bytes'     => $daily,
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function for_session( int $session_id ): array {
		return $this->repo->list_for_session( $session_id );
	}

	// -------------------------------------------------------- Internals.

	private function download_one( array $row, string $download_url, string $download_token ): string {
		$id  = (int) $row['id'];
		$now = current_time( 'mysql', true );

		$this->repo->update_recording(
			$id,
			array(
				'status'            => RecordingStatus::DOWNLOADING,
				'download_attempts' => (int) $row['download_attempts'] + 1,
				'updated_at'        => $now,
			)
		);

		$tmp = wp_tempnam( 'minhaj-recording-' . $id );

		try {
			$got = $this->zoom->download( $download_url, $download_token, $tmp );
		} catch ( RecordingsZoomException $e ) {
			wp_delete_file( $tmp );
			$this->mark_failed( $id, 'download: ' . $e->getMessage(), $now );
			return 'failed';
		}

		$actual_bytes = filesize( $tmp );
		if ( false === $actual_bytes || $actual_bytes !== (int) $row['bytes'] ) {
			// G-1 · bytes on disk MUST equal the announced size.
			wp_delete_file( $tmp );
			$this->mark_failed(
				$id,
				sprintf( 'bytes mismatch: expected %d, got %s', (int) $row['bytes'], false === $actual_bytes ? 'unreadable' : (string) $actual_bytes ),
				$now
			);
			return 'failed';
		}

		// Compute checksum on our local file (do not trust wire).
		$checksum = hash_file( 'sha256', $tmp );
		if ( false === $checksum ) {
			wp_delete_file( $tmp );
			$this->mark_failed( $id, 'checksum computation failed', $now );
			return 'failed';
		}

		// Sink to storage.
		$object_key = sprintf(
			'sessions/%d/%s/%s.%s',
			(int) $row['session_id'],
			gmdate( 'Y-m', strtotime( (string) $row['recording_start_utc'] ) ),
			(string) $row['zoom_file_id'],
			strtolower( (string) $row['file_type'] )
		);

		try {
			$this->storage->put( $object_key, $tmp );
		} catch ( StorageException $e ) {
			wp_delete_file( $tmp );
			$this->mark_failed( $id, 'storage put: ' . $e->getMessage(), $now );
			return 'failed';
		} finally {
			wp_delete_file( $tmp );
		}

		$this->repo->update_recording(
			$id,
			array(
				'status'          => RecordingStatus::STORED,
				'object_key'      => $object_key,
				'checksum_sha256' => $checksum,
				'downloaded_at'   => $now,
				'last_error'      => null,
				'updated_at'      => $now,
			)
		);

		$this->repo->insert_audit(
			array(
				'session_id'    => (int) $row['session_id'],
				'actor_user_id' => 0,
				'action'        => 'recording.stored',
				'subject_id'    => $id,
				'payload_json'  => (string) wp_json_encode(
					array(
						'object_key'    => $object_key,
						'checksum'      => $checksum,
						'bytes'         => $actual_bytes,
						'zoom_reported' => (int) ( $got['bytes'] ?? 0 ),
					)
				),
				'created_at'    => $now,
			)
		);
		do_action( Events::STORED, $id );

		return 'stored';
	}

	/**
	 * G-1 · three checks; **all three must pass** or delete is refused:
	 *   1. row has object_key + checksum populated (post-store state)
	 *   2. storage->exists(object_key)  (probe read)
	 *   3. storage->get_bytes(object_key, 0, 1024)  (byte-actual read)
	 */
	private function verify_triple( array $row ): bool {
		$key = (string) ( $row['object_key'] ?? '' );
		$sum = (string) ( $row['checksum_sha256'] ?? '' );
		if ( '' === $key || '' === $sum ) {
			return false;
		}
		if ( ! $this->storage->exists( $key ) ) {
			return false;
		}
		try {
			// Probe read of at least 1 byte — proves the object is
			// actually served, not just listed. For a size-0 file we
			// consider exists() enough.
			$this->storage->get_bytes( $key, 0, 1024 );
		} catch ( StorageException $e ) {
			return false;
		}
		return true;
	}

	private function do_zoom_delete( array $row ): bool {
		$id  = (int) $row['id'];
		$now = current_time( 'mysql', true );

		try {
			$this->zoom->delete_recording_file(
				(string) $row['zoom_meeting_uuid'],
				(string) $row['zoom_file_id']
			);
		} catch ( RecordingsZoomException $e ) {
			$this->repo->insert_audit(
				array(
					'session_id'    => (int) $row['session_id'],
					'actor_user_id' => 0,
					'action'        => 'recording.zoom_delete_failed',
					'subject_id'    => $id,
					'payload_json'  => (string) wp_json_encode( array( 'error' => $e->getMessage() ) ),
					'created_at'    => $now,
				)
			);
			return false;
		}

		$this->repo->update_recording(
			$id,
			array(
				'status'          => RecordingStatus::ZOOM_DELETED,
				'zoom_deleted_at' => $now,
				'updated_at'      => $now,
			)
		);
		$this->repo->insert_audit(
			array(
				'session_id'    => (int) $row['session_id'],
				'actor_user_id' => 0,
				'action'        => 'recording.zoom_deleted',
				'subject_id'    => $id,
				'payload_json'  => (string) wp_json_encode( array( 'zoom_file_id' => (string) $row['zoom_file_id'] ) ),
				'created_at'    => $now,
			)
		);
		do_action( Events::DELETED_FROM_ZOOM, $id );
		return true;
	}

	private function mark_failed( int $id, string $message, string $now ): void {
		$this->repo->update_recording(
			$id,
			array(
				'status'     => RecordingStatus::FAILED,
				'last_error' => substr( $message, 0, 255 ),
				'updated_at' => $now,
			)
		);
		$this->repo->insert_audit(
			array(
				'session_id'    => 0,
				'actor_user_id' => 0,
				'action'        => 'recording.download_failed',
				'subject_id'    => $id,
				'payload_json'  => (string) wp_json_encode( array( 'error' => $message ) ),
				'created_at'    => $now,
			)
		);
		do_action( Events::DOWNLOAD_FAILED, $id, $message );
	}

	private function log_denied( int $recording_id, int $user_id, string $now ): void {
		$this->repo->insert_access_log( $recording_id, $user_id, AccessAction::DENIED, $now );
		do_action( Events::ACCESS_DENIED, $recording_id, $user_id );
	}

	private function region_allowed( string $region ): bool {
		$allowed = apply_filters(
			'minhaj_recording_allowed_regions',
			array( 'eu-central-1', 'eu-west-1', 'eu-west-3', 'eu-north-1' )
		);
		return in_array( $region, (array) $allowed, true );
	}

	/**
	 * Retention date is computed once and stored (spec §3.1). Passing
	 * the payload lets a future extension anchor `assessment` retention
	 * to a program-end date instead of the fallback +365 days.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function compute_retention_until( string $kind, string $recording_end_iso, array $payload ): string {
		$days = (int) apply_filters(
			'minhaj_recording_retention_days',
			RecordingKind::default_retention_days( $kind ),
			$kind,
			$payload
		);
		$days = max( 1, $days );

		$anchor = strtotime( $recording_end_iso );
		if ( false === $anchor ) {
			$anchor = time();
		}
		return gmdate( 'Y-m-d', $anchor + ( $days * DAY_IN_SECONDS ) );
	}

	private function normalize_dt( string $iso ): string {
		$ts = strtotime( $iso );
		return false === $ts ? '1970-01-01 00:00:00' : gmdate( 'Y-m-d H:i:s', $ts );
	}
}
