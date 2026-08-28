<?php
/**
 * Recordings module bootstrap.
 *
 * Registers:
 *   • CreateRecordingsTables migration.
 *   • AccessListener — subscribes to `minhaj_access_can_view_recording`.
 *   • WebhookListener — subscribes to `minhaj_zoom_event_handled`.
 *   • PurgeExpiredScanner cron.
 *   • CLI commands.
 *
 * StorageClient and RecordingsZoomClient are resolved through filters
 * so tests inject fakes and production wires the real clients.
 *
 * @package Minhaj\Modules\Recordings
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings;

use Minhaj\Access\AccessPolicy;
use Minhaj\Access\AccessRepository;
use Minhaj\Modules\Recordings\Cli\DownloadDueCommand;
use Minhaj\Modules\Recordings\Cli\OrphanCheckCommand;
use Minhaj\Modules\Recordings\Cli\PurgeExpiredCommand;
use Minhaj\Modules\Recordings\Cli\QuotaReportCommand;
use Minhaj\Modules\Recordings\Cli\VerifyCommand;
use Minhaj\Modules\Recordings\Cron\PurgeExpiredScanner;
use Minhaj\Modules\Recordings\Migrations\CreateRecordingsTables;
use Minhaj\Modules\Recordings\Repository\RecordingsRepository;
use Minhaj\Modules\Recordings\Storage\LocalStorageClient;
use Minhaj\Modules\Recordings\Storage\StorageClient;
use Minhaj\Modules\Recordings\Zoom\FakeRecordingsZoomClient;
use Minhaj\Modules\Recordings\Zoom\RecordingsZoomClient;
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

		$repo    = new RecordingsRepository();
		$storage = self::resolve_storage_client();
		$zoom    = self::resolve_zoom_client();
		$access  = new AccessPolicyAdapter( new AccessPolicy( new AccessRepository() ) );
		$service = new RecordingsService( $repo, $zoom, $storage, $access );

		// AccessPolicy · answers `minhaj_access_can_view_recording` with
		// admin + owning-teacher only. Registered unconditionally so
		// production and tests both use the same rule.
		( new AccessListener( $repo ) )->register();

		// WebhookListener · picks up `recording.completed` events from
		// Meetings' unhandled bucket and turns them into rows.
		( new WebhookListener( $service ) )->register();

		// Nightly purge.
		( new PurgeExpiredScanner( $service ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'minhaj recordings purge-expired', new PurgeExpiredCommand( $service, $repo ) );
			WP_CLI::add_command( 'minhaj recordings quota-report', new QuotaReportCommand( $service ) );
			WP_CLI::add_command( 'minhaj recordings orphan-check', new OrphanCheckCommand( $repo, $zoom ) );
			WP_CLI::add_command( 'minhaj recordings verify', new VerifyCommand( $repo, $storage ) );
			WP_CLI::add_command( 'minhaj recordings download-due', new DownloadDueCommand( $service ) );
		}
	}

	/**
	 * @param array<int, \Minhaj\Migrations\Migration> $migrations
	 * @return array<int, \Minhaj\Migrations\Migration>
	 */
	public static function contribute_migrations( array $migrations ): array {
		$migrations[] = new CreateRecordingsTables();
		return $migrations;
	}

	private static function resolve_storage_client(): StorageClient {
		/**
		 * Filter · swap the StorageClient. Tests inject a
		 * LocalStorageClient rooted in a temp dir; production wires
		 * whichever EU S3/Blob provider is chosen (spec §9.4).
		 *
		 * @param StorageClient|null $client
		 */
		$client = apply_filters( 'minhaj_recording_storage', null );
		if ( $client instanceof StorageClient ) {
			return $client;
		}

		// Local fallback — creates a directory under uploads. Fine for
		// wp-env / integration, useless for prod.
		$uploads = wp_upload_dir();
		return new LocalStorageClient(
			rtrim( (string) $uploads['basedir'], '/' ) . '/minhaj-recordings',
			(string) apply_filters( 'minhaj_recording_storage_region', 'eu-central-1' )
		);
	}

	private static function resolve_zoom_client(): RecordingsZoomClient {
		/**
		 * Filter · swap the RecordingsZoomClient. Tests inject
		 * FakeRecordingsZoomClient; production wires a real HTTP
		 * client when the Zoom plan enables cloud recording.
		 *
		 * @param RecordingsZoomClient|null $client
		 */
		$client = apply_filters( 'minhaj_recording_zoom_client', null );
		if ( $client instanceof RecordingsZoomClient ) {
			return $client;
		}

		// Default to the fake with an empty quota. Production MUST
		// override via the filter, otherwise no download / delete /
		// quota call will actually reach Zoom.
		return new FakeRecordingsZoomClient();
	}
}
