<?php
/**
 * do_action names for Meetings. All fired AFTER commit (M-19).
 *
 * `SESSION_STARTED` and `SESSION_COMPLETED` reuse the existing Timetable
 * constants — spec §1 notes those were defined in Timetable but had no
 * emitter. This module is the emitter.
 *
 * @package Minhaj\Modules\Meetings
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings;

defined( 'ABSPATH' ) || exit;

final class Events {

	public const MEETING_CREATED = 'minhaj_meeting_created';
	public const MEETING_FAILED  = 'minhaj_meeting_failed';
	public const MEETING_REVOKED = 'minhaj_meeting_revoked';

	public const JOIN_TICKET_ISSUED = 'minhaj_join_ticket_issued';

	public const CONCURRENCY_THRESHOLD_REACHED = 'minhaj_concurrency_threshold_reached';

	// M-22 — emitted per drift row.
	public const SECURITY_DRIFT = 'minhaj_zoom_security_drift';

	// Existing Timetable events this module is finally the emitter of.
	public const SESSION_STARTED   = 'minhaj_session_started';
	public const SESSION_COMPLETED = 'minhaj_session_completed';
}
