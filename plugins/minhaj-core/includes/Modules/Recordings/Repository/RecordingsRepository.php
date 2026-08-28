<?php
/**
 * Recordings persistence layer. Only file in the module that talks to
 * $wpdb — the service stays SQL-ignorant and tests can swap this with a
 * double.
 *
 * @package Minhaj\Modules\Recordings\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Repository;

use Minhaj\Modules\Recordings\Domain\RecordingStatus;
use Minhaj\Modules\Recordings\Migrations\CreateRecordingsTables;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class RecordingsRepository {

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

	// -------------------------------------------------------------- Inserts.

	/**
	 * @param array<string, mixed> $data
	 * @return int inserted id
	 * @throws PersistenceException
	 */
	public function insert_recording( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( $this->recordings_table(), $data );

		if ( false !== $ok ) {
			return (int) $wpdb->insert_id;
		}

		$err = (string) $wpdb->last_error;
		if ( str_contains( $err, 'uq_zoom_file' ) || ( str_contains( $err, 'Duplicate' ) && str_contains( $err, 'zoom_file_id' ) ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_ZOOM_FILE,
				'zoom_file already registered: ' . $err
			);
		}

		throw new PersistenceException( PersistenceException::WRITE_FAILED, 'insert failed: ' . $err );
	}

	/** @param array<string, mixed> $data */
	public function update_recording( int $id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update( $this->recordings_table(), $data, array( 'id' => $id ) );
		if ( false === $ok ) {
			throw new PersistenceException( PersistenceException::WRITE_FAILED, 'update failed: ' . $wpdb->last_error );
		}
	}

	public function insert_access_log( int $recording_id, int $user_id, string $action, string $now ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->access_log_table(),
			array(
				'recording_id' => $recording_id,
				'user_id'      => $user_id,
				'action'       => $action,
				'created_at'   => $now,
			)
		);
	}

	/** @param array<string, mixed> $data */
	public function insert_audit( array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $this->audit_table(), $data );
	}

	// ---------------------------------------------------------------- Reads.

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->recordings_table(), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string, mixed>|null */
	public function find_by_zoom_file( string $zoom_file_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE zoom_file_id = %s', $this->recordings_table(), $zoom_file_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function list_for_session( int $session_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d ORDER BY id ASC',
				$this->recordings_table(),
				$session_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows whose retention has expired AND still hold storage (not purged
	 * and not legal_hold). Only these are candidates for `purge_expired`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_due_for_purge( int $limit, string $today_date ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				 WHERE retention_until < %s
				   AND status <> %s
				   AND status <> %s
				   AND object_key IS NOT NULL
				 ORDER BY retention_until ASC, id ASC
				 LIMIT %d',
				$this->recordings_table(),
				$today_date,
				RecordingStatus::PURGED,
				RecordingStatus::LEGAL_HOLD,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows not yet on our storage — the download-due picker.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_download_due( int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				 WHERE status IN (%s, %s)
				 ORDER BY created_at ASC, id ASC
				 LIMIT %d',
				$this->recordings_table(),
				RecordingStatus::PENDING,
				RecordingStatus::FAILED,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows on legal hold — surfaced by the retention report even though
	 * their retention window has passed (G-8).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_on_hold(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s ORDER BY id ASC',
				$this->recordings_table(),
				RecordingStatus::LEGAL_HOLD
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int, array<string, mixed>> */
	public function list_by_status( string $status, int $limit = 200 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d',
				$this->recordings_table(),
				$status,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	// -------------------------------------------------------- Table helpers.

	private function recordings_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateRecordingsTables::RECORDINGS_TABLE;
	}

	private function access_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateRecordingsTables::ACCESS_LOG_TABLE;
	}

	private function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . CreateRecordingsTables::AUDIT_TABLE;
	}
}
