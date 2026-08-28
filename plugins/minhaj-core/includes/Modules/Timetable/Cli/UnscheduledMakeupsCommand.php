<?php
/**
 * `wp minhaj timetable unscheduled-makeups` — surfaces every pending
 * make-up debt (spec §5 fallback path). Cancel operations that could not
 * find a slot within the walker cap record the make-up as `unscheduled`;
 * this command is how admin discovers what still owes an hour to a family.
 *
 * Read-only. No side effects. Meant for cron dashboards and manual checks.
 *
 * @package Minhaj\Modules\Timetable\Cli
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable\Cli;

use Minhaj\Modules\Timetable\Repository\TimetableRepository;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class UnscheduledMakeupsCommand {

	public function __construct( private readonly TimetableRepository $repo ) {}

	/**
	 * List pending unscheduled make-ups.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, csv, yaml, count. Default: table.
	 *
	 * [--limit=<limit>]
	 * : Cap on rows returned. Default 500.
	 *
	 * ## EXAMPLES
	 *
	 *     wp minhaj timetable unscheduled-makeups
	 *     wp minhaj timetable unscheduled-makeups --format=json --limit=50
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 500;

		$rows    = $this->repo->list_unscheduled_makeups( $limit );
		$orphans = $this->repo->list_no_show_sessions_without_makeup( $limit );

		if ( array() === $rows && array() === $orphans ) {
			WP_CLI::success( 'No unscheduled make-ups pending — debt queue is empty.' );
			return;
		}

		if ( array() !== $rows ) {
			WP_CLI::log( '' );
			WP_CLI::log( '== Unscheduled make-ups (explicit debt queue) ==' );
			WP_CLI\Utils\format_items(
				$format,
				$rows,
				array( 'id', 'group_id', 'sequence_no', 'lesson_no', 'teacher_id', 'makeup_for_id', 'anchor_timezone', 'created_at' )
			);
			WP_CLI::warning(
				sprintf( '%d unscheduled make-up(s) pending — call TimetableService::schedule_makeup() to assign a time.', count( $rows ) )
			);
		}

		if ( array() !== $orphans ) {
			// A no_show session with no make-up row is a reconciliation
			// gap: the post-COMMIT listener that should have written the
			// row either never fired (crashed worker, deferred queue
			// dropped) or was disabled. The gap is silent — no error
			// surfaces at attendance time — so the CLI is the safety
			// net. We exit non-zero so cron alerts on it.
			WP_CLI::log( '' );
			WP_CLI::log( '== no_show sessions with NO make-up row (reconciliation gap) ==' );
			WP_CLI\Utils\format_items(
				$format,
				$orphans,
				array( 'id', 'group_id', 'sequence_no', 'lesson_no', 'teacher_id', 'anchor_timezone', 'scheduled_start_utc' )
			);
			WP_CLI::warning(
				sprintf(
					'%d no_show session(s) have no make-up row — run the make-up backfill or investigate the missing NoShowMakeupListener.',
					count( $orphans )
				)
			);
			WP_CLI::halt( 1 );
		}
	}
}
