<?php
/**
 * Meetings module — spec-zoom-sessions-v1 §3.
 *
 * Five tables:
 *   • minhaj_zoom_licenses          — host accounts + concurrency capacity.
 *   • minhaj_session_meetings       — one Zoom meeting per session (uq_session).
 *   • minhaj_session_participants   — registered participants; STORED generated
 *                                     `active_host_flag` enforces "one host
 *                                     per session" via uq_session_host so the
 *                                     rule cannot be forgotten by PHP.
 *   • minhaj_zoom_events            — the receipt table for every webhook.
 *                                     uq_dedup makes retries a no-op at the
 *                                     DB level (M-17).
 *   • minhaj_meetings_audit         — actor-attributed row per write.
 *
 * spec-zoom-sessions-v1 explicitly forbids storing `start_url`, `join_url`,
 * or meeting passwords — they are bearer secrets. There is no column for
 * any of them, by design, and a grep of this migration must find neither.
 *
 * @package Minhaj\Modules\Meetings\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

final class CreateMeetingsTables extends Migration {

	public const VERSION = 20260830100000;

	public const LICENSES_TABLE     = 'minhaj_zoom_licenses';
	public const MEETINGS_TABLE     = 'minhaj_session_meetings';
	public const PARTICIPANTS_TABLE = 'minhaj_session_participants';
	public const EVENTS_TABLE       = 'minhaj_zoom_events';
	public const AUDIT_TABLE        = 'minhaj_meetings_audit';

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'meetings.create_tables';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		dbDelta( self::licenses_table_sql( $prefix, $charset ) );
		dbDelta( self::meetings_table_sql( $prefix, $charset ) );
		dbDelta( self::participants_table_sql( $prefix, $charset ) );
		dbDelta( self::events_table_sql( $prefix, $charset ) );
		dbDelta( self::audit_table_sql( $prefix, $charset ) );
	}

	public static function licenses_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::LICENSES_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			zoom_user_id VARCHAR(64) NOT NULL,
			email VARCHAR(190) NOT NULL,
			concurrent_capacity TINYINT UNSIGNED NOT NULL DEFAULT 2,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_zoom_user (zoom_user_id),
			KEY status (status)
		) ENGINE=InnoDB {$charset};";
	}

	public static function meetings_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::MEETINGS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			license_id BIGINT UNSIGNED NOT NULL,
			zoom_meeting_id VARCHAR(32) NOT NULL,
			zoom_meeting_uuid VARCHAR(64) NULL DEFAULT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'pending',
			scheduled_start_utc DATETIME NOT NULL,
			duration_minutes SMALLINT UNSIGNED NOT NULL,
			create_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_error VARCHAR(255) NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_session (session_id),
			KEY license_id (license_id),
			KEY zoom_meeting_id (zoom_meeting_id),
			KEY state (state)
		) ENGINE=InnoDB {$charset};";
	}

	public static function participants_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::PARTICIPANTS_TABLE;

		/*
		 * `active_host_flag` is a STORED generated column: NULL for every
		 * participant row, 1 for the host row. The unique index on
		 * (session_id, active_host_flag) fails when a second row for the
		 * same session tries to become host — same pattern the Groups
		 * module already uses to force one-active-seat.
		 *
		 * `subject_student_id` is nullable (teacher rows). NULL entries do
		 * not collide in a MySQL UNIQUE index, so multiple participant-role
		 * teacher rows for the same session are allowed. The uniqueness
		 * we care about — one row per student per session — is enforced
		 * only when subject_student_id is not null, which is what we want.
		 * Decision 18 · subject_student_id refers to `minhaj_students.id`.
		 */
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL,
			subject_student_id BIGINT UNSIGNED NULL DEFAULT NULL,
			role VARCHAR(20) NOT NULL,
			zoom_registrant_id VARCHAR(64) NULL DEFAULT NULL,
			zoom_participant_uuid VARCHAR(64) NULL DEFAULT NULL,
			issued_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			consumed_at DATETIME NULL DEFAULT NULL,
			active_host_flag TINYINT UNSIGNED GENERATED ALWAYS AS (IF(role='host', 1, NULL)) STORED,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_session_subject (session_id, subject_student_id),
			UNIQUE KEY uq_session_host (session_id, active_host_flag),
			KEY session_id (session_id),
			KEY zoom_registrant_id (zoom_registrant_id)
		) ENGINE=InnoDB {$charset};";
	}

	public static function events_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::EVENTS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			dedup_key VARCHAR(191) NOT NULL,
			event_type VARCHAR(64) NOT NULL,
			payload_json LONGTEXT NOT NULL,
			received_at DATETIME NOT NULL,
			processed_at DATETIME NULL DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'received',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_error VARCHAR(255) NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_dedup (dedup_key),
			KEY event_type (event_type),
			KEY received_at (received_at),
			KEY status (status)
		) ENGINE=InnoDB {$charset};";
	}

	public static function audit_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::AUDIT_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NULL DEFAULT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(64) NOT NULL,
			subject_id BIGINT UNSIGNED NULL DEFAULT NULL,
			payload_json LONGTEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY actor_user_id (actor_user_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset};";
	}
}
