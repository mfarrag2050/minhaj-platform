<?php
/**
 * §4 derivation table + §8-1 acceptance criteria.
 *
 * @package Minhaj\Tests\Unit\Modules\Attendance
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Attendance;

use Minhaj\Modules\Attendance\Domain\AttendanceStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( AttendanceStatus::class )]
final class AttendanceStatusTest extends TestCase {

	#[TestDox( 'AC-1a · joined 3 min late, stayed 55 of 60 → present (attended ratio > 0.70, late < 10 min)' )]
	public function test_ac1a_present(): void {
		$result = AttendanceStatus::derive_auto(
			55 * 60,       // attended_seconds
			3 * 60,        // late_seconds
			60 * 60,       // session_duration_seconds
			0.70,          // present_ratio
			10 * 60        // late_seconds threshold
		);

		$this->assertSame( AttendanceStatus::PRESENT, $result );
	}

	#[TestDox( 'AC-1b · joined 12 min late, stayed 45 of 60 → late (attended ratio 0.75 → present threshold met, but late_seconds > 10min)' )]
	public function test_ac1b_late(): void {
		$result = AttendanceStatus::derive_auto(
			45 * 60,
			12 * 60,
			60 * 60,
			0.70,
			10 * 60
		);

		$this->assertSame( AttendanceStatus::LATE, $result );
	}

	#[TestDox( 'AC-1c · joined 40 min late, stayed 15 of 60 → absent (below present ratio)' )]
	public function test_ac1c_absent(): void {
		$result = AttendanceStatus::derive_auto(
			15 * 60,
			40 * 60,
			60 * 60,
			0.70,
			10 * 60
		);

		$this->assertSame( AttendanceStatus::ABSENT, $result );
	}

	#[TestDox( 'zero attendance → absent regardless of thresholds' )]
	public function test_zero_seconds_is_absent(): void {
		$this->assertSame(
			AttendanceStatus::ABSENT,
			AttendanceStatus::derive_auto( 0, 0, 60 * 60, 0.70, 10 * 60 )
		);
	}

	#[TestDox( 'AC-1 boundary · exactly the present threshold with zero lateness is present' )]
	public function test_exact_boundary(): void {
		$this->assertSame(
			AttendanceStatus::PRESENT,
			AttendanceStatus::derive_auto( 42 * 60, 0, 60 * 60, 0.70, 10 * 60 )
		);
	}
}
