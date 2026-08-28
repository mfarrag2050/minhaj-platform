<?php
/**
 * spec-attendance-v1 §3.3 · teacher-side lifecycle. `no_show` is R-7:
 * the teacher never arrived; the session gets `no_show` and the class
 * gets the numbering + compensation treatment the cancellation path
 * already uses. `pending` is the initial state before finalize_session
 * ever runs.
 *
 * @package Minhaj\Modules\Attendance\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Attendance\Domain;

defined( 'ABSPATH' ) || exit;

final class TeacherPresenceStatus {

	public const PENDING  = 'pending';
	public const ATTENDED = 'attended';
	public const LATE     = 'late';
	public const NO_SHOW  = 'no_show';
}
