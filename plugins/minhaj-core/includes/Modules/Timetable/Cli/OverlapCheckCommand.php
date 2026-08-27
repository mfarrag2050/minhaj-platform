<?php
/**
 * `wp minhaj timetable overlap-check` — nightly guard for spec-timetable-v1
 * §7 R-5. MySQL cannot enforce range-exclusion at the schema level, so the
 * app-level transactional lock in TimetableService::generate_for_group is
 * necessary but not sufficient. This CLI command sweeps the whole sessions
 * table looking for teacher-window overlaps and exits non-zero on any hit.
 *
 * Meant to run under cron (systemd timer, wp-cli cron event, …). It only
 * reports — no writes, no destructive action. Cleanup is a human decision.
 *
 * @package Minhaj\Modules\Timetable\Cli
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable\Cli;

use Minhaj\Modules\Timetable\Repository\TimetableRepository;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class OverlapCheckCommand {

	public function __construct( private readonly TimetableRepository $repo ) {}

	/**
	 * Report every pair of overlapping sessions per teacher.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, csv, yaml, count. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp minhaj timetable overlap-check
	 *     wp minhaj timetable overlap-check --format=json
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$overlaps = $this->repo->find_teacher_session_overlaps();

		if ( array() === $overlaps ) {
			WP_CLI::success( 'No teacher-session overlaps found.' );
			return;
		}

		WP_CLI\Utils\format_items(
			$format,
			$overlaps,
			array( 'teacher_id', 'id_a', 'group_a', 'start_a', 'end_a', 'id_b', 'group_b', 'start_b', 'end_b' )
		);

		WP_CLI::error(
			sprintf( '%d overlapping session pair(s) detected — spec §7 R-5 caveat triggered.', count( $overlaps ) ),
			(int) 1
		);
	}
}
