<?php
/**
 * Group lifecycle states and the transition map from spec-groups-v1 §4.
 *
 * @package Minhaj\Modules\Groups\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Domain;

defined( 'ABSPATH' ) || exit;

final class GroupStatus {

	public const DRAFT     = 'draft';
	public const FORMING   = 'forming';
	public const SCHEDULED = 'scheduled';
	public const ACTIVE    = 'active';
	public const SUSPENDED = 'suspended';
	public const COMPLETED = 'completed';
	public const CANCELLED = 'cancelled';

	/**
	 * Allowed transitions per spec §4. `completed` and `cancelled` are terminal.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const TRANSITIONS = array(
		self::DRAFT     => array( self::FORMING ),
		self::FORMING   => array( self::SCHEDULED, self::CANCELLED ),
		self::SCHEDULED => array( self::ACTIVE, self::CANCELLED ),
		self::ACTIVE    => array( self::COMPLETED, self::SUSPENDED ),
		self::SUSPENDED => array( self::ACTIVE, self::CANCELLED ),
		self::COMPLETED => array(),
		self::CANCELLED => array(),
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
