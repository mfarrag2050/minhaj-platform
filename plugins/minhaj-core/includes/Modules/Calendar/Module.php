<?php
/**
 * Calendar module bootstrap.
 *
 * Registers:
 *   • CreateCalendarTables + AddCalendarFieldsToGroups migrations.
 *   • GenerationHooks — the bridge that answers Timetable's filters.
 *
 * Admin UI, WP-CLI stale-report command, and §4 recalculate_from land in
 * follow-up passes. The staleness report exists as a service method now
 * (list_stale_calendars) so a CLI or REST wrapper is a thin adapter.
 *
 * @package Minhaj\Modules\Calendar
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Calendar;

use Minhaj\Modules\Calendar\Migrations\AddCalendarFieldsToGroups;
use Minhaj\Modules\Calendar\Migrations\CreateCalendarTables;
use Minhaj\Modules\Calendar\Repository\CalendarRepository;

defined( 'ABSPATH' ) || exit;

final class Module {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_filter( 'minhaj_core_register_migrations', array( self::class, 'contribute_migrations' ) );

		$service = new CalendarService( new CalendarRepository() );
		( new GenerationHooks( $service ) )->register();
	}

	/**
	 * @param array<int, \Minhaj\Migrations\Migration> $migrations
	 * @return array<int, \Minhaj\Migrations\Migration>
	 */
	public static function contribute_migrations( array $migrations ): array {
		$migrations[] = new CreateCalendarTables();
		$migrations[] = new AddCalendarFieldsToGroups();

		return $migrations;
	}
}
