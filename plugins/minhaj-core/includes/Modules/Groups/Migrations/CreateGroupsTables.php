<?php
/**
 * Groups module — initial schema.
 *
 * Creates minhaj_groups, minhaj_group_members, minhaj_group_audit per
 * spec-groups-v1 §3. VARCHAR is used instead of ENUM for `type` and `status`
 * columns to keep future changes migration-friendly; the domain classes
 * (GroupType, GroupStatus) hold the authoritative value lists.
 *
 * MySQL/MariaDB do not support partial unique indexes. Spec §3.2 is
 * enforced by two STORED generated columns — `active_seat_index` and
 * `active_student_id` — computed by the DB from `status`, `seat_index`,
 * and `student_id`. When status='active' they mirror the source; otherwise
 * they are NULL, and NULL entries do not collide in a MySQL UNIQUE index.
 * Because the values are DB-maintained, the service layer cannot forget
 * to sync them — the constraint is always in effect. Verified against
 * WordPress core dbDelta on MariaDB 11 LTS.
 *
 * @package Minhaj\Modules\Groups\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

final class CreateGroupsTables extends Migration {

	public const VERSION = 20260827100000;

	public const GROUPS_TABLE  = 'minhaj_groups';
	public const MEMBERS_TABLE = 'minhaj_group_members';
	public const AUDIT_TABLE   = 'minhaj_group_audit';

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'groups.create_tables';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		dbDelta( self::groups_table_sql( $prefix, $charset ) );
		dbDelta( self::members_table_sql( $prefix, $charset ) );
		dbDelta( self::audit_table_sql( $prefix, $charset ) );
	}

	public static function groups_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::GROUPS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(32) NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'group',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			batch_id BIGINT UNSIGNED NULL DEFAULT NULL,
			level VARCHAR(32) NOT NULL DEFAULT '',
			teacher_id BIGINT UNSIGNED NULL DEFAULT NULL,
			teaching_language CHAR(5) NOT NULL DEFAULT '',
			-- منطقة عرض المجموعة لوليّ الأمر: IANA zone the parent portal
			-- renders session times in. Not used by generation — the anchor
			-- lives on minhaj_sessions.anchor_timezone, frozen per session.
			timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
			capacity_min TINYINT UNSIGNED NOT NULL,
			capacity_max TINYINT UNSIGNED NOT NULL,
			session_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
			total_sessions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			sessions_per_week TINYINT UNSIGNED NOT NULL DEFAULT 3,
			program_hours SMALLINT UNSIGNED NOT NULL DEFAULT 36,
			planned_start_date DATE NULL DEFAULT NULL,
			actual_start_date DATE NULL DEFAULT NULL,
			expected_end_date DATE NULL DEFAULT NULL,
			formation_deadline DATE NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status),
			KEY teacher_id (teacher_id),
			KEY batch_id (batch_id),
			KEY deleted_at (deleted_at)
		) ENGINE=InnoDB {$charset};";
	}

	public static function members_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::MEMBERS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			student_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			joined_at DATETIME NOT NULL,
			left_at DATETIME NULL DEFAULT NULL,
			seat_index TINYINT UNSIGNED NOT NULL,
			active_seat_index TINYINT UNSIGNED GENERATED ALWAYS AS (IF(status='active', seat_index, NULL)) STORED,
			active_student_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status='active', student_id, NULL)) STORED,
			transferred_from_group_id BIGINT UNSIGNED NULL DEFAULT NULL,
			transferred_to_group_id BIGINT UNSIGNED NULL DEFAULT NULL,
			order_id BIGINT UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_active_seat (group_id, active_seat_index),
			UNIQUE KEY uq_active_student (group_id, active_student_id),
			KEY group_id (group_id),
			KEY student_id (student_id),
			KEY status (status)
		) ENGINE=InnoDB {$charset};";
	}

	public static function audit_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::AUDIT_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(64) NOT NULL,
			subject_id BIGINT UNSIGNED NULL DEFAULT NULL,
			payload_json LONGTEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY group_id (group_id),
			KEY actor_user_id (actor_user_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset};";
	}
}
