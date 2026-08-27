<?php
/**
 * Timetable public interface — spec-timetable-v1 §8 (partial).
 *
 * This first cut ships three write methods only: set_availability,
 * add_absence, generate_for_group. Reschedule / cancel / regenerate_future
 * and the admin UI arrive with the §8 admin pass.
 *
 * Layering contract (mirrors GroupService — same rules, same reasons):
 *   • Callers (admin/CLI/REST) enforce current_user_can + nonce BEFORE
 *     calling this service. Every write takes `int $actor_user_id`
 *     explicitly so audits are attributable and tests can pass any id.
 *   • The Domain throws RuleViolationException. This service catches it at
 *     the outer boundary and returns WP_Error. The two styles do not leak.
 *   • generate_for_group runs ONE transaction from start to finish: the
 *     teacher's window is locked with SELECT … FOR UPDATE before any
 *     session insert. A conflict at any step rolls back the entire batch —
 *     the spec is explicit that we never leave a half-generated schedule.
 *   • Audit rows are inserted BEFORE commit; do_action events fire AFTER
 *     commit — never inside a transaction that may roll back.
 *
 * @package Minhaj\Modules\Timetable
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable;

use Minhaj\Modules\Timetable\Domain\RuleViolationException;
use Minhaj\Modules\Timetable\Domain\SessionStatus;
use Minhaj\Modules\Timetable\Domain\SessionTimeCalculator;
use Minhaj\Modules\Timetable\Domain\TimetableRules;
use Minhaj\Modules\Timetable\Repository\PersistenceException;
use Minhaj\Modules\Timetable\Repository\TimetableRepository;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/*
 * WP_Error messages here relay dev-facing rule codes and validated enum
 * values — never user-supplied HTML — so the WPCS output-escape sniff is
 * disabled at this boundary. Presentation layers escape at render.
 *
 * do_action hook names come from Events constants, all prefixed
 * `minhaj_*`. The sniff cannot resolve dynamic hook names statically and
 * flags them; the prefix rule is satisfied by construction.
 */
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

final class TimetableService {

	public function __construct( private readonly TimetableRepository $repo ) {}

	// =============================================================== set_availability.

	/**
	 * Insert one or more availability slots for a teacher. Slots are additive
	 * per spec §3.1 — old rows are never overwritten; they age out via
	 * effective_to (or stay open with effective_to = NULL).
	 *
	 * @param array<int, array{
	 *   weekday: int,
	 *   start_local: string,
	 *   end_local: string,
	 *   timezone: string,
	 *   effective_from: string,
	 *   effective_to?: ?string
	 * }> $slots
	 *
	 * @return true|WP_Error
	 */
	public function set_availability( int $actor_user_id, int $teacher_id, array $slots ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $teacher_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'teacher_id is required.', 'minhaj-core' ) );
		}

		if ( array() === $slots ) {
			return new WP_Error( 'invalid_arg', __( 'At least one availability slot is required.', 'minhaj-core' ) );
		}

		$normalised = array();
		foreach ( $slots as $slot ) {
			$normal = $this->normalise_availability_slot( $slot );
			if ( is_wp_error( $normal ) ) {
				return $normal;
			}
			$normalised[] = $normal;
		}

		$now          = current_time( 'mysql', true );
		$inserted_ids = array();

		$this->repo->begin_transaction();
		try {
			foreach ( $normalised as $slot ) {
				$slot['teacher_id'] = $teacher_id;
				$slot['created_at'] = $now;
				$inserted_ids[]     = $this->repo->insert_availability( $slot );
			}

			$this->repo->insert_audit(
				array(
					'group_id'      => null,
					'teacher_id'    => $teacher_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'availability.set',
					'subject_id'    => $teacher_id,
					'payload_json'  => (string) wp_json_encode(
						array(
							'slots_inserted' => count( $inserted_ids ),
							'ids'            => $inserted_ids,
						)
					),
					'created_at'    => $now,
				)
			);

			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::AVAILABILITY_CHANGED, $teacher_id, $inserted_ids, $actor_user_id );

		return true;
	}

	// ==================================================================== add_absence.

	/**
	 * @return int|WP_Error Absence row id on success.
	 */
	public function add_absence(
		int $actor_user_id,
		int $teacher_id,
		string $from_utc,
		string $to_utc,
		string $reason
	) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $teacher_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'teacher_id is required.', 'minhaj-core' ) );
		}

		if ( ! $this->is_utc_datetime( $from_utc ) || ! $this->is_utc_datetime( $to_utc ) ) {
			return new WP_Error( 'invalid_arg', __( 'from_utc and to_utc must be YYYY-MM-DD HH:MM:SS.', 'minhaj-core' ) );
		}

		if ( $from_utc >= $to_utc ) {
			return new WP_Error( 'invalid_range', __( 'from_utc must be strictly before to_utc.', 'minhaj-core' ) );
		}

		$reason_clean = sanitize_text_field( $reason );
		if ( '' === trim( $reason_clean ) ) {
			return new WP_Error( 'reason_required', __( 'A reason for the absence is required.', 'minhaj-core' ) );
		}

		$now        = current_time( 'mysql', true );
		$absence_id = 0;

		$this->repo->begin_transaction();
		try {
			$absence_id = $this->repo->insert_absence(
				array(
					'teacher_id'    => $teacher_id,
					'starts_at_utc' => $from_utc,
					'ends_at_utc'   => $to_utc,
					'reason'        => $reason_clean,
					'created_by'    => $actor_user_id,
					'created_at'    => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'group_id'      => null,
					'teacher_id'    => $teacher_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'absence.added',
					'subject_id'    => $absence_id,
					'payload_json'  => (string) wp_json_encode(
						array(
							'from_utc' => $from_utc,
							'to_utc'   => $to_utc,
							'reason'   => $reason_clean,
						)
					),
					'created_at'    => $now,
				)
			);

			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::TEACHER_ABSENCE_RECORDED, $teacher_id, $absence_id, $from_utc, $to_utc, $actor_user_id );

		return $absence_id;
	}

	// ============================================================= generate_for_group.

	/**
	 * Materialise a pattern into concrete sessions for a group. All-or-nothing:
	 * any availability / absence / double-book conflict aborts the entire
	 * batch (spec §7 R-4, R-5). The transaction locks the teacher's window
	 * with SELECT … FOR UPDATE so a concurrent generator cannot race in.
	 *
	 * @param array{
	 *   anchor_timezone: string,
	 *   weekdays: array<int, int>,
	 *   start_local: string,
	 *   duration_minutes: int,
	 *   weeks_count: int,
	 *   first_week_start: string
	 * } $pattern_args
	 *
	 * @return array<int, array<string, mixed>>|WP_Error List of created session rows on success.
	 */
	public function generate_for_group( int $actor_user_id, int $group_id, array $pattern_args ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $group_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'group_id is required.', 'minhaj-core' ) );
		}

		$group = $this->repo->find_group( $group_id );
		if ( null === $group ) {
			return new WP_Error( 'group_not_found', __( 'Group not found.', 'minhaj-core' ) );
		}

		$teacher_id = (int) ( $group['teacher_id'] ?? 0 );
		if ( $teacher_id <= 0 ) {
			return new WP_Error(
				'teacher_missing',
				__( 'Group has no teacher assigned — cannot generate sessions.', 'minhaj-core' )
			);
		}

		try {
			$sessions = SessionTimeCalculator::generate( $pattern_args );
		} catch ( Throwable $e ) {
			return new WP_Error( 'invalid_pattern', $e->getMessage() );
		}

		$expected_total = (int) ( $group['total_sessions'] ?? 0 );
		if ( $expected_total > 0 && count( $sessions ) !== $expected_total ) {
			return new WP_Error(
				'total_mismatch',
				sprintf(
					/* translators: 1: generated count, 2: expected total_sessions */
					__( 'Pattern produced %1$d sessions but group requires %2$d — R-1 violated.', 'minhaj-core' ),
					count( $sessions ),
					$expected_total
				)
			);
		}

		// Availability + absence checks against read-only state before the transaction.
		$window_from = $sessions[0]['scheduled_start_utc'];
		$window_to   = $sessions[ count( $sessions ) - 1 ]['scheduled_end_utc'];

		$from_date = substr( $window_from, 0, 10 );
		$to_date   = substr( $window_to, 0, 10 );

		$availability = $this->repo->list_availability_for_teacher_between( $teacher_id, $from_date, $to_date );
		$absences     = $this->repo->list_absences_for_teacher_between( $teacher_id, $window_from, $window_to );

		foreach ( $sessions as $s ) {
			try {
				TimetableRules::assert_availability_covers(
					$availability,
					$s['scheduled_start_utc'],
					$s['scheduled_end_utc']
				);
				TimetableRules::assert_no_absence(
					$absences,
					$s['scheduled_start_utc'],
					$s['scheduled_end_utc']
				);
			} catch ( RuleViolationException $e ) {
				return new WP_Error(
					'availability_conflict',
					sprintf(
						/* translators: 1: local wall clock, 2: rule code, 3: reason */
						__( 'Session on %1$s conflicts (%2$s): %3$s', 'minhaj-core' ),
						$s['local_start_wall'],
						$e->rule_code(),
						$e->getMessage()
					),
					array( 'rule' => $e->rule_code() )
				);
			}
		}

		$now          = current_time( 'mysql', true );
		$anchor_tz    = (string) $pattern_args['anchor_timezone'];
		$created_rows = array();
		$pattern_id   = 0;

		$this->repo->begin_transaction();
		try {
			$pattern_id = $this->repo->insert_pattern(
				array(
					'group_id'         => $group_id,
					'anchor_timezone'  => $anchor_tz,
					'weekdays_json'    => (string) wp_json_encode( array_values( $pattern_args['weekdays'] ) ),
					'start_local'      => $pattern_args['start_local'],
					'duration_minutes' => (int) $pattern_args['duration_minutes'],
					'weeks_count'      => (int) $pattern_args['weeks_count'],
					'first_week_start' => (string) $pattern_args['first_week_start'],
					'status'           => 'active',
					'created_at'       => $now,
					'updated_at'       => $now,
				)
			);

			// R-5 · Lock the teacher's whole window before inserting a single
			// row. See TimetableRepository::lock_teacher_sessions_between().
			$existing = $this->repo->lock_teacher_sessions_between( $teacher_id, $window_from, $window_to );

			foreach ( $sessions as $s ) {
				try {
					TimetableRules::assert_no_double_book(
						$existing,
						$s['scheduled_start_utc'],
						$s['scheduled_end_utc']
					);
				} catch ( RuleViolationException $e ) {
					$this->repo->rollback();
					return new WP_Error(
						'double_book',
						sprintf(
							/* translators: 1: local wall clock, 2: reason */
							__( 'Teacher already booked at %1$s: %2$s', 'minhaj-core' ),
							$s['local_start_wall'],
							$e->getMessage()
						),
						array( 'rule' => $e->rule_code() )
					);
				}

				$row = array(
					'group_id'            => $group_id,
					'pattern_id'          => $pattern_id,
					'sequence_no'         => (int) $s['sequence_no'],
					'lesson_no'           => (int) $s['sequence_no'],
					'scheduled_start_utc' => $s['scheduled_start_utc'],
					'scheduled_end_utc'   => $s['scheduled_end_utc'],
					'local_start_wall'    => $s['local_start_wall'],
					'anchor_timezone'     => $anchor_tz,
					'teacher_id'          => $teacher_id,
					'status'              => SessionStatus::SCHEDULED,
					'created_at'          => $now,
					'updated_at'          => $now,
				);

				$row['id']      = $this->repo->insert_session( $row );
				$created_rows[] = $row;

				// Extend the in-transaction ledger so later sessions in the
				// same batch see the ones we just inserted — otherwise two
				// adjacent overlapping sessions in the same generate call
				// would slip past the double-book check.
				$existing[] = array(
					'scheduled_start_utc' => $s['scheduled_start_utc'],
					'scheduled_end_utc'   => $s['scheduled_end_utc'],
				);
			}

			$this->repo->insert_audit(
				array(
					'group_id'      => $group_id,
					'teacher_id'    => $teacher_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'sessions.generated',
					'subject_id'    => $pattern_id,
					'payload_json'  => (string) wp_json_encode(
						array(
							'pattern_id'    => $pattern_id,
							'session_count' => count( $created_rows ),
							'first_utc'     => $window_from,
							'last_utc'      => $window_to,
						)
					),
					'created_at'    => $now,
				)
			);

			$this->repo->commit();
		} catch ( PersistenceException $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage(), array( 'kind' => $e->kind() ) );
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action(
			Events::SESSIONS_GENERATED,
			$group_id,
			$pattern_id,
			$teacher_id,
			array_column( $created_rows, 'id' ),
			$actor_user_id
		);

		return $created_rows;
	}

	// -------------------------------------------------------------------- Helpers.

	/**
	 * @param array<string, mixed> $slot
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalise_availability_slot( array $slot ) {
		$weekday = isset( $slot['weekday'] ) ? (int) $slot['weekday'] : -1;
		if ( $weekday < 0 || $weekday > 6 ) {
			return new WP_Error( 'invalid_arg', __( 'weekday must be 0..6.', 'minhaj-core' ) );
		}

		$start = isset( $slot['start_local'] ) ? sanitize_text_field( (string) $slot['start_local'] ) : '';
		$end   = isset( $slot['end_local'] ) ? sanitize_text_field( (string) $slot['end_local'] ) : '';
		if ( ! $this->is_time( $start ) || ! $this->is_time( $end ) ) {
			return new WP_Error( 'invalid_arg', __( 'start_local and end_local must be HH:MM or HH:MM:SS.', 'minhaj-core' ) );
		}

		$start_padded = 5 === strlen( $start ) ? $start . ':00' : $start;
		$end_padded   = 5 === strlen( $end ) ? $end . ':00' : $end;

		if ( $start_padded >= $end_padded ) {
			return new WP_Error( 'invalid_range', __( 'start_local must be strictly before end_local.', 'minhaj-core' ) );
		}

		$timezone = isset( $slot['timezone'] ) ? sanitize_text_field( (string) $slot['timezone'] ) : '';
		if ( '' === $timezone ) {
			return new WP_Error( 'invalid_arg', __( 'timezone is required.', 'minhaj-core' ) );
		}

		try {
			new \DateTimeZone( $timezone );
		} catch ( \Exception $e ) {
			return new WP_Error( 'invalid_arg', __( 'timezone is not a valid IANA identifier.', 'minhaj-core' ) );
		}

		$from = isset( $slot['effective_from'] ) ? sanitize_text_field( (string) $slot['effective_from'] ) : '';
		if ( ! $this->is_date( $from ) ) {
			return new WP_Error( 'invalid_arg', __( 'effective_from must be YYYY-MM-DD.', 'minhaj-core' ) );
		}

		$to_raw = $slot['effective_to'] ?? null;
		$to     = null;
		if ( null !== $to_raw && '' !== $to_raw ) {
			$to = sanitize_text_field( (string) $to_raw );
			if ( ! $this->is_date( $to ) ) {
				return new WP_Error( 'invalid_arg', __( 'effective_to must be YYYY-MM-DD or null.', 'minhaj-core' ) );
			}
			if ( $to < $from ) {
				return new WP_Error( 'invalid_range', __( 'effective_to cannot be before effective_from.', 'minhaj-core' ) );
			}
		}

		return array(
			'weekday'        => $weekday,
			'start_local'    => $start_padded,
			'end_local'      => $end_padded,
			'timezone'       => $timezone,
			'effective_from' => $from,
			'effective_to'   => $to,
		);
	}

	private function is_time( string $value ): bool {
		return 1 === preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', $value );
	}

	private function is_date( string $value ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}

	private function is_utc_datetime( string $value ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value );
	}

	/**
	 * @return true|WP_Error
	 */
	private function require_actor( int $actor_user_id ) {
		if ( $actor_user_id <= 0 ) {
			return new WP_Error(
				'missing_actor',
				__( 'actor_user_id must be a positive integer — audit rows cannot be anonymous.', 'minhaj-core' )
			);
		}

		return true;
	}
}
