<?php
/**
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Timetable\Domain;

use Minhaj\Modules\Timetable\Domain\LessonNumbering;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( LessonNumbering::class )]
final class LessonNumberingTest extends TestCase {

	#[TestDox( 'spec §9-3: cancel session 5 → seq=5 gets lesson_no=NULL, seq=6 inherits lesson_no=5, makeup appended at seq=37 with lesson_no=36' )]
	public function test_cancel_session_five_shifts_lesson_no_and_appends_makeup(): void {
		$result = LessonNumbering::cancel_with_makeup( 36, 5 );

		// Sessions before the cancelled slot: unchanged.
		$this->assertSame( 1, $result['renumbering'][1] );
		$this->assertSame( 4, $result['renumbering'][4] );

		// The cancelled slot itself: NULL — never counted toward the 36
		// contracted hours (§5).
		$this->assertNull( $result['renumbering'][5] );

		// Session 6 inherits what would have been the 5th lesson, and so on
		// down the line — every subsequent held session shifts down by 1.
		$this->assertSame( 5, $result['renumbering'][6] );
		$this->assertSame( 6, $result['renumbering'][7] );
		$this->assertSame( 35, $result['renumbering'][36] );

		// R-9: a make-up session is appended at the end of the program,
		// linked to the cancelled slot via makeup_for_sequence_no.
		$this->assertSame( 37, $result['makeup']['sequence_no'] );
		$this->assertSame( 36, $result['makeup']['lesson_no'] );
		$this->assertSame( 5, $result['makeup']['makeup_for_sequence_no'] );
	}

	public function test_cancel_first_session_still_appends_makeup_at_tail(): void {
		$result = LessonNumbering::cancel_with_makeup( 10, 1 );

		$this->assertNull( $result['renumbering'][1] );
		$this->assertSame( 1, $result['renumbering'][2] );
		$this->assertSame( 9, $result['renumbering'][10] );

		$this->assertSame( 11, $result['makeup']['sequence_no'] );
		$this->assertSame( 10, $result['makeup']['lesson_no'] );
		$this->assertSame( 1, $result['makeup']['makeup_for_sequence_no'] );
	}

	public function test_cancel_last_session(): void {
		$result = LessonNumbering::cancel_with_makeup( 10, 10 );

		$this->assertSame( 9, $result['renumbering'][9] );
		$this->assertNull( $result['renumbering'][10] );

		$this->assertSame( 11, $result['makeup']['sequence_no'] );
		$this->assertSame( 10, $result['makeup']['lesson_no'] );
		$this->assertSame( 10, $result['makeup']['makeup_for_sequence_no'] );
	}

	public function test_rejects_out_of_range_cancellation(): void {
		$this->expectException( \InvalidArgumentException::class );
		LessonNumbering::cancel_with_makeup( 10, 11 );
	}

	public function test_rejects_non_positive_total(): void {
		$this->expectException( \InvalidArgumentException::class );
		LessonNumbering::cancel_with_makeup( 0, 1 );
	}
}
