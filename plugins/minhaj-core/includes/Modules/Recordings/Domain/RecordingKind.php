<?php
/**
 * `session` vs `assessment` — spec §3.1 and §5.3 G-10.
 *
 * The kind drives retention and (potentially) the audience — right now
 * both follow قرار 11 (admin + owning teacher only) until §9.1 lands.
 *
 * @package Minhaj\Modules\Recordings\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Domain;

defined( 'ABSPATH' ) || exit;

final class RecordingKind {
	public const SESSION    = 'session';
	public const ASSESSMENT = 'assessment';

	/** @return array<int, string> */
	public static function all(): array {
		return array( self::SESSION, self::ASSESSMENT );
	}

	public static function is_valid( string $kind ): bool {
		return in_array( $kind, self::all(), true );
	}

	public static function default_retention_days( string $kind ): int {
		if ( self::ASSESSMENT === $kind ) {
			// Program end + 12 months — the caller supplies program end;
			// here we return a sane fallback (365 days) if no program
			// context is present. The service resolves the real anchor.
			return 365;
		}
		return 30;
	}
}
