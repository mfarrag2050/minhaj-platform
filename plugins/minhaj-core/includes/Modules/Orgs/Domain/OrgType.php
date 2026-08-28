<?php
/**
 * spec-organizations-v1 §1 — two partner types, only one wired.
 *
 * `supplier` = we are Data Controller, they source teachers + students.
 * `licensee` = they are Data Controller, they license the platform.
 *
 * `licensee` is DECLARED here so the schema and enums can hold both, but
 * §5 O-11 locks it out of code paths: creating an org with type='licensee'
 * or data_controller='org' throws "unsupported" until the software-vendor
 * bundle described in §9.5 lands. The value exists to future-proof the
 * column, not to activate the type.
 *
 * @package Minhaj\Modules\Orgs\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Orgs\Domain;

defined( 'ABSPATH' ) || exit;

final class OrgType {

	public const SUPPLIER = 'supplier';
	public const LICENSEE = 'licensee';

	/**
	 * Types wired into the code. See class doc — LICENSEE is intentionally excluded.
	 *
	 * @var array<int, string>
	 */
	private const ENABLED = array( self::SUPPLIER );

	public static function is_valid( string $type ): bool {
		return in_array( $type, array( self::SUPPLIER, self::LICENSEE ), true );
	}

	public static function is_enabled( string $type ): bool {
		return in_array( $type, self::ENABLED, true );
	}

	public static function data_controller_for( string $type ): string {
		return self::LICENSEE === $type ? 'org' : 'us';
	}
}
