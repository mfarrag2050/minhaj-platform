<?php
/**
 * spec-calendar-v1 §2.2 — a day inside a calendar carries a kind.
 *
 *   • `closure`     — the school is closed (holiday, weekend override).
 *   • `no_teaching` — non-holiday day still not taught (staff training).
 *
 * Both kinds cause generation to skip the day; the distinction is a
 * reporting hint, not a behavioural one. Kept as two values (rather than
 * folded into `closure`) so future reports can distinguish paid holidays
 * from operational suspensions without a schema migration.
 *
 * @package Minhaj\Modules\Calendar\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Calendar\Domain;

defined( 'ABSPATH' ) || exit;

final class CalendarDayKind {

	public const CLOSURE     = 'closure';
	public const NO_TEACHING = 'no_teaching';

	/**
	 * @var array<int, string>
	 */
	private const ALL = array( self::CLOSURE, self::NO_TEACHING );

	public static function is_valid( string $kind ): bool {
		return in_array( $kind, self::ALL, true );
	}
}
