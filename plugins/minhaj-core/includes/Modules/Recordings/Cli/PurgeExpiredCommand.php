<?php
/**
 * `wp minhaj recordings purge-expired [--dry-run] [--limit=N]`
 *
 * The nightly cron calls the service directly; this command exists so
 * an admin can trigger the same code path on demand — with `--dry-run`
 * for a preview of the queue.
 *
 * @package Minhaj\Modules\Recordings\Cli
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Cli;

use Minhaj\Modules\Recordings\RecordingsService;
use Minhaj\Modules\Recordings\Repository\RecordingsRepository;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class PurgeExpiredCommand {

	public function __construct(
		private readonly RecordingsService $service,
		private readonly RecordingsRepository $repo
	) {}

	/**
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 200;
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( $dry_run ) {
			$today = current_time( 'Y-m-d', true );
			$rows  = $this->repo->list_due_for_purge( $limit, $today );

			if ( array() === $rows ) {
				WP_CLI::success( 'No rows past retention_until.' );
				return;
			}

			WP_CLI::log( sprintf( 'DRY-RUN: %d row(s) would be purged.', count( $rows ) ) );
			WP_CLI\Utils\format_items(
				'table',
				$rows,
				array( 'id', 'session_id', 'group_id', 'kind', 'retention_until', 'status', 'object_key' )
			);
			return;
		}

		$n = $this->service->purge_expired( $limit );
		WP_CLI::success( sprintf( 'Purged %d recording(s). Tombstones kept.', $n ) );
	}
}
