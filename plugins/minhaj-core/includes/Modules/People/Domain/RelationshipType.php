<?php
/**
 * Guardianship relationship values from spec-people-v1 §2.1.
 *
 * @package Minhaj\Modules\People\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People\Domain;

defined( 'ABSPATH' ) || exit;

final class RelationshipType {

	public const PARENT         = 'parent';
	public const LEGAL_GUARDIAN = 'legal_guardian';
	public const OTHER          = 'other';

	public static function is_valid( string $type ): bool {
		return in_array( $type, array( self::PARENT, self::LEGAL_GUARDIAN, self::OTHER ), true );
	}
}
