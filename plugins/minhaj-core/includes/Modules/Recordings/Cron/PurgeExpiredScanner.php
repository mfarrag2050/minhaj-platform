<?php
/**
 * Nightly purge — G-6.
 *
 * Wired via WP-Cron: single `minhaj_recordings_purge_expired` event
 * scheduled daily. The event handler calls RecordingsService::purge_expired
 * with a batch size; if the queue is longer, the next night picks up
 * the remainder.
 *
 * @package Minhaj\Modules\Recordings\Cron
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Cron;

use Minhaj\Modules\Recordings\RecordingsService;

defined( 'ABSPATH' ) || exit;

final class PurgeExpiredScanner {

	public const HOOK = 'minhaj_recordings_purge_expired';

	public function __construct( private readonly RecordingsService $service ) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'schedule' ) );
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			// Runs at midnight UTC-ish; wp-cron is not real cron, but
			// the CLI command is the real safety net.
			wp_schedule_event( time() + 300, 'daily', self::HOOK );
		}
	}

	public function run(): void {
		$limit = (int) apply_filters( 'minhaj_recordings_purge_batch', 200 );
		$this->service->purge_expired( $limit );
	}
}
