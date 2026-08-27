<?php
/**
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\People;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Minhaj\Modules\People\PeopleService;
use Minhaj\Modules\People\Repository\PeopleRepository;
use Minhaj\Modules\People\Repository\PersistenceException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use WP_Error;

#[CoversClass( PeopleService::class )]
final class PeopleServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( fn( string $tag, mixed $value ) => $value );
		Functions\when( 'do_action' )->justReturn();
		Functions\when( 'current_time' )->alias(
			function ( string $format ) {
				return 'Y-m-d' === $format ? '2026-08-28' : '2026-08-28 12:00:00';
			}
		);
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $v ) => json_encode( $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias( fn( mixed $t ) => $t instanceof WP_Error );
		Functions\when( 'wp_insert_user' )->justReturn( 555 );
		Functions\when( 'wp_generate_password' )->justReturn( 'test-password' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ============================================================ create_student.

	#[TestDox( 'spec §6-1: creating a student from a guardian succeeds and writes exactly one primary-guardian row' )]
	public function test_create_student_writes_primary_guardianship(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$captured_guardianship = null;
		$repo->expects( $this->once() )->method( 'insert_student_profile' );
		$repo->expects( $this->once() )
			->method( 'insert_guardianship' )
			->willReturnCallback(
				function ( array $data ) use ( &$captured_guardianship ): int {
					$captured_guardianship = $data;
					return 77;
				}
			);
		$repo->expects( $this->once() )->method( 'insert_audit' )->willReturn( 1 );
		$repo->expects( $this->once() )->method( 'commit' );

		$user_id = ( new PeopleService( $repo ) )->create_student( 7, 42, array( 'first_name' => 'Sara' ) );

		$this->assertSame( 555, $user_id );
		$this->assertSame( 42, $captured_guardianship['guardian_id'] );
		$this->assertSame( 1, $captured_guardianship['is_primary'] );
		$this->assertSame( 555, $captured_guardianship['student_id'] );
	}

	#[TestDox( 'spec §6-2: creating a student without a guardian is rejected (S-1/S-2)' )]
	public function test_create_student_without_guardian_is_rejected(): void {
		$repo = $this->createMock( PeopleRepository::class );
		$repo->expects( $this->never() )->method( 'insert_student_profile' );
		$repo->expects( $this->never() )->method( 'insert_guardianship' );

		$result = ( new PeopleService( $repo ) )->create_student( 7, 0, array( 'first_name' => 'Sara' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardian_required', $result->get_error_code() );
	}

	#[TestDox( 'spec §6-3: a second active primary guardian raises DUPLICATE_PRIMARY_GUARDIAN from the DB and becomes duplicate_primary_guardian WP_Error — DB level, not PHP' )]
	public function test_second_active_primary_guardian_rejected_at_db_level(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'insert_student_profile' );
		$repo->method( 'insert_guardianship' )->willThrowException(
			new PersistenceException(
				PersistenceException::DUPLICATE_PRIMARY_GUARDIAN,
				'uq_active_primary_guardian collision'
			)
		);
		$repo->expects( $this->once() )->method( 'rollback' );
		$repo->expects( $this->never() )->method( 'commit' );

		$result = ( new PeopleService( $repo ) )->create_student( 7, 42, array( 'first_name' => 'Sara' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'duplicate_primary_guardian', $result->get_error_code() );
	}

	// ================================================= teacher_is_assignable (S-4).

	#[TestDox( 'spec §6-4: teacher with no valid check is not assignable (S-4)' )]
	public function test_teacher_without_valid_check_is_not_assignable(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'find_teacher_profile' )->willReturn( array( 'user_id' => 50, 'status' => 'active' ) );
		$repo->method( 'find_current_valid_check' )->willReturn( null );

		$result = ( new PeopleService( $repo ) )->teacher_is_assignable( 50 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_valid_check', $result->get_error_code() );
	}

	#[TestDox( 'spec §6-5: expired check means the teacher is no longer assignable — past assignments untouched by this dry check' )]
	public function test_expired_check_makes_teacher_not_assignable(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'find_teacher_profile' )->willReturn( array( 'user_id' => 50, 'status' => 'active' ) );
		// find_current_valid_check filters by expires_at >= today; expired = null.
		$repo->method( 'find_current_valid_check' )->willReturn( null );
		$repo->method( 'count_teacher_teachable_languages' )->willReturn( 1 );

		$result = ( new PeopleService( $repo ) )->teacher_is_assignable( 50 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_valid_check', $result->get_error_code() );
	}

	#[TestDox( 'S-7: teacher without declared teaching language is not assignable' )]
	public function test_teacher_without_language_is_not_assignable(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'find_teacher_profile' )->willReturn( array( 'user_id' => 50, 'status' => 'active' ) );
		$repo->method( 'find_current_valid_check' )->willReturn(
			array( 'id' => 1, 'teacher_id' => 50, 'status' => 'valid', 'expires_at' => '2099-01-01' )
		);
		$repo->method( 'count_teacher_teachable_languages' )->willReturn( 0 );

		$result = ( new PeopleService( $repo ) )->teacher_is_assignable( 50 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_teaching_language', $result->get_error_code() );
	}

	public function test_teacher_not_active_is_not_assignable(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'find_teacher_profile' )->willReturn( array( 'user_id' => 50, 'status' => 'suspended' ) );

		$result = ( new PeopleService( $repo ) )->teacher_is_assignable( 50 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'teacher_not_active', $result->get_error_code() );
	}

	public function test_teacher_is_assignable_happy_path(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'find_teacher_profile' )->willReturn( array( 'user_id' => 50, 'status' => 'active' ) );
		$repo->method( 'find_current_valid_check' )->willReturn(
			array( 'id' => 1, 'teacher_id' => 50, 'status' => 'valid', 'expires_at' => '2099-01-01' )
		);
		$repo->method( 'count_teacher_teachable_languages' )->willReturn( 2 );

		$result = ( new PeopleService( $repo ) )->teacher_is_assignable( 50 );

		$this->assertTrue( $result );
	}

	// =============================================================== transition_teacher.

	#[TestDox( 'spec §6-6: transitioning a teacher to active without a declared language is rejected (S-7)' )]
	public function test_transition_to_active_requires_a_language(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'find_teacher_profile' )->willReturn(
			array( 'user_id' => 50, 'status' => 'checks_pending' )
		);
		$repo->method( 'count_teacher_teachable_languages' )->willReturn( 0 );

		$repo->expects( $this->never() )->method( 'update_teacher_profile' );

		$result = ( new PeopleService( $repo ) )->transition_teacher( 7, 50, 'active', 'greenlight' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_teaching_language', $result->get_error_code() );
	}

	public function test_transition_to_active_requires_valid_check(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'find_teacher_profile' )->willReturn(
			array( 'user_id' => 50, 'status' => 'checks_pending' )
		);
		$repo->method( 'count_teacher_teachable_languages' )->willReturn( 1 );
		$repo->method( 'find_current_valid_check' )->willReturn( null );

		$repo->expects( $this->never() )->method( 'update_teacher_profile' );

		$result = ( new PeopleService( $repo ) )->transition_teacher( 7, 50, 'active', 'greenlight' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_valid_check', $result->get_error_code() );
	}

	public function test_transition_to_active_success(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'find_teacher_profile' )->willReturn(
			array( 'user_id' => 50, 'status' => 'checks_pending' )
		);
		$repo->method( 'count_teacher_teachable_languages' )->willReturn( 1 );
		$repo->method( 'find_current_valid_check' )->willReturn(
			array( 'id' => 1, 'teacher_id' => 50, 'expires_at' => '2099-01-01' )
		);

		$captured = null;
		$repo->expects( $this->once() )
			->method( 'update_teacher_profile' )
			->willReturnCallback(
				function ( int $id, array $data ) use ( &$captured ): void {
					$captured = array( 'id' => $id, 'data' => $data );
				}
			);
		$repo->expects( $this->once() )->method( 'insert_audit' );
		$repo->expects( $this->once() )->method( 'commit' );

		$result = ( new PeopleService( $repo ) )->transition_teacher( 7, 50, 'active', 'greenlight' );

		$this->assertTrue( $result );
		$this->assertSame( 'active', $captured['data']['status'] );
	}

	public function test_transition_rejects_disallowed_jump(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'find_teacher_profile' )->willReturn(
			array( 'user_id' => 50, 'status' => 'applicant' )
		);

		$result = ( new PeopleService( $repo ) )->transition_teacher( 7, 50, 'active', 'greenlight' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_transition', $result->get_error_code() );
	}

	// ================================================================ language_coverage.

	#[TestDox( 'spec §6-7: language_coverage("nl") returns the assignable count from the repo intersection query' )]
	public function test_language_coverage_returns_repo_count(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->expects( $this->once() )
			->method( 'count_assignable_teachers_for_locale' )
			->with( 'nl', $this->anything() )
			->willReturn( 4 );

		$result = ( new PeopleService( $repo ) )->language_coverage( 'nl' );

		$this->assertSame( 'nl', $result['locale'] );
		$this->assertSame( 4, $result['assignable'] );
	}

	// =============================================================== anonymize_student.

	#[TestDox( 'spec §6-8: anonymize_student blanks PII, sets anonymized_at, writes an audit row, and does not delete anything' )]
	public function test_anonymize_student_blanks_pii_and_writes_audit(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'find_student_profile' )->willReturn(
			array(
				'user_id'             => 555,
				'first_name'          => 'Sara',
				'family_name_initial' => 'A',
				'birth_year'          => 2016,
				'anonymized_at'       => null,
			)
		);

		$captured_update = null;
		$repo->expects( $this->once() )
			->method( 'update_student_profile' )
			->willReturnCallback(
				function ( int $id, array $data ) use ( &$captured_update ): void {
					$captured_update = array( 'id' => $id, 'data' => $data );
				}
			);

		$captured_audit = null;
		$repo->expects( $this->once() )
			->method( 'insert_audit' )
			->willReturnCallback(
				function ( array $data ) use ( &$captured_audit ): int {
					$captured_audit = $data;
					return 1;
				}
			);
		$repo->expects( $this->once() )->method( 'commit' );

		$result = ( new PeopleService( $repo ) )->anonymize_student( 7, 555, 'gdpr erasure request' );

		$this->assertTrue( $result );
		$this->assertSame( '', $captured_update['data']['first_name'] );
		$this->assertSame( '', $captured_update['data']['family_name_initial'] );
		$this->assertNull( $captured_update['data']['birth_year'] );
		$this->assertNotEmpty( $captured_update['data']['anonymized_at'], 'anonymized_at must be stamped' );

		$this->assertSame( 'student.anonymized', $captured_audit['action'] );
		$this->assertSame( 555, $captured_audit['subject_id'] );
		$this->assertSame( 7, $captured_audit['actor_user_id'] );
	}

	public function test_anonymize_student_rejects_when_already_anonymized(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$repo->method( 'find_student_profile' )->willReturn(
			array(
				'user_id'       => 555,
				'anonymized_at' => '2026-08-01 12:00:00',
			)
		);
		$repo->expects( $this->never() )->method( 'update_student_profile' );

		$result = ( new PeopleService( $repo ) )->anonymize_student( 7, 555, 'again' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'already_anonymized', $result->get_error_code() );
	}

	public function test_anonymize_student_requires_reason(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$result = ( new PeopleService( $repo ) )->anonymize_student( 7, 555, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'reason_required', $result->get_error_code() );
	}

	// ------------------------------------------------------------ actor guardrail.

	public function test_writes_reject_actor_zero(): void {
		$repo = $this->createMock( PeopleRepository::class );

		$result = ( new PeopleService( $repo ) )->create_student( 0, 1, array( 'first_name' => 'x' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_actor', $result->get_error_code() );
	}
}
