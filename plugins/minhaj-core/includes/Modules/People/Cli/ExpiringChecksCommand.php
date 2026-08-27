<?php
/**
 * `wp minhaj people expiring-checks [--within=<days>]` — S-5 report of
 * safeguarding checks nearing expiry. Read-only. Feeds cron dashboards and
 * ad-hoc admin sweeps.
 *
 * @package Minhaj\Modules\People\Cli
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People\Cli;

use Minhaj\Modules\People\Repository\PeopleRepository;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class ExpiringChecksCommand {

	public function __construct( private readonly PeopleRepository $repo ) {}

	/**
	 * ## OPTIONS
	 *
	 * [--within=<days>]
	 * : Look-ahead window in days. Default 60 (matches the S-5 cron scanner).
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, csv, yaml, count. Default: table.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$within = isset( $assoc_args['within'] ) ? max( 1, (int) $assoc_args['within'] ) : 60;
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$today    = gmdate( 'Y-m-d' );
		$deadline = gmdate( 'Y-m-d', strtotime( '+' . $within . ' days' ) );

		$rows = $this->repo->list_checks_expiring_between( $today, $deadline );

		if ( array() === $rows ) {
			WP_CLI::success( sprintf( 'No safeguarding checks expiring within %d days.', $within ) );
			return;
		}

		WP_CLI\Utils\format_items(
			$format,
			$rows,
			array( 'id', 'teacher_id', 'check_type', 'reference', 'issued_at', 'expires_at', 'status' )
		);

		WP_CLI::warning(
			sprintf( '%d check(s) expiring within %d days.', count( $rows ), $within )
		);
	}
}
