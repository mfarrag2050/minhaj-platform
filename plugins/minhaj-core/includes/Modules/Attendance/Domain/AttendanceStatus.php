<?php
/**
 * spec-attendance-v1 §4 · student attendance status. `auto_status`
 * holds what the auto-derivation produced; `status` holds what stands
 * after any human amendment. R-4 says `auto_status` is never touched
 * by an amendment — the system's observation stays as evidence.
 *
 * @package Minhaj\Modules\Attendance\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Attendance\Domain;

defined( 'ABSPATH' ) || exit;

final class AttendanceStatus {

	public const PRESENT = 'present';
	public const LATE    = 'late';
	public const ABSENT  = 'absent';

	/**
	 * @var array<int, string>
	 */
	private const ALL = array( self::PRESENT, self::LATE, self::ABSENT );

	public static function is_valid( string $status ): bool {
		return in_array( $status, self::ALL, true );
	}

	/**
	 * spec §4 derivation table. Applied only when computing
	 * `auto_status` — R-4 keeps `status` untouched if a human already
	 * amended.
	 */
	public static function derive_auto(
		int $attended_seconds,
		int $late_seconds,
		int $session_duration_seconds,
		float $present_ratio,
		int $late_seconds_threshold
	): string {
		$present_threshold = (int) floor( $session_duration_seconds * $present_ratio );

		if ( $attended_seconds >= $present_threshold ) {
			return $late_seconds > $late_seconds_threshold ? self::LATE : self::PRESENT;
		}

		return self::ABSENT;
	}
}
