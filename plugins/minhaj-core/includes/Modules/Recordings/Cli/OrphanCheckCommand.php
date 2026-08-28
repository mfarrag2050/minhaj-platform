<?php
/**
 * `wp minhaj recordings orphan-check`
 *
 * Lists Zoom cloud recordings that DO NOT have a row in
 * minhaj_recordings — those are dropped webhooks that would otherwise
 * consume Zoom quota silently. Exits non-zero when the list is
 * non-empty so cron can alert.
 *
 * @package Minhaj\Modules\Recordings\Cli
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Cli;

use Minhaj\Modules\Recordings\Repository\RecordingsRepository;
use Minhaj\Modules\Recordings\Zoom\RecordingsZoomClient;
use Minhaj\Modules\Recordings\Zoom\RecordingsZoomException;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class OrphanCheckCommand {

	public function __construct(
		private readonly RecordingsRepository $repo,
		private readonly RecordingsZoomClient $zoom
	) {}

	/**
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$from = isset( $assoc_args['from-days'] ) ? (int) $assoc_args['from-days'] : 30;

		try {
			$cloud = $this->zoom->list_cloud_recordings( $from );
		} catch ( RecordingsZoomException $e ) {
			WP_CLI::error( 'Zoom list failed: ' . $e->getMessage() );
			return;
		}

		$orphans = array();
		foreach ( $cloud as $row ) {
			$file_id = (string) ( $row['file_id'] ?? '' );
			if ( '' === $file_id ) {
				continue;
			}
			if ( null === $this->repo->find_by_zoom_file( $file_id ) ) {
				$orphans[] = $row;
			}
		}

		if ( array() === $orphans ) {
			WP_CLI::success( 'No orphans — every cloud recording has a row.' );
			return;
		}

		WP_CLI::log( sprintf( '%d orphan cloud recording(s) — missing webhook(s):', count( $orphans ) ) );
		WP_CLI\Utils\format_items(
			'table',
			$orphans,
			array( 'meeting_uuid', 'file_id', 'file_type', 'bytes', 'recording_end_utc' )
		);

		WP_CLI::halt( 1 );
	}
}
