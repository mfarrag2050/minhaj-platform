<?php
/**
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Timetable\Domain;

use Minhaj\Modules\Timetable\Domain\RuleViolationException;
use Minhaj\Modules\Timetable\Domain\TimetableRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( TimetableRules::class )]
final class TimetableRulesTest extends TestCase {

	// ================================================== assert_availability_covers.

	public function test_availability_covers_session_inside_slot(): void {
		$this->expectNotToPerformAssertions();

		TimetableRules::assert_availability_covers(
			array(
				array(
					'weekday'        => 0,
					'start_local'    => '17:00:00',
					'end_local'      => '20:00:00',
					'timezone'       => 'Europe/Amsterdam',
					'effective_from' => '2026-01-01',
					'effective_to'   => null,
				),
			),
			'2026-09-06 16:00:00', // Sunday 18:00 Amsterdam CEST.
			'2026-09-06 17:00:00'
		);
	}

	#[TestDox( 'R-4 fires when the session weekday does not match any slot' )]
	public function test_availability_rejects_when_weekday_mismatch(): void {
		$this->expectException( RuleViolationException::class );

		TimetableRules::assert_availability_covers(
			array(
				array(
					'weekday'        => 1, // Monday only.
					'start_local'    => '17:00:00',
					'end_local'      => '20:00:00',
					'timezone'       => 'Europe/Amsterdam',
					'effective_from' => '2026-01-01',
					'effective_to'   => null,
				),
			),
			'2026-09-06 16:00:00', // Sunday.
			'2026-09-06 17:00:00'
		);
	}

	#[TestDox( 'R-4 fires when the session is outside effective_from/effective_to' )]
	public function test_availability_rejects_when_outside_effective_range(): void {
		$this->expectException( RuleViolationException::class );

		TimetableRules::assert_availability_covers(
			array(
				array(
					'weekday'        => 0,
					'start_local'    => '17:00:00',
					'end_local'      => '20:00:00',
					'timezone'       => 'Europe/Amsterdam',
					'effective_from' => '2026-01-01',
					'effective_to'   => '2026-06-30',
				),
			),
			'2026-09-06 16:00:00',
			'2026-09-06 17:00:00'
		);
	}

	// ============================================================ assert_no_absence.

	public function test_no_absence_passes_when_none_overlap(): void {
		$this->expectNotToPerformAssertions();

		TimetableRules::assert_no_absence(
			array(
				array(
					'starts_at_utc' => '2026-10-01 00:00:00',
					'ends_at_utc'   => '2026-10-02 00:00:00',
				),
			),
			'2026-09-06 16:00:00',
			'2026-09-06 17:00:00'
		);
	}

	public function test_no_absence_rejects_when_overlap(): void {
		$this->expectException( RuleViolationException::class );

		TimetableRules::assert_no_absence(
			array(
				array(
					'starts_at_utc' => '2026-09-06 15:00:00',
					'ends_at_utc'   => '2026-09-06 18:00:00',
				),
			),
			'2026-09-06 16:00:00',
			'2026-09-06 17:00:00'
		);
	}

	// ========================================================= assert_no_double_book.

	#[TestDox( 'R-5: two overlapping sessions for the same teacher raise' )]
	public function test_double_book_rejects_overlap(): void {
		$this->expectException( RuleViolationException::class );

		TimetableRules::assert_no_double_book(
			array(
				array(
					'scheduled_start_utc' => '2026-09-06 16:30:00',
					'scheduled_end_utc'   => '2026-09-06 17:30:00',
				),
			),
			'2026-09-06 16:00:00',
			'2026-09-06 17:00:00'
		);
	}

	public function test_double_book_allows_touching_boundary(): void {
		$this->expectNotToPerformAssertions();

		// Session ends at 17:00; next starts at 17:00 exactly — legal.
		TimetableRules::assert_no_double_book(
			array(
				array(
					'scheduled_start_utc' => '2026-09-06 16:00:00',
					'scheduled_end_utc'   => '2026-09-06 17:00:00',
				),
			),
			'2026-09-06 17:00:00',
			'2026-09-06 18:00:00'
		);
	}
}
