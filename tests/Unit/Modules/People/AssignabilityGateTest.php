<?php
/**
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\People;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Minhaj\Modules\People\AssignabilityGate;
use Minhaj\Modules\People\PeopleService;
use Minhaj\Modules\People\Repository\PeopleRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use WP_Error;

#[CoversClass( AssignabilityGate::class )]
final class AssignabilityGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'current_time' )->alias(
			function ( string $format ) {
				return 'Y-m-d' === $format ? '2026-08-28' : '2026-08-28 12:00:00';
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn( mixed $t ) => $t instanceof WP_Error );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[TestDox( 'spec §6-4 across modules: an inactive teacher with no valid check surfaces as a WP_Error the Groups module treats as a rejection' )]
	public function test_gate_returns_wp_error_from_service_check(): void {
		// Drive the real (final) service via a mocked repo so the gate
		// exercises the same code path Groups will hit at runtime.
		$repo = $this->createMock( PeopleRepository::class );
		$repo->method( 'find_teacher_profile' )->willReturn(
			array( 'user_id' => 50, 'status' => 'active' )
		);
		$repo->method( 'find_current_valid_check' )->willReturn( null );

		$service = new PeopleService( $repo );
		$gate    = new AssignabilityGate( $service );

		$verdict = $gate->veto_if_not_assignable( true, 50, 1 );

		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'no_valid_check', $verdict->get_error_code() );
	}

	public function test_gate_short_circuits_when_prior_verdict_is_error(): void {
		$repo    = $this->createMock( PeopleRepository::class );
		$repo->expects( $this->never() )->method( 'find_teacher_profile' );
		$service = new PeopleService( $repo );

		$prior   = new WP_Error( 'other_veto', 'no' );
		$gate    = new AssignabilityGate( $service );
		$verdict = $gate->veto_if_not_assignable( $prior, 50, 1 );

		$this->assertSame( $prior, $verdict );
	}

	public function test_gate_allows_when_service_returns_true(): void {
		$repo = $this->createMock( PeopleRepository::class );
		$repo->method( 'find_teacher_profile' )->willReturn(
			array( 'user_id' => 50, 'status' => 'active' )
		);
		$repo->method( 'find_current_valid_check' )->willReturn(
			array( 'id' => 1, 'expires_at' => '2099-01-01' )
		);
		$repo->method( 'count_teacher_teachable_languages' )->willReturn( 1 );

		$service = new PeopleService( $repo );
		$gate    = new AssignabilityGate( $service );

		$verdict = $gate->veto_if_not_assignable( true, 50, 1 );

		$this->assertTrue( $verdict );
	}
}
