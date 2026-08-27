<?php
/**
 * Event names emitted by GroupService — the integration contract from
 * spec-groups-v1 §6. Other modules subscribe to these; they never call
 * the service directly.
 *
 * Events fire ONLY after the DB transaction commits.
 *
 * @package Minhaj\Modules\Groups
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups;

defined( 'ABSPATH' ) || exit;

final class Events {

	public const SCHEDULED          = 'minhaj_group_scheduled';
	public const ACTIVATED          = 'minhaj_group_activated';
	public const SUSPENDED          = 'minhaj_group_suspended';
	public const RESUMED            = 'minhaj_group_resumed';
	public const COMPLETED          = 'minhaj_group_completed';
	public const CANCELLED          = 'minhaj_group_cancelled';
	public const MEMBER_ADDED       = 'minhaj_group_member_added';
	public const MEMBER_REMOVED     = 'minhaj_group_member_removed';
	public const MEMBER_TRANSFERRED = 'minhaj_group_member_transferred';
	public const TEACHER_ASSIGNED   = 'minhaj_group_teacher_assigned';
	public const TEACHER_CHANGED    = 'minhaj_group_teacher_changed';

	private function __construct() {}
}
