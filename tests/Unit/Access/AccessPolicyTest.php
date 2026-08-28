<?php
/**
 * Acceptance tests for spec-access-v1 §8 (1–7). Test 8 lives alongside as
 * NoImplicitActorGrepTest — a static scan, not a runtime decision.
 *
 * These tests double AccessRepository so we can assert what the policy
 * decides without a live database. Repository correctness is verified
 * in the DB-scoped integration suite.
 *
 * @package Minhaj\Tests\Unit\Access
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Access;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Minhaj\Access\AccessDeniedException;
use Minhaj\Access\AccessPolicy;
use Minhaj\Access\AccessRepository;
use Minhaj\Access\Capabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( AccessPolicy::class )]
final class AccessPolicyTest extends TestCase {

	/**
	 * user_id => [ cap => true ]
	 *
	 * @var array<int, array<string, bool>>
	 */
	private array $caps = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, mixed $value ) {
				return $value;
			}
		);
		Functions\when( 'do_action' )->justReturn();
		Functions\when( 'current_time' )->justReturn( '2026-08-28 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $v ) => json_encode( $v ) );
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $cap ): bool {
				return $this->caps[ $user_id ][ $cap ] ?? false;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$this->caps = array();
		parent::tearDown();
	}

	// =========================================================== §8-1 teacher scope.

	#[TestDox( '§8-1: teacher sees only groups they are assigned to; a third group yields false + access_denied audit' )]
	public function test_teacher_sees_only_their_groups(): void {
		$this->grant( 10, Capabilities::VIEW_GROUP );

		$repo = $this->createMock( AccessRepository::class );

		$repo->method( 'find_group' )->willReturnCallback(
			static function ( int $id ): ?array {
				return match ( $id ) {
					101, 102 => array( 'id' => $id, 'teacher_id' => 10, 'org_id' => 0 ),
					103      => array( 'id' => 103, 'teacher_id' => 99, 'org_id' => 0 ),
					default  => null,
				};
			}
		);
		$repo->method( 'list_group_ids_for_teacher' )->with( 10 )->willReturn( array( 101, 102 ) );
		$repo->method( 'list_active_ward_ids_of_guardian' )->willReturn( array() );

		$denials = array();
		$repo->method( 'record_denial' )->willReturnCallback(
			static function ( string $subject_type, int $actor, int $subject, string $action ) use ( &$denials ): void {
				$denials[] = compact( 'subject_type', 'actor', 'subject', 'action' );
			}
		);

		$policy = new AccessPolicy( $repo );

		$this->assertTrue( $policy->can_view_group( 10, 101 ) );
		$this->assertTrue( $policy->can_view_group( 10, 102 ) );
		$this->assertFalse( $policy->can_view_group( 10, 103 ) );

		$this->assertNotEmpty( $denials );
		$this->assertSame( 'group', $denials[0]['subject_type'] );
		$this->assertSame( 'view_group', $denials[0]['action'] );
		$this->assertSame( 103, $denials[0]['subject'] );

		$this->assertSame( array( 101, 102 ), $policy->visible_group_ids_for( 10 ) );
	}

	// =========================================================== §8-2 parent scope.

	#[TestDox( '§8-2: parent of two children in two groups sees exactly those two; no visibility for a peer parent' )]
	public function test_parent_sees_only_their_wards_groups(): void {
		$this->grant( 42, Capabilities::VIEW_OWN_CHILD_GROUP );
		$this->grant( 43, Capabilities::VIEW_OWN_CHILD_GROUP );

		$repo = $this->createMock( AccessRepository::class );

		$repo->method( 'list_active_ward_ids_of_guardian' )->willReturnMap(
			array(
				array( 42, array( 500, 501 ) ),
				array( 43, array( 502 ) ),
			)
		);
		$repo->method( 'list_active_group_ids_of_student' )->willReturnMap(
			array(
				array( 500, array( 201 ) ),
				array( 501, array( 202 ) ),
				array( 502, array( 202 ) ),
			)
		);
		$repo->method( 'is_student_anonymized' )->willReturn( false );

		$policy = new AccessPolicy( $repo );

		$this->assertSame( array( 201, 202 ), $policy->visible_group_ids_for( 42 ) );
		$this->assertSame( array( 202 ), $policy->visible_group_ids_for( 43 ) );
	}

	// =========================================================== §8-3 A-2 mirror.

	#[TestDox( '§8-3 (A-2 mirror): every join_role the student passes, the guardian passes too' )]
	public function test_a2_mirror_join_role(): void {
		// Student user_id 500 is an active member of session 900's group 200.
		// Guardian 42 has an active can_view=1 row for student 500.

		$this->grant( 500, Capabilities::JOIN_SESSION );
		$this->grant( 42, Capabilities::JOIN_SESSION );

		$repo = $this->createMock( AccessRepository::class );

		$repo->method( 'find_session' )->with( 900 )->willReturn(
			array( 'id' => 900, 'group_id' => 200, 'teacher_id' => 10, 'org_id' => 0, 'status' => 'scheduled' )
		);
		$repo->method( 'is_student_anonymized' )->willReturn( false );
		$repo->method( 'is_active_member' )->with( 200, 500 )->willReturn( true );
		$repo->method( 'is_active_guardian_with_view' )->with( 42, 500 )->willReturn( true );

		$policy = new AccessPolicy( $repo );

		$student_join  = $policy->join_role( 500, 900 );
		$guardian_join = $policy->join_role( 42, 900, 500 );

		$this->assertSame( 'participant', $student_join );
		$this->assertSame( 'participant', $guardian_join );

		// Property: whenever student can, guardian can. Never a case where student can and guardian cannot.
		$this->assertTrue( false !== $student_join );
		$this->assertTrue( false !== $guardian_join );
	}

	// =========================================================== §8-4 guardianship end.

	#[TestDox( '§8-4: ending the guardianship (ended_at set) drops visibility on the very next call — no cache' )]
	public function test_guardianship_end_takes_effect_next_call(): void {
		$this->grant( 42, Capabilities::VIEW_OWN_CHILD_GROUP );

		$repo = $this->createMock( AccessRepository::class );
		$repo->method( 'find_group' )->willReturn(
			array( 'id' => 201, 'teacher_id' => 10, 'org_id' => 0 )
		);
		$repo->method( 'is_student_anonymized' )->willReturn( false );

		// First call: guardian has ward 500 who is member of group 201.
		$repo->expects( $this->exactly( 2 ) )
			->method( 'list_active_ward_ids_of_guardian' )
			->willReturnOnConsecutiveCalls( array( 500 ), array() );

		$repo->method( 'is_active_member' )->willReturnMap(
			array(
				array( 201, 500, true ),
			)
		);

		$policy_a = new AccessPolicy( $repo );
		$this->assertTrue( $policy_a->can_view_group( 42, 201 ) );

		// A separate request => fresh policy => no cached decision.
		$policy_b = new AccessPolicy( $repo );
		$this->assertFalse( $policy_b->can_view_group( 42, 201 ) );
	}

	// =========================================================== §8-5 anonymized.

	#[TestDox( '§8-5: anonymized_at blocks every non-admin decision and blocks participant join' )]
	public function test_anonymized_student_is_invisible_and_cannot_join(): void {
		$this->grant( 42, Capabilities::VIEW_OWN_CHILD_GROUP );
		$this->grant( 42, Capabilities::JOIN_SESSION );

		$repo = $this->createMock( AccessRepository::class );
		$repo->method( 'is_student_anonymized' )->willReturn( true );
		$repo->method( 'find_student_profile' )->willReturn(
			array( 'user_id' => 500, 'origin_org_id' => 0, 'anonymized_at' => '2026-01-01 00:00:00' )
		);
		$repo->method( 'find_session' )->willReturn(
			array( 'id' => 900, 'group_id' => 200, 'teacher_id' => 10, 'org_id' => 0, 'status' => 'scheduled' )
		);
		$repo->method( 'list_active_ward_ids_of_guardian' )->willReturn( array( 500 ) );
		$repo->method( 'is_active_guardian_with_view' )->willReturn( true );

		$policy = new AccessPolicy( $repo );

		$this->assertFalse( $policy->can_view_student( 42, 500 ) );
		$this->assertFalse( $policy->join_role( 42, 900, 500 ) );
	}

	// =========================================================== org_ids_for semantics.

	#[TestDox( 'org_ids_for returns null for unbounded users (platform admin) — distinct from empty array' )]
	public function test_org_ids_for_returns_null_for_platform_admin(): void {
		$this->grant( 1, Capabilities::MANAGE_GROUPS );

		$repo = $this->createMock( AccessRepository::class );
		$repo->expects( $this->never() )->method( 'list_org_ids_for_user' );

		$policy = new AccessPolicy( $repo );

		$this->assertNull( $policy->org_ids_for( 1 ) );
		$this->assertFalse( $policy->is_org_scoped( 1 ) );
	}

	#[TestDox( 'org_ids_for returns null for parents/teachers who hold no MANAGE_ORG cap' )]
	public function test_org_ids_for_returns_null_for_non_org_users(): void {
		$this->grant( 42, Capabilities::VIEW_OWN_CHILD_GROUP );
		$this->grant( 10, Capabilities::VIEW_GROUP );

		$repo = $this->createMock( AccessRepository::class );
		$repo->expects( $this->never() )->method( 'list_org_ids_for_user' );

		$policy = new AccessPolicy( $repo );

		$this->assertNull( $policy->org_ids_for( 42 ) );
		$this->assertFalse( $policy->is_org_scoped( 42 ) );
		$this->assertNull( $policy->org_ids_for( 10 ) );
		$this->assertFalse( $policy->is_org_scoped( 10 ) );
	}

	#[TestDox( 'org_ids_for returns an array for MANAGE_ORG holders — empty array is a real scope, not "unbounded"' )]
	public function test_org_ids_for_returns_array_for_org_admins(): void {
		$this->grant( 55, Capabilities::MANAGE_ORG );
		$this->grant( 56, Capabilities::MANAGE_ORG );

		$repo = $this->createMock( AccessRepository::class );
		$repo->method( 'list_org_ids_for_user' )->willReturnMap(
			array(
				array( 55, array( 3, 7 ) ),
				array( 56, array() ),
			)
		);

		$policy = new AccessPolicy( $repo );

		$this->assertSame( array( 3, 7 ), $policy->org_ids_for( 55 ) );
		$this->assertTrue( $policy->is_org_scoped( 55 ) );

		// Empty membership set — scoped user with zero orgs. NOT null.
		$this->assertSame( array(), $policy->org_ids_for( 56 ) );
		$this->assertTrue( $policy->is_org_scoped( 56 ) );
	}

	// =========================================================== §8-6 filter tighten-only.

	#[TestDox( '§8-6: minhaj_access_decision returning true on a false decision stays false + minhaj_access_decision_loosen_ignored fires' )]
	public function test_decision_filter_cannot_loosen(): void {
		// Override apply_filters for this test only — the setUp default
		// returns the base value unchanged; here we simulate a subscriber
		// that tries to flip a denial to a grant.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value ): mixed {
				if ( 'minhaj_access_decision' === $tag ) {
					return true;
				}
				return $value;
			}
		);

		$loosen_events = array();
		Functions\when( 'do_action' )->alias(
			static function ( string $tag, ...$args ) use ( &$loosen_events ): void {
				if ( 'minhaj_access_decision_loosen_ignored' === $tag ) {
					$loosen_events[] = $args;
				}
			}
		);

		$repo   = $this->createMock( AccessRepository::class );
		$policy = new AccessPolicy( $repo );

		// Missing group → base decision is false → filter tries to grant.
		$decision = $policy->can_view_group( 999, 12345 );

		$this->assertFalse( $decision, 'filter attempted to grant — must have been ignored' );
		$this->assertNotEmpty( $loosen_events, 'the loosen-attempt hook must fire when a filter tries to grant' );
		$this->assertSame( 'view_group', $loosen_events[0][0] );
	}

	// =========================================================== §8-7 unknown user.

	#[TestDox( '§8-7: missing/deleted user id returns false without throwing' )]
	public function test_unknown_user_returns_false(): void {
		$repo   = $this->createMock( AccessRepository::class );
		$policy = new AccessPolicy( $repo );

		$this->assertFalse( $policy->can_view_group( 0, 1 ) );
		$this->assertFalse( $policy->can_view_group( -1, 1 ) );
		$this->assertFalse( $policy->can_view_session( 0, 1 ) );
		$this->assertFalse( $policy->can_view_recording( 0, 1 ) );
		$this->assertFalse( $policy->join_role( 0, 1 ) );
		$this->assertSame( array(), $policy->visible_group_ids_for( 0 ) );
	}

	// ============================================================== assert() behaviour.

	#[TestDox( 'assert() throws AccessDeniedException on false decision and records a denial' )]
	public function test_assert_throws_and_logs(): void {
		$repo = $this->createMock( AccessRepository::class );
		$repo->expects( $this->once() )->method( 'record_denial' );

		$policy = new AccessPolicy( $repo );

		$this->expectException( AccessDeniedException::class );
		$policy->assert( false, 'view_group', 10, 201 );
	}

	// ------------------------------------------------------- Helper.

	private function grant( int $user_id, string $cap ): void {
		if ( ! isset( $this->caps[ $user_id ] ) ) {
			$this->caps[ $user_id ] = array();
		}
		$this->caps[ $user_id ][ $cap ] = true;
	}
}
