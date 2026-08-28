<?php
/**
 * Plugin activation handler.
 *
 * @package Minhaj
 */

declare( strict_types=1 );

namespace Minhaj;

use Minhaj\Access\Capabilities as AccessCapabilities;
use Minhaj\Migrations\Migrator;
use Minhaj\Modules\Groups\Roles;
use Minhaj\Modules\Orgs\Roles as OrgsRoles;
use Minhaj\Modules\People\Roles as PeopleRoles;

defined( 'ABSPATH' ) || exit;

final class Activator {

	private const ADMIN_CAPS = array(
		\Minhaj\Modules\Groups\Admin\AdminController::CAPABILITY,
	);

	public static function activate(): void {
		// Modules must contribute their migrations before the Migrator collects them.
		Plugin::instance()->register_modules();

		Migrator::instance()->install();

		self::grant_admin_capabilities();

		Roles::install();
		PeopleRoles::install();
		OrgsRoles::install();

		// Roles must exist before we hang capabilities on them.
		AccessCapabilities::install();
		self::grant_org_admin_capabilities();
	}

	private static function grant_org_admin_capabilities(): void {
		$role = get_role( OrgsRoles::org_admin_role() );
		if ( null === $role ) {
			return;
		}

		foreach ( array( AccessCapabilities::MANAGE_ORG, AccessCapabilities::VIEW_GROUP ) as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	private static function grant_admin_capabilities(): void {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return;
		}

		foreach ( self::ADMIN_CAPS as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}
}
