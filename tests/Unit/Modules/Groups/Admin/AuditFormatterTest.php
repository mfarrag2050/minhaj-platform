<?php
/**
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Groups\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Minhaj\Modules\Groups\Admin\AuditFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( AuditFormatter::class )]
final class AuditFormatterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );

		// Every actor lookup returns "admin"; every user lookup returns
		// "Some User" so we can assert the sentence template without
		// coupling to WP internals.
		Functions\when( 'get_user_by' )->alias(
			static function ( string $field, int $id ): object {
				return (object) array(
					'ID'           => $id,
					'display_name' => 1 === $id ? 'admin' : 'Some User',
				);
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[TestDox( 'member.added renders as "admin added student <name> to seat N"' )]
	public function test_member_added_sentence(): void {
		$row = array(
			'actor_user_id' => 1,
			'action'        => 'member.added',
			'subject_id'    => 42,
			'payload_json'  => (string) json_encode(
				array(
					'student_id' => 206,
					'seat_index' => 5,
				)
			),
			'created_at'    => '2025-05-01 10:00:00',
		);

		$sentence = AuditFormatter::sentence( $row );

		$this->assertStringContainsString( 'admin', $sentence );
		$this->assertStringContainsString( 'Some User', $sentence );
		$this->assertStringContainsString( '#206', $sentence );
		$this->assertStringContainsString( '5', $sentence );
		$this->assertStringNotContainsString( 'seat_index', $sentence, 'raw payload keys must not leak' );
	}

	#[TestDox( 'group.status_changed renders with from/to/reason (no raw JSON)' )]
	public function test_status_changed_sentence(): void {
		$row = array(
			'actor_user_id' => 1,
			'action'        => 'group.status_changed',
			'subject_id'    => 3,
			'payload_json'  => (string) json_encode(
				array(
					'from'   => 'forming',
					'to'     => 'scheduled',
					'reason' => 'quorum reached',
				)
			),
			'created_at'    => '2025-05-01 10:00:00',
		);

		$sentence = AuditFormatter::sentence( $row );

		$this->assertStringContainsString( 'forming', $sentence );
		$this->assertStringContainsString( 'scheduled', $sentence );
		$this->assertStringContainsString( 'quorum reached', $sentence );
		$this->assertStringNotContainsString( '"reason"', $sentence );
	}

	#[TestDox( 'member.removed carries the reason and the student label' )]
	public function test_member_removed_sentence(): void {
		$row = array(
			'actor_user_id' => 1,
			'action'        => 'member.removed',
			'subject_id'    => 22,
			'payload_json'  => (string) json_encode(
				array(
					'student_id' => 200,
					'reason'     => 'moved abroad',
				)
			),
			'created_at'    => '2025-05-01 10:00:00',
		);

		$sentence = AuditFormatter::sentence( $row );

		$this->assertStringContainsString( '#200', $sentence );
		$this->assertStringContainsString( 'moved abroad', $sentence );
	}

	#[TestDox( 'group.teacher_changed reports both endpoints of the swap' )]
	public function test_teacher_changed_sentence(): void {
		$row = array(
			'actor_user_id' => 1,
			'action'        => 'group.teacher_changed',
			'subject_id'    => 77,
			'payload_json'  => (string) json_encode(
				array(
					'from_teacher_id' => 55,
					'to_teacher_id'   => 77,
					'reason'          => 'workload rebalance',
				)
			),
			'created_at'    => '2025-05-01 10:00:00',
		);

		$sentence = AuditFormatter::sentence( $row );

		$this->assertStringContainsString( '#55', $sentence );
		$this->assertStringContainsString( '#77', $sentence );
		$this->assertStringContainsString( 'workload rebalance', $sentence );
	}

	#[TestDox( 'Unknown action codes fall back to a generic sentence, not to raw JSON' )]
	public function test_unknown_action_falls_back(): void {
		$row = array(
			'actor_user_id' => 1,
			'action'        => 'something.custom',
			'payload_json'  => '{"x":1}',
			'created_at'    => '2025-05-01 10:00:00',
		);

		$sentence = AuditFormatter::sentence( $row );

		$this->assertStringContainsString( 'admin', $sentence );
		$this->assertStringContainsString( 'something.custom', $sentence );
		$this->assertStringNotContainsString( '"x":1', $sentence );
	}

	#[TestDox( 'Zero actor id renders as the "system" label' )]
	public function test_zero_actor_renders_as_system(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$row = array(
			'actor_user_id' => 0,
			'action'        => 'group.updated',
			'payload_json'  => (string) json_encode( array( 'level' => 'A2' ) ),
			'created_at'    => '2025-05-01 10:00:00',
		);

		$sentence = AuditFormatter::sentence( $row );

		$this->assertStringContainsString( 'system', $sentence );
		$this->assertStringContainsString( 'level', $sentence );
	}
}
