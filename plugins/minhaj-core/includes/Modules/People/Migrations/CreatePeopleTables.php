<?php
/**
 * People module — initial schema for spec-people-v1 §2.
 *
 * Six tables:
 *   • minhaj_guardianship        — guardian↔student links + primary rule.
 *   • minhaj_student_profiles    — minimal PII per S-9 / P-3.
 *   • minhaj_teacher_profiles    — status, hours cap, markets.
 *   • minhaj_teacher_languages   — the "teacher-language inventory" report
 *                                  that gates every market decision (S-8).
 *   • minhaj_safeguarding_checks — background-check header only; the check
 *                                  contents are NEVER stored, per §2.5.
 *   • minhaj_person_audit        — actor-attributed audit row per write.
 *
 * S-2 · one active primary guardian per student — MySQL cannot express a
 * partial unique index (`WHERE ended_at IS NULL AND is_primary = 1`), so we
 * use the same STORED generated-column trick that guards seat uniqueness
 * in the groups module. `active_primary_student_id` mirrors student_id
 * only while the row is the active primary; everywhere else it is NULL,
 * and NULLs do not collide in a MySQL UNIQUE index. The DB maintains the
 * value — the service layer cannot forget to sync it.
 *
 * @package Minhaj\Modules\People\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

final class CreatePeopleTables extends Migration {

	public const VERSION = 20260828200000;

	public const GUARDIANSHIP_TABLE        = 'minhaj_guardianship';
	public const STUDENT_PROFILES_TABLE    = 'minhaj_student_profiles';
	public const TEACHER_PROFILES_TABLE    = 'minhaj_teacher_profiles';
	public const TEACHER_LANGUAGES_TABLE   = 'minhaj_teacher_languages';
	public const SAFEGUARDING_CHECKS_TABLE = 'minhaj_safeguarding_checks';
	public const PERSON_AUDIT_TABLE        = 'minhaj_person_audit';

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'people.create_tables';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		dbDelta( self::guardianship_table_sql( $prefix, $charset ) );
		dbDelta( self::student_profiles_table_sql( $prefix, $charset ) );
		dbDelta( self::teacher_profiles_table_sql( $prefix, $charset ) );
		dbDelta( self::teacher_languages_table_sql( $prefix, $charset ) );
		dbDelta( self::safeguarding_checks_table_sql( $prefix, $charset ) );
		dbDelta( self::person_audit_table_sql( $prefix, $charset ) );
	}

	public static function guardianship_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::GUARDIANSHIP_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			guardian_id BIGINT UNSIGNED NOT NULL,
			student_id BIGINT UNSIGNED NOT NULL,
			relationship VARCHAR(20) NOT NULL DEFAULT 'parent',
			is_primary TINYINT UNSIGNED NOT NULL DEFAULT 0,
			can_view TINYINT UNSIGNED NOT NULL DEFAULT 1,
			can_manage TINYINT UNSIGNED NOT NULL DEFAULT 0,
			started_at DATETIME NOT NULL,
			ended_at DATETIME NULL DEFAULT NULL,
			active_primary_student_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(is_primary=1 AND ended_at IS NULL, student_id, NULL)) STORED,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_active_primary_guardian (active_primary_student_id),
			KEY student_id (student_id),
			KEY guardian_id (guardian_id),
			KEY ended_at (ended_at)
		) ENGINE=InnoDB {$charset};";
	}

	public static function student_profiles_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::STUDENT_PROFILES_TABLE;

		/*
		 * Per S-9 / P-3 (data minimisation): birth_year not birth_date,
		 * family_name_initial not the full surname, no hidden fields —
		 * `notes_visible` names the invariant intentionally (§2.2).
		 * `anonymized_at` marks a S-10 anonymised row; personal columns
		 * are blanked but the row survives so audit + attendance counts
		 * stay honest.
		 */
		return "CREATE TABLE {$table} (
			user_id BIGINT UNSIGNED NOT NULL,
			first_name VARCHAR(100) NOT NULL DEFAULT '',
			family_name_initial VARCHAR(4) NOT NULL DEFAULT '',
			birth_year SMALLINT UNSIGNED NULL DEFAULT NULL,
			ui_locale VARCHAR(10) NOT NULL DEFAULT '',
			market VARCHAR(10) NOT NULL DEFAULT '',
			current_level VARCHAR(32) NOT NULL DEFAULT '',
			notes_visible TEXT NULL,
			created_at DATETIME NOT NULL,
			anonymized_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (user_id),
			KEY market (market),
			KEY anonymized_at (anonymized_at)
		) ENGINE=InnoDB {$charset};";
	}

	public static function teacher_profiles_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::TEACHER_PROFILES_TABLE;

		return "CREATE TABLE {$table} (
			user_id BIGINT UNSIGNED NOT NULL,
			display_name VARCHAR(100) NOT NULL DEFAULT '',
			timezone VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'applicant',
			weekly_hours_cap SMALLINT UNSIGNED NOT NULL DEFAULT 20,
			markets_json VARCHAR(255) NOT NULL DEFAULT '[]',
			bio_i18n LONGTEXT NULL,
			photo_ref VARCHAR(255) NOT NULL DEFAULT '',
			contract_ref VARCHAR(100) NOT NULL DEFAULT '',
			engaged_via VARCHAR(20) NOT NULL DEFAULT 'direct',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (user_id),
			KEY status (status)
		) ENGINE=InnoDB {$charset};";
	}

	public static function teacher_languages_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::TEACHER_LANGUAGES_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			teacher_id BIGINT UNSIGNED NOT NULL,
			locale VARCHAR(10) NOT NULL,
			proficiency VARCHAR(20) NOT NULL DEFAULT 'working',
			can_teach_in TINYINT UNSIGNED NOT NULL DEFAULT 0,
			verified_by BIGINT UNSIGNED NULL DEFAULT NULL,
			verified_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_teacher_locale (teacher_id, locale),
			KEY locale (locale),
			KEY can_teach_in (can_teach_in)
		) ENGINE=InnoDB {$charset};";
	}

	public static function safeguarding_checks_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::SAFEGUARDING_CHECKS_TABLE;

		/*
		 * §2.5 · we deliberately do NOT store the check result or contents.
		 * The check header (type, reference, issue date, expiry, verifier)
		 * lets the gate reason about validity without hoarding the police
		 * data the gate exists to protect. document_ref points to an
		 * encrypted store outside the app if a copy is legally required.
		 */
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			teacher_id BIGINT UNSIGNED NOT NULL,
			check_type VARCHAR(50) NOT NULL,
			reference VARCHAR(100) NOT NULL DEFAULT '',
			issued_at DATE NULL DEFAULT NULL,
			expires_at DATE NULL DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			verified_by BIGINT UNSIGNED NULL DEFAULT NULL,
			verified_at DATETIME NULL DEFAULT NULL,
			document_ref VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY teacher_id (teacher_id),
			KEY status_expires (status, expires_at),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB {$charset};";
	}

	public static function person_audit_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::PERSON_AUDIT_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subject_type VARCHAR(20) NOT NULL,
			subject_id BIGINT UNSIGNED NOT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(64) NOT NULL,
			payload_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY subject (subject_type, subject_id),
			KEY actor (actor_user_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset};";
	}
}
