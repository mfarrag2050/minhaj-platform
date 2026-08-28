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
				'session_duration_minutes' => 60,
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
				'session_duration_minutes' => 60,
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
				'session_duration_minutes' => 60,
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
				'session_duration_minutes' => 60,
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
				'session_duration_minutes' => 60,
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
				'session_duration_minutes' => 60,
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

	// ============================================== spec-calendar-v1 §7 · integration.

	#[TestDox( 'C-2: gate filter returns WP_Error → generate refuses without calling insert_session' )]
	public function test_gate_veto_blocks_generation(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value ) {
				if ( 'minhaj_timetable_pre_generate_gate' === $tag ) {
					return new WP_Error( 'no_calendar', 'no calendar attached' );
				}
				return $value;
			}
		);

		$repo = $this->createMock( TimetableRepository::class );
		$repo->method( 'find_group' )->willReturn(
			array( 'id' => 1, 'teacher_id' => 50, 'total_sessions' => 3, 'session_duration_minutes' => 60 )
		);
		$repo->expects( $this->never() )->method( 'insert_session' );

		$result = ( new TimetableService( $repo ) )->generate_for_group(
			7,
			1,
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 1 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 3,
				'first_week_start' => '2026-09-07',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_calendar', $result->get_error_code() );
	}

	#[TestDox( 'skip_and_extend refuses with calendar_over_disabled when the walker would need more than 2× the caller weeks' )]
	public function test_skip_and_extend_refuses_when_walk_would_exceed_cap(): void {
		// Caller asks for 3 weeks × 1 weekday = 3 sessions. If 20 dates
		// inside the 26-week filter window are disabled, needed_weeks
		// becomes ~25 which is far beyond the cap (max(6, 3+4)=7).
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value ) {
				if ( 'minhaj_timetable_skip_dates_for_group' === $tag ) {
					// 20 arbitrary future Mondays.
					$dates = array();
					$cursor = new \DateTimeImmutable( '2026-09-07' );
					for ( $i = 0; $i < 20; $i++ ) {
						$dates[] = $cursor->format( 'Y-m-d' );
						$cursor  = $cursor->modify( '+7 days' );
					}
					return $dates;
				}
				return $value;
			}
		);

		$repo = $this->createMock( TimetableRepository::class );
		$repo->method( 'find_group' )->willReturn(
			array(
				'id'                       => 1,
				'teacher_id'               => 50,
				'total_sessions'           => 3,
				'session_duration_minutes' => 60,
				'holiday_behavior'         => 'skip_and_extend',
			)
		);
		$repo->expects( $this->never() )->method( 'insert_session' );

		$result = ( new TimetableService( $repo ) )->generate_for_group(
			7,
			1,
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 1 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 3,
				'first_week_start' => '2026-09-07',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'calendar_over_disabled', $result->get_error_code() );
	}

	#[TestDox( 'spec-calendar §7-2 · skip_and_extend walks past skipped weeks to reach total_sessions with contiguous lesson_no 1..N' )]
	public function test_skip_and_extend_reaches_target_without_gaps(): void {
		// Skip week 2 (2026-09-14) via the filter; walker should extend
		// by one week to hit 3 sessions total.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value ) {
				if ( 'minhaj_timetable_skip_dates_for_group' === $tag ) {
					return array( '2026-09-14' );
				}
				return $value;
			}
		);

		$repo = $this->createMock( TimetableRepository::class );
		$repo->method( 'find_group' )->willReturn(
			array(
				'id'                       => 1,
				'teacher_id'               => 50,
				'total_sessions'           => 3,
				'session_duration_minutes' => 60,
				'holiday_behavior'         => 'skip_and_extend',
			)
		);
		$repo->method( 'list_availability_for_teacher_between' )->willReturn(
			array( $this->slot( array( 'weekday' => 1 ) ) )
		);
		$repo->method( 'list_absences_for_teacher_between' )->willReturn( array() );
		$repo->method( 'lock_teacher_sessions_between' )->willReturn( array() );
		$repo->method( 'insert_pattern' )->willReturn( 99 );
		$repo->method( 'insert_audit' )->willReturn( 1 );

		$captured = array();
		$repo->method( 'insert_session' )->willReturnCallback(
			function ( array $data ) use ( &$captured ): int {
				$captured[] = $data;
				return 1000 + (int) $data['sequence_no'];
			}
		);

		$result = ( new TimetableService( $repo ) )->generate_for_group(
			7,
			1,
			array(
				'anchor_timezone'  => 'Europe/Amsterdam',
				'weekdays'         => array( 1 ),
				'start_local'      => '18:00',
				'duration_minutes' => 60,
				'weeks_count'      => 3,
				'first_week_start' => '2026-09-07',
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );

		$sequence_nos = array_map( static fn( array $r ) => (int) $r['sequence_no'], $captured );
		$this->assertSame( array( 1, 2, 3 ), $sequence_nos );

		// The skipped Monday (2026-09-14) is not in the local_start_wall values.
		$walls = array_map( static fn( array $r ) => (string) $r['local_start_wall'], $captured );
		$this->assertNotContains( '2026-09-14 18:00:00', $walls );
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
				'anchor_timezone'     => 'Europe/Amsterdam',
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

	#[TestDox( 'spec §5.2: 12 weeks of unbroken availability conflicts → cancel STILL succeeds, make-up recorded as unscheduled (NULL times), MAKEUP_UNSCHEDULED event fires' )]
	public function test_cancel_records_unscheduled_makeup_when_walker_exhausts_cap(): void {
		$repo       = $this->createMock( TimetableRepository::class );
		$session_id = 105;

		$repo->method( 'find_session_for_update' )->willReturn(
			array(
				'id'              => $session_id,
				'group_id'        => 1,
				'pattern_id'      => 99,
				'teacher_id'      => 50,
				'sequence_no'     => 5,
				'lesson_no'       => 5,
				'status'          => 'scheduled',
				'anchor_timezone' => 'Europe/Amsterdam',
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

		// Walker sees the same wall on every candidate: no availability at all.
		$repo->method( 'list_availability_for_teacher_on' )->willReturn( array() );
		$repo->method( 'list_absences_for_teacher_between' )->willReturn( array() );
		$repo->method( 'lock_teacher_sessions_between' )->willReturn( array() );

		$captured_update = null;
		$captured_insert = null;

		$repo->expects( $this->once() )
			->method( 'update_session' )
			->willReturnCallback(
				function ( int $id, array $data ) use ( &$captured_update, $session_id ): void {
					$this->assertSame( $session_id, $id );
					$captured_update = $data;
				}
			);
		$repo->expects( $this->once() )->method( 'decrement_lesson_no_after_sequence' );
		$repo->expects( $this->once() )
			->method( 'insert_session' )
			->willReturnCallback(
				function ( array $data ) use ( &$captured_insert ): int {
					$captured_insert = $data;
					return 501;
				}
			);
		$repo->expects( $this->once() )->method( 'insert_audit' )->willReturn( 1 );
		$repo->expects( $this->once() )->method( 'begin_transaction' );
		$repo->expects( $this->once() )->method( 'commit' );
		$repo->expects( $this->never() )->method( 'rollback' );

		$result = ( new TimetableService( $repo ) )->cancel( 7, $session_id, 'teacher out sick tomorrow' );

		// The cancel completes — this is the guarantee spec §5.2 formalised.
		$this->assertIsArray( $result );

		// Cancelled row: same as the scheduled-slot path (§5.1).
		$this->assertSame( 'cancelled', $captured_update['status'] );
		$this->assertNull( $captured_update['lesson_no'] );

		// Make-up numbering stays intact — sequence_no = MAX+1, lesson_no preserved.
		$this->assertSame( 37, $captured_insert['sequence_no'] );
		$this->assertSame( 36, $captured_insert['lesson_no'] );
		$this->assertSame( $session_id, $captured_insert['makeup_for_id'] );

		// The only difference vs. §5.1 happy path: status is unscheduled, times are NULL.
		$this->assertSame( 'unscheduled', $captured_insert['status'] );
		$this->assertNull( $captured_insert['scheduled_start_utc'] );
		$this->assertNull( $captured_insert['scheduled_end_utc'] );
		$this->assertNull( $captured_insert['local_start_wall'] );

		// anchor_timezone is preserved so schedule_makeup later knows how to project.
		$this->assertSame( 'Europe/Amsterdam', $captured_insert['anchor_timezone'] );
	}

	#[TestDox( 'unscheduled fallback: pattern row missing → still cancels + creates unscheduled make-up, no rollback' )]
	public function test_cancel_falls_back_to_unscheduled_when_pattern_row_missing(): void {
		$repo       = $this->createMock( TimetableRepository::class );
		$session_id = 105;

		$repo->method( 'find_session_for_update' )->willReturn(
			array(
				'id'              => $session_id,
				'group_id'        => 1,
				'pattern_id'      => 99,
				'teacher_id'      => 50,
				'sequence_no'     => 5,
				'lesson_no'       => 5,
				'status'          => 'scheduled',
				'anchor_timezone' => 'Europe/Amsterdam',
			)
		);
		// Pattern row lost — must NOT block the cancel.
		$repo->method( 'find_pattern' )->willReturn( null );
		$repo->method( 'max_scheduled_start_utc_for_group' )->willReturn( '2026-11-26 17:00:00' );
		$repo->method( 'max_sequence_no_for_group' )->willReturn( 36 );
		$repo->method( 'max_lesson_no_for_group' )->willReturn( 36 );

		$captured_insert = null;
		$repo->expects( $this->once() )->method( 'update_session' );
		$repo->expects( $this->once() )->method( 'decrement_lesson_no_after_sequence' );
		$repo->expects( $this->once() )
			->method( 'insert_session' )
			->willReturnCallback(
				function ( array $data ) use ( &$captured_insert ): int {
					$captured_insert = $data;
					return 502;
				}
			);
		$repo->expects( $this->once() )->method( 'commit' );
		$repo->expects( $this->never() )->method( 'rollback' );

		$result = ( new TimetableService( $repo ) )->cancel( 7, $session_id, 'reason' );

		$this->assertIsArray( $result );
		$this->assertSame( 'unscheduled', $captured_insert['status'] );
		$this->assertNull( $captured_insert['scheduled_start_utc'] );
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

	// ================================================================ schedule_makeup.

	#[TestDox( 'schedule_makeup: happy path — pending unscheduled row flips to scheduled with computed local_wall + end_utc' )]
	public function test_schedule_makeup_happy_path(): void {
		$repo       = $this->createMock( TimetableRepository::class );
		$session_id = 501;

		$repo->method( 'find_session_for_update' )->willReturn(
			array(
				'id'                  => $session_id,
				'group_id'            => 1,
				'pattern_id'          => 99,
				'teacher_id'          => 50,
				'sequence_no'         => 37,
				'lesson_no'           => 36,
				'status'              => 'unscheduled',
				'anchor_timezone'     => 'Europe/Amsterdam',
				'makeup_for_id'       => 105,
				'scheduled_start_utc' => null,
				'scheduled_end_utc'   => null,
				'local_start_wall'    => null,
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
		// Sunday 2026-12-06 18:00 Amsterdam CET = 17:00 UTC — teacher free.
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

		$captured_update = null;
		$repo->expects( $this->once() )
			->method( 'update_session' )
			->willReturnCallback(
				function ( int $id, array $data ) use ( &$captured_update, $session_id ): void {
					$this->assertSame( $session_id, $id );
					$captured_update = $data;
				}
			);
		$repo->expects( $this->once() )->method( 'insert_audit' )->willReturn( 1 );
		$repo->expects( $this->once() )->method( 'commit' );
		$repo->expects( $this->never() )->method( 'rollback' );

		$result = ( new TimetableService( $repo ) )->schedule_makeup(
			7,
			$session_id,
			'2026-12-06 17:00:00',
			'first free Sunday'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'scheduled', $captured_update['status'] );
		$this->assertSame( '2026-12-06 17:00:00', $captured_update['scheduled_start_utc'] );
		$this->assertSame( '2026-12-06 18:00:00', $captured_update['scheduled_end_utc'] );
		$this->assertSame( '2026-12-06 18:00:00', $captured_update['local_start_wall'] );
	}

	public function test_schedule_makeup_rejects_when_slot_conflicts_availability(): void {
		$repo       = $this->createMock( TimetableRepository::class );
		$session_id = 501;

		$repo->method( 'find_session_for_update' )->willReturn(
			array(
				'id'              => $session_id,
				'group_id'        => 1,
				'pattern_id'      => 99,
				'teacher_id'      => 50,
				'sequence_no'     => 37,
				'lesson_no'       => 36,
				'status'          => 'unscheduled',
				'anchor_timezone' => 'Europe/Amsterdam',
				'makeup_for_id'   => 105,
			)
		);
		$repo->method( 'find_pattern' )->willReturn(
			array(
				'id'               => 99,
				'anchor_timezone'  => 'Europe/Amsterdam',
				'duration_minutes' => 60,
				'weekdays_json'    => '[0,2,4]',
				'start_local'      => '18:00:00',
			)
		);
		// Only Mondays covered — Sunday requested → R-4 rejects.
		$repo->method( 'list_availability_for_teacher_on' )->willReturn(
			array( $this->slot( array( 'weekday' => 1 ) ) )
		);
		$repo->method( 'list_absences_for_teacher_between' )->willReturn( array() );
		$repo->method( 'lock_teacher_sessions_between' )->willReturn( array() );

		$repo->expects( $this->never() )->method( 'update_session' );
		$repo->expects( $this->never() )->method( 'insert_audit' );
		$repo->expects( $this->once() )->method( 'rollback' );

		$result = ( new TimetableService( $repo ) )->schedule_makeup(
			7,
			$session_id,
			'2026-12-06 17:00:00',
			'try Sunday'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'schedule_conflict', $result->get_error_code() );
	}

	public function test_schedule_makeup_rejects_when_session_not_unscheduled(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'find_session_for_update' )->willReturn(
			array(
				'id'              => 501,
				'group_id'        => 1,
				'pattern_id'      => 99,
				'teacher_id'      => 50,
				'sequence_no'     => 37,
				'status'          => 'scheduled',
				'anchor_timezone' => 'Europe/Amsterdam',
				'makeup_for_id'   => 105,
			)
		);
		$repo->expects( $this->never() )->method( 'update_session' );

		$result = ( new TimetableService( $repo ) )->schedule_makeup( 7, 501, '2026-12-06 17:00:00', 'reason' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_unscheduled', $result->get_error_code() );
	}

	public function test_schedule_makeup_rejects_when_not_a_makeup(): void {
		$repo = $this->createMock( TimetableRepository::class );

		$repo->method( 'find_session_for_update' )->willReturn(
			array(
				'id'              => 501,
				'group_id'        => 1,
				'pattern_id'      => 99,
				'teacher_id'      => 50,
				'sequence_no'     => 37,
				'status'          => 'unscheduled',
				'anchor_timezone' => 'Europe/Amsterdam',
				'makeup_for_id'   => null,
			)
		);
		$repo->expects( $this->never() )->method( 'update_session' );

		$result = ( new TimetableService( $repo ) )->schedule_makeup( 7, 501, '2026-12-06 17:00:00', 'reason' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_a_makeup', $result->get_error_code() );
	}

	public function test_schedule_makeup_rejects_malformed_utc(): void {
		$repo   = $this->createMock( TimetableRepository::class );
		$result = ( new TimetableService( $repo ) )->schedule_makeup( 7, 501, 'not-a-date', 'reason' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_arg', $result->get_error_code() );
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
