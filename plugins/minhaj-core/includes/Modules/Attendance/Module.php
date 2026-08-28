<?php
/**
 * Attendance module bootstrap.
 *
 * Registers:
 *   • CreateAttendanceTables migration.
 *   • EventListener — subscribes to `minhaj_zoom_event_handled` and
 *     claims meeting.participant_joined / participant_left /
 *     meeting.ended so those webhook rows leave the events table with
 *     status `processed` instead of `ignored`.
 *
 * spec-attendance-v1 R-6 forbids importing TimetableService anywhere in
 * this module — no_show emits `minhaj_session_no_show` and lets the
 * Timetable module react in its own file.
 *
 * @package Minhaj\Modules\Attendance
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Attendance;

use Minhaj\Access\AccessPolicy;
use Minhaj\Access\AccessRepository;
use Minhaj\Modules\Attendance\Migrations\CreateAttendanceTables;
use Minhaj\Modules\Attendance\Repository\AttendanceRepository;

defined( 'ABSPATH' ) || exit;

final class Module {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_filter( 'minhaj_core_register_migrations', array( self::class, 'contribute_migrations' ) );

		$service = new AttendanceService(
			new AttendanceRepository(),
			new AccessPolicy( new AccessRepository() )
		);

		( new EventListener( $service ) )->register();
	}

	/**
	 * @param array<int, \Minhaj\Migrations\Migration> $migrations
	 * @return array<int, \Minhaj\Migrations\Migration>
	 */
	public static function contribute_migrations( array $migrations ): array {
		$migrations[] = new CreateAttendanceTables();

		return $migrations;
	}
}
