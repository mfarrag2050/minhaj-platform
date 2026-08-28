<?php
/**
 * spec-calendar-v1 §2.3 — how a group reacts when one of its sessions
 * falls on a calendar-disabled day.
 *
 *   • `skip_and_extend`   — DEFAULT (§3.4). Keep generating until the
 *                           contracted `total_sessions` is met; the last
 *                           session slides later on the calendar. The
 *                           student gets what they paid for.
 *
 *   • `skip_and_compress` — Fixed number of weeks; final count is less
 *                           than `total_sessions`. Only permitted with:
 *                             (1) MANAGE_GROUPS capability,
 *                             (2) a written reason,
 *                             (3) no paid enrolments — or an explicit
 *                                 paid-override with its own reason.
 *                           **NOT switchable via a global setting** (C-4):
 *                           it must be a per-group, per-actor decision.
 *
 * @package Minhaj\Modules\Calendar\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Calendar\Domain;

defined( 'ABSPATH' ) || exit;

final class HolidayBehavior {

	public const SKIP_AND_EXTEND   = 'skip_and_extend';
	public const SKIP_AND_COMPRESS = 'skip_and_compress';

	/**
	 * @var array<int, string>
	 */
	private const ALL = array( self::SKIP_AND_EXTEND, self::SKIP_AND_COMPRESS );

	public static function is_valid( string $behavior ): bool {
		return in_array( $behavior, self::ALL, true );
	}
}
