<?php
/**
 * Orgs persistence layer.
 *
 * Only file in the module that talks to $wpdb. Table names go through %i
 * so the sniff can verify prepared usage without interpolation false
 * positives — same pattern as the Groups/People/Timetable repositories.
 *
 * The three unique keys we translate to typed PersistenceException kinds:
 *   • uq_code            → DUPLICATE_ORG_CODE  (minhaj_orgs.code)
 *   • uq_token           → DUPLICATE_TOKEN     (registration_links.token)
 *   • uq_active_member   → DUPLICATE_ACTIVE_MEMBER (org_members active row)
 *
 * @package Minhaj\Modules\Orgs\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Orgs\Repository;

use Minhaj\Modules\Orgs\Migrations\CreateOrgsTables;
use Minhaj\Modules\People\Migrations\RestructureStudentsForNonWpIdentity;

defined( 'ABSPATH' ) || exit;

/*
 * PersistenceException messages carry $wpdb->last_error verbatim so devs can
 * diagnose DB failures. They never reach an HTML response — the service
 * layer converts them to WP_Error at the boundary.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class OrgRepository {

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

	// ----------------------------------------------------------------- Orgs.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException DUPLICATE_ORG_CODE on the uq_code collision.
	 */
	public function insert_org( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->orgs_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_code' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_ORG_CODE,
				'duplicate org code: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert org: ' . $error
		);
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function update_org( int $org_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->orgs_table(), $data, array( 'id' => $org_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update org: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_org( int $org_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->orgs_table(),
				$org_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * FOR UPDATE variant used by set_status / issue_link — locks the row for
	 * the length of the outer transaction so a concurrent status change
	 * cannot slip past the DPA gate.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_org_for_update( int $org_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d FOR UPDATE',
				$this->orgs_table(),
				$org_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_orgs( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY id ASC LIMIT %d OFFSET %d',
				$this->orgs_table(),
				max( 1, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Orgs whose country is outside the supplied ISO-3166 list — feeds the
	 * `transfer-check` CLI (O-9).
	 *
	 * @param array<int, string> $inside_countries
	 * @return array<int, array<string, mixed>>
	 */
	public function list_orgs_outside_countries( array $inside_countries ): array {
		if ( array() === $inside_countries ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $inside_countries ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE country <> '' AND country NOT IN ({$placeholders}) ORDER BY id ASC",
				$this->orgs_table(),
				...array_map( 'strval', $inside_countries )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return is_array( $rows ) ? $rows : array();
	}

	// ------------------------------------------------------- Registration links.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException DUPLICATE_TOKEN on uq_token collision.
	 */
	public function insert_registration_link( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->registration_links_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_token' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_TOKEN,
				'duplicate registration token: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert registration link: ' . $error
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_registration_link( int $link_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->registration_links_table(),
				$link_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_registration_link_by_token( string $token ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE token = %s',
				$this->registration_links_table(),
				$token
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * FOR UPDATE variant used by consume_registration_token — locks the row so
	 * concurrent consumers on a single-use link cannot both increment.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_registration_link_for_update( int $link_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d FOR UPDATE',
				$this->registration_links_table(),
				$link_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Atomic single-use increment. Uses a WHERE clause the DB evaluates under
	 * the row lock so two racing consumers cannot both succeed on max_uses=1.
	 *
	 * @return int Rows affected — 0 means the race was lost.
	 */
	public function increment_uses_if_available( int $link_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET uses_count = uses_count + 1 WHERE id = %d AND status = 'active' AND (max_uses IS NULL OR uses_count < max_uses) AND (expires_at IS NULL OR expires_at >= %s)",
				$this->registration_links_table(),
				$link_id,
				current_time( 'Y-m-d', true )
			)
		);

		return (int) $affected;
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function update_registration_link( int $link_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->registration_links_table(), $data, array( 'id' => $link_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update registration link: ' . $wpdb->last_error
			);
		}
	}

	// ---------------------------------------------------------------- Members.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException DUPLICATE_ACTIVE_MEMBER on uq_active_member collision.
	 */
	public function insert_member( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->org_members_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_active_member' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_ACTIVE_MEMBER,
				'user already an active member of this org: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert org member: ' . $error
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_member( int $membership_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->org_members_table(),
				$membership_id
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
	public function update_member( int $membership_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->org_members_table(), $data, array( 'id' => $membership_id ) );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update org member: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * @return array<int, int>
	 */
	public function list_active_org_ids_for_user( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT org_id FROM %i WHERE active_user_id = %d ORDER BY org_id',
				$this->org_members_table(),
				$user_id
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	// ------------------------------------------------------ Attribution reads.

	/**
	 * Distinct students attributed to an org whose registration happened in
	 * the window [$from_date .. $to_date]. Feeds attribution_report.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function attribution_rows( int $org_id, string $from_date, string $to_date ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id AS student_id, user_id, registration_link_id, created_at FROM %i WHERE origin_org_id = %d AND DATE(created_at) BETWEEN %s AND %s ORDER BY created_at ASC',
				$wpdb->prefix . RestructureStudentsForNonWpIdentity::STUDENTS_TABLE,
				$org_id,
				$from_date,
				$to_date
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// ---------------------------------------------------------------- Helpers.

	private function orgs_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateOrgsTables::ORGS_TABLE;
	}

	private function registration_links_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateOrgsTables::REGISTRATION_LINKS_TABLE;
	}

	private function org_members_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateOrgsTables::ORG_MEMBERS_TABLE;
	}
}
