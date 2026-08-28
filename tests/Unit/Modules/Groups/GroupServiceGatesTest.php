<?php
/**
 * Service-level gate tests for GroupService::create.
 *
 * The point of these tests is to prove that the language-coverage
 * and capacity-over-promise gates fire when the SERVICE is called
 * directly — not through the admin controller. If a future CLI, REST
 * endpoint, or module bypasses the UI, the gate is still what
 * refuses the write. Passing at the admin form and passing at the
 * service are two different things; only the second is a real
 * guarantee.
 *
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Groups;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Minhaj\Modules\Groups\GroupService;
use Minhaj\Modules\Groups\Repository\GroupRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use WP_Error;

#[CoversClass( GroupService::class )]
final class GroupServiceGatesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'do_action' )->justReturn();
		Functions\when( 'current_time' )->justReturn( '2026-08-28 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $v ) => json_encode( $v ) );
		Functions\when( 'absint' )->alias( fn( mixed $n ) => abs( (int) $n ) );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias( fn( mixed $t ) => $t instanceof WP_Error );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[TestDox( 'GroupService::create rejects a locale with zero teacher coverage — even when the UI would have hidden the option' )]
	public function test_service_rejects_uncovered_language_without_override(): void {
		// Locale `xh` is not in LaunchLanguages — a bypassing caller
		// (CLI, REST, a stale form) could still send it. The gate
		// must fire.
		Filters\expectApplied( 'minhaj_group_teaching_language_coverage' )
			->andReturn( 0 );

		$repo   = $this->createMock( GroupRepository::class );
		$result = ( new GroupService( $repo ) )->create(
			1,
			array(
				'type'              => 'group',
				'batch_id'          => 1,
				'level'             => 'A1',
				'teaching_language' => 'xh',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_assignable_teacher_for_language', $result->get_error_code() );
	}

	#[TestDox( 'GroupService::create rejects capacity_max above the default ceiling when no reason is passed' )]
	public function test_service_rejects_capacity_over_promise_without_reason(): void {
		// Language gate must pass so the capacity gate is reached.
		Filters\expectApplied( 'minhaj_group_teaching_language_coverage' )
			->andReturn( 5 );

		$repo   = $this->createMock( GroupRepository::class );
		$result = ( new GroupService( $repo ) )->create(
			1,
			array(
				'type'              => 'group',
				'batch_id'          => 1,
				'level'             => 'A1',
				'teaching_language' => 'nl',
				'capacity_min'      => 3,
				'capacity_max'      => 6,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'capacity_over_promise', $result->get_error_code() );
	}

	#[TestDox( 'GroupService::create fires the language gate BEFORE the capacity gate — coverage error takes precedence' )]
	public function test_language_gate_precedes_capacity_gate(): void {
		Filters\expectApplied( 'minhaj_group_teaching_language_coverage' )
			->andReturn( 0 );

		$repo   = $this->createMock( GroupRepository::class );
		$result = ( new GroupService( $repo ) )->create(
			1,
			array(
				'type'              => 'group',
				'batch_id'          => 1,
				'level'             => 'A1',
				'teaching_language' => 'xh',
				'capacity_min'      => 3,
				'capacity_max'      => 6, // Would also trigger capacity_over_promise.
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_assignable_teacher_for_language', $result->get_error_code() );
	}
}
