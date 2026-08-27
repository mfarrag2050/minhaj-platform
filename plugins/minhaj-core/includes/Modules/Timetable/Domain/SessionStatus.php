<?php
/**
 * Session lifecycle states from spec-timetable-v1 §6.
 *
 * `suspended` is deliberately NOT a session status — it lives on the group
 * (spec-groups §4). Suspending a group leaves its future sessions in place.
 *
 * @package Minhaj\Modules\Timetable\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable\Domain;

defined( 'ABSPATH' ) || exit;

final class SessionStatus {

	public const SCHEDULED   = 'scheduled';
	public const LIVE        = 'live';
	public const COMPLETED   = 'completed';
	public const CANCELLED   = 'cancelled';
	public const RESCHEDULED = 'rescheduled';
	public const NO_SHOW     = 'no_show';

	/**
	 * Allowed transitions per spec §6. Terminal states have no outgoing edges.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const TRANSITIONS = array(
		self::SCHEDULED   => array( self::LIVE, self::CANCELLED, self::RESCHEDULED, self::NO_SHOW ),
		self::LIVE        => array( self::COMPLETED ),
		self::COMPLETED   => array(),
		self::CANCELLED   => array(),
		self::RESCHEDULED => array(),
		self::NO_SHOW     => array(),
	);

	public static function is_valid( string $status ): bool {
		return array_key_exists( $status, self::TRANSITIONS );
	}

	public static function is_terminal( string $status ): bool {
		return self::is_valid( $status ) && array() === self::TRANSITIONS[ $status ];
	}

	/**
	 * @return array<int, string>
	 */
	public static function allowed_transitions( string $from ): array {
		return self::TRANSITIONS[ $from ] ?? array();
	}

	public static function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::allowed_transitions( $from ), true );
	}

	/**
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array_keys( self::TRANSITIONS );
	}
}
