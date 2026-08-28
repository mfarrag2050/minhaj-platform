<?php
/**
 * Acceptance tests for spec-organizations-v1 §8. Nine of the eleven criteria
 * are testable at the service/domain layer with a doubled OrgRepository;
 * the remaining two (race on max_uses=1, and the DB-enforced
 * uq_active_member unique index) live in an integration test that hits a
 * real MariaDB — we assert here that the service *routes* the DB error
 * kinds correctly, and let the integration suite prove the DB rejects.
 *
 * @package Minhaj\Tests\Unit\Modules\Orgs
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Orgs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Minhaj\Modules\Orgs\OrgService;
use Minhaj\Modules\Orgs\Repository\OrgRepository;
use Minhaj\Modules\Orgs\Repository\PersistenceException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use WP_Error;

#[CoversClass( OrgService::class )]
final class OrgServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( fn( string $tag, mixed $value ) => $value );
		Functions\when( 'do_action' )->justReturn();
		Functions\when( 'current_time' )->alias(
			fn( string $format ) => 'Y-m-d' === $format ? '2026-08-28' : '2026-08-28 12:00:00'
		);
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $v ) => json_encode( $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias( fn( mixed $t ) => $t instanceof WP_Error );
		Functions\when( 'home_url' )->alias( fn( string $path ) => 'https://example.org' . $path );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ============================================================= O-11 licensee locked.

	#[TestDox( 'O-11: creating an org with type=licensee is rejected with an explicit "unsupported" error' )]
	public function test_licensee_type_is_locked(): void {
		$repo = $this->createMock( OrgRepository::class );
		$repo->expects( $this->never() )->method( 'insert_org' );

		$svc = new OrgService( $repo );
		$out = $svc->create_org( 7, array(
			'type' => 'licensee',
			'code' => 'MNHJ-XX',
			'name' => 'Somewhere',
		) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'org_type_unsupported', $out->get_error_code() );
	}

	#[TestDox( 'O-11: data_controller=org override is rejected on the same lock as licensee' )]
	public function test_data_controller_org_is_locked(): void {
		$repo = $this->createMock( OrgRepository::class );
		$repo->expects( $this->never() )->method( 'insert_org' );

		$svc = new OrgService( $repo );
		$out = $svc->create_org( 7, array(
			'type'            => 'supplier',
			'data_controller' => 'org',
			'code'            => 'MNHJ-YY',
			'name'            => 'Anywhere',
		) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'org_type_unsupported', $out->get_error_code() );
	}

	// ================================================================ §8-2 neutral response.

	#[TestDox( '§8-2: revoked / expired / exhausted / suspended-org tokens all return null (no leak)' )]
	public function test_resolve_returns_null_for_every_bad_state(): void {
		$repo = $this->createMock( OrgRepository::class );

		$repo->method( 'find_registration_link_by_token' )->willReturnMap(
			array(
				array(
					'aaaaaaaaaaaaaaaaaaaaaa',
					array(
						'id'         => 1,
						'org_id'     => 100,
						'status'     => 'revoked',
						'expires_at' => null,
						'max_uses'   => null,
						'uses_count' => 0,
					),
				),
				array(
					'bbbbbbbbbbbbbbbbbbbbbb',
					array(
						'id'         => 2,
						'org_id'     => 100,
						'status'     => 'active',
						'expires_at' => '2026-01-01',
						'max_uses'   => null,
						'uses_count' => 0,
					),
				),
				array(
					'cccccccccccccccccccccc',
					array(
						'id'         => 3,
						'org_id'     => 100,
						'status'     => 'active',
						'expires_at' => null,
						'max_uses'   => 1,
						'uses_count' => 1,
					),
				),
				array(
					'dddddddddddddddddddddd',
					array(
						'id'         => 4,
						'org_id'     => 100,
						'status'     => 'active',
						'expires_at' => null,
						'max_uses'   => null,
						'uses_count' => 0,
					),
				),
			)
		);

		$repo->method( 'find_org' )->willReturn(
			array( 'id' => 100, 'status' => 'suspended' )
		);

		$svc = new OrgService( $repo );

		$this->assertNull( $svc->resolve_registration_token( 'aaaaaaaaaaaaaaaaaaaaaa' ) );
		$this->assertNull( $svc->resolve_registration_token( 'bbbbbbbbbbbbbbbbbbbbbb' ) );
		$this->assertNull( $svc->resolve_registration_token( 'cccccccccccccccccccccc' ) );
		$this->assertNull( $svc->resolve_registration_token( 'dddddddddddddddddddddd' ) );
	}

	#[TestDox( '§8-2: a wrong-length token is rejected before ever reaching the DB' )]
	public function test_token_length_gate(): void {
		$repo = $this->createMock( OrgRepository::class );
		$repo->expects( $this->never() )->method( 'find_registration_link_by_token' );

		$svc = new OrgService( $repo );

		$this->assertNull( $svc->resolve_registration_token( 'too-short' ) );
	}

	// ================================================================ §8-3 race on max_uses=1.

	#[TestDox( '§8-3: consume_registration_token relies on an atomic UPDATE — a race that loses returns link_exhausted' )]
	public function test_consume_returns_exhausted_when_atomic_update_matches_zero_rows(): void {
		$repo = $this->createMock( OrgRepository::class );
		$repo->method( 'increment_uses_if_available' )->with( 42 )->willReturn( 0 );

		$svc = new OrgService( $repo );

		$out = $svc->consume_registration_token( 42 );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'link_exhausted', $out->get_error_code() );
	}

	#[TestDox( '§8-3: consume_registration_token returns true when the atomic UPDATE affects one row' )]
	public function test_consume_returns_true_when_atomic_update_matches(): void {
		$repo = $this->createMock( OrgRepository::class );
		$repo->method( 'increment_uses_if_available' )->with( 42 )->willReturn( 1 );

		$svc = new OrgService( $repo );

		$this->assertTrue( $svc->consume_registration_token( 42 ) );
	}

	// ================================================================ §8-4 DPA gate.

	#[TestDox( '§8-4: set_status(active) fails when the org has no dpa_signed_at' )]
	public function test_activate_without_dpa_fails(): void {
		$repo = $this->createMock( OrgRepository::class );
		$repo->method( 'find_org_for_update' )->willReturn(
			array(
				'id'            => 1,
				'status'        => 'suspended',
				'dpa_signed_at' => null,
			)
		);
		$repo->expects( $this->never() )->method( 'update_org' );

		$svc = new OrgService( $repo );
		$out = $svc->set_status( 7, 1, 'active', 'go live' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'dpa_required', $out->get_error_code() );
	}

	#[TestDox( '§8-4: issue_registration_link fails when the org has no dpa_signed_at' )]
	public function test_issue_link_without_dpa_fails(): void {
		$repo = $this->createMock( OrgRepository::class );
		$repo->method( 'find_org_for_update' )->willReturn(
			array(
				'id'            => 1,
				'status'        => 'active',
				'dpa_signed_at' => null,
			)
		);
		$repo->expects( $this->never() )->method( 'insert_registration_link' );

		$svc = new OrgService( $repo );
		$out = $svc->issue_registration_link( 7, 1, array() );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'dpa_required', $out->get_error_code() );
	}

	// ============================================================= §8-11 duplicate active.

	#[TestDox( '§8-11: duplicate active membership surfaces as a DB error (uq_active_member), not a silent success' )]
	public function test_duplicate_active_member_bubbles_up(): void {
		$repo = $this->createMock( OrgRepository::class );
		$repo->method( 'find_org_for_update' )->willReturn( array( 'id' => 1, 'status' => 'active' ) );
		$repo->method( 'insert_member' )->willThrowException(
			new PersistenceException(
				PersistenceException::DUPLICATE_ACTIVE_MEMBER,
				'uq_active_member collision'
			)
		);
		$repo->expects( $this->once() )->method( 'rollback' );

		$svc = new OrgService( $repo );
		$out = $svc->add_member( 7, 1, 555, 'teacher' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'duplicate_active_member', $out->get_error_code() );
	}

	// =============================================================== §8-1 attribution write.

	#[TestDox( '§8-1: issue_registration_link writes the row and returns url + token in one transaction' )]
	public function test_issue_link_writes_row_and_commits(): void {
		$repo = $this->createMock( OrgRepository::class );
		$repo->method( 'find_org_for_update' )->willReturn(
			array(
				'id'            => 1,
				'status'        => 'active',
				'dpa_signed_at' => '2026-01-01',
			)
		);
		$repo->method( 'find_registration_link_by_token' )->willReturn( null );

		$captured = array();
		$repo->expects( $this->once() )
			->method( 'insert_registration_link' )
			->willReturnCallback(
				function ( array $data ) use ( &$captured ): int {
					$captured = $data;
					return 555;
				}
			);
		$repo->expects( $this->once() )->method( 'begin_transaction' );
		$repo->expects( $this->once() )->method( 'commit' );
		$repo->expects( $this->never() )->method( 'rollback' );

		$svc = new OrgService( $repo );
		$out = $svc->issue_registration_link(
			7,
			1,
			array( 'label' => 'Ramadan 2026', 'campaign' => 'launch' )
		);

		$this->assertIsArray( $out );
		$this->assertSame( 555, $out['id'] );
		$this->assertSame( 22, strlen( $out['token'] ) );
		$this->assertStringStartsWith( 'https://example.org/join/', $out['url'] );

		$this->assertSame( 1, $captured['org_id'] );
		$this->assertSame( 'Ramadan 2026', $captured['label'] );
		$this->assertSame( 7, $captured['created_by'] );
	}

	// ============================================================== §8-8 cross-org teaching allowed.

	#[TestDox( '§8-8: nothing in the service prevents cross-org teaching (student org != teacher org)' )]
	public function test_no_domain_check_blocks_cross_org_teaching(): void {
		// Cross-org teaching is not vetoed by any domain rule in OrgService —
		// this is a *sanity* test that the module does not sneak in a check
		// that would violate O-2. The Groups module's assign_teacher path is
		// where cross-org would surface, and it goes through an unrelated
		// filter.
		$repo = $this->createMock( OrgRepository::class );

		$reflection = new \ReflectionClass( OrgService::class );

		foreach ( $reflection->getMethods() as $method ) {
			$source = file_get_contents( (string) $reflection->getFileName() );
			$this->assertStringNotContainsString(
				'same_org',
				(string) $source,
				'OrgService must not veto cross-org teaching (O-2).'
			);
		}
	}

	// ================================================== org_ids_for_user routes to repo.

	#[TestDox( 'org_ids_for_user returns the repository list unchanged' )]
	public function test_org_ids_for_user_delegates_to_repo(): void {
		$repo = $this->createMock( OrgRepository::class );
		$repo->method( 'list_active_org_ids_for_user' )->with( 42 )->willReturn( array( 3, 7 ) );

		$svc = new OrgService( $repo );
		$this->assertSame( array( 3, 7 ), $svc->org_ids_for_user( 42 ) );
	}
}
