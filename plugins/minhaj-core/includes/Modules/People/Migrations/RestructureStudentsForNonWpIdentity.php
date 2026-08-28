<?php
/**
 * Decision 18 · a child is not a WordPress user.
 *
 * Before this migration:
 *   • minhaj_student_profiles.user_id was both PK and a wp_users FK.
 *   • Every path that "created a student" ran wp_insert_user() first,
 *     forced a fake login + generated password, then keyed everything
 *     else off that user_id.
 *
 * After decision 18 the two concerns split: identity is an integer we
 * own; authentication is a separate optional link that only fills when
 * a student turns 16 and needs to log in themselves. The parent
 * remains a WordPress user (§4 keeps actor_user_id meaningful — a
 * shared parent account would erase the audit trail).
 *
 * Since the tables are empty at this point in the project's life
 * (verified before writing this migration), we DROP + CREATE rather
 * than ALTER — clearer, and no data survives the split intact.
 *
 * The org columns added by AddOrgDimension re-appear on the new
 * table with the same names so downstream code (OrgService,
 * AccessRepository) does not need to know about the restructure.
 *
 * @package Minhaj\Modules\People\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

/*
 * The RuntimeException below carries validated integer + table-name string
 * values only — never user-supplied HTML. The sniff cannot see through
 * sprintf() to know that; disabling it here rather than escaping DB error
 * detail keeps the developer-facing message readable in logs.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class RestructureStudentsForNonWpIdentity extends Migration {

	public const VERSION = 20260829100000;

	public const STUDENTS_TABLE = 'minhaj_students';

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'people.restructure_students_for_non_wp_identity';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$prefix         = $wpdb->prefix;
		$charset        = $wpdb->get_charset_collate();
		$legacy_table   = $prefix . 'minhaj_student_profiles';
		$students_table = $prefix . self::STUDENTS_TABLE;

		// The legacy table must be empty for the drop to be safe; the guard
		// exists so a mistaken run in a future environment that somehow has
		// rows halts with an error message instead of silently discarding
		// child records.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$legacy_table}" );
		if ( $count > 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			throw new \RuntimeException(
				sprintf( 'refusing to drop %s: %d rows present. Decision 18 restructure requires an empty table.', $legacy_table, $count )
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$legacy_table}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		dbDelta( self::students_table_sql( $prefix, $charset ) );
	}

	public static function students_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::STUDENTS_TABLE;

		/*
		 * `user_id` is nullable. A NULL means "no WordPress account for
		 * this student" and is the DEFAULT case — children do not log in.
		 * When a student turns 16 and needs their own account, the row
		 * is UPDATEd with the newly-created wp_users.ID.
		 *
		 * `active_user_link` is the STORED generated column that lets us
		 * enforce "one student per WP user" without a partial index. A
		 * WP user cannot be linked to two students at once; this catches
		 * a misuse where a parent account is accidentally reused for
		 * two child rows.
		 *
		 * The `origin_org_id` and `registration_link_id` columns come
		 * from spec-organizations-v1 §3.5. They live on `minhaj_students`
		 * for exactly the same reason they lived on `minhaj_student_profiles`
		 * before the split.
		 */
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL DEFAULT NULL,
			first_name VARCHAR(100) NOT NULL DEFAULT '',
			family_name_initial VARCHAR(4) NOT NULL DEFAULT '',
			birth_year SMALLINT UNSIGNED NULL DEFAULT NULL,
			ui_locale VARCHAR(10) NOT NULL DEFAULT '',
			market VARCHAR(10) NOT NULL DEFAULT '',
			origin_org_id BIGINT UNSIGNED NULL DEFAULT NULL,
			registration_link_id BIGINT UNSIGNED NULL DEFAULT NULL,
			current_level VARCHAR(32) NOT NULL DEFAULT '',
			notes_visible TEXT NULL,
			created_at DATETIME NOT NULL,
			anonymized_at DATETIME NULL DEFAULT NULL,
			active_user_link BIGINT UNSIGNED GENERATED ALWAYS AS (IF(user_id IS NULL, NULL, user_id)) STORED,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_active_user_link (active_user_link),
			KEY market (market),
			KEY origin_org_id (origin_org_id),
			KEY anonymized_at (anonymized_at)
		) ENGINE=InnoDB {$charset};";
	}
}
