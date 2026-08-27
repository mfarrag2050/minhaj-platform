<?php
/**
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Timetable\Domain;

use Minhaj\Modules\Timetable\Domain\SessionTimeCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( SessionTimeCalculator::class )]
final class SessionTimeCalculatorTest extends TestCase {

	#[TestDox( 'spec §9-1: 3 weekdays × 12 weeks = 36 sessions with sequence_no 1..36' )]
	public function test_three_weekdays_twelve_weeks_produces_36_sessions(): void {
		$sessions = SessionTimeCalculator::generate(
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 0, 2, 4 ), // Sun, Tue, Thu.
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 12,
				'first_week_start' => '2026-09-06', // A Sunday.
			)
		);

		$this->assertCount( 36, $sessions );

		$expected_seq = 1;
		foreach ( $sessions as $s ) {
			$this->assertSame( $expected_seq, $s['sequence_no'] );
			$expected_seq++;
		}

		// Sanity: first and last local wall clocks.
		$this->assertSame( '2026-09-06 18:00:00', $sessions[0]['local_start_wall'] );
		$this->assertSame( '2026-11-26 18:00:00', $sessions[35]['local_start_wall'] );
	}

	#[TestDox( 'spec §9-2: Amsterdam DST — local_start_wall is stable, scheduled_start_utc shifts by an hour on transition' )]
	public function test_dst_transition_shifts_utc_but_holds_local_wall(): void {
		// Europe/Amsterdam switches CEST → CET on the last Sunday of October
		// (2026-10-25). A pattern that straddles the transition MUST keep the
		// wall clock the parent was promised, and quietly shift UTC.
		$sessions = SessionTimeCalculator::generate(
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 0 ), // Every Sunday.
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 6,
				'first_week_start' => '2026-10-11', // Sunday, before the switch.
			)
		);

		$this->assertCount( 6, $sessions );

		// Every local_start_wall is 18:00 — that is the parent's promise.
		foreach ( $sessions as $s ) {
			$this->assertStringEndsWith( '18:00:00', $s['local_start_wall'] );
		}

		// Before the switch: CEST (UTC+2) → 18:00 local = 16:00 UTC.
		$this->assertSame( '2026-10-11 18:00:00', $sessions[0]['local_start_wall'] );
		$this->assertSame( '2026-10-11 16:00:00', $sessions[0]['scheduled_start_utc'] );

		$this->assertSame( '2026-10-18 18:00:00', $sessions[1]['local_start_wall'] );
		$this->assertSame( '2026-10-18 16:00:00', $sessions[1]['scheduled_start_utc'] );

		// After the switch (2026-10-25 is transition day; that Sunday is
		// already CET post-3AM): CET (UTC+1) → 18:00 local = 17:00 UTC.
		$this->assertSame( '2026-10-25 18:00:00', $sessions[2]['local_start_wall'] );
		$this->assertSame( '2026-10-25 17:00:00', $sessions[2]['scheduled_start_utc'] );

		$this->assertSame( '2026-11-01 18:00:00', $sessions[3]['local_start_wall'] );
		$this->assertSame( '2026-11-01 17:00:00', $sessions[3]['scheduled_start_utc'] );

		// The UTC offset for the first session and the third session must
		// differ by exactly one hour — this is the single behaviour the whole
		// time module exists to defend.
		$before = strtotime( $sessions[1]['scheduled_start_utc'] . ' UTC' );
		$after  = strtotime( $sessions[2]['scheduled_start_utc'] . ' UTC' );

		$this->assertNotFalse( $before );
		$this->assertNotFalse( $after );

		$delta_seconds = $after - $before - ( 7 * 24 * 3600 );
		$this->assertSame( 3600, $delta_seconds, 'CEST→CET transition must shift UTC by +3600s beyond the week gap' );
	}

	#[TestDox( 'sessions are sorted chronologically by UTC even when weekdays are unsorted in input' )]
	public function test_output_sorted_regardless_of_input_order(): void {
		$sessions = SessionTimeCalculator::generate(
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 4, 0, 2 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 2,
				'first_week_start' => '2026-09-06',
			)
		);

		$prev = '';
		foreach ( $sessions as $s ) {
			$this->assertGreaterThan( $prev, $s['scheduled_start_utc'] );
			$prev = $s['scheduled_start_utc'];
		}
	}

	public function test_rejects_invalid_timezone(): void {
		$this->expectException( \InvalidArgumentException::class );
		SessionTimeCalculator::generate(
			array(
				'anchor_timezone'  => 'Not/A_Zone',
				'weekdays'         => array( 0 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 1,
				'first_week_start' => '2026-09-06',
			)
		);
	}

	public function test_rejects_weekday_out_of_range(): void {
		$this->expectException( \InvalidArgumentException::class );
		SessionTimeCalculator::generate(
			array(
				'anchor_timezone'  => 'UTC',
				'weekdays'         => array( 7 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 1,
				'first_week_start' => '2026-09-06',
			)
		);
	}
}
