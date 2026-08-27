<?php
/**
 * Adds `has_unscheduled_makeup` to `wp_minhaj_groups`.
 *
 * The four date columns (planned_start_date, actual_start_date,
 * expected_end_date, formation_deadline) already exist on the table; this
 * migration only introduces the tri-state indicator the admin UI needs to
 * distinguish a genuinely-final expected_end_date from one that may still
 * shift because a make-up debt is pending in the schedule.
 *
 * Spec-groups-v1 §3.1 documents the derivation rule; the flag is written
 * by the Timetable module's SessionDerivedDatesListener alongside
 * expected_end_date so the two fields never disagree.
 *
 * @package Minhaj\Modules\Groups\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

final class AddDerivedFieldsToGroups extends Migration {

	public const VERSION = 20260828100000;

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'groups.add_derived_fields';
	}

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . CreateGroupsTables::GROUPS_TABLE;

		/*
		 * ALTER … ADD COLUMN IF NOT EXISTS is MySQL 8+ / MariaDB 10.0+ and
		 * lets us keep the migration idempotent without an explicit SHOW
		 * COLUMNS probe. SchemaChange sniff is scoped away from
		 * Migrations/ in phpcs.xml.dist.
		 */

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS has_unscheduled_makeup TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER expected_end_date" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
