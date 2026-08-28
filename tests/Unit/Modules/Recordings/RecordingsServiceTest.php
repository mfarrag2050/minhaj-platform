<?php
/**
 * Service-layer proofs for the recordings guards that don't need a
 * live DB.
 *
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Recordings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Minhaj\Modules\Recordings\RecordingAccessCheck;
use Minhaj\Modules\Recordings\Domain\RecordingKind;
use Minhaj\Modules\Recordings\Domain\RecordingStatus;
use Minhaj\Modules\Recordings\RecordingsService;
use Minhaj\Modules\Recordings\Repository\RecordingsRepository;
use Minhaj\Modules\Recordings\Storage\StorageClient;
use Minhaj\Modules\Recordings\Zoom\FakeRecordingsZoomClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use WP_Error;

#[CoversClass( RecordingsService::class )]
final class RecordingsServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'do_action' )->justReturn();
		Functions\when( 'apply_filters' )->alias( fn( string $tag, mixed $value ) => $value );
		Functions\when( 'current_time' )->justReturn( '2026-08-28 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $v ) => (string) json_encode( $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias( fn( mixed $t ) => $t instanceof WP_Error );
		Functions\when( 'wp_tempnam' )->alias( fn( string $prefix ) => sys_get_temp_dir() . '/' . $prefix . '-' . uniqid( '', true ) );
		Functions\when( 'wp_delete_file' )->justReturn();
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[TestDox( 'issue_view_url returns access_denied and logs a `denied` row when policy refuses' )]
	public function test_issue_view_url_denied_logs_and_refuses(): void {
		$repo    = $this->createMock( RecordingsRepository::class );
		$access  = $this->createMock( RecordingAccessCheck::class );
		$storage = $this->createMock( StorageClient::class );

		$repo->method( 'find' )->willReturn(
			array(
				'id'         => 7,
				'session_id' => 42,
				'status'     => RecordingStatus::STORED,
				'object_key' => 'sessions/42/mp4',
			)
		);
		$access->method( 'can_view_recording' )->willReturn( false );

		// The denied row MUST be logged, and access_denied event MUST fire.
		$repo->expects( $this->once() )
			->method( 'insert_access_log' )
			->with( 7, 99, 'denied', $this->anything() );

		$svc    = new RecordingsService( $repo, new FakeRecordingsZoomClient(), $storage, $access );
		$result = $svc->issue_view_url( 99, 7 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'access_denied', $result->get_error_code() );
	}

	#[TestDox( 'issue_view_url on a purged row returns recording_purged and logs denied — even for admin' )]
	public function test_issue_view_url_on_tombstone_returns_purged(): void {
		$repo    = $this->createMock( RecordingsRepository::class );
		$access  = $this->createMock( RecordingAccessCheck::class );
		$storage = $this->createMock( StorageClient::class );

		$repo->method( 'find' )->willReturn(
			array(
				'id'         => 7,
				'session_id' => 42,
				'status'     => RecordingStatus::PURGED,
				'object_key' => null,
			)
		);
		// can_view is not even called — the tombstone check runs first.
		$access->expects( $this->never() )->method( 'can_view_recording' );
		$repo->expects( $this->once() )->method( 'insert_access_log' )
			->with( 7, 1, 'denied', $this->anything() );

		$svc    = new RecordingsService( $repo, new FakeRecordingsZoomClient(), $storage, $access );
		$result = $svc->issue_view_url( 1, 7 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'recording_purged', $result->get_error_code() );
	}

	#[TestDox( 'issue_view_url on granted access presigns via StorageClient and logs `view`' )]
	public function test_issue_view_url_grants_signs_and_logs(): void {
		$repo    = $this->createMock( RecordingsRepository::class );
		$access  = $this->createMock( RecordingAccessCheck::class );
		$storage = $this->createMock( StorageClient::class );

		$repo->method( 'find' )->willReturn(
			array(
				'id'         => 7,
				'session_id' => 42,
				'status'     => RecordingStatus::STORED,
				'object_key' => 'sessions/42/mp4',
			)
		);
		$access->method( 'can_view_recording' )->willReturn( true );

		$storage->expects( $this->once() )
			->method( 'presign' )
			->with( 'sessions/42/mp4', 15 )
			->willReturn( 'https://signed/watch?sig=abc' );

		$repo->expects( $this->once() )->method( 'insert_access_log' )
			->with( 7, 5, 'view', $this->anything() );

		$svc = new RecordingsService( $repo, new FakeRecordingsZoomClient(), $storage, $access );
		$url = $svc->issue_view_url( 5, 7 );

		$this->assertSame( 'https://signed/watch?sig=abc', $url );
	}

	#[TestDox( 'delete_from_zoom_when_verified refuses when storage->exists is false — G-1' )]
	public function test_delete_refused_when_object_missing(): void {
		$repo    = $this->createMock( RecordingsRepository::class );
		$access  = $this->createMock( RecordingAccessCheck::class );
		$storage = $this->createMock( StorageClient::class );
		$zoom    = new FakeRecordingsZoomClient();

		$repo->method( 'find' )->willReturn(
			array(
				'id'                => 7,
				'session_id'        => 42,
				'zoom_meeting_uuid' => 'muid',
				'zoom_file_id'      => 'fid',
				'object_key'        => 'sessions/42/mp4',
				'checksum_sha256'   => str_repeat( 'a', 64 ),
			)
		);
		$storage->method( 'exists' )->willReturn( false );

		$svc = new RecordingsService( $repo, $zoom, $storage, $access );
		$this->assertFalse( $svc->delete_from_zoom_when_verified( 7 ) );
		$this->assertSame( 0, $zoom->delete_calls_for( 'fid' ) );
	}

	#[TestDox( 'delete_from_zoom_when_verified refuses when checksum is missing — G-1' )]
	public function test_delete_refused_when_checksum_missing(): void {
		$repo    = $this->createMock( RecordingsRepository::class );
		$access  = $this->createMock( RecordingAccessCheck::class );
		$storage = $this->createMock( StorageClient::class );
		$zoom    = new FakeRecordingsZoomClient();

		$repo->method( 'find' )->willReturn(
			array(
				'id'                => 7,
				'session_id'        => 42,
				'zoom_meeting_uuid' => 'muid',
				'zoom_file_id'      => 'fid',
				'object_key'        => 'sessions/42/mp4',
				'checksum_sha256'   => null,
			)
		);

		$svc = new RecordingsService( $repo, $zoom, $storage, $access );
		$this->assertFalse( $svc->delete_from_zoom_when_verified( 7 ) );
		$this->assertSame( 0, $zoom->delete_calls_for( 'fid' ) );
	}

	#[TestDox( 'promote_to_assessment requires a written reason' )]
	public function test_promote_requires_reason(): void {
		$repo    = $this->createMock( RecordingsRepository::class );
		$access  = $this->createMock( RecordingAccessCheck::class );
		$storage = $this->createMock( StorageClient::class );
		$svc     = new RecordingsService( $repo, new FakeRecordingsZoomClient(), $storage, $access );

		$this->expectException( \RuntimeException::class );
		$svc->promote_to_assessment( 1, 7, '   ' );
	}

	#[TestDox( 'set_legal_hold requires a written reason' )]
	public function test_hold_requires_reason(): void {
		$repo    = $this->createMock( RecordingsRepository::class );
		$access  = $this->createMock( RecordingAccessCheck::class );
		$storage = $this->createMock( StorageClient::class );
		$svc     = new RecordingsService( $repo, new FakeRecordingsZoomClient(), $storage, $access );

		$this->expectException( \RuntimeException::class );
		$svc->set_legal_hold( 1, 7, true, '' );
	}
}
