<?php
/**
 * Attendance persistence layer.
 *
 * Only file in the module that talks to $wpdb. Table names are passed
 * through %i so the sniff can verify prepared usage — the pattern
 * every other repository in this plugin uses.
 *
 * The interval insert catches DUPLICATE_INTERVAL from uq_interval and
 * turns webhook retries into no-ops at the DB level (§3.2, R-10).
 *
 * @package Minhaj\Modules\Attendance\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Attendance\Repository;

use Minhaj\Modules\Attendance\Migrations\CreateAttendanceTables;
use Minhaj\Modules\Groups\Migrations\CreateGroupsTables;
use Minhaj\Modules\Meetings\Migrations\CreateMeetingsTables;
use Minhaj\Modules\Timetable\Migrations\CreateTimetableTables;

defined( 'ABSPATH' ) || exit;

/*
 * PersistenceException messages relay $wpdb->last_error verbatim so
 * devs can diagnose. They never reach an HTML response — the service
 * converts them to WP_Error at the boundary.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class AttendanceRepository {

	// ---------------------------------------------------------- Transactions.

	public function begin_transaction(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
	}

	public function commit(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'COMMIT' );
	}

	public function rollback(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'ROLLBACK' );
	}

	// ---------------------------------------------------------------- Intervals.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException DUPLICATE_INTERVAL on uq_interval collision.
	 */
	public function insert_interval( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->intervals_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_interval' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_INTERVAL,
				'duplicate attendance interval: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert attendance interval: ' . $error
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update_interval( int $interval_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->intervals_table(), $data, array( 'id' => $interval_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update attendance interval: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * Open interval (left_at_utc IS NULL) for a given (session_id, participant_uuid).
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_open_interval( int $session_id, string $participant_uuid ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d AND zoom_participant_uuid = %s AND left_at_utc IS NULL ORDER BY id DESC LIMIT 1',
				$this->intervals_table(),
				$session_id,
				$participant_uuid
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_intervals_for_session( int $session_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d ORDER BY joined_at_utc ASC, id ASC',
				$this->intervals_table(),
				$session_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Close every open interval (left_at_utc IS NULL) for the session at
	 * the given timestamp. Used when a meeting.ended arrives without a
	 * matching participant.left for every attendee.
	 */
	public function close_open_intervals_for_session( int $session_id, string $left_at_utc ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET left_at_utc = %s WHERE session_id = %d AND left_at_utc IS NULL',
				$this->intervals_table(),
				$left_at_utc,
				$session_id
			)
		);

		return (int) $affected;
	}

	// -------------------------------------------------------------- Attendance.

	/**
	 * @param array<string, mixed> $data
	 */
	public function upsert_attendance( int $session_id, int $student_id, array $data ): int {
		global $wpdb;

		$existing = $this->find_attendance( $session_id, $student_id );

		if ( null === $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert(
				$this->attendance_table(),
				array_merge(
					array(
						'session_id' => $session_id,
						'student_id' => $student_id,
					),
					$data
				)
			);
			if ( false === $result ) {
				throw new PersistenceException(
					PersistenceException::WRITE_FAILED,
					'failed to insert attendance: ' . $wpdb->last_error
				);
			}
			return (int) $wpdb->insert_id;
		}

		// R-10 · re-processing MUST NOT overwrite an amended human status.
		// Callers pass ONLY the fields safe to update; the service layer
		// filters `status` out when `amended_at IS NOT NULL`.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->attendance_table(), $data, array( 'id' => (int) $existing['id'] ) );
		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update attendance: ' . $wpdb->last_error
			);
		}
		return (int) $existing['id'];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_attendance( int $session_id, int $student_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d AND student_id = %d',
				$this->attendance_table(),
				$session_id,
				$student_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_attendance_by_id( int $attendance_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->attendance_table(),
				$attendance_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update_attendance( int $attendance_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->attendance_table(), $data, array( 'id' => $attendance_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update attendance: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_attendance_for_session( int $session_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d ORDER BY student_id ASC',
				$this->attendance_table(),
				$session_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_attendance_for_student( int $student_id, ?int $group_id = null ): array {
		global $wpdb;

		if ( null === $group_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE student_id = %d ORDER BY session_id DESC',
					$this->attendance_table(),
					$student_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE student_id = %d AND group_id = %d ORDER BY session_id DESC',
					$this->attendance_table(),
					$student_id,
					$group_id
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	// ------------------------------------------------------- Teacher presence.

	/**
	 * @param array<string, mixed> $data
	 */
	public function upsert_teacher_presence( int $session_id, int $teacher_id, array $data ): int {
		global $wpdb;

		$existing = $this->find_teacher_presence( $session_id );

		if ( null === $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert(
				$this->teacher_presence_table(),
				array_merge(
					array(
						'session_id' => $session_id,
						'teacher_id' => $teacher_id,
					),
					$data
				)
			);
			if ( false === $result ) {
				throw new PersistenceException(
					PersistenceException::WRITE_FAILED,
					'failed to insert teacher presence: ' . $wpdb->last_error
				);
			}
			return (int) $wpdb->insert_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->teacher_presence_table(), $data, array( 'id' => (int) $existing['id'] ) );

		return (int) $existing['id'];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_teacher_presence( int $session_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d',
				$this->teacher_presence_table(),
				$session_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	// ------------------------------------------------------------------ Audit.

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert_audit( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->audit_table(), $data );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to insert attendance audit: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	// ---------------------------------------------------- Cross-module reads.

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_session( int $session_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, group_id, teacher_id, org_id, scheduled_start_utc, scheduled_end_utc, status
					FROM %i WHERE id = %d',
				$wpdb->prefix . CreateTimetableTables::SESSIONS_TABLE,
				$session_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Roster: active students in a group, together with the subject id
	 * used in the session_participants row (spec-zoom-sessions-v1 M-12).
	 *
	 * @return array<int, array{student_id:int, group_id:int}>
	 */
	public function list_group_roster( int $group_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT student_id, group_id FROM %i WHERE group_id = %d AND status = 'active'",
				$wpdb->prefix . CreateGroupsTables::MEMBERS_TABLE,
				$group_id
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $r ): array => array(
				'student_id' => (int) $r['student_id'],
				'group_id'   => (int) $r['group_id'],
			),
			(array) $rows
		);
	}

	/**
	 * Look up a participant row by (session_id, zoom_registrant_id). The
	 * `subject_student_id` on that row is the ONLY key we accept — R-1
	 * forbids matching by displayed name.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_participant_by_registrant( int $session_id, string $zoom_registrant_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d AND zoom_registrant_id = %s LIMIT 1',
				$wpdb->prefix . CreateMeetingsTables::PARTICIPANTS_TABLE,
				$session_id,
				$zoom_registrant_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	// ---------------------------------------------------------------- Helpers.

	private function attendance_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateAttendanceTables::ATTENDANCE_TABLE;
	}

	private function intervals_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateAttendanceTables::INTERVALS_TABLE;
	}

	private function teacher_presence_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateAttendanceTables::TEACHER_PRESENCE_TABLE;
	}

	private function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateAttendanceTables::AUDIT_TABLE;
	}
}
