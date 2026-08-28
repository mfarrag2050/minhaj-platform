<?php
/**
 * spec-organizations-v1 §3.3 — the roles a person can hold inside a
 * partner org. Distinct from WP roles: these describe *what they do for
 * the org*, not what capabilities they carry on the platform. Cap grants
 * happen through Access\Capabilities + Orgs\Roles.
 *
 * @package Minhaj\Modules\Orgs\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Orgs\Domain;

defined( 'ABSPATH' ) || exit;

final class MembershipRole {

	public const ORG_ADMIN   = 'org_admin';
	public const TEACHER     = 'teacher';
	public const COORDINATOR = 'coordinator';

	/**
	 * @var array<int, string>
	 */
	private const ALL = array( self::ORG_ADMIN, self::TEACHER, self::COORDINATOR );

	public static function is_valid( string $role ): bool {
		return in_array( $role, self::ALL, true );
	}
}
