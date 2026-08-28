<?php
/**
 * Calendar persistence layer.
 *
 * Only file in the module that talks to $wpdb. Table names go through %i
 * so the sniff can verify prepared usage without interpolation false
 * positives — same pattern as every other repository in this plugin.
 *
 * The two unique keys translated to typed PersistenceException kinds:
 *   • uq_calendar_date   → DUPLICATE_DAY      (calendar_days)
 *   • uq_group_calendar  → DUPLICATE_ATTACHED (group_calendars)
 *
 * @package Minhaj\Modules\Calendar\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Calendar\Repository;

use Minhaj\Modules\Calendar\Migrations\CreateCalendarTables;
use Minhaj\Modules\Groups\Migrations\CreateGroupsTables;
use Minhaj\Modules\Timetable\Migrations\CreateTimetableTables;

defined( 'ABSPATH' ) || exit;

/*
 * PersistenceException messages carry $wpdb->last_error verbatim so devs
 * can diagnose DB failures. They never reach an HTML response — the
 * service layer converts them to WP_Error at the boundary.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class CalendarRepository {

	// ---------------------------------------------------------- Transactions.

	public function begin_transaction(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
	}

	public function commit(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'COMMIT' );
	}

	public function rollback(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'ROLLBACK' );
	}

	// ------------------------------------------------------------ Calendars.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException On write failure.
	 */
	public function insert_calendar( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->calendars_table(), $data );

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to insert calendar: ' . $wpdb->last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_calendar( int $calendar_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->calendars_table(),
				$calendar_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The best default calendar for a country — the newest active one
	 * scoped to the country and public (org_id NULL). Callers use this
	 * to nudge the acknowledgement flow at generation time (C-2).
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_default_calendar_for_country( string $country ): ?array {
		global $wpdb;

		$country = strtoupper( trim( $country ) );
		if ( '' === $country ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE country = %s AND org_id IS NULL AND status = 'active' ORDER BY id DESC LIMIT 1",
				$this->calendars_table(),
				$country
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * List every active calendar that carries no `day_date` more than
	 * $threshold_days into the future. Feeds the stale-calendar report (C-3).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_stale_calendars( string $today_iso, int $threshold_days = 90 ): array {
		global $wpdb;

		$cutoff = ( new \DateTimeImmutable( $today_iso, new \DateTimeZone( 'UTC' ) ) )
			->modify( '+' . $threshold_days . ' days' )
			->format( 'Y-m-d' );

		$calendars = $this->calendars_table();
		$days      = $this->calendar_days_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*
					FROM %i c
					LEFT JOIN %i d
						ON d.calendar_id = c.id
						AND d.day_date > %s
					WHERE c.status = 'active'
					GROUP BY c.id
					HAVING COUNT(d.id) = 0
					ORDER BY c.id ASC",
				$calendars,
				$days,
				$cutoff
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// -------------------------------------------------------------- Days.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException DUPLICATE_DAY on uq_calendar_date collision.
	 */
	public function insert_day( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->calendar_days_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_calendar_date' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_DAY,
				'calendar already carries this date: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to insert calendar day: ' . $error
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_day( int $day_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->calendar_days_table(),
				$day_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function delete_day_by_id( int $day_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->delete( $this->calendar_days_table(), array( 'id' => $day_id ) );
	}

	// --------------------------------------------------- Held-sessions guard.

	/**
	 * How many sessions on the given date, in any group attached to the
	 * calendar that owns this day, are already `live` or `completed`.
	 *
	 * spec-calendar-v1 §4 guard, split across two tables — see C-5 in
	 * the review memo. Takes a FOR UPDATE lock so a concurrent completion
	 * cannot slip past between the count and the delete.
	 *
	 * The date match runs on `local_start_wall`, which was stored at
	 * generation time as the anchor-local wall clock and MUST NOT be
	 * re-derived from UTC (spec-timetable-v1 T-3 — tzdata evolves
	 * several times a year). This is deliberately NOT the earlier
	 * DATE(CONVERT_TZ(scheduled_start_utc, 'UTC', s.anchor_timezone))
	 * form: CONVERT_TZ returns NULL when `mysql.time_zone_name` is
	 * unpopulated (the default on many hosting stacks), which would
	 * make this guard fail OPEN — matching zero rows and silently
	 * letting the delete through. Failing open on a policy that
	 * protects a held session is the exact class of silent bug spec
	 * §4 asks us to prevent.
	 */
	public function count_held_sessions_on_calendar_date_for_update( int $calendar_id, string $day_date_iso ): int {
		global $wpdb;

		$sessions        = $wpdb->prefix . CreateTimetableTables::SESSIONS_TABLE;
		$group_calendars = $this->group_calendars_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(s.id)
					FROM %i s
					INNER JOIN %i gc ON gc.group_id = s.group_id
					WHERE gc.calendar_id = %d
					  AND s.status IN ('live', 'completed')
					  AND s.local_start_wall IS NOT NULL
					  AND DATE( s.local_start_wall ) = %s
					FOR UPDATE",
				$sessions,
				$group_calendars,
				$calendar_id,
				$day_date_iso
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	// ---------------------------------------------------- Group attachments.

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws PersistenceException DUPLICATE_ATTACHED on uq_group_calendar collision.
	 */
	public function attach_calendar_to_group( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->group_calendars_table(), $data );

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		$error = (string) $wpdb->last_error;
		if ( str_contains( $error, 'uq_group_calendar' ) ) {
			throw new PersistenceException(
				PersistenceException::DUPLICATE_ATTACHED,
				'calendar already attached to group: ' . $error
			);
		}

		throw new PersistenceException(
			PersistenceException::WRITE_FAILED,
			'failed to attach calendar to group: ' . $error
		);
	}

	public function detach_calendar_from_group( int $group_id, int $calendar_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->delete(
			$this->group_calendars_table(),
			array(
				'group_id'    => $group_id,
				'calendar_id' => $calendar_id,
			)
		);
	}

	/**
	 * @return array<int, int>
	 */
	public function list_calendar_ids_for_group( int $group_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT calendar_id FROM %i WHERE group_id = %d ORDER BY calendar_id',
				$this->group_calendars_table(),
				$group_id
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * The union of disabled dates from every calendar attached to a group,
	 * bounded to [$from_iso, $to_iso]. Returned as YYYY-MM-DD strings in
	 * calendar-local (naive) terms — TimetableService matches these against
	 * anchor-local session dates.
	 *
	 * @return array<int, string>
	 */
	public function list_disabled_dates_for_group( int $group_id, string $from_iso, string $to_iso ): array {
		global $wpdb;

		$days            = $this->calendar_days_table();
		$group_calendars = $this->group_calendars_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT d.day_date
					FROM %i d
					INNER JOIN %i gc ON gc.calendar_id = d.calendar_id
					WHERE gc.group_id = %d
					  AND d.day_date BETWEEN %s AND %s
					ORDER BY d.day_date',
				$days,
				$group_calendars,
				$group_id,
				$from_iso,
				$to_iso
			)
		);

		return array_map( 'strval', (array) $rows );
	}

	// ---------------------------------------------------- Groups cross-read.

	/**
	 * Cross-module read of the group's calendar-related fields. Timetable
	 * needs holiday_behavior + the ack pair to know whether to refuse
	 * generation. Groups module still owns writes to this table.
	 *
	 * @return array{
	 *   holiday_behavior:string,
	 *   no_calendar_ack_by:?int,
	 *   no_calendar_ack_reason:string,
	 *   no_calendar_ack_at:?string
	 * }|null
	 */
	public function find_group_calendar_state( int $group_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT holiday_behavior, no_calendar_ack_by, no_calendar_ack_reason, no_calendar_ack_at
					FROM %i WHERE id = %d AND deleted_at IS NULL',
				$wpdb->prefix . CreateGroupsTables::GROUPS_TABLE,
				$group_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'holiday_behavior'       => (string) $row['holiday_behavior'],
			'no_calendar_ack_by'     => null === $row['no_calendar_ack_by'] ? null : (int) $row['no_calendar_ack_by'],
			'no_calendar_ack_reason' => (string) $row['no_calendar_ack_reason'],
			'no_calendar_ack_at'     => null === $row['no_calendar_ack_at'] ? null : (string) $row['no_calendar_ack_at'],
		);
	}

	/**
	 * Narrow cross-module write: Calendar service owns the four columns it
	 * introduced on `minhaj_groups`. Everything else on the group is written
	 * by the Groups module.
	 */
	public function update_group_calendar_fields( int $group_id, array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$wpdb->prefix . CreateGroupsTables::GROUPS_TABLE,
			$data,
			array( 'id' => $group_id )
		);

		if ( false === $result ) {
			throw new PersistenceException(
				PersistenceException::WRITE_FAILED,
				'failed to update group calendar fields: ' . $wpdb->last_error
			);
		}

		return (int) $result;
	}

	/**
	 * How many active memberships on this group carry an order_id — the
	 * paid-enrolment count that C-4 blocks skip_and_compress on.
	 */
	public function count_paid_enrolments_for_group( int $group_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
					WHERE group_id = %d AND status = 'active' AND order_id IS NOT NULL",
				$wpdb->prefix . CreateGroupsTables::MEMBERS_TABLE,
				$group_id
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	// ---------------------------------------------------------------- Helpers.

	private function calendars_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateCalendarTables::CALENDARS_TABLE;
	}

	private function calendar_days_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateCalendarTables::CALENDAR_DAYS_TABLE;
	}

	private function group_calendars_table(): string {
		global $wpdb;

		return $wpdb->prefix . CreateCalendarTables::GROUP_CALENDARS_TABLE;
	}
}
