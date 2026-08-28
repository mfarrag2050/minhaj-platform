<?php
/**
 * do_action names for Calendar. Fired AFTER commit — same rule as every
 * other module: an event inside an aborted transaction is worse than no
 * event at all.
 *
 * @package Minhaj\Modules\Calendar
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Calendar;

defined( 'ABSPATH' ) || exit;

final class Events {

	public const CALENDAR_CREATED = 'minhaj_calendar_created';
	public const DAY_ADDED        = 'minhaj_calendar_day_added';
	public const DAY_DELETED      = 'minhaj_calendar_day_deleted';

	public const ATTACHED_TO_GROUP   = 'minhaj_calendar_attached_to_group';
	public const DETACHED_FROM_GROUP = 'minhaj_calendar_detached_from_group';

	public const NO_CALENDAR_ACK      = 'minhaj_group_no_calendar_ack';
	public const HOLIDAY_BEHAVIOR_SET = 'minhaj_group_holiday_behavior_set';

	/**
	 * Non-blocking signal emitted by TimetableService (via a filter path)
	 * when a group about to be generated relies on a calendar that has no
	 * future days ≥ 90 days out. C-3.
	 */
	public const STALE_WARNING = 'minhaj_calendar_stale_warning';
}
