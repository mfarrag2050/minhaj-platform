<?php
/**
 * Event names emitted by the Timetable module. Other modules subscribe here
 * (Zoom, notifications, attendance) — none call the service directly.
 *
 * Events fire ONLY after the DB transaction commits.
 *
 * @package Minhaj\Modules\Timetable
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable;

defined( 'ABSPATH' ) || exit;

final class Events {

	public const SESSIONS_GENERATED       = 'minhaj_sessions_generated';
	public const SESSION_RESCHEDULED      = 'minhaj_session_rescheduled';
	public const SESSION_CANCELLED        = 'minhaj_session_cancelled';
	public const SESSION_STARTED          = 'minhaj_session_started';
	public const SESSION_COMPLETED        = 'minhaj_session_completed';
	public const AVAILABILITY_CHANGED     = 'minhaj_teacher_availability_changed';
	public const TEACHER_ABSENCE_RECORDED = 'minhaj_teacher_absence_recorded';

	private function __construct() {}
}
