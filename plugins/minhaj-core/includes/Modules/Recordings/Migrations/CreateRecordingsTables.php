<?php
/**
 * Recordings module — schema per spec-recordings-v1 §3.
 *
 * Three tables:
 *   • minhaj_recordings           · one row per Zoom file (uq_zoom_file
 *                                    is the anti-dupe barrier).
 *   • minhaj_recording_access_log · every `view` / `denied` / `purge` /
 *                                    `export`. NO IP addresses (spec §3.2).
 *   • minhaj_recordings_audit     · admin actions on this module.
 *
 * `retention_until` is DATE NOT NULL by design — the spec (§3.1) is
 * explicit: no row is allowed without a scheduled purge date. Any code
 * path that would insert one without it will fail at the DB level.
 *
 * @package Minhaj\Modules\Recordings\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

final class CreateRecordingsTables extends Migration {

	public const VERSION          = 20260830400001;
	public const RECORDINGS_TABLE = 'minhaj_recordings';
	public const ACCESS_LOG_TABLE = 'minhaj_recording_access_log';
	public const AUDIT_TABLE      = 'minhaj_recordings_audit';

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'recordings.create_tables';
	}

	public function up(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$recordings = $wpdb->prefix . self::RECORDINGS_TABLE;
		$access     = $wpdb->prefix . self::ACCESS_LOG_TABLE;
		$audit      = $wpdb->prefix . self::AUDIT_TABLE;

		$sql_recordings = "CREATE TABLE {$recordings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			group_id BIGINT UNSIGNED NOT NULL,
			org_id BIGINT UNSIGNED NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'session',
			zoom_meeting_uuid VARCHAR(64) NOT NULL,
			zoom_file_id VARCHAR(64) NOT NULL,
			file_type VARCHAR(16) NOT NULL,
			recording_start_utc DATETIME NOT NULL,
			recording_end_utc DATETIME NOT NULL,
			bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			checksum_sha256 CHAR(64) NULL,
			status VARCHAR(24) NOT NULL DEFAULT 'pending',
			storage_region VARCHAR(16) NOT NULL,
			object_key VARCHAR(255) NULL,
			download_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_error VARCHAR(255) NULL,
			downloaded_at DATETIME NULL,
			zoom_deleted_at DATETIME NULL,
			retention_until DATE NOT NULL,
			purged_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_zoom_file (zoom_file_id),
			KEY session_id (session_id),
			KEY group_id (group_id),
			KEY kind (kind),
			KEY status (status),
			KEY retention_until (retention_until),
			KEY purged_at (purged_at),
			KEY org_id (org_id)
		) ENGINE=InnoDB {$charset};";

		$sql_access = "CREATE TABLE {$access} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			recording_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(16) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY recording_id (recording_id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset};";

		$sql_audit = "CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(64) NOT NULL,
			subject_id BIGINT UNSIGNED NULL,
			payload_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY actor_user_id (actor_user_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset};";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql_recordings );
		$wpdb->query( $sql_access );
		$wpdb->query( $sql_audit );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}
}
