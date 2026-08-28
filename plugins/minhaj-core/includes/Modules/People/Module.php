<?php
/**
 * People module bootstrap.
 *
 * Registers:
 *   • CreatePeopleTables migration.
 *   • AssignabilityGate — the S-4 filter subscriber the Groups module calls.
 *   • ExpiringChecksScanner — S-5 daily cron.
 *   • CLI: language-coverage, expiring-checks.
 *
 * Admin UI comes in a follow-up pass.
 *
 * @package Minhaj\Modules\People
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People;

use Minhaj\Modules\People\Cli\ExpiringChecksCommand;
use Minhaj\Modules\People\Cli\LanguageCoverageCommand;
use Minhaj\Modules\People\Cron\ExpiringChecksScanner;
use Minhaj\Modules\People\Migrations\CreatePeopleTables;
use Minhaj\Modules\People\Migrations\RestructureStudentsForNonWpIdentity;
use Minhaj\Modules\People\Repository\PeopleRepository;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Module {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_filter( 'minhaj_core_register_migrations', array( self::class, 'contribute_migrations' ) );

		$repo    = new PeopleRepository();
		$service = new PeopleService( $repo );

		// S-4 gate: subscribes to Groups's minhaj_group_can_assign_teacher filter.
		( new AssignabilityGate( $service ) )->register();

		// S-5 daily cron: fires minhaj_check_expiring per row within 60 days.
		( new ExpiringChecksScanner( $repo ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'minhaj people language-coverage', new LanguageCoverageCommand( $service ) );
			WP_CLI::add_command( 'minhaj people expiring-checks', new ExpiringChecksCommand( $repo ) );
		}
	}

	/**
	 * @param array<int, \Minhaj\Migrations\Migration> $migrations
	 * @return array<int, \Minhaj\Migrations\Migration>
	 */
	public static function contribute_migrations( array $migrations ): array {
		$migrations[] = new CreatePeopleTables();
		$migrations[] = new RestructureStudentsForNonWpIdentity();

		return $migrations;
	}
}
