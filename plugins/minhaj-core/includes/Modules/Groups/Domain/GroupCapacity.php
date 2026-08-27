<?php
/**
 * Group capacity constants.
 *
 * Group size is fixed at 3–5 for `type=group` per operational numbers v0.1,
 * and 1 for `type=individual`. Per user directive 2026-08-27 these values
 * are frozen on the group row at creation — the domain does not read any
 * global option.
 *
 * @package Minhaj\Modules\Groups\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Domain;

defined( 'ABSPATH' ) || exit;

final class GroupCapacity {

	public const GROUP_DEFAULT_MIN = 3;
	public const GROUP_DEFAULT_MAX = 5;
	public const HARD_CAP          = 6;
	public const INDIVIDUAL_SIZE   = 1;

	/**
	 * @return array{min:int,max:int}
	 */
	public static function defaults_for_type( string $type ): array {
		if ( GroupType::INDIVIDUAL === $type ) {
			return array(
				'min' => self::INDIVIDUAL_SIZE,
				'max' => self::INDIVIDUAL_SIZE,
			);
		}

		return array(
			'min' => self::GROUP_DEFAULT_MIN,
			'max' => self::GROUP_DEFAULT_MAX,
		);
	}
}
