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
