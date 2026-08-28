<?php
/**
 * Calendar module — initial schema for spec-calendar-v1 §2.
 *
 * Three tables:
 *   • minhaj_calendars        — a named list of non-teaching days. org_id
 *                               NULL = a public calendar (C-6: only our
 *                               staff can create or edit these).
 *   • minhaj_calendar_days    — a single dated entry inside a calendar.
 *                               UNIQUE (calendar_id, day_date) — two rows
 *                               for the same date on the same calendar are
 *                               a data-model contradiction.
 *   • minhaj_group_calendars  — junction linking a group to zero or more
 *                               calendars. UNIQUE (group_id, calendar_id).
 *                               The union of disabled days across the
 *                               attached calendars is what generation
 *                               skips (§2.3).
 *
 * Deliberately not stored:
 *   • "Session on skipped day" — the whole point is the session is never
 *     inserted. `lesson_no` / `sequence_no` stay contiguous (§3.3).
 *   • Recurring rules (RRULE) — every day is explicit. Islamic holidays
 *     advance ~11 days a year, and a rule would silently drift; the C-3
 *     staleness guard exists precisely because the source of truth is a
 *     human entering dates by hand.
 *
 * @package Minhaj\Modules\Calendar\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Calendar\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

final class CreateCalendarTables extends Migration {

	public const VERSION = 20260828500000;

	public const CALENDARS_TABLE       = 'minhaj_calendars';
	public const CALENDAR_DAYS_TABLE   = 'minhaj_calendar_days';
	public const GROUP_CALENDARS_TABLE = 'minhaj_group_calendars';

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'calendar.create_tables';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		dbDelta( self::calendars_table_sql( $prefix, $charset ) );
		dbDelta( self::calendar_days_table_sql( $prefix, $charset ) );
		dbDelta( self::group_calendars_table_sql( $prefix, $charset ) );
	}

	public static function calendars_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::CALENDARS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			org_id BIGINT UNSIGNED NULL DEFAULT NULL,
			country CHAR(2) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY org_id (org_id),
			KEY country (country),
			KEY status (status)
		) ENGINE=InnoDB {$charset};";
	}

	public static function calendar_days_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::CALENDAR_DAYS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			calendar_id BIGINT UNSIGNED NOT NULL,
			day_date DATE NOT NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'closure',
			label VARCHAR(190) NOT NULL DEFAULT '',
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_calendar_date (calendar_id, day_date),
			KEY day_date (day_date)
		) ENGINE=InnoDB {$charset};";
	}

	public static function group_calendars_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::GROUP_CALENDARS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			calendar_id BIGINT UNSIGNED NOT NULL,
			attached_by BIGINT UNSIGNED NOT NULL,
			attached_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_group_calendar (group_id, calendar_id),
			KEY calendar_id (calendar_id)
		) ENGINE=InnoDB {$charset};";
	}
}
