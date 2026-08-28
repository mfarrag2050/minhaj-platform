<?php
/**
 * Lifecycle of a webhook row in `minhaj_zoom_events`.
 *
 *   • received  — landed via ingest; not yet touched by the processor.
 *   • processed — successfully dispatched to its handler.
 *   • ignored   — deliberately skipped (e.g. late meeting.started after ended).
 *   • failed    — retry cap exhausted.
 *
 * @package Minhaj\Modules\Meetings\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Domain;

defined( 'ABSPATH' ) || exit;

final class EventStatus {

	public const RECEIVED  = 'received';
	public const PROCESSED = 'processed';
	public const IGNORED   = 'ignored';
	public const FAILED    = 'failed';
}
