<?php
/**
 * Attendance public interface — spec-attendance-v1 §6.
 *
 * Layering contract (mirrors every other service in this plugin):
 *   • Callers enforce current_user_can + nonce BEFORE calling. Every
 *     write takes `int $actor_user_id` explicitly (R-11).
 *   • Domain rules throw RuleViolationException; the service catches
 *     at the outer boundary and returns WP_Error.
 *   • Writes ride a single transaction. Audit rows land BEFORE commit;
 *     do_action events fire AFTER commit (R-11).
 *   • R-6 · NO call to TimetableService lives inside this file (or
 *     anywhere in the Attendance module). The `no_show` path emits
 *     `minhaj_session_no_show` and Timetable's listener does the
 *     schedule work in its own module. The
 *     NoTimetableServiceCallInAttendanceGrepTest static scan enforces
 *     this at CI time.
 *
 * @package Minhaj\Modules\Attendance
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Attendance;

use Minhaj\Access\AccessPolicy;
use Minhaj\Modules\Attendance\Domain\AttendanceStatus;
use Minhaj\Modules\Attendance\Domain\Source;
use Minhaj\Modules\Attendance\Domain\TeacherPresenceStatus;
use Minhaj\Modules\Attendance\Repository\AttendanceRepository;
use Minhaj\Modules\Attendance\Repository\PersistenceException;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/*
 * WP_Error messages built here relay dev-facing rule codes and enum
 * values, never user-supplied HTML — WPCS EscapeOutput suppressed at
 * this boundary. Presentation escapes at render.
 */
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

final class AttendanceService {

	public function __construct(
		private readonly AttendanceRepository $repo,
		private readonly ?AccessPolicy $access = null
	) {}

	// ============================================================= record_interval.

	/**
	 * Called by the Zoom event handler on `meeting.participant_joined` and
	 * `meeting.participant_left`. Idempotent at the DB level via
	 * uq_interval on (participant_uuid, joined_at_utc) — replayed
	 * webhooks collide and get treated as a no-op.
	 *
	 * R-1 · resolution goes through
	 * `AttendanceRepository::find_participant_by_registrant`; a name
	 * from the Zoom payload is never inspected.
	 *
	 * @return int Interval id, or 0 on idempotent no-op.
	 */
	public function record_interval(
		int $session_id,
		string $participant_uuid,
		string $registrant_id,
		string $joined_utc,
		?string $left_utc
	): int {
		$now = current_time( 'mysql', true );

		try {
			return $this->repo->insert_interval(
				array(
					'session_id'            => $session_id,
					'zoom_participant_uuid' => $participant_uuid,
					'zoom_registrant_id'    => $registrant_id,
					'joined_at_utc'         => $joined_utc,
					'left_at_utc'           => $left_utc,
					'created_at'            => $now,
				)
			);
		} catch ( PersistenceException $e ) {
			if ( PersistenceException::DUPLICATE_INTERVAL === $e->kind() ) {
				return 0;
			}
			throw $e;
		}
	}

	/**
	 * Called when a `meeting.participant_left` webhook arrives after
	 * `participant_joined`. Updates the open interval's `left_at_utc`.
	 */
	public function close_interval(
		int $session_id,
		string $participant_uuid,
		string $left_utc
	): void {
		$open = $this->repo->find_open_interval( $session_id, $participant_uuid );
		if ( null === $open ) {
			return;
		}

		$this->repo->update_interval( (int) $open['id'], array( 'left_at_utc' => $left_utc ) );
	}

	public function close_open_intervals( int $session_id, string $ended_at_utc ): int {
		return $this->repo->close_open_intervals_for_session( $session_id, $ended_at_utc );
	}

	// =============================================================== finalize_session.

	/**
	 * R-10 · idempotent. R-12 · re-runs sum interval seconds from all
	 * encounters; the second run of finalize_session updates the same
	 * attendance rows with the new sum.
	 *
	 * @return array<int, array<string, mixed>> The attendance rows after finalize.
	 */
	public function finalize_session( int $session_id ): array {
		$session = $this->repo->find_session( $session_id );
		if ( null === $session ) {
			return array();
		}

		$now = current_time( 'mysql', true );

		// Teacher presence + no_show gate (R-7). If the teacher never
		// crossed the threshold, mark the session no_show, emit the
		// event, and STOP — R-7 explicitly does NOT count student
		// attendance in a no_show session.
		$teacher_presence = $this->repo->find_teacher_presence( $session_id );
		$teacher_state    = $this->classify_teacher_presence( $session, $teacher_presence );

		$this->repo->begin_transaction();
		try {
			$was_already_finalized = null !== $teacher_presence
				&& null !== ( $teacher_presence['finalized_at'] ?? null );

			$presence_id = $this->repo->upsert_teacher_presence(
				$session_id,
				(int) $session['teacher_id'],
				array(
					'status'       => $teacher_state,
					'finalized_at' => $was_already_finalized ? $teacher_presence['finalized_at'] : $now,
					'created_at'   => $teacher_presence['created_at'] ?? $now,
					'updated_at'   => $now,
				)
			);

			$rows = array();

			if ( TeacherPresenceStatus::NO_SHOW === $teacher_state ) {
				// R-7 · session becomes no_show. Emit the event AFTER commit.
				$this->repo->insert_audit(
					array(
						'session_id'    => $session_id,
						'student_id'    => null,
						'actor_user_id' => 0,
						'action'        => 'session.no_show',
						'payload_json'  => (string) wp_json_encode( array( 'teacher_id' => (int) $session['teacher_id'] ) ),
						'created_at'    => $now,
					)
				);
				$this->repo->commit();

				if ( ! $was_already_finalized ) {
					do_action( Events::SESSION_NO_SHOW, $session_id, (int) $session['teacher_id'] );
					do_action( Events::ATTENDANCE_FINALIZED, $session_id );
				}

				return array();
			}

			$rows = $this->recompute_student_rows( $session, $now );

			$this->repo->insert_audit(
				array(
					'session_id'    => $session_id,
					'student_id'    => null,
					'actor_user_id' => 0,
					'action'        => 'session.finalized',
					'payload_json'  => (string) wp_json_encode(
						array(
							'attended_rows' => count( $rows ),
							'reran'         => $was_already_finalized,
						)
					),
					'created_at'    => $now,
				)
			);

			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return array();
		}

		if ( ! $was_already_finalized ) {
			$any_present = false;
			foreach ( $rows as $row ) {
				if ( AttendanceStatus::ABSENT !== $row['auto_status'] ) {
					$any_present = true;
					break;
				}
			}

			if ( ! $any_present && array() !== $rows ) {
				do_action( Events::SESSION_ZERO_ATTENDANCE, $session_id );
			}

			do_action( Events::ATTENDANCE_FINALIZED, $session_id );
		}

		return $rows;
	}

	// ================================================================= amend.

	/**
	 * R-4 · 48-hour window from `scheduled_end_utc`. R-5 · no
	 * `attended_seconds` mutation. `auto_status` is preserved.
	 *
	 * @return true|WP_Error
	 */
	public function amend( int $actor_user_id, int $attendance_id, string $status, string $reason ) {
		if ( $actor_user_id <= 0 ) {
			return new WP_Error( 'missing_actor', __( 'actor_user_id is required.', 'minhaj-core' ) );
		}

		if ( ! AttendanceStatus::is_valid( $status ) ) {
			return new WP_Error( 'invalid_status', __( 'Unknown attendance status.', 'minhaj-core' ) );
		}

		$reason_clean = sanitize_text_field( $reason );
		if ( '' === trim( $reason_clean ) ) {
			return new WP_Error( 'reason_required', __( 'A reason is required.', 'minhaj-core' ) );
		}

		$row = $this->repo->find_attendance_by_id( $attendance_id );
		if ( null === $row ) {
			return new WP_Error( 'not_found', __( 'Attendance row not found.', 'minhaj-core' ) );
		}

		$session = $this->repo->find_session( (int) $row['session_id'] );
		if ( null === $session ) {
			return new WP_Error( 'session_not_found', __( 'Session not found.', 'minhaj-core' ) );
		}

		$window_hours = (int) apply_filters( 'minhaj_attendance_amend_window_hours', 48 );
		$end_ts       = strtotime( (string) $session['scheduled_end_utc'] . ' UTC' );
		$window_end   = $end_ts + $window_hours * 3600;

		if ( time() > $window_end ) {
			return new WP_Error(
				'outside_amend_window',
				sprintf(
					/* translators: %d: hours */
					__( 'Amendment window (%d h) has passed.', 'minhaj-core' ),
					$window_hours
				)
			);
		}

		$now = current_time( 'mysql', true );

		$this->repo->begin_transaction();
		try {
			$this->repo->update_attendance(
				$attendance_id,
				array(
					'status'       => $status,
					'source'       => Source::MANUAL,
					'amended_by'   => $actor_user_id,
					'amended_at'   => $now,
					'amend_reason' => $reason_clean,
					'updated_at'   => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'session_id'    => (int) $row['session_id'],
					'student_id'    => (int) $row['student_id'],
					'actor_user_id' => $actor_user_id,
					'action'        => 'attendance.amended',
					'payload_json'  => (string) wp_json_encode(
						array(
							'from'   => (string) $row['status'],
							'to'     => $status,
							'reason' => $reason_clean,
						)
					),
					'created_at'    => $now,
				)
			);

			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::ATTENDANCE_AMENDED, $attendance_id, $status, $actor_user_id );

		return true;
	}

	// ============================================================== set_note.

	/**
	 * R-9 · the only free-text field is `note_visible`. No hidden note.
	 *
	 * @return true|WP_Error
	 */
	public function set_note( int $actor_user_id, int $attendance_id, string $note_visible ) {
		if ( $actor_user_id <= 0 ) {
			return new WP_Error( 'missing_actor', __( 'actor_user_id is required.', 'minhaj-core' ) );
		}

		$note_clean = wp_kses_post( $note_visible );
		$row        = $this->repo->find_attendance_by_id( $attendance_id );
		if ( null === $row ) {
			return new WP_Error( 'not_found', __( 'Attendance row not found.', 'minhaj-core' ) );
		}

		$now = current_time( 'mysql', true );

		try {
			$this->repo->update_attendance(
				$attendance_id,
				array(
					'note_visible' => $note_clean,
					'updated_at'   => $now,
				)
			);
			$this->repo->insert_audit(
				array(
					'session_id'    => (int) $row['session_id'],
					'student_id'    => (int) $row['student_id'],
					'actor_user_id' => $actor_user_id,
					'action'        => 'attendance.note_set',
					'payload_json'  => (string) wp_json_encode( array( 'note_len' => strlen( $note_clean ) ) ),
					'created_at'    => $now,
				)
			);
		} catch ( Throwable $e ) {
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		return true;
	}

	// ================================================================= reads.

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function for_session( int $session_id, int $viewer_user_id = 0 ): array {
		$rows = $this->repo->list_attendance_for_session( $session_id );
		return $this->apply_viewer_scope( $rows, $viewer_user_id );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function for_student( int $student_id, ?int $group_id = null ): array {
		return $this->repo->list_attendance_for_student( $student_id, $group_id );
	}

	/**
	 * @return array{group_id:int, totals:array<string,int>}
	 */
	public function summary_for_group( int $group_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS n FROM %i WHERE group_id = %d GROUP BY status',
				$wpdb->prefix . 'minhaj_attendance',
				$group_id
			),
			ARRAY_A
		);

		$totals = array(
			'present' => 0,
			'late'    => 0,
			'absent'  => 0,
		);
		foreach ( (array) $rows as $r ) {
			$s            = (string) $r['status'];
			$totals[ $s ] = (int) $r['n'];
		}

		return array(
			'group_id' => $group_id,
			'totals'   => $totals,
		);
	}

	// ------------------------------------------------------------------- Helpers.

	/**
	 * @param array<string, mixed> $session
	 * @return array<int, array<string, mixed>>
	 */
	private function recompute_student_rows( array $session, string $now ): array {
		$session_id = (int) $session['id'];
		$group_id   = (int) $session['group_id'];
		$org_id     = isset( $session['org_id'] ) ? (int) $session['org_id'] : null;

		$duration_seconds   = max(
			1,
			strtotime( (string) $session['scheduled_end_utc'] . ' UTC' )
				- strtotime( (string) $session['scheduled_start_utc'] . ' UTC' )
		);
		$scheduled_start_ts = strtotime( (string) $session['scheduled_start_utc'] . ' UTC' );

		$present_ratio     = (float) apply_filters( 'minhaj_attendance_present_ratio', 0.70 );
		$late_minutes      = (int) apply_filters( 'minhaj_attendance_late_minutes', 10 );
		$late_seconds_gate = $late_minutes * 60;

		// Walk all intervals for the session, group by registrant_id (R-1).
		$intervals = $this->repo->list_intervals_for_session( $session_id );

		$per_registrant = array();
		foreach ( $intervals as $iv ) {
			$rid = (string) ( $iv['zoom_registrant_id'] ?? '' );
			if ( '' === $rid ) {
				continue;
			}
			$per_registrant[ $rid ][] = $iv;
		}

		$roster       = $this->repo->list_group_roster( $group_id );
		$rows_written = array();

		// 1 · authorised participants (registrant known → student known).
		$known_students = array();
		foreach ( $per_registrant as $rid => $rows ) {
			$participant = $this->repo->find_participant_by_registrant( $session_id, (string) $rid );
			if ( null === $participant || null === $participant['subject_student_id'] ) {
				// R-2 · unknown registrant: mark intervals attendance_id=NULL,
				// emit alarm, do NOT count.
				do_action( Events::UNKNOWN_PARTICIPANT_DETECTED, $session_id, (string) $rid );
				continue;
			}
			$student_id                    = (int) $participant['subject_student_id'];
			$known_students[ $student_id ] = true;

			$sums = $this->sum_intervals( $rows, $scheduled_start_ts );

			$existing        = $this->repo->find_attendance( $session_id, $student_id );
			$is_amended      = null !== $existing && null !== ( $existing['amended_at'] ?? null );
			$attended_status = AttendanceStatus::derive_auto(
				$sums['attended_seconds'],
				$sums['late_seconds'],
				$duration_seconds,
				$present_ratio,
				$late_seconds_gate
			);

			$auto_status_filtered = (string) apply_filters(
				'minhaj_attendance_auto_status',
				$attended_status,
				$session_id,
				$student_id,
				$sums
			);

			$data = array(
				'auto_status'      => $auto_status_filtered,
				'source'           => Source::ZOOM,
				'first_join_utc'   => $sums['first_join_utc'],
				'last_leave_utc'   => $sums['last_leave_utc'],
				'attended_seconds' => (int) $sums['attended_seconds'],
				'late_seconds'     => (int) $sums['late_seconds'],
				'group_id'         => $group_id,
				'org_id'           => $org_id,
				'created_at'       => $existing['created_at'] ?? $now,
				'updated_at'       => $now,
			);

			// R-10 · do not overwrite an amended human status.
			if ( ! $is_amended ) {
				$data['status'] = $auto_status_filtered;
			}

			$id = $this->repo->upsert_attendance( $session_id, $student_id, $data );

			$rows_written[] = array_merge(
				array(
					'id'         => $id,
					'session_id' => $session_id,
					'student_id' => $student_id,
				),
				$data
			);
		}

		// 2 · roster members with no intervals → absent row (idempotent on re-run).
		foreach ( $roster as $member ) {
			$sid = $member['student_id'];
			if ( isset( $known_students[ $sid ] ) ) {
				continue;
			}
			$existing   = $this->repo->find_attendance( $session_id, $sid );
			$is_amended = null !== $existing && null !== ( $existing['amended_at'] ?? null );

			$data = array(
				'auto_status'      => AttendanceStatus::ABSENT,
				'source'           => Source::ZOOM,
				'first_join_utc'   => null,
				'last_leave_utc'   => null,
				'attended_seconds' => 0,
				'late_seconds'     => 0,
				'group_id'         => $group_id,
				'org_id'           => $org_id,
				'created_at'       => $existing['created_at'] ?? $now,
				'updated_at'       => $now,
			);
			if ( ! $is_amended ) {
				$data['status'] = AttendanceStatus::ABSENT;
			}

			$id = $this->repo->upsert_attendance( $session_id, $sid, $data );

			$rows_written[] = array_merge(
				array(
					'id'         => $id,
					'session_id' => $session_id,
					'student_id' => $sid,
				),
				$data
			);
		}

		return $rows_written;
	}

	/**
	 * @param array<int, array<string, mixed>> $intervals
	 * @return array{attended_seconds:int, late_seconds:int, first_join_utc:?string, last_leave_utc:?string}
	 */
	private function sum_intervals( array $intervals, int $scheduled_start_ts ): array {
		$attended = 0;
		$late     = 0;
		$first    = null;
		$last     = null;

		foreach ( $intervals as $iv ) {
			$join_ts = strtotime( (string) $iv['joined_at_utc'] . ' UTC' );
			$left    = $iv['left_at_utc'] ?? null;
			if ( null === $left ) {
				continue; // R-3 · only closed intervals contribute
			}
			$left_ts   = strtotime( (string) $left . ' UTC' );
			$dur       = max( 0, $left_ts - $join_ts );
			$attended += $dur;

			if ( null === $first || $join_ts < strtotime( $first . ' UTC' ) ) {
				$first = gmdate( 'Y-m-d H:i:s', $join_ts );
			}
			if ( null === $last || $left_ts > strtotime( $last . ' UTC' ) ) {
				$last = gmdate( 'Y-m-d H:i:s', $left_ts );
			}

			// Late component: time from scheduled_start to first join.
			// Only the FIRST interval per registrant contributes lateness.
			if ( 0 === $late && $join_ts > $scheduled_start_ts ) {
				$late = $join_ts - $scheduled_start_ts;
			}
		}

		return array(
			'attended_seconds' => (int) $attended,
			'late_seconds'     => (int) $late,
			'first_join_utc'   => $first,
			'last_leave_utc'   => $last,
		);
	}

	/**
	 * @param array<string, mixed> $session
	 * @param array<string, mixed>|null $teacher_presence
	 */
	private function classify_teacher_presence( array $session, ?array $teacher_presence ): string {
		$no_show_minutes = (int) apply_filters( 'minhaj_teacher_no_show_minutes', 15 );
		$now_ts          = time();
		$scheduled_start = strtotime( (string) $session['scheduled_start_utc'] . ' UTC' );
		$scheduled_end   = strtotime( (string) $session['scheduled_end_utc'] . ' UTC' );

		if ( null === $teacher_presence
			|| empty( $teacher_presence['first_join_utc'] )
		) {
			// No teacher join ever, and the session's end has passed.
			if ( $now_ts >= $scheduled_end ) {
				return TeacherPresenceStatus::NO_SHOW;
			}
			return TeacherPresenceStatus::PENDING;
		}

		$first_join_ts = strtotime( (string) $teacher_presence['first_join_utc'] . ' UTC' );
		if ( $first_join_ts > $scheduled_start + $no_show_minutes * 60 ) {
			return TeacherPresenceStatus::LATE;
		}

		return TeacherPresenceStatus::ATTENDED;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function apply_viewer_scope( array $rows, int $viewer_user_id ): array {
		if ( 0 === $viewer_user_id || null === $this->access ) {
			return $rows;
		}

		// spec §7 · parent sees ONLY their child's row.
		$wards = array_flip(
			array_map(
				'intval',
				(array) apply_filters(
					'minhaj_attendance_viewer_wards',
					array(),
					$viewer_user_id
				)
			)
		);

		if ( array() === $wards ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static fn( array $r ) => isset( $wards[ (int) $r['student_id'] ] )
			)
		);
	}
}
