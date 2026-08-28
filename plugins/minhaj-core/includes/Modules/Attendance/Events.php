<?php
/**
 * do_action names for Attendance. All fired AFTER commit (R-11).
 *
 * @package Minhaj\Modules\Attendance
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Attendance;

defined( 'ABSPATH' ) || exit;

final class Events {

	public const ATTENDANCE_RECORDED  = 'minhaj_attendance_recorded';
	public const ATTENDANCE_AMENDED   = 'minhaj_attendance_amended';
	public const ATTENDANCE_FINALIZED = 'minhaj_attendance_finalized';

	public const SESSION_NO_SHOW         = 'minhaj_session_no_show';
	public const SESSION_ZERO_ATTENDANCE = 'minhaj_session_zero_attendance';

	public const UNKNOWN_PARTICIPANT_DETECTED = 'minhaj_unknown_participant_detected';
}
