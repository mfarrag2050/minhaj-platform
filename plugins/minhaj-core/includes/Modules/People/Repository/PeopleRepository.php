<?php
/**
 * People persistence layer.
 *
 * Only file in the module that talks to $wpdb. Table names go through %i
 * (WP 6.2+), keeping the sniff able to verify prepared usage without
 * table-name interpolation false positives.
 *
 * The uq_active_primary_guardian unique index is enforced by MySQL against
 * a STORED generated column — insert_guardianship classifies the collision
 * as DUPLICATE_PRIMARY_GUARDIAN so the service can translate the DB error
 * into a clean WP_Error at the boundary.
 *
 * @package Minhaj\Modules\People\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People\Repository;

use Minhaj\Modules\People\Migrations\CreatePeopleTables;
use Minhaj\Modules\People\Migrations\RestructureStudentsForNonWpIdentity;

defined( 'ABSPATH' ) || exit;

/*
 * PersistenceException messages here relay $wpdb->last_error verbatim so
 * developers can diagnose DB failures. These messages never reach HTML
 * responses — the service layer converts them to WP_Error before returning.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class PeopleRepository {

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

	// ---------------------------------------------------------- Guardianship.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException DUPLICATE_PRIMARY_GUARDIAN on the S-2 unique-index collision.
	 */
	public function insert_guardianship( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->guardianship_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_active_primary_guardian' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_PRIMARY_GUARDIAN,
				'student already has an active primary guardian: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert guardianship: ' . $error
		);
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function update_guardianship( int $guardianship_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->guardianship_table(), $data, array( 'id' => $guardianship_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update guardianship: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_active_primary_guardian( int $student_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE student_id = %d AND is_primary = 1 AND ended_at IS NULL',
				$this->guardianship_table(),
				$student_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_guardians_of_student( int $student_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE student_id = %d ORDER BY id ASC',
				$this->guardianship_table(),
				$student_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// ------------------------------------------------------------- Students.
	//
	// Decision 18 · the child's identity (id) is separate from the
	// optional WordPress user link (user_id). Every write below insists
	// user_id is either NULL or provided by the caller — the service
	// layer never fabricates one for a child.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function insert_student( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->students_table(), $data );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to insert student: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function update_student( int $student_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->students_table(), $data, array( 'id' => $student_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update student: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_student( int $student_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->students_table(),
				$student_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Simple case-insensitive prefix search for the admin autocomplete
	 * (Groups::AjaxSearchController). Returns non-anonymised rows only.
	 *
	 * @return array<int, array{id:int, first_name:string, family_name_initial:string, market:string}>
	 */
	public function search_students_by_first_name( string $query, int $limit = 15 ): array {
		global $wpdb;

		$like = $wpdb->esc_like( $query ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, first_name, family_name_initial, market
					FROM %i
					WHERE anonymized_at IS NULL AND first_name LIKE %s
					ORDER BY first_name, id
					LIMIT %d',
				$this->students_table(),
				$like,
				max( 1, $limit )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'                  => (int) $r['id'],
				'first_name'          => (string) $r['first_name'],
				'family_name_initial' => (string) $r['family_name_initial'],
				'market'              => (string) $r['market'],
			);
		}

		return $out;
	}

	// ---------------------------------------------------------- Teacher profiles.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function upsert_teacher_profile( array $data ): void {
		global $wpdb;

		$existing = $this->find_teacher_profile( (int) $data['user_id'] );

		if ( null === $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert( $this->teacher_profiles_table(), $data );
		} else {
			$user_id = (int) $data['user_id'];
			unset( $data['user_id'], $data['created_at'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $this->teacher_profiles_table(), $data, array( 'user_id' => $user_id ) );
		}

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to upsert teacher profile: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_teacher_profile( int $user_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d',
				$this->teacher_profiles_table(),
				$user_id
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
	public function update_teacher_profile( int $user_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->teacher_profiles_table(), $data, array( 'user_id' => $user_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update teacher profile: ' . $wpdb->last_error
			);
		}
	}

	// ---------------------------------------------------------- Teacher languages.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException DUPLICATE_TEACHER_LANGUAGE on the uq_teacher_locale collision.
	 */
	public function insert_teacher_language( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->teacher_languages_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_teacher_locale' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_TEACHER_LANGUAGE,
				'teacher_id + locale already exists: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert teacher language: ' . $error
		);
	}

	public function delete_teacher_languages( int $teacher_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->teacher_languages_table(), array( 'teacher_id' => $teacher_id ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_teacher_languages( int $teacher_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE teacher_id = %d ORDER BY locale ASC',
				$this->teacher_languages_table(),
				$teacher_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function count_teacher_teachable_languages( int $teacher_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE teacher_id = %d AND can_teach_in = 1',
				$this->teacher_languages_table(),
				$teacher_id
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	/**
	 * Assignable teachers who can teach in $locale — the intersection of:
	 * profile.status='active', at least one non-expired valid safeguarding
	 * check, and at least one can_teach_in=1 row for $locale.
	 *
	 * Directly answers S-8 / the language_coverage report.
	 */
	public function count_assignable_teachers_for_locale( string $locale, string $today_iso ): int {
		global $wpdb;

		$profiles  = $this->teacher_profiles_table();
		$languages = $this->teacher_languages_table();
		$checks    = $this->safeguarding_checks_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.user_id)
					FROM %i p
					INNER JOIN %i l ON l.teacher_id = p.user_id AND l.can_teach_in = 1 AND l.locale = %s
					INNER JOIN %i c ON c.teacher_id = p.user_id AND c.status = 'valid' AND (c.expires_at IS NULL OR c.expires_at >= %s)
					WHERE p.status = %s",
				$profiles,
				$languages,
				$locale,
				$checks,
				$today_iso,
				'active'
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	// ---------------------------------------------------------- Checks.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function insert_check( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->safeguarding_checks_table(), $data );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to insert safeguarding check: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null The most recent valid non-expired check, or null.
	 */
	public function find_current_valid_check( int $teacher_id, string $today_iso ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE teacher_id = %d AND status = 'valid' AND (expires_at IS NULL OR expires_at >= %s) ORDER BY expires_at DESC LIMIT 1",
				$this->safeguarding_checks_table(),
				$teacher_id,
				$today_iso
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Checks in status=valid whose expires_at falls between $from_iso and
	 * $to_iso — feeds the daily cron scanner (S-5).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_checks_expiring_between( string $from_iso, string $to_iso ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'valid' AND expires_at IS NOT NULL AND expires_at BETWEEN %s AND %s ORDER BY expires_at ASC",
				$this->safeguarding_checks_table(),
				$from_iso,
				$to_iso
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// ---------------------------------------------------------- Audit.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function insert_audit( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->person_audit_table(), $data );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to insert person audit row: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	public function count_audit_for_subject( string $subject_type, int $subject_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE subject_type = %s AND subject_id = %d',
				$this->person_audit_table(),
				$subject_type,
				$subject_id
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	// ---------------------------------------------------------- Helpers.

	private function guardianship_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreatePeopleTables::GUARDIANSHIP_TABLE;
	}

	private function students_table(): string {
		global $wpdb;

		return $wpdb->prefix . RestructureStudentsForNonWpIdentity::STUDENTS_TABLE;
	}

	private function teacher_profiles_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreatePeopleTables::TEACHER_PROFILES_TABLE;
	}

	private function teacher_languages_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreatePeopleTables::TEACHER_LANGUAGES_TABLE;
	}

	private function safeguarding_checks_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreatePeopleTables::SAFEGUARDING_CHECKS_TABLE;
	}

	private function person_audit_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreatePeopleTables::PERSON_AUDIT_TABLE;
	}
}
