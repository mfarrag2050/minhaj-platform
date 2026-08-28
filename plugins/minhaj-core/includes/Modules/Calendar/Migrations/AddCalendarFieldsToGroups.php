<?php
/**
 * Adds three columns to `wp_minhaj_groups`:
 *
 *   • holiday_behavior       — spec-calendar-v1 §2.3. Default
 *                              'skip_and_extend' (§3.4). The mere presence
 *                              of the column does NOT permit skip_and_compress
 *                              to be flipped on with a global setting (C-4).
 *   • no_calendar_ack_by     — actor_user_id of the person who acknowledged
 *                              that this group generates without a calendar
 *                              (C-2). NULL until acknowledged.
 *   • no_calendar_ack_reason — the free-text reason. Sanitised at input,
 *                              never rendered as HTML.
 *
 * @package Minhaj\Modules\Calendar\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Calendar\Migrations;

use Minhaj\Migrations\Migration;
use Minhaj\Modules\Groups\Migrations\CreateGroupsTables;

defined( 'ABSPATH' ) || exit;

final class AddCalendarFieldsToGroups extends Migration {

	public const VERSION = 20260828500001;

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'calendar.add_group_columns';
	}

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . CreateGroupsTables::GROUPS_TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS holiday_behavior VARCHAR(20) NOT NULL DEFAULT 'skip_and_extend' AFTER has_unscheduled_makeup" );
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS no_calendar_ack_by BIGINT UNSIGNED NULL DEFAULT NULL AFTER holiday_behavior" );
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS no_calendar_ack_reason VARCHAR(255) NOT NULL DEFAULT '' AFTER no_calendar_ack_by" );
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS no_calendar_ack_at DATETIME NULL DEFAULT NULL AFTER no_calendar_ack_reason" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
