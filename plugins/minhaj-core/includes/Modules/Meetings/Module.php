<?php
/**
 * Meetings module bootstrap.
 *
 * Registers:
 *   • CreateMeetingsTables migration.
 *   • REST webhook endpoint (via WebhookController).
 *   • Recording-cloud handlers are DEFERRED per the plan — the events
 *     table catches them and process_pending_events marks unknown types
 *     as `ignored` so they don't block.
 *
 * ZoomClient is resolved through a filter so tests inject FakeZoomClient
 * and production gets HttpZoomClient by default.
 *
 * @package Minhaj\Modules\Meetings
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings;

use Minhaj\Access\AccessPolicy;
use Minhaj\Access\AccessRepository;
use Minhaj\Modules\Meetings\Migrations\CreateMeetingsTables;
use Minhaj\Modules\Meetings\Repository\MeetingsRepository;
use Minhaj\Modules\Meetings\Rest\WebhookController;
use Minhaj\Modules\Meetings\Zoom\HttpZoomClient;
use Minhaj\Modules\Meetings\Zoom\WebhookVerifier;
use Minhaj\Modules\Meetings\Zoom\ZoomClient;

defined( 'ABSPATH' ) || exit;

final class Module {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_filter( 'minhaj_core_register_migrations', array( self::class, 'contribute_migrations' ) );

		$repo   = new MeetingsRepository();
		$zoom   = self::resolve_zoom_client();
		$access = new AccessPolicy( new AccessRepository() );
		$svc    = new MeetingsService( $repo, $zoom, $access );

		( new WebhookController( $svc, WebhookVerifier::from_config() ) )->register();
	}

	/**
	 * @param array<int, \Minhaj\Migrations\Migration> $migrations
	 * @return array<int, \Minhaj\Migrations\Migration>
	 */
	public static function contribute_migrations( array $migrations ): array {
		$migrations[] = new CreateMeetingsTables();

		return $migrations;
	}

	private static function resolve_zoom_client(): ZoomClient {
		/**
		 * Filter · swap the ZoomClient implementation. Tests inject
		 * FakeZoomClient via this filter; production defaults to
		 * HttpZoomClient which reads the Server-to-Server OAuth
		 * credentials from wp-config.php constants (M-14).
		 *
		 * @param ZoomClient|null $client
		 */
		$client = apply_filters( 'minhaj_zoom_client', null );
		if ( $client instanceof ZoomClient ) {
			return $client;
		}

		return new HttpZoomClient();
	}
}
