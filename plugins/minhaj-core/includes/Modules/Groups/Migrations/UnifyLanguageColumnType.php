<?php
/**
 * Unifies the three "language" column types across the plugin.
 *
 * Before this migration:
 *   • minhaj_groups.teaching_language           CHAR(5)
 *   • minhaj_student_profiles.ui_locale         VARCHAR(10)   (now minhaj_students.ui_locale)
 *   • minhaj_teacher_languages.locale           VARCHAR(10)
 *
 * One concept, three definitions. CHAR(5) truncates BCP 47 tags like
 * `zh-Hant` at five characters and, worse, right-pads short tags with
 * spaces on some engines — so `= 'nl'` fails against a stored `'nl   '`
 * silently. VARCHAR(10) matches BCP 47's typical length for a
 * language-region combination without truncation, and matches the two
 * columns the People module already ships.
 *
 * The migration converts `teaching_language` to VARCHAR(10) so the
 * comparison `teaching_language = locale` (used by the assignability
 * gate) starts to actually match.
 *
 * @package Minhaj\Modules\Groups\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

final class UnifyLanguageColumnType extends Migration {

	public const VERSION = 20260830300001;

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'groups.unify_language_column_type';
	}

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . CreateGroupsTables::GROUPS_TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Strip trailing spaces that CHAR(5) may have left behind.
		$wpdb->query( "UPDATE {$table} SET teaching_language = TRIM(teaching_language)" );
		$wpdb->query( "ALTER TABLE {$table} MODIFY teaching_language VARCHAR(10) NOT NULL DEFAULT ''" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
