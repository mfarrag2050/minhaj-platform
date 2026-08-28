<?php
/**
 * Add the org dimension to every table that already carries a subject we
 * attribute — spec-organizations-v1 §3.5. Doing this once, on empty tables,
 * is a one-day migration. Doing it later, on a live database of minors, is
 * a rebuild of the access layer. That trade is the reason this ships in
 * phase 2 alongside spec-access-v1.
 *
 * `origin_org_id` on the student profile is deliberately mirror-copied to
 * `minhaj_sessions.org_id` at generation time — same trick the module
 * already uses for `teacher_id` and `anchor_timezone`. A later change to
 * the group's `org_id` must not rewrite historical sessions; attribution
 * is snapshot-at-time-of-generation, not by-reference.
 *
 * `curriculum_id` defaults to 1 on `minhaj_groups` because the paired
 * migration (CreateOrgsTables) seeds `manhaj-v1` at id=1. Nothing reads
 * this column today — it exists so a second curriculum can be added
 * without a data migration (§3.4).
 *
 * @package Minhaj\Modules\Orgs\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Orgs\Migrations;

use Minhaj\Migrations\Migration;
use Minhaj\Modules\Groups\Migrations\CreateGroupsTables;
use Minhaj\Modules\People\Migrations\CreatePeopleTables;
use Minhaj\Modules\Timetable\Migrations\CreateTimetableTables;

defined( 'ABSPATH' ) || exit;

final class AddOrgDimension extends Migration {

	public const VERSION = 20260828400001;

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'orgs.add_org_dimension';
	}

	public function up(): void {
		global $wpdb;

		$prefix = $wpdb->prefix;

		$teacher_profiles = $prefix . CreatePeopleTables::TEACHER_PROFILES_TABLE;
		$student_profiles = $prefix . CreatePeopleTables::STUDENT_PROFILES_TABLE;
		$groups           = $prefix . CreateGroupsTables::GROUPS_TABLE;
		$sessions         = $prefix . CreateTimetableTables::SESSIONS_TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Teacher profiles — supplier org.
		$wpdb->query( "ALTER TABLE {$teacher_profiles} ADD COLUMN IF NOT EXISTS org_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER user_id" );
		$wpdb->query( "ALTER TABLE {$teacher_profiles} ADD KEY IF NOT EXISTS org_id (org_id)" );

		// Student profiles — origin (attribution) org + the specific link they came through.
		$wpdb->query( "ALTER TABLE {$student_profiles} ADD COLUMN IF NOT EXISTS origin_org_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER market" );
		$wpdb->query( "ALTER TABLE {$student_profiles} ADD COLUMN IF NOT EXISTS registration_link_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER origin_org_id" );
		$wpdb->query( "ALTER TABLE {$student_profiles} ADD KEY IF NOT EXISTS origin_org_id (origin_org_id)" );

		// Groups — operating org + curriculum.
		$wpdb->query( "ALTER TABLE {$groups} ADD COLUMN IF NOT EXISTS org_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER teacher_id" );
		$wpdb->query( "ALTER TABLE {$groups} ADD COLUMN IF NOT EXISTS curriculum_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER org_id" );
		$wpdb->query( "ALTER TABLE {$groups} ADD KEY IF NOT EXISTS org_id (org_id)" );

		// Sessions — snapshot of the group's operating org at generation time.
		$wpdb->query( "ALTER TABLE {$sessions} ADD COLUMN IF NOT EXISTS org_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER teacher_id" );
		$wpdb->query( "ALTER TABLE {$sessions} ADD KEY IF NOT EXISTS org_id (org_id)" );

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
