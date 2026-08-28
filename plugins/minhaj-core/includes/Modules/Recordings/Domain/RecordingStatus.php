<?php
/**
 * Lifecycle states — spec §4.
 *
 * pending → downloading → stored → zoom_deleted → purged.
 * failed  ← anywhere in the download path (visible in reports).
 * legal_hold ← manual freeze that blocks purge (G-8).
 *
 * @package Minhaj\Modules\Recordings\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Domain;

defined( 'ABSPATH' ) || exit;

final class RecordingStatus {
	public const PENDING      = 'pending';
	public const DOWNLOADING  = 'downloading';
	public const STORED       = 'stored';
	public const ZOOM_DELETED = 'zoom_deleted';
	public const PURGED       = 'purged';
	public const FAILED       = 'failed';
	public const LEGAL_HOLD   = 'legal_hold';

	/** @return array<int, string> */
	public static function all(): array {
		return array(
			self::PENDING,
			self::DOWNLOADING,
			self::STORED,
			self::ZOOM_DELETED,
			self::PURGED,
			self::FAILED,
			self::LEGAL_HOLD,
		);
	}

	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}
}
