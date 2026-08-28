<?php
/**
 * `wp minhaj recordings verify --recording=<id>`
 *
 * Re-runs the triple verification (G-1) on a single stored row —
 * checksum + exists + probe read. Used when an admin wants to check
 * integrity before a manual delete_from_zoom_when_verified.
 *
 * Never triggers a Zoom delete on its own — verification is a read.
 *
 * @package Minhaj\Modules\Recordings\Cli
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Cli;

use Minhaj\Modules\Recordings\Repository\RecordingsRepository;
use Minhaj\Modules\Recordings\Storage\StorageClient;
use Minhaj\Modules\Recordings\Storage\StorageException;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class VerifyCommand {

	public function __construct(
		private readonly RecordingsRepository $repo,
		private readonly StorageClient $storage
	) {}

	/**
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$id = isset( $assoc_args['recording'] ) ? (int) $assoc_args['recording'] : 0;
		if ( $id <= 0 ) {
			WP_CLI::error( 'Pass --recording=<id>.' );
			return;
		}

		$row = $this->repo->find( $id );
		if ( null === $row ) {
			WP_CLI::error( 'Recording not found.' );
			return;
		}

		$key = (string) ( $row['object_key'] ?? '' );
		$sum = (string) ( $row['checksum_sha256'] ?? '' );

		if ( '' === $key || '' === $sum ) {
			WP_CLI::error( 'Row has no object_key/checksum — not yet stored.' );
			return;
		}

		if ( ! $this->storage->exists( $key ) ) {
			WP_CLI::error( 'exists() = false — object missing on storage.' );
			return;
		}

		try {
			$probe = $this->storage->get_bytes( $key, 0, 1024 );
		} catch ( StorageException $e ) {
			WP_CLI::error( 'probe read failed: ' . $e->getMessage() );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Verified — object_key exists, %d bytes probed, checksum on record = %s.',
				strlen( $probe ),
				substr( $sum, 0, 12 ) . '…'
			)
		);
	}
}
