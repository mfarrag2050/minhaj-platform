<?php
/**
 * spec-zoom-sessions-v1 M-22 — settings lock + periodic drift check.
 *
 * @package Minhaj\Tests\Unit\Modules\Meetings
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Meetings;

use Minhaj\Modules\Meetings\Zoom\FakeZoomClient;
use Minhaj\Modules\Meetings\Zoom\SecuritySettingsChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( SecuritySettingsChecker::class )]
final class SecuritySettingsCheckerTest extends TestCase {

	private function compliant_settings(): array {
		return array(
			'in_meeting' => array(
				'private_chat'                              => false,
				'chat'                                      => true,
				'file_transfer'                             => false,
				'allow_participants_to_rename_themselves'   => false,
				'waiting_room'                              => true,
			),
			'recording'  => array(
				'local_recording' => false,
			),
		);
	}

	#[TestDox( 'M-22 · compliant account settings produce zero drift' )]
	public function test_compliant_account_has_no_drift(): void {
		$checker = new SecuritySettingsChecker( new FakeZoomClient( $this->compliant_settings() ) );

		$this->assertSame( array(), $checker->drift() );
	}

	#[TestDox( 'M-22 · private_chat=true is flagged (the most dangerous drift)' )]
	public function test_private_chat_flip_is_flagged(): void {
		$settings                              = $this->compliant_settings();
		$settings['in_meeting']['private_chat'] = true;

		$drift = ( new SecuritySettingsChecker( new FakeZoomClient( $settings ) ) )->drift();

		$this->assertCount( 1, $drift );
		$this->assertSame( 'in_meeting.private_chat', $drift[0]['path'] );
		$this->assertSame( false, $drift[0]['expected'] );
		$this->assertSame( true, $drift[0]['actual'] );
	}

	#[TestDox( 'M-22 · missing recording section shows local_recording drift (null !== false)' )]
	public function test_missing_key_counts_as_drift(): void {
		$settings = $this->compliant_settings();
		unset( $settings['recording'] );

		$drift = ( new SecuritySettingsChecker( new FakeZoomClient( $settings ) ) )->drift();

		$paths = array_column( $drift, 'path' );
		$this->assertContains( 'recording.local_recording', $paths );
	}

	#[TestDox( 'M-22 · multiple simultaneous flips are all reported' )]
	public function test_multiple_drifts(): void {
		$settings = $this->compliant_settings();
		$settings['in_meeting']['private_chat']  = true;
		$settings['in_meeting']['file_transfer'] = true;
		$settings['recording']['local_recording'] = true;

		$drift = ( new SecuritySettingsChecker( new FakeZoomClient( $settings ) ) )->drift();

		$paths = array_column( $drift, 'path' );
		$this->assertContains( 'in_meeting.private_chat', $paths );
		$this->assertContains( 'in_meeting.file_transfer', $paths );
		$this->assertContains( 'recording.local_recording', $paths );
	}
}
