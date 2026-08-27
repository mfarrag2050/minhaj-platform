<?php
/**
 * Timetable persistence layer.
 *
 * Only file in the module that talks to $wpdb. Table names are passed via
 * the %i placeholder (WP 6.2+), which lets the sniff verify prepared usage
 * without table-name interpolation false positives.
 *
 * The teacher-window read used inside generate_for_group takes FOR UPDATE
 * because §7 R-5 cannot be enforced with a MySQL constraint — the app-side
 * check must hold a lock across the whole window during the insert batch.
 *
 * @package Minhaj\Modules\Timetable\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable\Repository;

use Minhaj\Modules\Timetable\Migrations\CreateTimetableTables;

defined( 'ABSPATH' ) || exit;

/*
 * PersistenceException messages here relay $wpdb->last_error verbatim so
 * developers can diagnose DB failures. These messages never reach an HTML
 * response — the service layer converts them to WP_Error before returning.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class TimetableRepository {

	// ---------------------------------------------------------- Transaction API.

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

	// --------------------------------------------------------------- Availability.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function insert_availability( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->availability_table(), $data );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to insert availability: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * All active availability slots for a teacher on a given date.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_availability_for_teacher_on( int $teacher_id, string $date_iso ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE teacher_id = %d AND effective_from <= %s AND (effective_to IS NULL OR effective_to >= %s)',
				$this->availability_table(),
				$teacher_id,
				$date_iso,
				$date_iso
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All active availability slots for a teacher across the pattern window.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_availability_for_teacher_between( int $teacher_id, string $from_date, string $to_date ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE teacher_id = %d AND effective_from <= %s AND (effective_to IS NULL OR effective_to >= %s)',
				$this->availability_table(),
				$teacher_id,
				$to_date,
				$from_date
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// -------------------------------------------------------------------- Absences.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function insert_absence( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->absences_table(), $data );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to insert absence: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_absences_for_teacher_between( int $teacher_id, string $from_utc, string $to_utc ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE teacher_id = %d AND starts_at_utc < %s AND ends_at_utc > %s',
				$this->absences_table(),
				$teacher_id,
				$to_utc,
				$from_utc
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// -------------------------------------------------------------------- Patterns.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function insert_pattern( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->patterns_table(), $data );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to insert schedule pattern: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_active_pattern_for_group( int $group_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE group_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
				$this->patterns_table(),
				$group_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	// -------------------------------------------------------------------- Sessions.

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_session( int $session_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->sessions_table(),
				$session_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * FOR UPDATE variant used by cancel(). Locks the row for the length of
	 * the outer transaction so a concurrent write cannot mutate the session
	 * between the state check and the update.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_session_for_update( int $session_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d FOR UPDATE',
				$this->sessions_table(),
				$session_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function update_session( int $session_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->sessions_table(), $data, array( 'id' => $session_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update session: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * Shift lesson_no down by one for every held session after $after_sequence
	 * in the group. Cancelled rows keep lesson_no NULL and are skipped by the
	 * `status <> 'cancelled'` filter.
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function decrement_lesson_no_after_sequence( int $group_id, int $after_sequence ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET lesson_no = lesson_no - 1 WHERE group_id = %d AND sequence_no > %d AND status <> 'cancelled' AND lesson_no IS NOT NULL",
				$this->sessions_table(),
				$group_id,
				$after_sequence
			)
		);

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to shift lesson_no: ' . $wpdb->last_error
			);
		}
	}

	public function max_sequence_no_for_group( int $group_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(sequence_no) FROM %i WHERE group_id = %d',
				$this->sessions_table(),
				$group_id
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	public function max_lesson_no_for_group( int $group_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(lesson_no) FROM %i WHERE group_id = %d',
				$this->sessions_table(),
				$group_id
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	public function max_scheduled_start_utc_for_group( int $group_id ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(scheduled_start_utc) FROM %i WHERE group_id = %d',
				$this->sessions_table(),
				$group_id
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * DATE part of MAX(scheduled_start_utc) across sessions that still count
	 * toward the group's committed hours — excludes `cancelled` (never happens)
	 * and `unscheduled` (no time yet). Feeds the derived expected_end_date
	 * per spec-groups-v1 §3.1.
	 *
	 * Returns null when the group has no dated sessions yet — the derivation
	 * refuses to guess.
	 */
	public function max_active_scheduled_date_for_group( int $group_id ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT DATE(MAX(scheduled_start_utc)) FROM %i WHERE group_id = %d AND scheduled_start_utc IS NOT NULL AND status NOT IN ('cancelled', 'unscheduled')",
				$this->sessions_table(),
				$group_id
			)
		);

		return null === $value ? null : (string) $value;
	}

	public function count_unscheduled_makeups_for_group( int $group_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE group_id = %d AND status = 'unscheduled'",
				$this->sessions_table(),
				$group_id
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	/**
	 * Narrow cross-module write: the derived-dates listener owns two columns
	 * on the groups table (expected_end_date + has_unscheduled_makeup). It
	 * is scoped to those two columns intentionally — the Groups module still
	 * owns every other write to its table.
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function update_group_derived_dates(
		int $group_id,
		?string $expected_end_date,
		int $has_unscheduled_makeup
	): void {
		global $wpdb;

		$data = array(
			'expected_end_date'      => $expected_end_date,
			'has_unscheduled_makeup' => $has_unscheduled_makeup > 0 ? 1 : 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$wpdb->prefix . 'minhaj_groups',
			$data,
			array( 'id' => $group_id )
		);

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update group derived dates: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * Pending make-ups: rows with status='unscheduled' and a makeup_for_id
	 * link back to a cancelled session. Feeds the debt-queue CLI.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_unscheduled_makeups( int $limit = 500 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, group_id, sequence_no, lesson_no, teacher_id, makeup_for_id, anchor_timezone, created_at FROM %i WHERE status = 'unscheduled' ORDER BY created_at ASC LIMIT %d",
				$this->sessions_table(),
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_pattern( int $pattern_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->patterns_table(),
				$pattern_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lock and return every existing session for a teacher whose window
	 * intersects [$from_utc, $to_utc). Used by generate_for_group to enforce
	 * §7 R-5 while a batch of new sessions is being inserted.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function lock_teacher_sessions_between( int $teacher_id, string $from_utc, string $to_utc ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, group_id, scheduled_start_utc, scheduled_end_utc, status FROM %i WHERE teacher_id = %d AND scheduled_start_utc IS NOT NULL AND scheduled_start_utc < %s AND scheduled_end_utc > %s AND status NOT IN ('cancelled', 'unscheduled') FOR UPDATE",
				$this->sessions_table(),
				$teacher_id,
				$to_utc,
				$from_utc
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On failure — DUPLICATE_SEQUENCE if the
	 *                              (group_id, sequence_no) unique key collided.
	 */
	public function insert_session( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->sessions_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;

		if ( str_contains( $error, 'uq_group_sequence' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_SEQUENCE,
				'session sequence collision for group: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert session: ' . $error
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_sessions_for_group( int $group_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d ORDER BY sequence_no ASC',
				$this->sessions_table(),
				$group_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All overlapping pairs across every teacher — used by the nightly cli
	 * guard (spec §7 R-5 caveat). Self-join by teacher, kept small by the
	 * teacher_start index. `id_a < id_b` deduplicates the pair set.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function find_teacher_session_overlaps(): array {
		global $wpdb;

		$table = $this->sessions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id AS id_a, a.group_id AS group_a, a.scheduled_start_utc AS start_a, a.scheduled_end_utc AS end_a,
						b.id AS id_b, b.group_id AS group_b, b.scheduled_start_utc AS start_b, b.scheduled_end_utc AS end_b,
						a.teacher_id
					FROM %i a
					INNER JOIN %i b
						ON a.teacher_id = b.teacher_id
						AND a.id < b.id
						AND a.status NOT IN ('cancelled', 'unscheduled')
						AND b.status NOT IN ('cancelled', 'unscheduled')
						AND a.scheduled_start_utc IS NOT NULL
						AND b.scheduled_start_utc IS NOT NULL
						AND a.scheduled_start_utc < b.scheduled_end_utc
						AND a.scheduled_end_utc > b.scheduled_start_utc
					ORDER BY a.teacher_id ASC, a.scheduled_start_utc ASC",
				$table,
				$table
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// ------------------------------------------------------------------------ Audit.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function insert_audit( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->audit_table(), $data );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to insert audit row: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	// ------------------------------------------------------ Cross-module reads.

	/**
	 * Read a group row from the groups module. Timetable reads teacher_id and
	 * total_sessions from the group; write access stays owned by that module.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_group( int $group_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL',
				$wpdb->prefix . 'minhaj_groups',
				$group_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	// ---------------------------------------------------------------- Helpers.

	private function availability_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateTimetableTables::AVAILABILITY_TABLE;
	}

	private function absences_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateTimetableTables::ABSENCES_TABLE;
	}

	private function patterns_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateTimetableTables::PATTERNS_TABLE;
	}

	private function sessions_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateTimetableTables::SESSIONS_TABLE;
	}

	private function audit_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateTimetableTables::AUDIT_TABLE;
	}
}
