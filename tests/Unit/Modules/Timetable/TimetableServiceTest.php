<?php
/**
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Timetable;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Minhaj\Modules\Timetable\Repository\TimetableRepository;
use Minhaj\Modules\Timetable\TimetableService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use WP_Error;

#[CoversClass( TimetableService::class )]
final class TimetableServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( fn( string $tag, mixed $value ) => $value );
		Functions\when( 'do_action' )->justReturn();
		Functions\when( 'current_time' )->justReturn( '2026-08-27 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $v ) => json_encode( $v ) );
		Functions\when( 'absint' )->alias( fn( mixed $n ) => abs( (int) $n ) );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias( fn( mixed $t ) => $t instanceof WP_Error );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ============================================================ set_availability.

	public function test_set_availability_requires_actor(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$result = ( new TimetableService( $repo ) )->set_availability(
			0,
			10,
			array( $this->slot() )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_actor', $result->get_error_code() );
	}

	public function test_set_availability_rejects_empty_slots(): void {
		$repo   = $this->createMock( TimetableRepository::class );
		$result = ( new TimetableService( $repo ) )->set_availability( 7, 10, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_arg', $result->get_error_code() );
	}

	public function test_set_availability_writes_slots_and_audits(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$captured_slots = array();
		$repo->method( 'insert_availability' )->willReturnCallback(
			function ( array $data ) use ( &$captured_slots ): int {
				$captured_slots[] = $data;
				return count( $captured_slots );
			}
		);

		$audit_capture = null;
		$repo->expects( $this->once() )
			->method( 'insert_audit' )
			->willReturnCallback(
				function ( array $data ) use ( &$audit_capture ): int {
					$audit_capture = $data;
					return 900;
				}
			);
		$repo->expects( $this->once() )->method( 'begin_transaction' );
		$repo->expects( $this->once() )->method( 'commit' );
		$repo->expects( $this->never() )->method( 'rollback' );

		$result = ( new TimetableService( $repo ) )->set_availability(
			7,
			10,
			array( $this->slot(), $this->slot( array( 'weekday' => 2 ) ) )
		);

		$this->assertTrue( $result );
		$this->assertCount( 2, $captured_slots );
		$this->assertSame( 10, $captured_slots[0]['teacher_id'] );
		$this->assertSame( 'availability.set', $audit_capture['action'] );
		$this->assertSame( 7, $audit_capture['actor_user_id'] );
	}

	// ================================================================= add_absence.

	public function test_add_absence_rejects_reversed_window(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$result = ( new TimetableService( $repo ) )->add_absence(
			7,
			10,
			'2026-11-02 00:00:00',
			'2026-11-01 00:00:00',
			'medical leave'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_range', $result->get_error_code() );
	}

	public function test_add_absence_writes_and_returns_id(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'insert_absence' )->willReturn( 42 );

		$audit = null;
		$repo->expects( $this->once() )
			->method( 'insert_audit' )
			->willReturnCallback(
				function ( array $data ) use ( &$audit ): int {
					$audit = $data;
					return 1;
				}
			);
		$repo->expects( $this->once() )->method( 'commit' );

		$result = ( new TimetableService( $repo ) )->add_absence(
			7,
			10,
			'2026-11-01 00:00:00',
			'2026-11-02 00:00:00',
			'medical leave'
		);

		$this->assertSame( 42, $result );
		$this->assertSame( 'absence.added', $audit['action'] );
		$this->assertSame( 7, $audit['actor_user_id'] );
		$this->assertSame( 10, $audit['teacher_id'] );
	}

	// =========================================================== generate_for_group.

	#[TestDox( 'generate_for_group: happy path — 36 sessions inserted, sequence 1..36, one audit row, one event' )]
	public function test_generate_for_group_writes_36_sessions_with_sequence_one_to_36(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'find_group' )->willReturn(
			array(
				'id'             => 1,
				'teacher_id'     => 50,
				'total_sessions' => 36,
			)
		);
		$repo->method( 'list_availability_for_teacher_between' )->willReturn(
			array(
				$this->slot( array( 'weekday' => 0 ) ),
				$this->slot( array( 'weekday' => 2 ) ),
				$this->slot( array( 'weekday' => 4 ) ),
			)
		);
		$repo->method( 'list_absences_for_teacher_between' )->willReturn( array() );
		$repo->method( 'lock_teacher_sessions_between' )->willReturn( array() );
		$repo->method( 'insert_pattern' )->willReturn( 99 );

		$inserted_seqs = array();
		$repo->method( 'insert_session' )->willReturnCallback(
			function ( array $data ) use ( &$inserted_seqs ): int {
				$inserted_seqs[] = (int) $data['sequence_no'];
				return 1000 + (int) $data['sequence_no'];
			}
		);
		$repo->method( 'insert_audit' )->willReturn( 1 );

		$repo->expects( $this->once() )->method( 'begin_transaction' );
		$repo->expects( $this->once() )->method( 'commit' );
		$repo->expects( $this->never() )->method( 'rollback' );

		$result = ( new TimetableService( $repo ) )->generate_for_group(
			7,
			1,
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 0, 2, 4 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 12,
				'first_week_start' => '2026-09-06',
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 36, $result );
		$this->assertSame( range( 1, 36 ), $inserted_seqs );
	}

	#[TestDox( 'spec §9-2 (through the service): DST week straddles CEST→CET, UTC shifts, wall clock stays 18:00' )]
	public function test_generate_for_group_preserves_wall_across_dst(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'find_group' )->willReturn(
			array(
				'id'             => 1,
				'teacher_id'     => 50,
				'total_sessions' => 6,
			)
		);
		$repo->method( 'list_availability_for_teacher_between' )->willReturn(
			array( $this->slot( array( 'weekday' => 0 ) ) )
		);
		$repo->method( 'list_absences_for_teacher_between' )->willReturn( array() );
		$repo->method( 'lock_teacher_sessions_between' )->willReturn( array() );
		$repo->method( 'insert_pattern' )->willReturn( 99 );

		$captured = array();
		$repo->method( 'insert_session' )->willReturnCallback(
			function ( array $data ) use ( &$captured ): int {
				$captured[] = $data;
				return 1000 + (int) $data['sequence_no'];
			}
		);
		$repo->method( 'insert_audit' )->willReturn( 1 );

		$result = ( new TimetableService( $repo ) )->generate_for_group(
			7,
			1,
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 0 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 6,
				'first_week_start' => '2026-10-11',
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 6, $captured );

		// Every stored local_start_wall is 18:00 — the parent's promise.
		foreach ( $captured as $row ) {
			$this->assertStringEndsWith( '18:00:00', $row['local_start_wall'] );
		}

		// Pre-transition weeks in CEST: 16:00 UTC. Post-transition in CET: 17:00 UTC.
		$this->assertSame( '2026-10-11 16:00:00', $captured[0]['scheduled_start_utc'] );
		$this->assertSame( '2026-10-18 16:00:00', $captured[1]['scheduled_start_utc'] );
		$this->assertSame( '2026-10-25 17:00:00', $captured[2]['scheduled_start_utc'] );
		$this->assertSame( '2026-11-01 17:00:00', $captured[3]['scheduled_start_utc'] );

		// Anchor timezone recorded per session (T-3).
		$this->assertSame( 'Europe/Amsterdam', $captured[0]['anchor_timezone'] );
	}

	#[TestDox( 'spec §9-6: teacher already booked in the window — clean rejection, transaction rolls back, nothing inserted' )]
	public function test_generate_for_group_rejects_double_booking(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'find_group' )->willReturn(
			array(
				'id'             => 1,
				'teacher_id'     => 50,
				'total_sessions' => 3,
			)
		);
		$repo->method( 'list_availability_for_teacher_between' )->willReturn(
			array( $this->slot( array( 'weekday' => 0 ) ) )
		);
		$repo->method( 'list_absences_for_teacher_between' )->willReturn( array() );

		// Teacher already has a session that overlaps the very first one we
		// would insert. The FOR-UPDATE fetch returns it, and the rule must
		// abort the whole batch.
		$repo->method( 'lock_teacher_sessions_between' )->willReturn(
			array(
				array(
					'id'                  => 501,
					'scheduled_start_utc' => '2026-09-06 15:30:00',
					'scheduled_end_utc'   => '2026-09-06 16:30:00',
				),
			)
		);
		$repo->method( 'insert_pattern' )->willReturn( 99 );

		$repo->expects( $this->once() )->method( 'begin_transaction' );
		$repo->expects( $this->once() )->method( 'rollback' );
		$repo->expects( $this->never() )->method( 'commit' );
		$repo->expects( $this->never() )->method( 'insert_session' );

		$result = ( new TimetableService( $repo ) )->generate_for_group(
			7,
			1,
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 0 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 3,
				'first_week_start' => '2026-09-06',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'double_book', $result->get_error_code() );
	}

	#[TestDox( 'availability gap: pattern places a session outside every slot — nothing inserted, error names the wall clock' )]
	public function test_generate_for_group_rejects_when_availability_missing(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'find_group' )->willReturn(
			array(
				'id'             => 1,
				'teacher_id'     => 50,
				'total_sessions' => 3,
			)
		);
		// Only Mondays covered — pattern targets Sunday.
		$repo->method( 'list_availability_for_teacher_between' )->willReturn(
			array( $this->slot( array( 'weekday' => 1 ) ) )
		);
		$repo->method( 'list_absences_for_teacher_between' )->willReturn( array() );

		$repo->expects( $this->never() )->method( 'begin_transaction' );
		$repo->expects( $this->never() )->method( 'insert_session' );

		$result = ( new TimetableService( $repo ) )->generate_for_group(
			7,
			1,
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 0 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 3,
				'first_week_start' => '2026-09-06',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'availability_conflict', $result->get_error_code() );
	}

	public function test_generate_for_group_rejects_when_group_missing_teacher(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'find_group' )->willReturn(
			array(
				'id'             => 1,
				'teacher_id'     => 0,
				'total_sessions' => 36,
			)
		);

		$result = ( new TimetableService( $repo ) )->generate_for_group(
			7,
			1,
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 0 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 1,
				'first_week_start' => '2026-09-06',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'teacher_missing', $result->get_error_code() );
	}

	#[TestDox( 'R-1 enforced: pattern count must match group.total_sessions exactly' )]
	public function test_generate_for_group_enforces_total_sessions_match(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'find_group' )->willReturn(
			array(
				'id'             => 1,
				'teacher_id'     => 50,
				'total_sessions' => 36,
			)
		);

		$result = ( new TimetableService( $repo ) )->generate_for_group(
			7,
			1,
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 0 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 2,     // 2 sessions, not 36.
				'first_week_start' => '2026-09-06',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'total_mismatch', $result->get_error_code() );
	}

	// ========================================================================= cancel.

	#[TestDox( 'spec §5.1: cancel session 5 of 36 — seq keeps 5/cancelled/NULL, decrement runs on seq>5, makeup lands at seq=37/lesson=36 with makeup_for_id=session id' )]
	public function test_cancel_session_five_of_thirtysix_produces_exact_state_table(): void {
		$repo         = $this->createMock( TimetableRepository::class );
		$session_id   = 105;
		$group_id     = 1;
		$teacher_id   = 50;

		$repo->method( 'find_session_for_update' )->willReturn(
			array(
				'id'                  => $session_id,
				'group_id'            => $group_id,
				'pattern_id'          => 99,
				'teacher_id'          => $teacher_id,
				'sequence_no'         => 5,
				'lesson_no'           => 5,
				'status'              => 'scheduled',
				'scheduled_start_utc' => '2026-09-15 16:00:00',
				'scheduled_end_utc'   => '2026-09-15 17:00:00',
			)
		);
		$repo->method( 'find_pattern' )->willReturn(
			array(
				'id'               => 99,
				'group_id'         => $group_id,
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays_json'    => '[0,2,4]',
				'start_local'      => '18:00:00',
				'duration_minutes' => 60,
			)
		);
		// Last existing session in the group: Thu 2026-11-26 18:00 CET = 17:00 UTC.
		$repo->method( 'max_scheduled_start_utc_for_group' )->willReturn( '2026-11-26 17:00:00' );
		$repo->method( 'max_sequence_no_for_group' )->willReturn( 36 );
		$repo->method( 'max_lesson_no_for_group' )->willReturn( 36 );

		// The walker will land on Sunday 2026-11-29 (dow=0) — the first weekday in the pattern past the last session.
		$repo->method( 'list_availability_for_teacher_on' )->willReturn(
			array(
				array(
					'weekday'        => 0,
					'start_local'    => '17:00:00',
					'end_local'      => '20:00:00',
					'timezone'       => 'Europe/Amsterdam',
					'effective_from' => '2026-01-01',
					'effective_to'   => null,
				),
			)
		);
		$repo->method( 'list_absences_for_teacher_between' )->willReturn( array() );
		$repo->method( 'lock_teacher_sessions_between' )->willReturn( array() );

		$captured_update    = null;
		$captured_decrement = null;
		$captured_insert    = null;

		$repo->expects( $this->once() )
			->method( 'update_session' )
			->willReturnCallback(
				function ( int $id, array $data ) use ( &$captured_update, $session_id ): void {
					$this->assertSame( $session_id, $id );
					$captured_update = $data;
				}
			);
		$repo->expects( $this->once() )
			->method( 'decrement_lesson_no_after_sequence' )
			->willReturnCallback(
				function ( int $gid, int $after ) use ( &$captured_decrement ): void {
					$captured_decrement = array( $gid, $after );
				}
			);
		$repo->expects( $this->once() )
			->method( 'insert_session' )
			->willReturnCallback(
				function ( array $data ) use ( &$captured_insert ): int {
					$captured_insert = $data;
					return 999;
				}
			);
		$repo->expects( $this->once() )->method( 'insert_audit' )->willReturn( 1 );
		$repo->expects( $this->once() )->method( 'begin_transaction' );
		$repo->expects( $this->once() )->method( 'commit' );
		$repo->expects( $this->never() )->method( 'rollback' );

		$result = ( new TimetableService( $repo ) )->cancel( 7, $session_id, 'family bereavement' );

		$this->assertIsArray( $result );

		// Row 1 · the cancelled session: status flips, lesson_no clears, sequence_no untouched.
		$this->assertSame( 'cancelled', $captured_update['status'] );
		$this->assertNull( $captured_update['lesson_no'] );
		$this->assertArrayNotHasKey( 'sequence_no', $captured_update, 'sequence_no must never appear in a cancel update — R-8' );

		// Row 2 · the shift: every held session with sequence_no > 5 loses one lesson_no.
		$this->assertSame( array( $group_id, 5 ), $captured_decrement );

		// Row 3 · the make-up (§5.1 exact table).
		$this->assertSame( 37, $captured_insert['sequence_no'], 'makeup goes at MAX(sequence_no)+1 = 37, NOT at cancelled+1' );
		$this->assertSame( 36, $captured_insert['lesson_no'], 'makeup lesson_no preserves the promised 36-lesson total' );
		$this->assertSame( $session_id, $captured_insert['makeup_for_id'] );
		$this->assertSame( 'scheduled', $captured_insert['status'] );
		$this->assertSame( 'Europe/Amsterdam', $captured_insert['anchor_timezone'] );

		// Row 3 · the datetime — walker landed on the first Sunday after the last Thu.
		$this->assertSame( '2026-11-29 18:00:00', $captured_insert['local_start_wall'] );
		$this->assertSame( '2026-11-29 17:00:00', $captured_insert['scheduled_start_utc'] );
		$this->assertSame( '2026-11-29 18:00:00', $captured_insert['scheduled_end_utc'] );

		// And the row we hand back to the caller carries an insert id.
		$this->assertSame( 999, $result['id'] );
	}

	#[TestDox( 'walker cap: 12 weeks of unbroken availability conflicts → makeup_no_slot, rollback, cancellation not applied' )]
	public function test_cancel_fails_cleanly_when_no_slot_within_twelve_weeks(): void {
		$repo       = $this->createMock( TimetableRepository::class );
		$session_id = 105;

		$repo->method( 'find_session_for_update' )->willReturn(
			array(
				'id'          => $session_id,
				'group_id'    => 1,
				'pattern_id'  => 99,
				'teacher_id'  => 50,
				'sequence_no' => 5,
				'lesson_no'   => 5,
				'status'      => 'scheduled',
			)
		);
		$repo->method( 'find_pattern' )->willReturn(
			array(
				'id'               => 99,
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays_json'    => '[0,2,4]',
				'start_local'      => '18:00:00',
				'duration_minutes' => 60,
			)
		);
		$repo->method( 'max_scheduled_start_utc_for_group' )->willReturn( '2026-11-26 17:00:00' );
		$repo->method( 'max_sequence_no_for_group' )->willReturn( 36 );
		$repo->method( 'max_lesson_no_for_group' )->willReturn( 36 );

		// Every candidate slot faces the same wall: no availability at all.
		// assert_availability_covers throws on the first evaluation of every
		// weekday in every week the walker visits — the R-4 branch that
		// rejects an empty availability list keeps this cheap for the mock.
		$repo->method( 'list_availability_for_teacher_on' )->willReturn( array() );
		$repo->method( 'list_absences_for_teacher_between' )->willReturn( array() );
		$repo->method( 'lock_teacher_sessions_between' )->willReturn( array() );

		$repo->expects( $this->once() )->method( 'begin_transaction' );
		$repo->expects( $this->once() )->method( 'rollback' );
		$repo->expects( $this->never() )->method( 'commit' );

		$repo->expects( $this->never() )->method( 'update_session' );
		$repo->expects( $this->never() )->method( 'decrement_lesson_no_after_sequence' );
		$repo->expects( $this->never() )->method( 'insert_session' );
		$repo->expects( $this->never() )->method( 'insert_audit' );

		$result = ( new TimetableService( $repo ) )->cancel( 7, $session_id, 'family bereavement' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'makeup_no_slot', $result->get_error_code() );
	}

	public function test_cancel_rejects_when_session_not_found(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'find_session_for_update' )->willReturn( null );
		$repo->expects( $this->once() )->method( 'rollback' );

		$result = ( new TimetableService( $repo ) )->cancel( 7, 999, 'reason' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'session_not_found', $result->get_error_code() );
	}

	public function test_cancel_rejects_when_already_cancelled(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'find_session_for_update' )->willReturn(
			array(
				'id'          => 1,
				'group_id'    => 1,
				'pattern_id'  => 99,
				'teacher_id'  => 50,
				'sequence_no' => 5,
				'status'      => 'cancelled',
			)
		);

		$result = ( new TimetableService( $repo ) )->cancel( 7, 1, 'reason' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'already_cancelled', $result->get_error_code() );
	}

	public function test_cancel_requires_reason(): void {
		$repo   = $this->createMock( TimetableRepository::class );
		$result = ( new TimetableService( $repo ) )->cancel( 7, 1, '   ' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'reason_required', $result->get_error_code() );
	}

	// ---------------------------------------------------------------- helpers.

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function slot( array $overrides = array() ): array {
		return array_merge(
			array(
				'weekday'        => 0,
				'start_local'    => '17:00',
				'end_local'      => '20:00',
				'timezone'       => 'Europe/Amsterdam',
				'effective_from' => '2026-01-01',
				'effective_to'   => null,
			),
			$overrides
		);
	}
}
