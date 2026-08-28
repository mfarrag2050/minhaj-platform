<?php
/**
 * Orgs module bootstrap.
 *
 * Registers:
 *   • CreateOrgsTables + AddOrgDimension migrations.
 *   • Roles::install() — the `minhaj_org_admin` tenure tag.
 *
 * Admin UI, WP-CLI commands, and the org-scoped list tables come with
 * spec-organizations-v1 §7 / §9 in follow-up passes. Nothing on this
 * module hooks the runtime yet — spec §2 keeps the phase-2 scope tight.
 *
 * @package Minhaj\Modules\Orgs
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Orgs;

use Minhaj\Modules\Orgs\Migrations\AddOrgDimension;
use Minhaj\Modules\Orgs\Migrations\CreateOrgsTables;

defined( 'ABSPATH' ) || exit;

final class Module {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_filter( 'minhaj_core_register_migrations', array( self::class, 'contribute_migrations' ) );
	}

	/**
	 * @param array<int, \Minhaj\Migrations\Migration> $migrations
	 * @return array<int, \Minhaj\Migrations\Migration>
	 */
	public static function contribute_migrations( array $migrations ): array {
		$migrations[] = new CreateOrgsTables();
		$migrations[] = new AddOrgDimension();

		return $migrations;
	}
}
