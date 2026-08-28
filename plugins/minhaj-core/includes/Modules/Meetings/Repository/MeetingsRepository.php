<?php
/**
 * Meetings persistence layer.
 *
 * Only file in the module that talks to $wpdb. Table names are passed via
 * %i so the sniff can verify prepared usage — the pattern every other
 * repository in this plugin uses.
 *
 * Concurrency measurement locks its window with SELECT ... FOR UPDATE:
 * the range-exclusion problem is the same one the teacher-window lock
 * solves. MySQL can't express "no overlapping meeting range" as a schema
 * constraint (§5.2 M-6).
 *
 * @package Minhaj\Modules\Meetings\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Repository;

use Minhaj\Modules\Meetings\Migrations\CreateMeetingsTables;

defined( 'ABSPATH' ) || exit;

/*
 * PersistenceException messages relay $wpdb->last_error verbatim so devs
 * can diagnose DB failures. They never reach an HTML response — the
 * service converts them to WP_Error at the boundary.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class MeetingsRepository {

	// -------------------------------------------------------- Transaction API.

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

	// -------------------------------------------------------------- Licenses.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function insert_license( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->licenses_table(), $data );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to insert license: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_active_licenses(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'active' ORDER BY id",
				$this->licenses_table()
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// -------------------------------------------------------------- Meetings.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException DUPLICATE_SESSION on uq_session collision.
	 */
	public function insert_meeting( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->meetings_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_session' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_SESSION,
				'session already has a meeting: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert meeting: ' . $error
		);
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function update_meeting( int $meeting_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->meetings_table(), $data, array( 'id' => $meeting_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update meeting: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_meeting_for_session( int $session_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d',
				$this->meetings_table(),
				$session_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_meeting_by_zoom_id( string $zoom_meeting_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE zoom_meeting_id = %s ORDER BY id DESC LIMIT 1',
				$this->meetings_table(),
				$zoom_meeting_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lock and return every non-cancelled meeting whose scheduled window
	 * intersects [$from_utc, $to_utc). Used by assert_concurrency_within_cap
	 * so a concurrent generator cannot slip past the count.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function lock_meetings_between( string $from_utc, string $to_utc ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, session_id, license_id, scheduled_start_utc, duration_minutes, state
					FROM %i
					WHERE state NOT IN ('revoked', 'failed', 'ended')
					  AND scheduled_start_utc < %s
					  AND DATE_ADD(scheduled_start_utc, INTERVAL duration_minutes MINUTE) > %s
					FOR UPDATE",
				$this->meetings_table(),
				$to_utc,
				$from_utc
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_pending_due( string $now_utc, string $horizon_utc, int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE state = 'pending' AND scheduled_start_utc BETWEEN %s AND %s ORDER BY scheduled_start_utc ASC LIMIT %d",
				$this->meetings_table(),
				$now_utc,
				$horizon_utc,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// ----------------------------------------------------------- Participants.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException DUPLICATE_SESSION_HOST / DUPLICATE_SESSION_SUBJECT on unique-key collision.
	 */
	public function insert_participant( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->participants_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_session_host' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_SESSION_HOST,
				'session already has a host: ' . $error
			);
		}
		if ( str_contains( $error, 'uq_session_subject' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_SESSION_SUBJECT,
				'student already registered for this session: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert participant: ' . $error
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_participant_for_subject( int $session_id, int $subject_student_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d AND subject_student_id = %d LIMIT 1',
				$this->participants_table(),
				$session_id,
				$subject_student_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	// ----------------------------------------------------------------- Events.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException DUPLICATE_EVENT on uq_dedup collision.
	 */
	public function insert_event( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->events_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_dedup' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_EVENT,
				'duplicate zoom event: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert zoom event: ' . $error
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_pending_events( int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'received' ORDER BY received_at ASC LIMIT %d",
				$this->events_table(),
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update_event( int $event_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->events_table(), $data, array( 'id' => $event_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update zoom event: ' . $wpdb->last_error
			);
		}
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
				'failed to insert meetings audit: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	// ------------------------------------------------------ Cross-module reads.

	/**
	 * Read a session row from the Timetable module. Meetings only needs
	 * teacher_id, group_id, scheduled_start_utc, scheduled_end_utc,
	 * anchor_timezone, status, org_id — the write path stays owned by
	 * Timetable.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_session( int $session_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, group_id, teacher_id, org_id, scheduled_start_utc, scheduled_end_utc, anchor_timezone, status
					FROM %i
					WHERE id = %d',
				$wpdb->prefix . 'minhaj_sessions',
				$session_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Cross-module write: only the two columns Meetings owns on a Timetable
	 * session (status transitions live/completed on meeting webhooks).
	 */
	public function update_session_status( int $session_id, string $status ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'minhaj_sessions',
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $session_id )
		);
	}

	// ---------------------------------------------------------------- Helpers.

	private function licenses_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateMeetingsTables::LICENSES_TABLE;
	}

	private function meetings_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateMeetingsTables::MEETINGS_TABLE;
	}

	private function participants_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateMeetingsTables::PARTICIPANTS_TABLE;
	}

	private function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateMeetingsTables::EVENTS_TABLE;
	}

	private function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateMeetingsTables::AUDIT_TABLE;
	}
}
