<?php
/**
 * Policy-layer checks for the invariants in spec-groups-v1 §5.
 *
 * These sit above the DB constraints, not in place of them. R-1 is enforced
 * ultimately by the unique index on the members table; this class just gives
 * higher layers a fast, clean early rejection with a rule code.
 *
 * @package Minhaj\Modules\Groups\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Domain;

defined( 'ABSPATH' ) || exit;

/*
 * Exception messages here are developer-facing (logs, error reporters). They
 * carry integers and validated domain enums only — never user-provided HTML.
 * Escaping them as HTML would corrupt log output, so the WPCS output-escape
 * sniff is disabled for this file. The service layer is responsible for
 * translating rule codes into user-facing text.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class GroupRules {

	/**
	 * R-1 · An active membership count must never reach capacity_max.
	 *
	 * @throws RuleViolationException When adding one more would exceed capacity_max.
	 */
	public static function assert_seat_available( int $active_count, int $capacity_max ): void {
		if ( $active_count < 0 || $capacity_max < 1 ) {
			throw new RuleViolationException(
				'R-1',
				sprintf( 'invalid counts: active=%d, capacity_max=%d', $active_count, $capacity_max )
			);
		}

		if ( $active_count >= $capacity_max ) {
			throw new RuleViolationException(
				'R-1',
				sprintf(
					'no seat available: active memberships %d already at capacity_max %d',
					$active_count,
					$capacity_max
				)
			);
		}
	}

	/**
	 * R-2 · Cannot transition to `scheduled` below capacity_min.
	 *
	 * @throws RuleViolationException When active_count < capacity_min.
	 */
	public static function assert_ready_to_schedule( int $active_count, int $capacity_min ): void {
		if ( $capacity_min < 1 ) {
			throw new RuleViolationException(
				'R-2',
				sprintf( 'invalid capacity_min: %d', $capacity_min )
			);
		}

		if ( $active_count < $capacity_min ) {
			throw new RuleViolationException(
				'R-2',
				sprintf(
					'cannot schedule with only %d active members (capacity_min=%d)',
					$active_count,
					$capacity_min
				)
			);
		}
	}

	/**
	 * R-3 · `type=individual` ⇒ capacity_min = capacity_max = 1.
	 *
	 * Also validates the group-type envelope: min ≤ max, both ≥ 1, and max ≤ HARD_CAP.
	 *
	 * @throws RuleViolationException On any capacity/type mismatch.
	 */
	public static function assert_capacity_matches_type( string $type, int $capacity_min, int $capacity_max ): void {
		if ( ! GroupType::is_valid( $type ) ) {
			throw new RuleViolationException(
				'R-3',
				sprintf( 'unknown group type: %s', $type )
			);
		}

		if ( GroupType::INDIVIDUAL === $type ) {
			if ( GroupCapacity::INDIVIDUAL_SIZE !== $capacity_min || GroupCapacity::INDIVIDUAL_SIZE !== $capacity_max ) {
				throw new RuleViolationException(
					'R-3',
					sprintf(
						'individual groups require capacity_min=capacity_max=%d, got %d/%d',
						GroupCapacity::INDIVIDUAL_SIZE,
						$capacity_min,
						$capacity_max
					)
				);
			}

			return;
		}

		if ( $capacity_min < 1 || $capacity_max < 1 ) {
			throw new RuleViolationException(
				'R-3',
				sprintf( 'capacity values must be ≥ 1 (got min=%d, max=%d)', $capacity_min, $capacity_max )
			);
		}

		if ( $capacity_min > $capacity_max ) {
			throw new RuleViolationException(
				'R-3',
				sprintf( 'capacity_min (%d) cannot exceed capacity_max (%d)', $capacity_min, $capacity_max )
			);
		}

		if ( $capacity_max > GroupCapacity::HARD_CAP ) {
			throw new RuleViolationException(
				'R-3',
				sprintf(
					'capacity_max %d exceeds hard cap %d — deliberate code change required',
					$capacity_max,
					GroupCapacity::HARD_CAP
				)
			);
		}
	}
}
