<?php
/**
 * spec-calendar-v1 §2.1 — a calendar is either `active` (attached groups
 * consult it) or `archived` (kept for audit, but new generation ignores).
 *
 * @package Minhaj\Modules\Calendar\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Calendar\Domain;

defined( 'ABSPATH' ) || exit;

final class CalendarStatus {

	public const ACTIVE   = 'active';
	public const ARCHIVED = 'archived';

	/**
	 * @var array<int, string>
	 */
	private const ALL = array( self::ACTIVE, self::ARCHIVED );

	public static function is_valid( string $status ): bool {
		return in_array( $status, self::ALL, true );
	}
}
