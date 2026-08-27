<?php
/**
 * Timetable module — initial schema for spec-timetable-v1 §3.
 *
 * Five tables:
 *   • minhaj_teacher_availability — weekly wall-time slots per teacher.
 *   • minhaj_teacher_absences     — known absence windows in UTC.
 *   • minhaj_schedule_patterns    — one active pattern per group.
 *   • minhaj_sessions             — generated sessions with sequence_no + lesson_no.
 *   • minhaj_timetable_audit      — actor-attributed audit row per write.
 *
 * MySQL/MariaDB cannot enforce range-overlap uniqueness at the schema level
 * (no exclusion constraints, no range types) — spec §7 R-5 admits this and
 * requires an application-level transaction + a nightly cli guard.
 *
 * @package Minhaj\Modules\Timetable\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

final class CreateTimetableTables extends Migration {

	public const VERSION = 20260828000000;

	public const AVAILABILITY_TABLE = 'minhaj_teacher_availability';
	public const ABSENCES_TABLE     = 'minhaj_teacher_absences';
	public const PATTERNS_TABLE     = 'minhaj_schedule_patterns';
	public const SESSIONS_TABLE     = 'minhaj_sessions';
	public const AUDIT_TABLE        = 'minhaj_timetable_audit';

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'timetable.create_tables';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		dbDelta( self::availability_table_sql( $prefix, $charset ) );
		dbDelta( self::absences_table_sql( $prefix, $charset ) );
		dbDelta( self::patterns_table_sql( $prefix, $charset ) );
		dbDelta( self::sessions_table_sql( $prefix, $charset ) );
		dbDelta( self::audit_table_sql( $prefix, $charset ) );
	}

	public static function availability_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::AVAILABILITY_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			teacher_id BIGINT UNSIGNED NOT NULL,
			weekday TINYINT UNSIGNED NOT NULL,
			start_local TIME NOT NULL,
			end_local TIME NOT NULL,
			timezone VARCHAR(64) NOT NULL,
			effective_from DATE NOT NULL,
			effective_to DATE NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY teacher_id (teacher_id),
			KEY teacher_effective (teacher_id, effective_from, effective_to)
		) ENGINE=InnoDB {$charset};";
	}

	public static function absences_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::ABSENCES_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			teacher_id BIGINT UNSIGNED NOT NULL,
			starts_at_utc DATETIME NOT NULL,
			ends_at_utc DATETIME NOT NULL,
			reason VARCHAR(255) NOT NULL DEFAULT '',
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY teacher_id (teacher_id),
			KEY teacher_window (teacher_id, starts_at_utc, ends_at_utc)
		) ENGINE=InnoDB {$charset};";
	}

	public static function patterns_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::PATTERNS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			anchor_timezone VARCHAR(64) NOT NULL,
			weekdays_json VARCHAR(64) NOT NULL,
			start_local TIME NOT NULL,
			duration_minutes SMALLINT UNSIGNED NOT NULL,
			weeks_count SMALLINT UNSIGNED NOT NULL,
			first_week_start DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY group_id (group_id),
			KEY group_status (group_id, status)
		) ENGINE=InnoDB {$charset};";
	}

	public static function sessions_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::SESSIONS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			pattern_id BIGINT UNSIGNED NOT NULL,
			sequence_no SMALLINT UNSIGNED NOT NULL,
			lesson_no SMALLINT UNSIGNED NULL DEFAULT NULL,
			scheduled_start_utc DATETIME NOT NULL,
			scheduled_end_utc DATETIME NOT NULL,
			local_start_wall DATETIME NOT NULL,
			anchor_timezone VARCHAR(64) NOT NULL,
			teacher_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			actual_start_utc DATETIME NULL DEFAULT NULL,
			actual_end_utc DATETIME NULL DEFAULT NULL,
			rescheduled_from_id BIGINT UNSIGNED NULL DEFAULT NULL,
			makeup_for_id BIGINT UNSIGNED NULL DEFAULT NULL,
			curriculum_ref VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_group_sequence (group_id, sequence_no),
			KEY teacher_start (teacher_id, scheduled_start_utc),
			KEY group_id (group_id),
			KEY pattern_id (pattern_id),
			KEY status (status)
		) ENGINE=InnoDB {$charset};";
	}

	public static function audit_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::AUDIT_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NULL DEFAULT NULL,
			teacher_id BIGINT UNSIGNED NULL DEFAULT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(64) NOT NULL,
			subject_id BIGINT UNSIGNED NULL DEFAULT NULL,
			payload_json LONGTEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY group_id (group_id),
			KEY teacher_id (teacher_id),
			KEY actor_user_id (actor_user_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset};";
	}
}
