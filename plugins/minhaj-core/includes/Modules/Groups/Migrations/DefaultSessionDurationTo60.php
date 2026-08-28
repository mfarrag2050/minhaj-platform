<?php
/**
 * Change the default for `wp_minhaj_groups.session_duration_minutes` from 0
 * to 60, and backfill any existing rows that were created with the old
 * default. The value 0 was never meaningful — generation cannot materialise
 * a zero-length session — but the old default let callers omit the field
 * and end up with an unusable group. The paired guard in
 * TimetableService::generate_for_group rejects any group still at 0.
 *
 * @package Minhaj\Modules\Groups\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

final class DefaultSessionDurationTo60 extends Migration {

	public const VERSION = 20260828300000;

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'groups.default_session_duration_to_60';
	}

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . CreateGroupsTables::GROUPS_TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} MODIFY session_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60" );
		$wpdb->query( "UPDATE {$table} SET session_duration_minutes = 60 WHERE session_duration_minutes = 0" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
