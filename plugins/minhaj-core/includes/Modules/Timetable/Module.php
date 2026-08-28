<?php
/**
 * Timetable module bootstrap.
 *
 * Registers the schema migrations and the wp-cli guards (overlap-check for
 * spec §7 R-5, unscheduled-makeups for the §5 debt queue).
 *
 * Admin UI and the remaining §8 service methods (reschedule,
 * regenerate_future, teacher_load) come in a follow-up pass.
 *
 * @package Minhaj\Modules\Timetable
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable;

use Minhaj\Modules\Timetable\Cli\OverlapCheckCommand;
use Minhaj\Modules\Timetable\Cli\UnscheduledMakeupsCommand;
use Minhaj\Modules\Timetable\Migrations\AlterSessionsForUnscheduledMakeups;
use Minhaj\Modules\Timetable\Migrations\CreateTimetableTables;
use Minhaj\Modules\Timetable\Repository\TimetableRepository;
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

		// Derived-dates listener: single source for expected_end_date +
		// has_unscheduled_makeup on the groups table. Wired unconditionally
		// so the values stay current in admin, cron, CLI, and REST alike.
		$repo = new TimetableRepository();
		( new SessionDerivedDatesListener( $repo ) )->register();

		// no_show → unscheduled make-up row. Post-commit; the CLI catches
		// the gap when this listener fails.
		( new NoShowMakeupListener( $repo ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$repo = new TimetableRepository();
			WP_CLI::add_command( 'minhaj timetable overlap-check', new OverlapCheckCommand( $repo ) );
			WP_CLI::add_command( 'minhaj timetable unscheduled-makeups', new UnscheduledMakeupsCommand( $repo ) );
		}
	}

	/**
	 * @param array<int, \Minhaj\Migrations\Migration> $migrations
	 * @return array<int, \Minhaj\Migrations\Migration>
	 */
	public static function contribute_migrations( array $migrations ): array {
		$migrations[] = new CreateTimetableTables();
		$migrations[] = new AlterSessionsForUnscheduledMakeups();

		return $migrations;
	}
}
