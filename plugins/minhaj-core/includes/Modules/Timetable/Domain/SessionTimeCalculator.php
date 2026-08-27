<?php
/**
 * Materialises a weekly recurrence pattern into concrete session times.
 *
 * spec-timetable-v1 §4 — T-2 / T-3 / R-9 boundary:
 *   • Recurrence is defined by wall time in the anchor timezone (T-2).
 *   • Each occurrence is projected to UTC individually so the DST transition
 *     of the anchor market shifts scheduled_start_utc by an hour while
 *     local_start_wall stays constant (T-3). This is the one behaviour the
 *     whole module exists to guarantee, and it is verified explicitly in
 *     tests/Unit/Modules/Timetable/Domain/SessionTimeCalculatorTest.php.
 *   • local_start_wall is stored as the naive wall string and MUST NOT be
 *     re-derived from UTC later — tzdata evolves several times a year.
 *
 * The class is pure: no globals, no WordPress, no database. That is why the
 * DST regression is unit-testable against the system tzdata alone.
 *
 * @package Minhaj\Modules\Timetable\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/*
 * Exception messages here are developer-facing (invalid pattern arguments
 * from callers). They carry validated IANA strings and integers only —
 * never user-supplied HTML — so the WPCS output-escape sniff is disabled
 * at this boundary. The service layer converts violations to WP_Error.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class SessionTimeCalculator {

	private const UTC = 'UTC';

	/**
	 * Compute the ordered session sequence for a pattern.
	 *
	 * @param array{
	 *   anchor_timezone: string,
	 *   weekdays: array<int, int>,
	 *   start_local: string,
	 *   duration_minutes: int,
	 *   weeks_count: int,
	 *   first_week_start: string
	 * } $args
	 *
	 * @return array<int, array{
	 *   sequence_no: int,
	 *   local_start_wall: string,
	 *   scheduled_start_utc: string,
	 *   scheduled_end_utc: string,
	 *   weekday: int
	 * }>
	 *
	 * @throws InvalidArgumentException On malformed input — callers must validate at the boundary.
	 */
	public static function generate( array $args ): array {
		$anchor_tz  = self::require_timezone( $args['anchor_timezone'] ?? '' );
		$weekdays   = self::normalise_weekdays( $args['weekdays'] ?? array() );
		$start_time = self::require_time( $args['start_local'] ?? '' );
		$duration   = (int) ( $args['duration_minutes'] ?? 0 );
		$weeks      = (int) ( $args['weeks_count'] ?? 0 );
		$first_week = self::require_date( $args['first_week_start'] ?? '' );

		if ( $duration < 1 ) {
			throw new InvalidArgumentException( 'duration_minutes must be ≥ 1' );
		}

		if ( $weeks < 1 ) {
			throw new InvalidArgumentException( 'weeks_count must be ≥ 1' );
		}

		/*
		 * Anchor the calendar walk at first_week_start with the pattern's wall
		 * time. Every subsequent occurrence is derived by adding a day count
		 * in the anchor timezone, then projected to UTC — projecting first and
		 * adding days in UTC would smear the DST hour across the schedule.
		 */
		$anchor = new DateTimeImmutable( $first_week . ' ' . $start_time, $anchor_tz );

		$anchor_dow = (int) $anchor->format( 'w' );
		$utc_zone   = new DateTimeZone( self::UTC );
		$sessions   = array();

		for ( $week = 0; $week < $weeks; $week++ ) {
			foreach ( $weekdays as $weekday ) {
				$offset = ( ( $weekday - $anchor_dow + 7 ) % 7 ) + ( $week * 7 );
				$local  = $anchor->modify( '+' . $offset . ' days' );

				if ( false === $local ) {
					throw new InvalidArgumentException( 'invalid date arithmetic on anchor' );
				}

				$utc_start = $local->setTimezone( $utc_zone );
				$utc_end   = $utc_start->modify( '+' . $duration . ' minutes' );

				if ( false === $utc_end ) {
					throw new InvalidArgumentException( 'invalid duration arithmetic' );
				}

				$sessions[] = array(
					'local_start_wall'    => $local->format( 'Y-m-d H:i:s' ),
					'scheduled_start_utc' => $utc_start->format( 'Y-m-d H:i:s' ),
					'scheduled_end_utc'   => $utc_end->format( 'Y-m-d H:i:s' ),
					'weekday'             => (int) $local->format( 'w' ),
				);
			}
		}

		usort(
			$sessions,
			static function ( array $a, array $b ): int {
				return strcmp( $a['scheduled_start_utc'], $b['scheduled_start_utc'] );
			}
		);

		$numbered = array();
		$seq      = 1;
		foreach ( $sessions as $s ) {
			$s['sequence_no'] = $seq++;
			$numbered[]       = $s;
		}

		return $numbered;
	}

	private static function require_timezone( string $tz ): DateTimeZone {
		if ( '' === $tz ) {
			throw new InvalidArgumentException( 'anchor_timezone is required' );
		}

		try {
			return new DateTimeZone( $tz );
		} catch ( \Exception $e ) {
			throw new InvalidArgumentException( 'invalid anchor_timezone: ' . $tz );
		}
	}

	/**
	 * @param array<int, int|string> $weekdays
	 * @return array<int, int>
	 */
	private static function normalise_weekdays( array $weekdays ): array {
		if ( array() === $weekdays ) {
			throw new InvalidArgumentException( 'weekdays must not be empty' );
		}

		$normalised = array();
		foreach ( $weekdays as $w ) {
			$w = (int) $w;
			if ( $w < 0 || $w > 6 ) {
				throw new InvalidArgumentException( 'weekday out of range 0..6: ' . $w );
			}
			$normalised[ $w ] = $w;
		}

		$normalised = array_values( $normalised );
		sort( $normalised );

		return $normalised;
	}

	private static function require_time( string $time ): string {
		if ( 1 !== preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', $time ) ) {
			throw new InvalidArgumentException( 'start_local must be HH:MM or HH:MM:SS' );
		}

		return 5 === strlen( $time ) ? $time . ':00' : $time;
	}

	private static function require_date( string $date ): string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			throw new InvalidArgumentException( 'first_week_start must be YYYY-MM-DD' );
		}

		return $date;
	}
}
