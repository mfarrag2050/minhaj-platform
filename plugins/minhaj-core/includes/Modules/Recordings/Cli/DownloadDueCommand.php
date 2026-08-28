<?php
/**
 * `wp minhaj recordings download-due [--limit=N]`
 *
 * On-demand trigger of the nightly download queue. In production the
 * caller does NOT supply bearers — the command reports rows whose
 * bearer has expired ("skipped_no_bearer") so a follow-up can
 * re-fetch them via `list_cloud_recordings`.
 *
 * @package Minhaj\Modules\Recordings\Cli
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Cli;

use Minhaj\Modules\Recordings\RecordingsService;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class DownloadDueCommand {

	public function __construct( private readonly RecordingsService $service ) {}

	/**
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50;

		$results = $this->service->download_due( $limit );

		if ( array() === $results ) {
			WP_CLI::success( 'Download queue empty.' );
			return;
		}

		$table = array();
		foreach ( $results as $id => $status ) {
			$table[] = array(
				'id'     => $id,
				'status' => $status,
			);
		}
		WP_CLI\Utils\format_items( 'table', $table, array( 'id', 'status' ) );

		$failed = array_filter( $results, static fn( $s ) => 'failed' === $s );
		if ( array() !== $failed ) {
			WP_CLI::warning( sprintf( '%d row(s) failed — check audit rows.', count( $failed ) ) );
		}
	}
}
