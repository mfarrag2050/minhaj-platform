<?php
/**
 * Policy-layer checks for spec-timetable-v1 §7 R-4 and R-5.
 *
 * These are pure functions the service calls before writing. They sit above
 * the DB, not in place of it — R-5 also relies on SELECT … FOR UPDATE inside
 * the generation transaction, because MySQL cannot express range-exclusion
 * as a schema constraint (see §7 R-5 caveat).
 *
 * @package Minhaj\Modules\Timetable\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable\Domain;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/*
 * Exception messages here are developer-facing (logs, generation error map).
 * They carry validated integers and datetime strings only — never
 * user-supplied HTML. The service layer converts them to WP_Error.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class TimetableRules {

	private const UTC = 'UTC';

	/**
	 * R-4a · A session must fall inside at least one availability slot of its
	 * teacher, interpreted in that teacher's local timezone (the slot carries
	 * its own IANA zone — spec §3.1).
	 *
	 * @param array<int, array{
	 *   weekday: int,
	 *   start_local: string,
	 *   end_local: string,
	 *   timezone: string,
	 *   effective_from: string,
	 *   effective_to: ?string
	 * }> $availability
	 *
	 * @throws RuleViolationException When no slot covers the session.
	 */
	public static function assert_availability_covers(
		array $availability,
		string $session_start_utc,
		string $session_end_utc
	): void {
		if ( array() === $availability ) {
			throw new RuleViolationException(
				'R-4',
				sprintf( 'teacher has no availability slots at %s UTC', $session_start_utc )
			);
		}

		$utc         = new DateTimeZone( self::UTC );
		$start_utc_d = new DateTimeImmutable( $session_start_utc, $utc );
		$end_utc_d   = new DateTimeImmutable( $session_end_utc, $utc );

		foreach ( $availability as $slot ) {
			$slot_tz          = new DateTimeZone( $slot['timezone'] );
			$local_start      = $start_utc_d->setTimezone( $slot_tz );
			$local_end        = $end_utc_d->setTimezone( $slot_tz );
			$session_date_iso = $local_start->format( 'Y-m-d' );

			if ( $slot['effective_from'] > $session_date_iso ) {
				continue;
			}

			if ( null !== $slot['effective_to'] && $slot['effective_to'] < $session_date_iso ) {
				continue;
			}

			if ( (int) $local_start->format( 'w' ) !== (int) $slot['weekday'] ) {
				continue;
			}

			$slot_start = new DateTimeImmutable( $session_date_iso . ' ' . $slot['start_local'], $slot_tz );
			$slot_end   = new DateTimeImmutable( $session_date_iso . ' ' . $slot['end_local'], $slot_tz );

			if ( $local_start >= $slot_start && $local_end <= $slot_end ) {
				return;
			}
		}

		throw new RuleViolationException(
			'R-4',
			sprintf(
				'teacher not available at %s UTC (%s..%s)',
				$session_start_utc,
				$session_start_utc,
				$session_end_utc
			)
		);
	}

	/**
	 * R-4b · A session must not fall inside any known absence window.
	 *
	 * @param array<int, array{starts_at_utc:string, ends_at_utc:string}> $absences
	 *
	 * @throws RuleViolationException On any overlap.
	 */
	public static function assert_no_absence(
		array $absences,
		string $session_start_utc,
		string $session_end_utc
	): void {
		foreach ( $absences as $absence ) {
			if (
				$absence['starts_at_utc'] < $session_end_utc
				&& $absence['ends_at_utc'] > $session_start_utc
			) {
				throw new RuleViolationException(
					'R-4',
					sprintf(
						'teacher absent %s..%s overlaps session at %s UTC',
						$absence['starts_at_utc'],
						$absence['ends_at_utc'],
						$session_start_utc
					)
				);
			}
		}
	}

	/**
	 * R-5 · No overlap with an existing session for the same teacher. The
	 * caller MUST have taken a row lock (SELECT … FOR UPDATE) over the
	 * teacher's window before calling this — the application-layer check
	 * alone cannot enforce range exclusion (§7 R-5 caveat).
	 *
	 * @param array<int, array{scheduled_start_utc:string, scheduled_end_utc:string}> $existing_sessions
	 *
	 * @throws RuleViolationException On any overlap.
	 */
	public static function assert_no_double_book(
		array $existing_sessions,
		string $session_start_utc,
		string $session_end_utc
	): void {
		foreach ( $existing_sessions as $existing ) {
			if (
				$existing['scheduled_start_utc'] < $session_end_utc
				&& $existing['scheduled_end_utc'] > $session_start_utc
			) {
				throw new RuleViolationException(
					'R-5',
					sprintf(
						'teacher already booked %s..%s conflicts with session at %s UTC',
						$existing['scheduled_start_utc'],
						$existing['scheduled_end_utc'],
						$session_start_utc
					)
				);
			}
		}
	}

	/**
	 * R-6 · No overlap with an existing session that the same student is
	 * already booked into. Comparison runs on `scheduled_start_utc` /
	 * `scheduled_end_utc` — the UTC instants of the two sessions.
	 * `local_start_wall` is the answer to a different question ("what
	 * anchor-local day is this?") and must not be used here; the two
	 * columns exist so the two questions never collide.
	 *
	 * The caller MUST have taken a FOR UPDATE row lock over the student's
	 * existing sessions in the window before calling — same pattern the
	 * teacher check uses (§7 R-5 caveat also applies at student level).
	 *
	 * @param array<int, array{scheduled_start_utc:string, scheduled_end_utc:string, group_id?:int}> $existing_sessions
	 *
	 * @throws RuleViolationException On any overlap.
	 */
	public static function assert_no_student_double_book(
		array $existing_sessions,
		string $session_start_utc,
		string $session_end_utc,
		int $student_id
	): void {
		foreach ( $existing_sessions as $existing ) {
			if (
				$existing['scheduled_start_utc'] < $session_end_utc
				&& $existing['scheduled_end_utc'] > $session_start_utc
			) {
				throw new RuleViolationException(
					'R-6',
					sprintf(
						'student %d already booked %s..%s (group %d) conflicts with session at %s UTC',
						$student_id,
						$existing['scheduled_start_utc'],
						$existing['scheduled_end_utc'],
						(int) ( $existing['group_id'] ?? 0 ),
						$session_start_utc
					)
				);
			}
		}
	}

	/**
	 * Family-level overlap detection (R-7): siblings who share a guardian
	 * cannot both attend two overlapping sessions in different groups —
	 * the family has only one screen, one adult present, one pair of
	 * hands to move the children. Return the offending overlaps; the
	 * service emits an admin warning but does NOT block, because a
	 * two-parent family might genuinely have two screens.
	 *
	 * Compared on UTC — same reason as R-6.
	 *
	 * @param array<int, array{scheduled_start_utc:string, scheduled_end_utc:string, student_id:int, group_id?:int}> $sibling_sessions
	 *
	 * @return array<int, array{scheduled_start_utc:string, scheduled_end_utc:string, student_id:int, group_id?:int}>
	 */
	public static function detect_family_overlaps(
		array $sibling_sessions,
		string $session_start_utc,
		string $session_end_utc
	): array {
		$overlaps = array();
		foreach ( $sibling_sessions as $existing ) {
			if (
				$existing['scheduled_start_utc'] < $session_end_utc
				&& $existing['scheduled_end_utc'] > $session_start_utc
			) {
				$overlaps[] = $existing;
			}
		}

		return $overlaps;
	}
}
