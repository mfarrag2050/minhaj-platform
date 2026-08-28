<?php
/**
 * Event names — spec §6. Every action name lives here so the static
 * hook-prefix sniff can be silenced with a single phpcs:disable at
 * the source and future refactors don't scatter typos.
 *
 * @package Minhaj\Modules\Recordings
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings;

defined( 'ABSPATH' ) || exit;

final class Events {
	public const REGISTERED        = 'minhaj_recording_registered';
	public const STORED            = 'minhaj_recording_stored';
	public const DOWNLOAD_FAILED   = 'minhaj_recording_download_failed';
	public const DELETED_FROM_ZOOM = 'minhaj_recording_deleted_from_zoom';
	public const PURGED            = 'minhaj_recording_purged';
	public const ACCESS_DENIED     = 'minhaj_recording_access_denied';
	public const QUOTA_WARNING     = 'minhaj_zoom_quota_warning';
	public const LEGAL_HOLD_SET    = 'minhaj_recording_legal_hold_set';
}
