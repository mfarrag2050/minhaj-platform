<?php
/**
 * spec-groups-v1 · batch lifecycle for `minhaj_batches.status`.
 *
 *   • planned — announced, not yet accepting registrations.
 *   • open    — accepting registrations.
 *   • running — cohort is in class.
 *   • closed  — finished; frozen for reporting.
 *
 * @package Minhaj\Modules\Groups\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Domain;

defined( 'ABSPATH' ) || exit;

final class BatchStatus {

	public const PLANNED = 'planned';
	public const OPEN    = 'open';
	public const RUNNING = 'running';
	public const CLOSED  = 'closed';

	/**
	 * @var array<int, string>
	 */
	private const ALL = array( self::PLANNED, self::OPEN, self::RUNNING, self::CLOSED );

	public static function is_valid( string $s ): bool {
		return in_array( $s, self::ALL, true );
	}

	/**
	 * @return array<int, string>
	 */
	public static function all(): array {
		return self::ALL;
	}
}
