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

use Minhaj\Modules\Groups\Migrations\CreateBatchesTable;
use Minhaj\Modules\Groups\Migrations\CreateGroupCodeCounters;
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

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_code' ) || str_contains( strtolower( $error ), "'code'" ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_CODE,
				'group code collision: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert group: ' . $error
		);
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

	// ------------------------------------------------------ Read-only listings.

	/**
	 * @param array<string, mixed> $filters Accepts: status, batch_id, teacher_id, teaching_language, search.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_groups( array $filters, int $per_page, int $offset ): array {
		global $wpdb;

		[ $where, $args ] = $this->build_group_filter_clause( $filters );

		$args[] = max( 1, $per_page );
		$args[] = max( 0, $offset );

		/*
		 * $where is composed only of hard-coded SQL fragments (see
		 * build_group_filter_clause) — every dynamic value enters through the
		 * %s/%d/%i placeholders in $args, so prepare() gets exactly the
		 * replacements it needs. The sniffs disabled here cannot see across
		 * the helper's return value.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql  = $wpdb->prepare(
			'SELECT * FROM %i WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			$this->groups_table(),
			...$args
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public function count_groups( array $filters ): int {
		global $wpdb;

		[ $where, $args ] = $this->build_group_filter_clause( $filters );

		// See list_groups() — $where holds SQL fragments only; placeholders match $args exactly.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql   = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE ' . $where,
			$this->groups_table(),
			...$args
		);
		$count = $wpdb->get_var( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return (int) $count;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_members( int $group_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d ORDER BY seat_index ASC, id ASC',
				$this->members_table(),
				$group_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_audit( int $group_id, int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d ORDER BY id DESC LIMIT %d',
				$this->audit_table(),
				$group_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<int, string>
	 */
	public function distinct_teaching_languages(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT teaching_language FROM %i WHERE teaching_language <> '' AND deleted_at IS NULL ORDER BY teaching_language",
				$this->groups_table()
			)
		);

		return array_values( array_filter( array_map( 'strval', (array) $rows ) ) );
	}

	/**
	 * @return array<int, int>
	 */
	public function distinct_teacher_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT teacher_id FROM %i WHERE teacher_id IS NOT NULL AND deleted_at IS NULL ORDER BY teacher_id',
				$this->groups_table()
			)
		);

		return array_values( array_filter( array_map( 'intval', (array) $rows ) ) );
	}

	/**
	 * @return array<int, int>
	 */
	public function distinct_batch_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT batch_id FROM %i WHERE batch_id IS NOT NULL AND deleted_at IS NULL ORDER BY batch_id',
				$this->groups_table()
			)
		);

		return array_values( array_filter( array_map( 'intval', (array) $rows ) ) );
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{0:string, 1:array<int, mixed>}
	 */
	private function build_group_filter_clause( array $filters ): array {
		global $wpdb;

		$where_parts = array( 'deleted_at IS NULL' );
		$args        = array();

		if ( isset( $filters['status'] ) && '' !== $filters['status'] ) {
			$where_parts[] = 'status = %s';
			$args[]        = (string) $filters['status'];
		}

		if ( isset( $filters['batch_id'] ) && (int) $filters['batch_id'] > 0 ) {
			$where_parts[] = 'batch_id = %d';
			$args[]        = (int) $filters['batch_id'];
		}

		if ( isset( $filters['teacher_id'] ) && (int) $filters['teacher_id'] > 0 ) {
			$where_parts[] = 'teacher_id = %d';
			$args[]        = (int) $filters['teacher_id'];
		}

		if ( isset( $filters['teaching_language'] ) && '' !== $filters['teaching_language'] ) {
			$where_parts[] = 'teaching_language = %s';
			$args[]        = (string) $filters['teaching_language'];
		}

		if ( isset( $filters['search'] ) && '' !== $filters['search'] ) {
			$like          = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where_parts[] = '(code LIKE %s OR level LIKE %s)';
			$args[]        = $like;
			$args[]        = $like;
		}

		return array( implode( ' AND ', $where_parts ), $args );
	}

	/**
	 * @return array<int, array{id:int, code:string, status:string}>
	 */
	public function search_groups_by_code( string $query, int $exclude_id = 0, int $limit = 15 ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $query ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, code, status FROM %i WHERE code LIKE %s AND id <> %d AND deleted_at IS NULL ORDER BY id DESC LIMIT %d',
				$this->groups_table(),
				$like,
				$exclude_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $r ): array {
				return array(
					'id'     => (int) $r['id'],
					'code'   => (string) $r['code'],
					'status' => (string) $r['status'],
				);
			},
			$rows
		);
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

	// ---------------------------------------------------------------- Batches.

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_batch( int $batch_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->batches_table(),
				$batch_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * List batches an admin can pick from — planned + open + running.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_selectable_batches( int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status IN ('planned','open','running') ORDER BY starts_on ASC, id ASC LIMIT %d",
				$this->batches_table(),
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count how many groups already carry the given (batch_id, level),
	 * **including soft-deleted rows**. Deleted rows still consume their
	 * slot — this method is a historical read, not a live one. Kept for
	 * diagnostics; `reserve_next_seq` is what code generation uses.
	 */
	public function count_groups_in_batch_level( int $batch_id, string $level ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE batch_id = %d AND level = %s',
				$this->groups_table(),
				$batch_id,
				$level
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	/**
	 * Atomically reserve the next sequence number for a (batch_id, level)
	 * pair. Runs **outside** any wrapping transaction — the counter must
	 * survive a rollback, otherwise a failed insert would let the next
	 * caller reuse the freed slot.
	 *
	 * MariaDB / MySQL: `INSERT … ON DUPLICATE KEY UPDATE` is atomic on
	 * a single row and safe under concurrent execution — the second
	 * caller blocks on the row lock until the first commits.
	 *
	 * Returns the reserved seq (1-based). The counter row's `next_seq`
	 * always holds the value that WILL be reserved on the next call.
	 */
	public function reserve_next_seq( int $batch_id, string $level ): int {
		global $wpdb;

		$table = $this->counters_table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (batch_id, level, next_seq, updated_at) VALUES (%d, %s, 2, %s)
				 ON DUPLICATE KEY UPDATE next_seq = next_seq + 1, updated_at = VALUES(updated_at)',
				$table,
				$batch_id,
				$level,
				$now
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$next = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT next_seq FROM %i WHERE batch_id = %d AND level = %s',
				$table,
				$batch_id,
				$level
			)
		);

		return max( 1, (int) $next - 1 );
	}

	// ------------------------------------------------------------- Helpers.

	private function groups_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateGroupsTables::GROUPS_TABLE;
	}

	private function batches_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateBatchesTable::BATCHES_TABLE;
	}

	private function counters_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateGroupCodeCounters::COUNTER_TABLE;
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
