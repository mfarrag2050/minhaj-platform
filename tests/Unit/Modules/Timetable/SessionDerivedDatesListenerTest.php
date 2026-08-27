<?php
/**
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Timetable;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Minhaj\Modules\Timetable\Repository\TimetableRepository;
use Minhaj\Modules\Timetable\SessionDerivedDatesListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( SessionDerivedDatesListener::class )]
final class SessionDerivedDatesListenerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[TestDox( 'spec-groups-v1 §3.1 (test 1): 36 sessions generated → expected_end_date = date of session 36, flag=0' )]
	public function test_thirtysix_scheduled_sessions_set_expected_end_to_last_session_date(): void {
		$repo = $this->createMock( TimetableRepository::class );

		// Post-generation state: 36 sessions, none cancelled/unscheduled.
		// Last session (Thu 2026-11-26 18:00 CET) UTC-date = 2026-11-26.
		$repo->method( 'max_active_scheduled_date_for_group' )->willReturn( '2026-11-26' );
		$repo->method( 'count_unscheduled_makeups_for_group' )->willReturn( 0 );

		$captured = null;
		$repo->expects( $this->once() )
			->method( 'update_group_derived_dates' )
			->willReturnCallback(
				function ( int $gid, ?string $end, int $flag ) use ( &$captured ): void {
					$captured = array(
						'group_id' => $gid,
						'end'      => $end,
						'flag'     => $flag,
					);
				}
			);

		( new SessionDerivedDatesListener( $repo ) )->recompute_for_group( 1 );

		$this->assertSame( 1, $captured['group_id'] );
		$this->assertSame( '2026-11-26', $captured['end'] );
		$this->assertSame( 0, $captured['flag'] );
	}

	#[TestDox( 'spec-groups-v1 §3.1 (test 2): cancel session 5 → scheduled make-up added → expected_end_date advances to make-up date, flag=0' )]
	public function test_scheduled_makeup_advances_expected_end(): void {
		$repo = $this->createMock( TimetableRepository::class );

		// The listener re-runs after cancel + make-up commit. Now MAX excludes
		// the newly-cancelled session (status=cancelled) and includes the
		// make-up appended at Sun 2026-11-29.
		$repo->method( 'max_active_scheduled_date_for_group' )->willReturn( '2026-11-29' );
		$repo->method( 'count_unscheduled_makeups_for_group' )->willReturn( 0 );

		$captured = null;
		$repo->method( 'update_group_derived_dates' )
			->willReturnCallback(
				function ( int $gid, ?string $end, int $flag ) use ( &$captured ): void {
					$captured = array( 'end' => $end, 'flag' => $flag );
				}
			);

		( new SessionDerivedDatesListener( $repo ) )->recompute_for_group( 1 );

		$this->assertSame( '2026-11-29', $captured['end'] );
		$this->assertSame( 0, $captured['flag'] );
	}

	#[TestDox( 'spec-groups-v1 §3.1 (test 3): cancel with unscheduled make-up → expected_end_date unchanged, flag=1' )]
	public function test_unscheduled_makeup_flags_group_but_leaves_end_date(): void {
		$repo = $this->createMock( TimetableRepository::class );

		// The last active-scheduled session is still 2026-11-26 (the make-up
		// carries NULL times and is filtered out by MAX). The unscheduled row
		// bumps the counter.
		$repo->method( 'max_active_scheduled_date_for_group' )->willReturn( '2026-11-26' );
		$repo->method( 'count_unscheduled_makeups_for_group' )->willReturn( 1 );

		$captured = null;
		$repo->method( 'update_group_derived_dates' )
			->willReturnCallback(
				function ( int $gid, ?string $end, int $flag ) use ( &$captured ): void {
					$captured = array( 'end' => $end, 'flag' => $flag );
				}
			);

		( new SessionDerivedDatesListener( $repo ) )->recompute_for_group( 1 );

		$this->assertSame( '2026-11-26', $captured['end'], 'end date must NOT move — unscheduled make-up has no time yet' );
		$this->assertSame( 1, $captured['flag'], 'has_unscheduled_makeup must be set so admin sees the debt' );
	}

	#[TestDox( 'spec-groups-v1 §3.1 (test 4): schedule the pending make-up → expected_end_date advances, flag returns to 0' )]
	public function test_scheduling_the_makeup_lifts_the_flag_and_extends_end(): void {
		$repo = $this->createMock( TimetableRepository::class );

		// After schedule_makeup: former unscheduled row now carries times.
		// MAX picks it up; the queue counter drops to zero.
		$repo->method( 'max_active_scheduled_date_for_group' )->willReturn( '2026-12-06' );
		$repo->method( 'count_unscheduled_makeups_for_group' )->willReturn( 0 );

		$captured = null;
		$repo->method( 'update_group_derived_dates' )
			->willReturnCallback(
				function ( int $gid, ?string $end, int $flag ) use ( &$captured ): void {
					$captured = array( 'end' => $end, 'flag' => $flag );
				}
			);

		( new SessionDerivedDatesListener( $repo ) )->recompute_for_group( 1 );

		$this->assertSame( '2026-12-06', $captured['end'] );
		$this->assertSame( 0, $captured['flag'] );
	}

	#[TestDox( 'no dated sessions at all → expected_end_date persists as NULL, the derivation refuses to guess' )]
	public function test_no_sessions_yields_null_end_date(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'max_active_scheduled_date_for_group' )->willReturn( null );
		$repo->method( 'count_unscheduled_makeups_for_group' )->willReturn( 0 );

		$captured = null;
		$repo->method( 'update_group_derived_dates' )
			->willReturnCallback(
				function ( int $gid, ?string $end, int $flag ) use ( &$captured ): void {
					$captured = array( 'end' => $end, 'flag' => $flag );
				}
			);

		( new SessionDerivedDatesListener( $repo ) )->recompute_for_group( 1 );

		$this->assertNull( $captured['end'] );
		$this->assertSame( 0, $captured['flag'] );
	}

	public function test_ignores_non_positive_group_id(): void {
		$repo = $this->createMock( TimetableRepository::class );
		$repo->expects( $this->never() )->method( 'update_group_derived_dates' );

		( new SessionDerivedDatesListener( $repo ) )->recompute_for_group( 0 );
	}
}
