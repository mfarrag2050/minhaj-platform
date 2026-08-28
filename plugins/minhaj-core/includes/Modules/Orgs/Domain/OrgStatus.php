<?php
/**
 * spec-organizations-v1 §3.1 — lifecycle states for a partner org.
 *
 * `suspended` (O-6) is deliberately not `closed`: an in-flight group under
 * a suspended org keeps running to term. What suspension blocks is *new*
 * registration and *new* group assignment; existing children in a paid
 * programme are not cut off over a commercial dispute between us and a
 * partner.
 *
 * @package Minhaj\Modules\Orgs\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Orgs\Domain;

defined( 'ABSPATH' ) || exit;

final class OrgStatus {

	public const ACTIVE    = 'active';
	public const SUSPENDED = 'suspended';
	public const CLOSED    = 'closed';

	/**
	 * @var array<int, string>
	 */
	private const ALL = array( self::ACTIVE, self::SUSPENDED, self::CLOSED );

	public static function is_valid( string $status ): bool {
		return in_array( $status, self::ALL, true );
	}
}
