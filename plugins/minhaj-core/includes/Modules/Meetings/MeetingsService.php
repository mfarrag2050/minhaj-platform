<?php
/**
 * Meetings public interface — spec-zoom-sessions-v1 §6.
 *
 * Scope: meetings, registrants, webhooks, and the concurrency cap.
 * Cloud recording flows (recording.completed and downstream)
 * are DELIBERATELY DEFERRED — the spec is explicit that they live in
 * spec-recordings-v1 and their handling depends on the account plan.
 *
 * Layering (same rules as every other service in this plugin):
 *   • Callers enforce current_user_can + nonce BEFORE calling. Every
 *     write takes `int $actor_user_id` explicitly (M-16, A-3).
 *   • Domain rules throw RuleViolationException; the service catches
 *     at the outer boundary and returns WP_Error / rethrows into REST.
 *   • Writes ride a single transaction. Audit rows are inserted
 *     BEFORE commit; do_action events fire AFTER commit (M-19) —
 *     never inside a rollback.
 *
 * @package Minhaj\Modules\Meetings
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings;

use Minhaj\Access\AccessPolicy;
use Minhaj\Modules\Meetings\Domain\EventStatus;
use Minhaj\Modules\Meetings\Domain\JoinTicket;
use Minhaj\Modules\Meetings\Domain\MeetingState;
use Minhaj\Modules\Meetings\Domain\ParticipantRole;
use Minhaj\Modules\Meetings\Domain\RuleViolationException;
use Minhaj\Modules\Meetings\Repository\MeetingsRepository;
use Minhaj\Modules\Meetings\Repository\PersistenceException;
use Minhaj\Modules\Meetings\Zoom\ZoomApiException;
use Minhaj\Modules\Meetings\Zoom\ZoomClient;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/*
 * See the same disable pattern in every other module: WP_Error and
 * do_action names built here relay validated string enums and IDs, never
 * user-supplied HTML. Presentation escapes at render.
 */
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

final class MeetingsService {

	public function __construct(
		private readonly MeetingsRepository $repo,
		private readonly ZoomClient $zoom,
		private readonly ?AccessPolicy $access = null
	) {}

	// =============================================================== create_meeting.

	/**
	 * M-1 · create the Zoom meeting for a session. Callers should only
	 * invoke inside the lead-hours window; the guard is applied here so
	 * a bad caller can't force early creation.
	 *
	 * @return int|WP_Error Meeting id on success.
	 */
	public function create_meeting_for_session( int $actor_user_id, int $session_id ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$session = $this->repo->find_session( $session_id );
		if ( null === $session ) {
			return new WP_Error( 'session_not_found', __( 'Session not found.', 'minhaj-core' ) );
		}

		if ( empty( $session['scheduled_start_utc'] ) ) {
			return new WP_Error( 'session_unscheduled', __( 'Session has no scheduled start time.', 'minhaj-core' ) );
		}

		$existing = $this->repo->find_meeting_for_session( $session_id );
		if ( null !== $existing && MeetingState::REVOKED !== $existing['state'] ) {
			return (int) $existing['id'];
		}

		$licenses = $this->repo->list_active_licenses();
		if ( array() === $licenses ) {
			return new WP_Error( 'no_active_license', __( 'No active Zoom license available.', 'minhaj-core' ) );
		}
		$license = $licenses[0];

		$duration = $this->duration_minutes_for_session( $session );
		$now      = current_time( 'mysql', true );

		// Build the Zoom payload with the hard M-3 defaults. Any override
		// via `minhaj_meeting_settings` must respect the sniff-like check
		// below — an admin plugin cannot flip auto_recording off.
		/**
		 * @var array<string, mixed> $settings
		 */
		$settings = apply_filters(
			'minhaj_meeting_settings',
			array(
				'topic'      => 'Minhaj · session ' . $session_id,
				'type'       => 2,
				'start_time' => str_replace( ' ', 'T', (string) $session['scheduled_start_utc'] ) . 'Z',
				'duration'   => $duration,
				'timezone'   => 'UTC',
				'settings'   => array(
					'auto_recording'         => 'cloud',
					'waiting_room'           => true,
					'join_before_host'       => false,
					'approval_type'          => 1,
					'meeting_authentication' => false,
					'private_meeting'        => true,
				),
			),
			$session_id
		);

		if ( 'cloud' !== ( $settings['settings']['auto_recording'] ?? '' ) ) {
			return new WP_Error(
				'auto_recording_off',
				__( 'Filter attempted to disable cloud recording — refused (spec §5.1 M-3).', 'minhaj-core' )
			);
		}

		try {
			$response = $this->zoom->create_meeting( (string) $license['zoom_user_id'], $settings );
		} catch ( ZoomApiException $e ) {
			return new WP_Error( 'zoom_create_failed', $e->getMessage(), array( 'status' => $e->status() ) );
		}

		$meeting_id = 0;
		$this->repo->begin_transaction();
		try {
			$meeting_id = $this->repo->insert_meeting(
				array(
					'session_id'          => $session_id,
					'license_id'          => (int) $license['id'],
					'zoom_meeting_id'     => (string) $response['id'],
					'zoom_meeting_uuid'   => isset( $response['uuid'] ) ? (string) $response['uuid'] : null,
					'state'               => MeetingState::CREATED,
					'scheduled_start_utc' => (string) $session['scheduled_start_utc'],
					'duration_minutes'    => $duration,
					'create_attempts'     => 1,
					'created_at'          => $now,
					'updated_at'          => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'session_id'    => $session_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'meeting.created',
					'subject_id'    => $meeting_id,
					'payload_json'  => (string) wp_json_encode(
						array(
							'zoom_meeting_id' => (string) $response['id'],
							'license_id'      => (int) $license['id'],
						)
					),
					'created_at'    => $now,
				)
			);

			$this->repo->commit();
		} catch ( PersistenceException $e ) {
			$this->repo->rollback();
			if ( PersistenceException::DUPLICATE_SESSION === $e->kind() ) {
				return new WP_Error( 'duplicate_meeting', __( 'A meeting already exists for this session.', 'minhaj-core' ) );
			}
			return new WP_Error( 'persistence_error', $e->getMessage() );
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::MEETING_CREATED, $session_id, $meeting_id, (string) $response['id'], $actor_user_id );

		return $meeting_id;
	}

	// =============================================================== revoke_meeting.

	/**
	 * @return true|WP_Error
	 */
	public function revoke_meeting_for_session( int $actor_user_id, int $session_id, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$reason_clean = sanitize_text_field( $reason );
		if ( '' === trim( $reason_clean ) ) {
			return new WP_Error( 'reason_required', __( 'A reason is required.', 'minhaj-core' ) );
		}

		$meeting = $this->repo->find_meeting_for_session( $session_id );
		if ( null === $meeting ) {
			return true; // Nothing to revoke.
		}

		if ( MeetingState::REVOKED === $meeting['state'] || MeetingState::ENDED === $meeting['state'] ) {
			return true;
		}

		try {
			$this->zoom->delete_meeting( (string) $meeting['zoom_meeting_id'] );
		} catch ( ZoomApiException $e ) {
			// Zoom 404 (meeting already deleted / never existed) is not an error.
			if ( 404 !== $e->status() ) {
				return new WP_Error( 'zoom_delete_failed', $e->getMessage(), array( 'status' => $e->status() ) );
			}
		}

		$now = current_time( 'mysql', true );
		$this->repo->begin_transaction();
		try {
			$this->repo->update_meeting(
				(int) $meeting['id'],
				array(
					'state'      => MeetingState::REVOKED,
					'updated_at' => $now,
				)
			);
			$this->repo->insert_audit(
				array(
					'session_id'    => $session_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'meeting.revoked',
					'subject_id'    => (int) $meeting['id'],
					'payload_json'  => (string) wp_json_encode( array( 'reason' => $reason_clean ) ),
					'created_at'    => $now,
				)
			);
			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::MEETING_REVOKED, $session_id, (int) $meeting['id'], $reason_clean, $actor_user_id );

		return true;
	}

	// ============================================================= issue_join_ticket.

	/**
	 * M-10 · M-11 · M-12 · issue a short-lived registrant to Zoom and
	 * return the join_url as a JoinTicket. Never persist join_url.
	 *
	 * @return JoinTicket|WP_Error
	 */
	public function issue_join_ticket( int $actor_user_id, int $session_id, ?int $subject_student_id = null ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( null === $this->access ) {
			return new WP_Error( 'access_missing', __( 'AccessPolicy dependency not wired.', 'minhaj-core' ) );
		}

		$role = $this->access->join_role( $actor_user_id, $session_id, $subject_student_id );
		if ( false === $role ) {
			return new WP_Error( 'forbidden', __( 'Access denied for this session.', 'minhaj-core' ) );
		}

		$session = $this->repo->find_session( $session_id );
		if ( null === $session ) {
			return new WP_Error( 'session_not_found', __( 'Session not found.', 'minhaj-core' ) );
		}

		$meeting = $this->repo->find_meeting_for_session( $session_id );
		if ( null === $meeting || MeetingState::REVOKED === $meeting['state'] ) {
			return new WP_Error( 'meeting_not_ready', __( 'Meeting has not been created for this session.', 'minhaj-core' ) );
		}

		// M-11 · join window: 15 minutes before start to 15 minutes after end.
		$now_ts   = time();
		$start_ts = strtotime( (string) $session['scheduled_start_utc'] . ' UTC' );
		$end_ts   = strtotime( (string) $session['scheduled_end_utc'] . ' UTC' );
		$window   = (int) apply_filters( 'minhaj_join_window_minutes', 15 );

		if ( $now_ts < $start_ts - ( $window * 60 ) || $now_ts > $end_ts + ( $window * 60 ) ) {
			return new WP_Error( 'outside_join_window', __( 'Join button not active outside its window.', 'minhaj-core' ) );
		}

		// Registrant name via minhaj_students (decision 18 — no wp_users lookup for a child).
		[ $first_name, $family_initial, $registrant_email ] = $this->resolve_registrant_identity(
			$actor_user_id,
			$subject_student_id,
			ParticipantRole::HOST === $role
		);

		try {
			$response = $this->zoom->add_registrant(
				(string) $meeting['zoom_meeting_id'],
				array(
					'email'      => $registrant_email,
					'first_name' => $first_name,
					'last_name'  => $family_initial,
				)
			);
		} catch ( ZoomApiException $e ) {
			return new WP_Error( 'zoom_registrant_failed', $e->getMessage(), array( 'status' => $e->status() ) );
		}

		$now        = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', $now_ts + 15 * 60 );

		try {
			$participant_id = $this->repo->insert_participant(
				array(
					'session_id'         => $session_id,
					'actor_user_id'      => $actor_user_id,
					'subject_student_id' => $subject_student_id,
					'role'               => $role,
					'zoom_registrant_id' => isset( $response['registrant_id'] ) ? (string) $response['registrant_id'] : null,
					'issued_at'          => $now,
					'expires_at'         => $expires_at,
				)
			);
		} catch ( PersistenceException $e ) {
			if ( PersistenceException::DUPLICATE_SESSION_HOST === $e->kind() ) {
				return new WP_Error( 'duplicate_host', __( 'Session already has a host — spec §3.3.', 'minhaj-core' ) );
			}
			if ( PersistenceException::DUPLICATE_SESSION_SUBJECT === $e->kind() ) {
				return new WP_Error( 'duplicate_registrant', __( 'This student is already registered.', 'minhaj-core' ) );
			}
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::JOIN_TICKET_ISSUED, $session_id, $participant_id, $actor_user_id );

		return new JoinTicket(
			participant_id: $participant_id,
			role: (string) $role,
			redirect_to: (string) ( $response['join_url'] ?? '' ),
			expires_at_ts: $now_ts + 15 * 60,
		);
	}

	// ==================================================== concurrency guard (M-5 / M-6).

	/**
	 * Called by TimetableService::generate_for_group. Throws
	 * RuleViolationException if the new slots would push concurrency
	 * past the cap in any of their overlapping windows.
	 *
	 * @param array<int, array{start_utc:string, end_utc:string}> $candidate_slots
	 *
	 * @throws RuleViolationException On breach.
	 */
	public function assert_concurrency_within_cap( array $candidate_slots ): void {
		if ( array() === $candidate_slots ) {
			return;
		}

		$cap = (int) apply_filters( 'minhaj_max_concurrent_sessions', $this->compute_default_cap() );
		if ( $cap <= 0 ) {
			return;
		}

		foreach ( $candidate_slots as $slot ) {
			$existing = $this->repo->lock_meetings_between( $slot['start_utc'], $slot['end_utc'] );
			$peak     = count( $existing ) + 1; // +1 for the candidate itself
			if ( $peak > $cap ) {
				throw new RuleViolationException(
					'M-6',
					sprintf(
						'concurrency cap %d would be exceeded (peak %d) between %s and %s',
						$cap,
						$peak,
						$slot['start_utc'],
						$slot['end_utc']
					)
				);
			}

			if ( $peak >= (int) ceil( $cap * 0.8 ) ) {
				do_action( Events::CONCURRENCY_THRESHOLD_REACHED, $slot['start_utc'], $slot['end_utc'], $peak, $cap );
			}
		}
	}

	public function concurrency_at( string $from_utc, string $to_utc ): int {
		return count( $this->repo->lock_meetings_between( $from_utc, $to_utc ) );
	}

	// ================================================================= ingest_webhook.

	/**
	 * M-15 · M-16 · already-verified webhook is stored for later
	 * processing and returns quickly.
	 *
	 * @return int|WP_Error Event id.
	 */
	public function ingest_webhook( string $raw_body, string $dedup_key, string $event_type ) {
		$payload = (array) json_decode( $raw_body, true );

		// M-7 (7 in §7): scrub bearer secrets before persistence.
		$payload = $this->scrub_bearer_secrets( $payload );

		$now = current_time( 'mysql', true );

		try {
			$id = $this->repo->insert_event(
				array(
					'dedup_key'    => substr( $dedup_key, 0, 191 ),
					'event_type'   => substr( $event_type, 0, 64 ),
					'payload_json' => (string) wp_json_encode( $payload ),
					'received_at'  => $now,
					'status'       => EventStatus::RECEIVED,
					'attempts'     => 0,
				)
			);
		} catch ( PersistenceException $e ) {
			if ( PersistenceException::DUPLICATE_EVENT === $e->kind() ) {
				// M-17 · duplicate: return existing without error. The caller replies 200.
				return 0;
			}
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		return $id;
	}

	// =========================================================== process_pending_events.

	public function process_pending_events( int $limit = 100 ): int {
		$rows = $this->repo->list_pending_events( $limit );
		if ( array() === $rows ) {
			return 0;
		}

		$processed = 0;
		foreach ( $rows as $row ) {
			$this->process_one_event( $row );
			++$processed;
		}

		return $processed;
	}

	// ------------------------------------------------------------------- Helpers.

	/**
	 * @param array<string, mixed> $row
	 */
	private function process_one_event( array $row ): void {
		$now        = current_time( 'mysql', true );
		$event_id   = (int) $row['id'];
		$event_type = (string) $row['event_type'];
		$payload    = (array) json_decode( (string) $row['payload_json'], true );

		try {
			switch ( $event_type ) {
				case 'meeting.started':
					$this->handle_meeting_started( $payload );
					break;
				case 'meeting.ended':
					$this->handle_meeting_ended( $payload );
					break;
				default:
					// Unknown event → mark ignored so it does not retry forever.
					$this->repo->update_event(
						$event_id,
						array(
							'status'       => EventStatus::IGNORED,
							'processed_at' => $now,
						)
					);
					return;
			}

			$this->repo->update_event(
				$event_id,
				array(
					'status'       => EventStatus::PROCESSED,
					'processed_at' => $now,
				)
			);
		} catch ( Throwable $e ) {
			$this->repo->update_event(
				$event_id,
				array(
					'attempts'   => (int) $row['attempts'] + 1,
					'last_error' => substr( $e->getMessage(), 0, 255 ),
				)
			);
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function handle_meeting_started( array $payload ): void {
		$zoom_meeting_id = (string) ( $payload['object']['id'] ?? '' );
		if ( '' === $zoom_meeting_id ) {
			return;
		}

		$meeting = $this->repo->find_meeting_by_zoom_id( $zoom_meeting_id );
		if ( null === $meeting ) {
			return;
		}

		// M-18 · late started after ended → ignore.
		if ( MeetingState::ENDED === $meeting['state'] ) {
			return;
		}

		$now = current_time( 'mysql', true );
		$this->repo->update_meeting(
			(int) $meeting['id'],
			array(
				'state'             => MeetingState::STARTED,
				'zoom_meeting_uuid' => isset( $payload['object']['uuid'] ) ? (string) $payload['object']['uuid'] : null,
				'updated_at'        => $now,
			)
		);

		$this->repo->update_session_status( (int) $meeting['session_id'], 'live' );

		do_action( Events::SESSION_STARTED, (int) $meeting['session_id'], (int) $meeting['id'] );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function handle_meeting_ended( array $payload ): void {
		$zoom_meeting_id = (string) ( $payload['object']['id'] ?? '' );
		if ( '' === $zoom_meeting_id ) {
			return;
		}

		$meeting = $this->repo->find_meeting_by_zoom_id( $zoom_meeting_id );
		if ( null === $meeting ) {
			return;
		}

		$now = current_time( 'mysql', true );
		$this->repo->update_meeting(
			(int) $meeting['id'],
			array(
				'state'      => MeetingState::ENDED,
				'updated_at' => $now,
			)
		);

		$this->repo->update_session_status( (int) $meeting['session_id'], 'completed' );

		do_action( Events::SESSION_COMPLETED, (int) $meeting['session_id'], (int) $meeting['id'] );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function scrub_bearer_secrets( array $payload ): array {
		$dangerous = array( 'join_url', 'start_url', 'password', 'h323_password', 'encrypted_password' );

		$scrub = function ( array $node ) use ( &$scrub, $dangerous ) {
			foreach ( $node as $k => $v ) {
				if ( in_array( $k, $dangerous, true ) ) {
					unset( $node[ $k ] );
					continue;
				}
				if ( is_array( $v ) ) {
					$node[ $k ] = $scrub( $v );
				}
			}
			return $node;
		};

		return $scrub( $payload );
	}

	/**
	 * @param array<string, mixed> $session
	 */
	private function duration_minutes_for_session( array $session ): int {
		$start = strtotime( (string) $session['scheduled_start_utc'] . ' UTC' );
		$end   = strtotime( (string) $session['scheduled_end_utc'] . ' UTC' );
		return max( 1, (int) round( ( $end - $start ) / 60 ) );
	}

	private function compute_default_cap(): int {
		$total = 0;
		foreach ( $this->repo->list_active_licenses() as $lic ) {
			$total += (int) $lic['concurrent_capacity'];
		}
		return $total;
	}

	/**
	 * @return array{0:string, 1:string, 2:string}
	 */
	private function resolve_registrant_identity( int $actor_user_id, ?int $subject_student_id, bool $is_host ): array {
		global $wpdb;

		if ( $is_host ) {
			$user = get_userdata( $actor_user_id );
			return array(
				(string) ( $user->first_name ?? $user->display_name ?? 'Teacher' ),
				'',
				(string) ( $user->user_email ?? 'noreply@example.com' ),
			);
		}

		// Registrant email is the GUARDIAN's email — child email is never sent to Zoom.
		$guardian = get_userdata( $actor_user_id );
		$email    = (string) ( $guardian->user_email ?? 'noreply@example.com' );

		if ( null === $subject_student_id ) {
			return array( 'Student', '', $email );
		}

		// Decision 18 · minhaj_students, keyed by id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT first_name, family_name_initial FROM %i WHERE id = %d',
				$wpdb->prefix . 'minhaj_students',
				$subject_student_id
			),
			ARRAY_A
		);

		return array(
			(string) ( $row['first_name'] ?? 'Student' ),
			(string) ( $row['family_name_initial'] ?? '' ),
			$email,
		);
	}

	/**
	 * @return true|WP_Error
	 */
	private function require_actor( int $actor_user_id ) {
		if ( $actor_user_id <= 0 ) {
			return new WP_Error(
				'missing_actor',
				__( 'actor_user_id must be a positive integer — audit rows cannot be anonymous.', 'minhaj-core' )
			);
		}

		return true;
	}
}
