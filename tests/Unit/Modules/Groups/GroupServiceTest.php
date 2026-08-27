<?php
/**
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Groups;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Minhaj\Modules\Groups\Domain\GroupStatus;
use Minhaj\Modules\Groups\GroupService;
use Minhaj\Modules\Groups\Repository\GroupRepository;
use Minhaj\Modules\Groups\Repository\PersistenceException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use WP_Error;

#[CoversClass( GroupService::class )]
final class GroupServiceTest extends TestCase {

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

	// ============================================================ add_member.

	#[TestDox( 'add_member: rejects actor_user_id ≤ 0 — audit rows must not be anonymous' )]
	public function test_add_member_requires_actor(): void {
		$repo   = $this->createMock( GroupRepository::class );
		$result = ( new GroupService( $repo ) )->add_member( 0, 1, 100 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_actor', $result->get_error_code() );
	}

	#[TestDox( 'add_member is idempotent: repeated call for the same active (group, student) returns the existing id and does not insert' )]
	public function test_add_member_idempotent_fast_path(): void {
		$repo = $this->createMock( GroupRepository::class );

		// Fast path check outside transaction returns the existing row.
		$repo->method( 'find_group' )->willReturn( $this->group_row( array( 'capacity_max' => 5 ) ) );
		$repo->method( 'count_active_members' )->willReturn( 3 );
		$repo->method( 'find_active_member' )->willReturn(
			array(
				'id'         => 42,
				'group_id'   => 1,
				'student_id' => 100,
				'status'     => 'active',
				'seat_index' => 2,
			)
		);

		$repo->expects( $this->never() )->method( 'begin_transaction' );
		$repo->expects( $this->never() )->method( 'insert_member' );
		$repo->expects( $this->never() )->method( 'insert_audit' );

		$result = ( new GroupService( $repo ) )->add_member( 7, 1, 100 );

		$this->assertSame( 42, $result );
	}

	#[TestDox( 'add_member: happy path inserts membership, audits with actor + action=member.added, and commits' )]
	public function test_add_member_happy_path_writes_audit_with_actor(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_group' )->willReturn( $this->group_row( array( 'capacity_max' => 5 ) ) );
		$repo->method( 'find_group_for_update' )->willReturn( $this->group_row( array( 'capacity_max' => 5 ) ) );
		$repo->method( 'find_active_member' )->willReturn( null );
		$repo->method( 'count_active_members' )->willReturn( 2 );
		$repo->method( 'find_used_seat_indices' )->willReturn( array( 1, 3 ) );  // free: 2, 4, 5.
		$repo->method( 'insert_member' )->willReturn( 501 );

		$repo->expects( $this->once() )->method( 'begin_transaction' );
		$repo->expects( $this->once() )->method( 'commit' );
		$repo->expects( $this->never() )->method( 'rollback' );

		$audit_capture = null;
		$repo->expects( $this->once() )
			->method( 'insert_audit' )
			->willReturnCallback(
				function ( array $data ) use ( &$audit_capture ): int {
					$audit_capture = $data;
					return 900;
				}
			);

		$result = ( new GroupService( $repo ) )->add_member( 7, 1, 100 );

		$this->assertSame( 501, $result );
		$this->assertSame( 'member.added', $audit_capture['action'] );
		$this->assertSame( 7, $audit_capture['actor_user_id'] );
		$this->assertSame( 1, $audit_capture['group_id'] );
		$this->assertSame( 501, $audit_capture['subject_id'] );

		$payload = json_decode( $audit_capture['payload_json'], true );
		$this->assertSame( 100, $payload['student_id'] );
		$this->assertSame( 2, $payload['seat_index'], 'smallest free seat (used=[1,3]) is 2' );
	}

	#[TestDox( 'withdraw then re-add reuses the freed seat (smallest_free_seat picks lowest gap)' )]
	public function test_add_member_reuses_freed_seat(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_group' )->willReturn( $this->group_row( array( 'capacity_max' => 3 ) ) );
		$repo->method( 'find_group_for_update' )->willReturn( $this->group_row( array( 'capacity_max' => 3 ) ) );
		$repo->method( 'find_active_member' )->willReturn( null );
		$repo->method( 'count_active_members' )->willReturn( 2 );
		// Seats 2 and 3 are taken; seat 1 was withdrawn.
		$repo->method( 'find_used_seat_indices' )->willReturn( array( 2, 3 ) );

		$seat_captured = null;
		$repo->method( 'insert_member' )
			->willReturnCallback(
				function ( array $data ) use ( &$seat_captured ): int {
					$seat_captured = $data['seat_index'];
					return 777;
				}
			);
		$repo->method( 'insert_audit' )->willReturn( 1 );

		$result = ( new GroupService( $repo ) )->add_member( 7, 1, 200 );

		$this->assertSame( 777, $result );
		$this->assertSame( 1, $seat_captured, 'freed seat 1 must be reused before any new seat' );
	}

	#[TestDox( 'R-1 fast path: can_accept sees the group full and add_member returns group_full without opening a transaction' )]
	public function test_add_member_r1_caught_by_can_accept_short_circuits(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_group' )->willReturn( $this->group_row( array( 'capacity_max' => 3 ) ) );
		$repo->method( 'find_active_member' )->willReturn( null );
		$repo->method( 'count_active_members' )->willReturn( 3 );

		$repo->expects( $this->never() )->method( 'begin_transaction' );
		$repo->expects( $this->never() )->method( 'insert_member' );

		$result = ( new GroupService( $repo ) )->add_member( 7, 1, 999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'group_full', $result->get_error_code() );
	}

	#[TestDox( 'R-1 race: can_accept passes then count grows before FOR UPDATE — R-1 catches inside the transaction and rolls back' )]
	public function test_add_member_r1_race_inside_transaction_returns_group_full(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_group' )->willReturn( $this->group_row( array( 'capacity_max' => 3 ) ) );
		$repo->method( 'find_group_for_update' )->willReturn( $this->group_row( array( 'capacity_max' => 3 ) ) );
		$repo->method( 'find_active_member' )->willReturn( null );
		// First call (can_accept fast path) sees 2; second call (under row lock) sees 3.
		$repo->method( 'count_active_members' )->willReturnOnConsecutiveCalls( 2, 3 );

		$repo->expects( $this->once() )->method( 'begin_transaction' );
		$repo->expects( $this->once() )->method( 'rollback' );
		$repo->expects( $this->never() )->method( 'commit' );
		$repo->expects( $this->never() )->method( 'insert_member' );

		$result = ( new GroupService( $repo ) )->add_member( 7, 1, 999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'group_full', $result->get_error_code() );
	}

	#[TestDox( 'DUPLICATE_SEAT from the unique index is converted to a clean WP_Error group_full, not a fatal' )]
	public function test_add_member_duplicate_seat_becomes_group_full(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_group' )->willReturn( $this->group_row( array( 'capacity_max' => 3 ) ) );
		$repo->method( 'find_group_for_update' )->willReturn( $this->group_row( array( 'capacity_max' => 3 ) ) );
		$repo->method( 'find_active_member' )->willReturn( null );
		$repo->method( 'count_active_members' )->willReturn( 2 );
		$repo->method( 'find_used_seat_indices' )->willReturn( array( 1, 2 ) );
		$repo->method( 'insert_member' )->willThrowException(
			new PersistenceException( PersistenceException::DUPLICATE_SEAT, 'uq_active_seat collision' )
		);

		$repo->expects( $this->once() )->method( 'rollback' );

		$result = ( new GroupService( $repo ) )->add_member( 7, 1, 500 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'group_full', $result->get_error_code() );
	}

	#[TestDox( 'DUPLICATE_STUDENT race: fetches existing membership and returns id idempotently' )]
	public function test_add_member_duplicate_student_race_is_idempotent(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_group' )->willReturn( $this->group_row( array( 'capacity_max' => 5 ) ) );
		$repo->method( 'find_group_for_update' )->willReturn( $this->group_row( array( 'capacity_max' => 5 ) ) );
		$repo->method( 'count_active_members' )->willReturn( 2 );
		$repo->method( 'find_used_seat_indices' )->willReturn( array( 1, 2 ) );
		$repo->method( 'insert_member' )->willThrowException(
			new PersistenceException( PersistenceException::DUPLICATE_STUDENT, 'uq_active_student collision' )
		);

		// find_active_member: null (fast path) → null (inside tx) → row (after duplicate exception).
		$repo->method( 'find_active_member' )->willReturn(
			null,
			null,
			array( 'id' => 91, 'group_id' => 1, 'student_id' => 100, 'status' => 'active' )
		);

		$result = ( new GroupService( $repo ) )->add_member( 7, 1, 100 );

		$this->assertSame( 91, $result );
	}

	// ============================================================ can_accept.

	public function test_can_accept_true_for_already_active_student(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_group' )->willReturn( $this->group_row( array( 'capacity_max' => 3 ) ) );
		$repo->method( 'find_active_member' )->willReturn( array( 'id' => 1, 'status' => 'active' ) );
		$repo->expects( $this->never() )->method( 'count_active_members' );

		$result = ( new GroupService( $repo ) )->can_accept( 1, 100 );

		$this->assertTrue( $result );
	}

	public function test_can_accept_group_full(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_group' )->willReturn( $this->group_row( array( 'capacity_max' => 3 ) ) );
		$repo->method( 'find_active_member' )->willReturn( null );
		$repo->method( 'count_active_members' )->willReturn( 3 );

		$result = ( new GroupService( $repo ) )->can_accept( 1, 100 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'group_full', $result->get_error_code() );
	}

	// ============================================================= transition.

	#[TestDox( 'R-2 caught: transition to scheduled below capacity_min returns not_ready_to_schedule' )]
	public function test_transition_to_scheduled_below_min_is_not_ready(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_group_for_update' )->willReturn(
			$this->group_row(
				array(
					'status'       => GroupStatus::FORMING,
					'capacity_min' => 3,
				)
			)
		);
		$repo->method( 'count_active_members' )->willReturn( 2 );

		$repo->expects( $this->never() )->method( 'update_group' );
		$repo->expects( $this->once() )->method( 'rollback' );

		$result = ( new GroupService( $repo ) )->transition( 7, 1, GroupStatus::SCHEDULED, 'ready' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_ready_to_schedule', $result->get_error_code() );
	}

	#[TestDox( 'transition to scheduled with sufficient members updates status and audits status_changed' )]
	public function test_transition_to_scheduled_succeeds_and_audits(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_group_for_update' )->willReturn(
			$this->group_row(
				array(
					'status'       => GroupStatus::FORMING,
					'capacity_min' => 3,
				)
			)
		);
		$repo->method( 'count_active_members' )->willReturn( 3 );
		$repo->expects( $this->once() )->method( 'update_group' );
		$repo->expects( $this->once() )->method( 'commit' );

		$audit = null;
		$repo->expects( $this->once() )
			->method( 'insert_audit' )
			->willReturnCallback(
				function ( array $data ) use ( &$audit ): int {
					$audit = $data;
					return 1;
				}
			);

		$result = ( new GroupService( $repo ) )->transition(
			7,
			1,
			GroupStatus::SCHEDULED,
			'ready to schedule'
		);

		$this->assertTrue( $result );
		$this->assertSame( 'group.status_changed', $audit['action'] );
		$this->assertSame( 7, $audit['actor_user_id'] );

		$payload = json_decode( $audit['payload_json'], true );
		$this->assertSame( GroupStatus::FORMING, $payload['from'] );
		$this->assertSame( GroupStatus::SCHEDULED, $payload['to'] );
		$this->assertSame( 'ready to schedule', $payload['reason'] );
	}

	#[TestDox( 'transition rejects a disallowed jump from the state machine map' )]
	public function test_transition_rejects_disallowed_jump(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_group_for_update' )->willReturn(
			$this->group_row( array( 'status' => GroupStatus::DRAFT ) )
		);
		$repo->expects( $this->never() )->method( 'update_group' );
		$repo->expects( $this->once() )->method( 'rollback' );

		$result = ( new GroupService( $repo ) )->transition( 7, 1, GroupStatus::ACTIVE, 'skip forming' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_transition', $result->get_error_code() );
	}

	// ============================================================= remove_member.

	public function test_remove_member_writes_audit_with_actor_and_action(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_membership' )->willReturn(
			array(
				'id'         => 55,
				'group_id'   => 10,
				'student_id' => 500,
				'status'     => 'active',
			)
		);

		$audit = null;
		$repo->expects( $this->once() )
			->method( 'insert_audit' )
			->willReturnCallback(
				function ( array $data ) use ( &$audit ): int {
					$audit = $data;
					return 1;
				}
			);
		$repo->expects( $this->once() )->method( 'update_member' );
		$repo->expects( $this->once() )->method( 'commit' );

		$result = ( new GroupService( $repo ) )->remove_member( 7, 55, 'personal choice' );

		$this->assertTrue( $result );
		$this->assertSame( 'member.removed', $audit['action'] );
		$this->assertSame( 7, $audit['actor_user_id'] );
		$this->assertSame( 10, $audit['group_id'] );
		$this->assertSame( 55, $audit['subject_id'] );
	}

	public function test_remove_member_rejects_non_active(): void {
		$repo = $this->createMock( GroupRepository::class );

		$repo->method( 'find_membership' )->willReturn(
			array(
				'id'         => 55,
				'group_id'   => 10,
				'student_id' => 500,
				'status'     => 'withdrawn',
			)
		);
		$repo->expects( $this->never() )->method( 'update_member' );

		$result = ( new GroupService( $repo ) )->remove_member( 7, 55, 'stale' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'member_not_active', $result->get_error_code() );
	}

	// =========================================================== assign_teacher.

	#[TestDox( 'S-4 gate wired: an early filter returning WP_Error blocks assign_teacher before any DB write' )]
	public function test_assign_teacher_respects_assignability_filter(): void {
		$repo = $this->createMock( GroupRepository::class );

		// Redefine apply_filters — this test needs the People-side veto to
		// come through as a WP_Error, not the pass-through default.
		Monkey\Functions\when( 'apply_filters' )->alias(
			function ( string $tag, mixed $value ) {
				if ( 'minhaj_group_can_assign_teacher' === $tag ) {
					return new WP_Error( 'no_valid_check', 'blocked by test' );
				}
				return $value;
			}
		);

		$repo->expects( $this->never() )->method( 'begin_transaction' );
		$repo->expects( $this->never() )->method( 'update_group' );
		$repo->expects( $this->never() )->method( 'insert_audit' );

		$result = ( new GroupService( $repo ) )->assign_teacher( 7, 1, 50, 'greenlight' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_valid_check', $result->get_error_code() );
	}

	public function test_assign_teacher_proceeds_when_filter_allows(): void {
		$repo = $this->createMock( GroupRepository::class );

		Monkey\Functions\when( 'apply_filters' )->alias(
			fn( string $tag, mixed $value ) => $value
		);

		$repo->method( 'find_group_for_update' )->willReturn( $this->group_row() );
		$repo->expects( $this->once() )->method( 'begin_transaction' );
		$repo->expects( $this->once() )->method( 'update_group' );
		$repo->expects( $this->once() )->method( 'insert_audit' )->willReturn( 1 );
		$repo->expects( $this->once() )->method( 'commit' );

		$result = ( new GroupService( $repo ) )->assign_teacher( 7, 1, 50, 'greenlight' );

		$this->assertTrue( $result );
	}

	// ============================================================= helpers.

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function group_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                => 1,
				'code'              => 'TEST-01',
				'type'              => 'group',
				'status'            => GroupStatus::FORMING,
				'capacity_min'      => 3,
				'capacity_max'      => 5,
				'teacher_id'        => null,
				'actual_start_date' => null,
				'deleted_at'        => null,
			),
			$overrides
		);
	}
}
