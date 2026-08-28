<?php
/**
 * Read-only persistence layer for AccessPolicy. Never opens a transaction;
 * the only writes are the denial rows the policy asks for via record_denial()
 * and they land in the audit table of the module that owns the subject
 * (spec-access-v1 §5 A-6).
 *
 * Table names go through %i (WP 6.2+) so the sniff can verify prepared usage
 * without interpolation false positives — mirrors the pattern used by every
 * other repository in this plugin.
 *
 * @package Minhaj\Access
 */

declare( strict_types=1 );

namespace Minhaj\Access;

use Minhaj\Modules\Groups\Migrations\CreateGroupsTables;
use Minhaj\Modules\People\Migrations\CreatePeopleTables;
use Minhaj\Modules\Timetable\Migrations\CreateTimetableTables;

defined( 'ABSPATH' ) || exit;

class AccessRepository {

	// ---------------------------------------------------------------- Group reads.

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_group( int $group_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, teacher_id, org_id, deleted_at FROM %i WHERE id = %d',
				$this->groups_table(),
				$group_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		if ( null !== $row['deleted_at'] ) {
			return null;
		}

		return $row;
	}

	/**
	 * @return array<int, int>
	 */
	public function list_group_ids_for_teacher( int $teacher_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE teacher_id = %d AND deleted_at IS NULL ORDER BY id',
				$this->groups_table(),
				$teacher_id
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Groups whose org_id belongs to the supplied list. Feeds org-admin scoping.
	 *
	 * @param array<int, int> $org_ids
	 * @return array<int, int>
	 */
	public function list_group_ids_in_orgs( array $org_ids ): array {
		if ( array() === $org_ids ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $org_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE deleted_at IS NULL AND org_id IN (' . $placeholders . ') ORDER BY id',
				$this->groups_table(),
				...array_map( 'intval', $org_ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Active membership: student is a member of $group_id right now. Used by
	 * can_view_recording / join_role to decide participation.
	 */
	public function is_active_member( int $group_id, int $student_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE group_id = %d AND student_id = %d AND status = 'active' LIMIT 1",
				$this->members_table(),
				$group_id,
				$student_id
			)
		);

		return null !== $id;
	}

	/**
	 * @return array<int, int>
	 */
	public function list_active_group_ids_of_student( int $student_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT group_id FROM %i WHERE student_id = %d AND status = 'active' ORDER BY group_id",
				$this->members_table(),
				$student_id
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	// -------------------------------------------------------------- Student reads.

	/**
	 * @return array<string, mixed>|null Empty user_id + anonymized_at semantics preserved.
	 */
	public function find_student_profile( int $student_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT user_id, origin_org_id, anonymized_at FROM %i WHERE user_id = %d',
				$this->student_profiles_table(),
				$student_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function is_student_anonymized( int $student_id ): bool {
		$profile = $this->find_student_profile( $student_id );
		if ( null === $profile ) {
			return false;
		}

		return null !== $profile['anonymized_at'];
	}

	// ---------------------------------------------------------- Guardianship reads.

	/**
	 * True iff there is an active (ended_at IS NULL) row linking this guardian
	 * to this student with can_view=1. Missing rows return false — the caller
	 * treats that as denial, never as "not sure".
	 */
	public function is_active_guardian_with_view( int $guardian_id, int $student_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE guardian_id = %d AND student_id = %d AND ended_at IS NULL AND can_view = 1 LIMIT 1',
				$this->guardianship_table(),
				$guardian_id,
				$student_id
			)
		);

		return null !== $id;
	}

	/**
	 * @return array<int, int>
	 */
	public function list_active_ward_ids_of_guardian( int $guardian_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT student_id FROM %i WHERE guardian_id = %d AND ended_at IS NULL AND can_view = 1 ORDER BY student_id',
				$this->guardianship_table(),
				$guardian_id
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	// -------------------------------------------------------------- Session reads.

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_session( int $session_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, group_id, teacher_id, org_id, status FROM %i WHERE id = %d',
				$this->sessions_table(),
				$session_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	// ------------------------------------------------------------------ Org reads.

	/**
	 * Active org memberships for a user. Reads the generated `active_user_id`
	 * so an ended row does not resurface.
	 *
	 * @return array<int, int>
	 */
	public function list_org_ids_for_user( int $user_id ): array {
		global $wpdb;

		$table = $this->org_members_table();
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT org_id FROM %i WHERE active_user_id = %d ORDER BY org_id',
				$table,
				$user_id
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	// -------------------------------------------------------------- Audit writes.

	/**
	 * Route a denial to the audit table owned by the subject's module.
	 * spec-access-v1 §5 A-6 requires the denial to land where a subsequent
	 * investigation would look for it — never in a single global sink that
	 * every module needs to correlate against later.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function record_denial(
		string $subject_type,
		int $actor_user_id,
		int $subject_id,
		string $action,
		array $payload
	): void {
		global $wpdb;

		$now          = current_time( 'mysql', true );
		$payload_json = (string) wp_json_encode( array_merge( array( 'context' => $action ), $payload ) );

		switch ( $subject_type ) {
			case 'group':
			case 'group_scope':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$this->groups_audit_table(),
					array(
						'group_id'      => $subject_id,
						'actor_user_id' => $actor_user_id,
						'action'        => 'access_denied',
						'subject_id'    => $subject_id,
						'payload_json'  => $payload_json,
						'created_at'    => $now,
					)
				);
				return;

			case 'session':
			case 'recording':
			case 'attendance':
			case 'join':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$this->timetable_audit_table(),
					array(
						'group_id'      => null,
						'teacher_id'    => null,
						'actor_user_id' => $actor_user_id,
						'action'        => 'access_denied',
						'subject_id'    => $subject_id,
						'payload_json'  => $payload_json,
						'created_at'    => $now,
					)
				);
				return;

			case 'student':
			case 'guardian':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$this->person_audit_table(),
					array(
						'subject_type'  => $subject_type,
						'subject_id'    => $subject_id,
						'actor_user_id' => $actor_user_id,
						'action'        => 'access_denied',
						'payload_json'  => $payload_json,
						'created_at'    => $now,
					)
				);
				return;
		}

		// Unknown subject — write to the person_audit table as a last resort
		// with subject_type preserved so investigation can still find it. A
		// silent drop would defeat the point of A-6.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->person_audit_table(),
			array(
				'subject_type'  => $subject_type,
				'subject_id'    => $subject_id,
				'actor_user_id' => $actor_user_id,
				'action'        => 'access_denied',
				'payload_json'  => $payload_json,
				'created_at'    => $now,
			)
		);
	}

	// ---------------------------------------------------------------- Helpers.

	private function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	private function groups_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateGroupsTables::GROUPS_TABLE;
	}

	private function members_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateGroupsTables::MEMBERS_TABLE;
	}

	private function groups_audit_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateGroupsTables::AUDIT_TABLE;
	}

	private function student_profiles_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreatePeopleTables::STUDENT_PROFILES_TABLE;
	}

	private function guardianship_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreatePeopleTables::GUARDIANSHIP_TABLE;
	}

	private function person_audit_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreatePeopleTables::PERSON_AUDIT_TABLE;
	}

	private function sessions_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateTimetableTables::SESSIONS_TABLE;
	}

	private function timetable_audit_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateTimetableTables::AUDIT_TABLE;
	}

	private function org_members_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'minhaj_org_members';
	}
}
