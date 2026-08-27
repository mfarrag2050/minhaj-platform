<?php
/**
 * Groups persistence layer.
 *
 * Only file in the module that talks to $wpdb. The service layer stays
 * ignorant of SQL, and tests can swap this class with a double.
 *
 * All table names are passed via the %i placeholder (WordPress 6.2+),
 * which lets the sniff verify prepared usage without table-name
 * interpolation false positives.
 *
 * @package Minhaj\Modules\Groups\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Repository;

use Minhaj\Modules\Groups\Migrations\CreateGroupsTables;

defined( 'ABSPATH' ) || exit;

/*
 * PersistenceException messages here relay $wpdb->last_error verbatim so
 * developers can diagnose DB failures. These messages never reach an HTML
 * response — the service layer converts them to WP_Error before returning.
 * Escaping raw DB error strings as HTML would corrupt them in logs.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class GroupRepository {

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

	// -------------------------------------------------------------- Group reads.

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_group( int $group_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL',
				$this->groups_table(),
				$group_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_group_for_update( int $group_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE',
				$this->groups_table(),
				$group_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function code_exists( string $code ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE code = %s LIMIT 1',
				$this->groups_table(),
				$code
			)
		);

		return null !== $found;
	}

	// ------------------------------------------------------------ Group writes.

	/**
	 * @param array<string, mixed> $data
	 * @throws PersistenceException When the insert fails.
	 */
	public function insert_group( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->groups_table(), $data );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to insert group: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update_group( int $group_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->groups_table(), $data, array( 'id' => $group_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update group: ' . $wpdb->last_error
			);
		}
	}

	// ---------------------------------------------------------- Member reads.

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_membership( int $membership_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->members_table(),
				$membership_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_active_member( int $group_id, int $student_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE group_id = %d AND student_id = %d AND status = 'active'",
				$this->members_table(),
				$group_id,
				$student_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function count_active_members( int $group_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE group_id = %d AND status = 'active'",
				$this->members_table(),
				$group_id
			)
		);

		return (int) $count;
	}

	/**
	 * @return array<int, int>
	 */
	public function find_used_seat_indices( int $group_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT seat_index FROM %i WHERE group_id = %d AND status = 'active'",
				$this->members_table(),
				$group_id
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	// ----------------------------------------------------------- Member writes.

	/**
	 * @param array<string, mixed> $data
	 * @throws PersistenceException On any DB failure, with kind() indicating which unique key collided.
	 */
	public function insert_member( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->members_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;

		if ( str_contains( $error, 'uq_active_seat' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_SEAT,
				'seat taken by concurrent request: ' . $error
			);
		}

		if ( str_contains( $error, 'uq_active_student' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_STUDENT,
				'student already active in this group: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert member: ' . $error
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update_member( int $membership_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->members_table(), $data, array( 'id' => $membership_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update membership: ' . $wpdb->last_error
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
				'failed to insert audit row: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	// ------------------------------------------------------------- Helpers.

	private function groups_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateGroupsTables::GROUPS_TABLE;
	}

	private function members_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateGroupsTables::MEMBERS_TABLE;
	}

	private function audit_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateGroupsTables::AUDIT_TABLE;
	}
}
