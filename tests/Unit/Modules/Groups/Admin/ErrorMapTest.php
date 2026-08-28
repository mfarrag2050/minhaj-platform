<?php
/**
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Groups\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Minhaj\Modules\Groups\Admin\ErrorMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( ErrorMap::class )]
final class ErrorMapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:string}>
	 */
	public static function service_error_codes(): array {
		return array(
			'group_full — the LearnDash lesson stays human' => array(
				'group_full',
				'error',
				'no free seats',
			),
			'invalid_transition'    => array( 'invalid_transition', 'error', 'transition' ),
			'not_ready_to_schedule' => array( 'not_ready_to_schedule', 'error', 'minimum' ),
			'code_taken'            => array( 'code_taken', 'error', 'already in use' ),
			'reason_required'       => array( 'reason_required', 'error', 'reason' ),
			'missing_actor'         => array( 'missing_actor', 'error', 'actor' ),
		);
	}

	#[DataProvider( 'service_error_codes' )]
	#[TestDox( 'Each service error code maps to a user-facing sentence and the right notice type' )]
	public function test_service_codes_map_to_human_messages( string $code, string $expected_type, string $expected_substring ): void {
		$resolved = ErrorMap::resolve( $code );

		$this->assertSame( $expected_type, $resolved['type'] );
		$this->assertNotSame( $code, $resolved['message'], 'raw error code must not reach the UI' );
		$this->assertStringContainsStringIgnoringCase( $expected_substring, $resolved['message'] );
	}

	public function test_success_sentinels_map_to_success_notices(): void {
		foreach ( array( 'create_ok', 'add_member_ok', 'transition_ok' ) as $code ) {
			$resolved = ErrorMap::resolve( $code );
			$this->assertSame( 'success', $resolved['type'], "expected success type for {$code}" );
		}
	}

	public function test_capacity_over_promise_maps_to_error(): void {
		// The pre-save gate now REFUSES capacity>5 without a written
		// reason (previously the group was saved with a warning). The
		// code is therefore an error, not a soft notice.
		$resolved = ErrorMap::resolve( 'capacity_over_promise' );

		$this->assertSame( 'error', $resolved['type'] );
		$this->assertStringContainsStringIgnoringCase( '3', $resolved['message'] );
		$this->assertStringContainsStringIgnoringCase( '5', $resolved['message'] );
	}

	public function test_unknown_code_falls_back_to_generic_error(): void {
		$resolved = ErrorMap::resolve( 'not_a_real_code' );

		$this->assertSame( 'error', $resolved['type'] );
		$this->assertNotSame( 'not_a_real_code', $resolved['message'] );
	}
}
