<?php
/**
 * Orgs module role registration — spec-organizations-v1 §7.
 *
 * Installs `minhaj_org_admin`: a partner-side administrator who sees only
 * their org's teachers, groups, links, and attribution counts. The role
 * carries no capabilities directly here — Access\Capabilities grants
 * MANAGE_ORG + VIEW_GROUP to it on install so all capability wiring stays
 * in one place.
 *
 * Never removed on plugin deactivation — losing the tag would silently
 * unassign every partner-admin already in the system.
 *
 * @package Minhaj\Modules\Orgs
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Orgs;

defined( 'ABSPATH' ) || exit;

final class Roles {

	public const ORG_ADMIN = 'minhaj_org_admin';

	public static function install(): void {
		if ( null === get_role( self::ORG_ADMIN ) ) {
			add_role(
				self::ORG_ADMIN,
				__( 'Organisation Admin', 'minhaj-core' ),
				array( 'read' => true )
			);
		}
	}

	public static function org_admin_role(): string {
		return (string) apply_filters( 'minhaj_orgs_admin_role', self::ORG_ADMIN );
	}
}
