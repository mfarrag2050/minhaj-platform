<?php
/**
 * spec-zoom-sessions-v1 §4 — meeting lifecycle. Distinct from the session
 * lifecycle (SessionStatus): a meeting can be `revoked` while its session
 * is `scheduled`, or `ended` while its session is `completed`.
 *
 * @package Minhaj\Modules\Meetings\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Domain;

defined( 'ABSPATH' ) || exit;

final class MeetingState {

	public const PENDING = 'pending';
	public const CREATED = 'created';
	public const STARTED = 'started';
	public const ENDED   = 'ended';
	public const FAILED  = 'failed';
	public const REVOKED = 'revoked';

	/**
	 * @var array<string, array<int, string>>
	 */
	private const TRANSITIONS = array(
		self::PENDING => array( self::CREATED, self::FAILED, self::REVOKED ),
		self::CREATED => array( self::STARTED, self::REVOKED, self::FAILED ),
		self::STARTED => array( self::STARTED, self::ENDED ), // reentry allowed for the rejoin-grace window
		self::ENDED   => array(),
		self::FAILED  => array(),
		self::REVOKED => array(),
	);

	public static function is_valid( string $state ): bool {
		return array_key_exists( $state, self::TRANSITIONS );
	}

	public static function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}
}
