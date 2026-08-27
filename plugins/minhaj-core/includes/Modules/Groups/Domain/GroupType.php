<?php
/**
 * Group type values from spec-groups-v1 §3.1.
 *
 * @package Minhaj\Modules\Groups\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Domain;

defined( 'ABSPATH' ) || exit;

final class GroupType {

	public const INDIVIDUAL = 'individual';
	public const GROUP      = 'group';

	public static function is_valid( string $type ): bool {
		return self::INDIVIDUAL === $type || self::GROUP === $type;
	}

	/**
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array( self::INDIVIDUAL, self::GROUP );
	}
}
