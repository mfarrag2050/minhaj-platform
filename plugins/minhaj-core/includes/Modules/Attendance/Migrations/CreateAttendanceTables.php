<?php
/**
 * Attendance module — spec-attendance-v1 §3.
 *
 * Four tables:
 *   • minhaj_attendance            — one row per (session, student).
 *     uq_session_student enforces R-10 (write-once + amend, never
 *     duplicate). student_id references minhaj_students.id after
 *     decision 18.
 *   • minhaj_attendance_intervals  — raw Zoom join/leave events.
 *     uq_interval on (participant_uuid, joined_at_utc) makes webhook
 *     retries idempotent at the DB level (spec §3.2).
 *   • minhaj_teacher_presence      — per-session teacher presence.
 *     Carries `finalized_at` used by finalize_session for M-4-style
 *     idempotency of the `minhaj_attendance_finalized` event.
 *   • minhaj_attendance_audit      — actor-attributed audit rows.
 *
 * spec §3.1 explicitly forbids a `notes_internal` column — R-9 mirror
 * principle. The only free-text field is `note_visible`. Any future
 * migration that tries to add a hidden note field must be reviewed
 * against R-9 and the acceptance criterion AC-12 that greps this
 * schema for `notes_internal`.
 *
 * @package Minhaj\Modules\Attendance\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Attendance\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

final class CreateAttendanceTables extends Migration {

	public const VERSION = 20260830200000;

	public const ATTENDANCE_TABLE       = 'minhaj_attendance';
	public const INTERVALS_TABLE        = 'minhaj_attendance_intervals';
	public const TEACHER_PRESENCE_TABLE = 'minhaj_teacher_presence';
	public const AUDIT_TABLE            = 'minhaj_attendance_audit';

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'attendance.create_tables';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		dbDelta( self::attendance_table_sql( $prefix, $charset ) );
		dbDelta( self::intervals_table_sql( $prefix, $charset ) );
		dbDelta( self::teacher_presence_table_sql( $prefix, $charset ) );
		dbDelta( self::audit_table_sql( $prefix, $charset ) );
	}

	public static function attendance_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::ATTENDANCE_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			student_id BIGINT UNSIGNED NOT NULL,
			group_id BIGINT UNSIGNED NOT NULL,
			org_id BIGINT UNSIGNED NULL DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'absent',
			auto_status VARCHAR(20) NOT NULL DEFAULT 'absent',
			source VARCHAR(20) NOT NULL DEFAULT 'zoom',
			first_join_utc DATETIME NULL DEFAULT NULL,
			last_leave_utc DATETIME NULL DEFAULT NULL,
			attended_seconds INT UNSIGNED NOT NULL DEFAULT 0,
			late_seconds INT UNSIGNED NOT NULL DEFAULT 0,
			amended_by BIGINT UNSIGNED NULL DEFAULT NULL,
			amended_at DATETIME NULL DEFAULT NULL,
			amend_reason VARCHAR(255) NULL DEFAULT NULL,
			note_visible TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_session_student (session_id, student_id),
			KEY session_id (session_id),
			KEY student_id (student_id),
			KEY group_id (group_id),
			KEY org_id (org_id),
			KEY status (status)
		) ENGINE=InnoDB {$charset};";
	}

	public static function intervals_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::INTERVALS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			attendance_id BIGINT UNSIGNED NULL DEFAULT NULL,
			zoom_participant_uuid VARCHAR(64) NOT NULL,
			zoom_registrant_id VARCHAR(64) NULL DEFAULT NULL,
			joined_at_utc DATETIME NOT NULL,
			left_at_utc DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_interval (zoom_participant_uuid, joined_at_utc),
			KEY session_id (session_id),
			KEY attendance_id (attendance_id),
			KEY zoom_registrant_id (zoom_registrant_id)
		) ENGINE=InnoDB {$charset};";
	}

	public static function teacher_presence_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::TEACHER_PRESENCE_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			teacher_id BIGINT UNSIGNED NOT NULL,
			first_join_utc DATETIME NULL DEFAULT NULL,
			last_leave_utc DATETIME NULL DEFAULT NULL,
			attended_seconds INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			finalized_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_session (session_id),
			KEY teacher_id (teacher_id)
		) ENGINE=InnoDB {$charset};";
	}

	public static function audit_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::AUDIT_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NULL DEFAULT NULL,
			student_id BIGINT UNSIGNED NULL DEFAULT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(64) NOT NULL,
			payload_json LONGTEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY student_id (student_id),
			KEY actor_user_id (actor_user_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset};";
	}
}
