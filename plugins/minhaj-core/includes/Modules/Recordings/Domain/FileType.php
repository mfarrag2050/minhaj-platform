<?php
/**
 * Zoom recording file types — spec §3.1.
 *
 * TRANSCRIPT and CHAT ride the same lifecycle as MP4/M4A (§7: chat text
 * inside a session with children is as sensitive as the video).
 *
 * @package Minhaj\Modules\Recordings\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Domain;

defined( 'ABSPATH' ) || exit;

final class FileType {
	public const MP4        = 'MP4';
	public const M4A        = 'M4A';
	public const TRANSCRIPT = 'TRANSCRIPT';
	public const CHAT       = 'CHAT';

	/** @return array<int, string> */
	public static function all(): array {
		return array( self::MP4, self::M4A, self::TRANSCRIPT, self::CHAT );
	}

	public static function is_valid( string $type ): bool {
		return in_array( strtoupper( $type ), self::all(), true );
	}
}
