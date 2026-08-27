<?php
/**
 * Event names emitted by PeopleService — the integration contract from
 * spec-people-v1 §5. Other modules subscribe here; nothing calls the
 * service directly across module boundaries.
 *
 * Events fire ONLY after the DB transaction commits.
 *
 * @package Minhaj\Modules\People
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People;

defined( 'ABSPATH' ) || exit;

final class Events {

	public const STUDENT_CREATED      = 'minhaj_student_created';
	public const STUDENT_ANONYMIZED   = 'minhaj_student_anonymized';
	public const GUARDIANSHIP_CHANGED = 'minhaj_guardianship_changed';
	public const TEACHER_ACTIVATED    = 'minhaj_teacher_activated';
	public const TEACHER_SUSPENDED    = 'minhaj_teacher_suspended';
	public const TEACHER_TRANSITIONED = 'minhaj_teacher_transitioned';
	public const CHECK_RECORDED       = 'minhaj_check_recorded';
	public const CHECK_EXPIRING       = 'minhaj_check_expiring';
	public const CHECK_EXPIRED        = 'minhaj_check_expired';

	private function __construct() {}
}
