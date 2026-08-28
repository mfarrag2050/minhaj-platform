<?php
/**
 * M-22 · account-level security settings verified periodically.
 *
 * A guard we never check decays silently — someone with Zoom-admin
 * privileges may flip a switch that leaves private-chat allowed between
 * minors and adults, and no one on our side notices until a complaint.
 * This class defines the required values and returns the delta between
 * the Zoom account state and what the spec demands.
 *
 * The check itself does not "fix" anything on the Zoom side (attempting
 * to would need a second write API surface with its own audit risk).
 * It reports; the human decides.
 *
 * @package Minhaj\Modules\Meetings\Zoom
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Zoom;

defined( 'ABSPATH' ) || exit;

final class SecuritySettingsChecker {

	/**
	 * Required values expressed as (dot-path, expected). Matches the shape
	 * Zoom returns from GET /accounts/me/settings.
	 *
	 * @var array<string, mixed>
	 */
	private const REQUIRED = array(
		'in_meeting.private_chat'   => false,
		'in_meeting.chat'           => true,
		'in_meeting.file_transfer'  => false,
		'in_meeting.allow_participants_to_rename_themselves' => false,
		'in_meeting.waiting_room'   => true,
		'recording.local_recording' => false,
	);

	public function __construct( private readonly ZoomClient $client ) {}

	/**
	 * @return array<int, array{path:string, expected:mixed, actual:mixed}> Empty = compliant.
	 */
	public function drift(): array {
		$settings = $this->client->get_account_settings();

		$drift = array();
		foreach ( self::REQUIRED as $path => $expected ) {
			$actual = $this->lookup( $settings, $path );
			if ( $expected !== $actual ) {
				$drift[] = array(
					'path'     => $path,
					'expected' => $expected,
					'actual'   => $actual,
				);
			}
		}

		return $drift;
	}

	/**
	 * @param array<string, mixed> $haystack
	 */
	private function lookup( array $haystack, string $dotted ): mixed {
		$parts  = explode( '.', $dotted );
		$cursor = $haystack;
		foreach ( $parts as $part ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $part, $cursor ) ) {
				return null;
			}
			$cursor = $cursor[ $part ];
		}

		return $cursor;
	}
}
