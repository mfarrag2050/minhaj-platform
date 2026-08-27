<?php
/**
 * Follow-up migration to CreateTimetableTables: makes the three datetime
 * fields on `minhaj_sessions` nullable so an unscheduled make-up (spec §5
 * fallback path) can be persisted without inventing placeholder timestamps.
 *
 * A cancelled session must always produce a make-up commitment — either
 * scheduled at a concrete UTC or held as an obligation with no time yet.
 * NULLs let the queue reflect reality; a placeholder like `1970-01-01`
 * would be indistinguishable from a real time and would corrupt every
 * downstream index and query that reasons about ordering.
 *
 * Applied only where CreateTimetableTables was already run; on a fresh
 * install it lands seconds after the initial create.
 *
 * @package Minhaj\Modules\Timetable\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

final class AlterSessionsForUnscheduledMakeups extends Migration {

	public const VERSION = 20260828000001;

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'timetable.nullable_makeup_session_times';
	}

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . CreateTimetableTables::SESSIONS_TABLE;

		/*
		 * ALTER MODIFY on three columns — dbDelta does not reliably rewrite
		 * NOT NULL to NULL on an existing column (it re-emits the CREATE),
		 * so this migration executes direct queries. The SchemaChange sniff
		 * is scoped away from Migrations/ in phpcs.xml.dist.
		 */

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} MODIFY scheduled_start_utc DATETIME NULL DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$table} MODIFY scheduled_end_utc DATETIME NULL DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$table} MODIFY local_start_wall DATETIME NULL DEFAULT NULL" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
