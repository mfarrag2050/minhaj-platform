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

		// Zero-length sessions cannot be materialised (the ancient default of 0
		// on the groups table shipped rows that would silently generate empty
		// windows). Reject rather than translate — the admin must set a real
		// duration before generation.
		if ( (int) ( $group['session_duration_minutes'] ?? 0 ) <= 0 ) {
			return new WP_Error(
				'invalid_group_duration',
				__( 'Group session_duration_minutes must be > 0 before generation.', 'minhaj-core' )
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

	// ========================================================================= cancel.

	/**
	 * Cancel a session and always create its make-up (spec §5 fallback +
	 * §7 R-9). Both writes ride one transaction and the only acceptable
	 * failure is a database error — the earlier "fail-if-no-slot" behaviour
	 * blocked admins in exactly the situation the feature exists for (teacher
	 * ill tomorrow, calendar packed) and left a session marked `scheduled`
	 * that would never happen.
	 *
	 * Numbering follows §5 + §5.1 either way:
	 *   • The cancelled session keeps its sequence_no; lesson_no clears to
	 *     NULL. (§7 R-8 — sequence_no is never moved.)
	 *   • Every later held session shifts lesson_no down by one.
	 *   • The make-up gets sequence_no = MAX(sequence_no)+1 and lesson_no =
	 *     MAX(lesson_no)-before-cancel, preserving the contracted curriculum
	 *     count.
	 *
	 * Datetime resolution has two branches:
	 *   • Slot found within the walker cap → make-up saved as `scheduled`
	 *     with concrete times.
	 *   • No slot found (walker exhausted, pattern data missing/corrupt, or
	 *     the group has no prior sessions at all) → make-up saved as
	 *     `unscheduled` with NULL times. The obligation persists in the debt
	 *     queue until admin calls schedule_makeup().
	 *
	 * Walker cap is `minhaj_timetable_makeup_max_weeks` (default 12). Only
	 * a genuine database write failure aborts the transaction.
	 *
	 * @return array<string, mixed>|WP_Error The make-up session row on success (with `status`
	 *                                       reflecting scheduled vs unscheduled).
	 */
	public function cancel( int $actor_user_id, int $session_id, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $session_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'session_id is required.', 'minhaj-core' ) );
		}

		$reason_clean = sanitize_text_field( $reason );
		if ( '' === trim( $reason_clean ) ) {
			return new WP_Error( 'reason_required', __( 'A reason for cancellation is required.', 'minhaj-core' ) );
		}

		/**
		 * Filter · how many weeks the make-up walker may look forward before
		 * conceding and recording the make-up as `unscheduled`. Extend only
		 * when a specific market's holiday calendar makes the default 12
		 * too tight — a chronically low cap does not block admin, it just
		 * grows the pending-makeup queue faster.
		 *
		 * @param int $max_weeks Default 12.
		 */
		$max_weeks = (int) apply_filters( 'minhaj_timetable_makeup_max_weeks', 12 );
		if ( $max_weeks < 1 ) {
			$max_weeks = 1;
		}

		$now            = current_time( 'mysql', true );
		$makeup_row     = null;
		$group_id_after = 0;
		$cancelled_seq  = 0;
		$was_scheduled  = false;

		$this->repo->begin_transaction();
		try {
			$session = $this->repo->find_session_for_update( $session_id );
			if ( null === $session ) {
				$this->repo->rollback();
				return new WP_Error( 'session_not_found', __( 'Session not found.', 'minhaj-core' ) );
			}

			$current_status = (string) $session['status'];
			if ( SessionStatus::CANCELLED === $current_status ) {
				$this->repo->rollback();
				return new WP_Error( 'already_cancelled', __( 'Session is already cancelled.', 'minhaj-core' ) );
			}

			if ( ! SessionStatus::can_transition( $current_status, SessionStatus::CANCELLED ) ) {
				$this->repo->rollback();
				return new WP_Error(
					'invalid_transition',
					sprintf(
						/* translators: 1: current session status */
						__( 'Cannot cancel a session in status %s.', 'minhaj-core' ),
						$current_status
					)
				);
			}

			$group_id        = (int) $session['group_id'];
			$pattern_id      = (int) $session['pattern_id'];
			$teacher_id      = (int) $session['teacher_id'];
			$cancelled_seq   = (int) $session['sequence_no'];
			$anchor_timezone = (string) $session['anchor_timezone'];

			// Pattern is optional for the unscheduled fallback — a corrupt
			// or missing pattern must NOT block the cancel.
			$pattern  = $this->repo->find_pattern( $pattern_id );
			$last_utc = $this->repo->max_scheduled_start_utc_for_group( $group_id );

			// Numbering snapshots taken BEFORE the cancel/decrement — the
			// make-up references the pre-change state (§5.1).
			$next_seq         = $this->repo->max_sequence_no_for_group( $group_id ) + 1;
			$makeup_lesson_no = $this->repo->max_lesson_no_for_group( $group_id );

			$slot = null;
			if ( null !== $pattern && null !== $last_utc ) {
				$slot = $this->find_makeup_slot( $teacher_id, $pattern, $last_utc, $max_weeks );
			}

			// 1 · The cancelled row keeps its sequence_no; lesson_no clears.
			$this->repo->update_session(
				$session_id,
				array(
					'status'     => SessionStatus::CANCELLED,
					'lesson_no'  => null,
					'updated_at' => $now,
				)
			);

			// 2 · Shift lesson_no down for every held session after this seq.
			$this->repo->decrement_lesson_no_after_sequence( $group_id, $cancelled_seq );

			// 3 · Insert the make-up — scheduled if a slot was found,
			// unscheduled otherwise. sequence_no + lesson_no are identical
			// across both branches; the difference is only in the timing
			// columns and the status.
			$row = array(
				'group_id'        => $group_id,
				'pattern_id'      => $pattern_id,
				'sequence_no'     => $next_seq,
				'lesson_no'       => $makeup_lesson_no,
				'anchor_timezone' => $anchor_timezone,
				'teacher_id'      => $teacher_id,
				'makeup_for_id'   => $session_id,
				'created_at'      => $now,
				'updated_at'      => $now,
			);

			if ( null !== $slot ) {
				$row['status']              = SessionStatus::SCHEDULED;
				$row['scheduled_start_utc'] = $slot['scheduled_start_utc'];
				$row['scheduled_end_utc']   = $slot['scheduled_end_utc'];
				$row['local_start_wall']    = $slot['local_start_wall'];
				$was_scheduled              = true;
			} else {
				$row['status']              = SessionStatus::UNSCHEDULED;
				$row['scheduled_start_utc'] = null;
				$row['scheduled_end_utc']   = null;
				$row['local_start_wall']    = null;
			}

			$row['id']      = $this->repo->insert_session( $row );
			$makeup_row     = $row;
			$group_id_after = $group_id;

			$this->repo->insert_audit(
				array(
					'group_id'      => $group_id,
					'teacher_id'    => $teacher_id,
					'actor_user_id' => $actor_user_id,
					'action'        => $was_scheduled ? 'session.cancelled_with_makeup' : 'session.cancelled_with_unscheduled_makeup',
					'subject_id'    => $session_id,
					'payload_json'  => (string) wp_json_encode(
						array(
							'cancelled_session_id'  => $session_id,
							'cancelled_sequence_no' => $cancelled_seq,
							'makeup_session_id'     => $row['id'],
							'makeup_sequence_no'    => $next_seq,
							'makeup_lesson_no'      => $makeup_lesson_no,
							'makeup_status'         => $row['status'],
							'reason'                => $reason_clean,
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
			Events::SESSION_CANCELLED,
			$session_id,
			$group_id_after,
			(int) ( $makeup_row['id'] ?? 0 ),
			$cancelled_seq,
			$reason_clean,
			$actor_user_id
		);

		if ( ! $was_scheduled && null !== $makeup_row ) {
			do_action(
				Events::MAKEUP_UNSCHEDULED,
				(int) $makeup_row['id'],
				$group_id_after,
				(int) $makeup_row['teacher_id'],
				$session_id,
				$actor_user_id
			);
		}

		return $makeup_row;
	}

	// ================================================================ schedule_makeup.

	/**
	 * Attach a concrete time to a pending unscheduled make-up (spec §6 new
	 * transition `unscheduled → scheduled`). The three checks that guard
	 * generate_for_group also guard this path — an availability, absence,
	 * or double-book conflict rejects cleanly and the make-up stays in the
	 * queue for another attempt.
	 *
	 * @return array<string, mixed>|WP_Error The updated make-up session row.
	 */
	public function schedule_makeup( int $actor_user_id, int $session_id, string $start_utc, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $session_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'session_id is required.', 'minhaj-core' ) );
		}

		if ( ! $this->is_utc_datetime( $start_utc ) ) {
			return new WP_Error( 'invalid_arg', __( 'start_utc must be YYYY-MM-DD HH:MM:SS.', 'minhaj-core' ) );
		}

		$reason_clean = sanitize_text_field( $reason );
		if ( '' === trim( $reason_clean ) ) {
			return new WP_Error( 'reason_required', __( 'A reason for scheduling the make-up is required.', 'minhaj-core' ) );
		}

		$now         = current_time( 'mysql', true );
		$updated_row = null;

		$this->repo->begin_transaction();
		try {
			$session = $this->repo->find_session_for_update( $session_id );
			if ( null === $session ) {
				$this->repo->rollback();
				return new WP_Error( 'session_not_found', __( 'Session not found.', 'minhaj-core' ) );
			}

			if ( SessionStatus::UNSCHEDULED !== (string) $session['status'] ) {
				$this->repo->rollback();
				return new WP_Error(
					'not_unscheduled',
					__( 'schedule_makeup only accepts sessions in the unscheduled state.', 'minhaj-core' )
				);
			}

			if ( empty( $session['makeup_for_id'] ) ) {
				$this->repo->rollback();
				return new WP_Error(
					'not_a_makeup',
					__( 'Session has no makeup_for_id — schedule_makeup only handles make-up rows.', 'minhaj-core' )
				);
			}

			$pattern_id = (int) $session['pattern_id'];
			$pattern    = $this->repo->find_pattern( $pattern_id );
			if ( null === $pattern ) {
				$this->repo->rollback();
				return new WP_Error( 'pattern_not_found', __( 'Schedule pattern for the make-up was not found.', 'minhaj-core' ) );
			}

			$duration = (int) $pattern['duration_minutes'];
			if ( $duration < 1 ) {
				$this->repo->rollback();
				return new WP_Error( 'invalid_pattern', __( 'Pattern duration_minutes is invalid.', 'minhaj-core' ) );
			}

			$anchor_timezone = (string) $session['anchor_timezone'];
			try {
				$anchor_tz = new \DateTimeZone( $anchor_timezone );
			} catch ( \Exception $e ) {
				$this->repo->rollback();
				return new WP_Error( 'invalid_timezone', __( 'Session anchor timezone is invalid.', 'minhaj-core' ) );
			}

			$utc_zone  = new \DateTimeZone( 'UTC' );
			$start_dt  = new \DateTimeImmutable( $start_utc, $utc_zone );
			$end_dt    = $start_dt->modify( '+' . $duration . ' minutes' );
			$end_utc   = $end_dt->format( 'Y-m-d H:i:s' );
			$local_dt  = $start_dt->setTimezone( $anchor_tz );
			$local_str = $local_dt->format( 'Y-m-d H:i:s' );

			$teacher_id   = (int) $session['teacher_id'];
			$date_iso     = $local_dt->format( 'Y-m-d' );
			$availability = $this->repo->list_availability_for_teacher_on( $teacher_id, $date_iso );
			$absences     = $this->repo->list_absences_for_teacher_between( $teacher_id, $start_utc, $end_utc );
			$existing     = $this->repo->lock_teacher_sessions_between( $teacher_id, $start_utc, $end_utc );

			try {
				TimetableRules::assert_availability_covers( $availability, $start_utc, $end_utc );
				TimetableRules::assert_no_absence( $absences, $start_utc, $end_utc );
				TimetableRules::assert_no_double_book( $existing, $start_utc, $end_utc );
			} catch ( RuleViolationException $e ) {
				$this->repo->rollback();
				return new WP_Error(
					'schedule_conflict',
					sprintf(
						/* translators: 1: rule code (R-4/R-5), 2: proposed local wall clock, 3: reason */
						__( '%1$s at %2$s: %3$s', 'minhaj-core' ),
						$e->rule_code(),
						$local_str,
						$e->getMessage()
					),
					array( 'rule' => $e->rule_code() )
				);
			}

			$this->repo->update_session(
				$session_id,
				array(
					'status'              => SessionStatus::SCHEDULED,
					'scheduled_start_utc' => $start_utc,
					'scheduled_end_utc'   => $end_utc,
					'local_start_wall'    => $local_str,
					'updated_at'          => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'group_id'      => (int) $session['group_id'],
					'teacher_id'    => $teacher_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'makeup.scheduled',
					'subject_id'    => $session_id,
					'payload_json'  => (string) wp_json_encode(
						array(
							'session_id'          => $session_id,
							'sequence_no'         => (int) $session['sequence_no'],
							'scheduled_start_utc' => $start_utc,
							'scheduled_end_utc'   => $end_utc,
							'local_start_wall'    => $local_str,
							'reason'              => $reason_clean,
						)
					),
					'created_at'    => $now,
				)
			);

			$updated_row                        = $session;
			$updated_row['status']              = SessionStatus::SCHEDULED;
			$updated_row['scheduled_start_utc'] = $start_utc;
			$updated_row['scheduled_end_utc']   = $end_utc;
			$updated_row['local_start_wall']    = $local_str;
			$updated_row['updated_at']          = $now;

			$this->repo->commit();
		} catch ( PersistenceException $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage(), array( 'kind' => $e->kind() ) );
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action(
			Events::MAKEUP_SCHEDULED,
			$session_id,
			(int) $updated_row['group_id'],
			(int) $updated_row['teacher_id'],
			$start_utc,
			$actor_user_id
		);

		return $updated_row;
	}

	/**
	 * Walk the pattern forward from $last_utc looking for the first slot that
	 * satisfies availability / absence / double-book. Bounded by $max_weeks.
	 *
	 * Returns null on any of: exhausted cap, malformed pattern data, or an
	 * absent last_utc — the caller falls back to an unscheduled make-up. The
	 * "walker failed" outcome is never an error at this layer.
	 *
	 * @param array<string, mixed> $pattern Pattern row (weekdays_json + start_local + duration_minutes + anchor_timezone).
	 *
	 * @return array{local_start_wall:string, scheduled_start_utc:string, scheduled_end_utc:string}|null
	 */
	private function find_makeup_slot( int $teacher_id, array $pattern, string $last_utc, int $max_weeks ): ?array {
		try {
			$anchor_tz = new \DateTimeZone( (string) $pattern['anchor_timezone'] );
		} catch ( \Exception $e ) {
			return null;
		}

		$weekdays = json_decode( (string) $pattern['weekdays_json'], true );
		if ( ! is_array( $weekdays ) || array() === $weekdays ) {
			return null;
		}
		$weekdays = array_map( 'intval', $weekdays );

		$duration = (int) $pattern['duration_minutes'];
		if ( $duration < 1 ) {
			return null;
		}

		$start_time = (string) $pattern['start_local'];
		if ( 5 === strlen( $start_time ) ) {
			$start_time .= ':00';
		}

		$utc_zone  = new \DateTimeZone( 'UTC' );
		$last_dt   = new \DateTimeImmutable( $last_utc, $utc_zone );
		$last_wall = $last_dt->setTimezone( $anchor_tz );

		// Start on the day AFTER the last session's anchor-local date so we
		// never re-issue a slot that already ran today.
		$cursor = new \DateTimeImmutable(
			$last_wall->format( 'Y-m-d' ) . ' ' . $start_time,
			$anchor_tz
		);
		$cursor = $cursor->modify( '+1 day' );

		$day_cap = $max_weeks * 7;

		for ( $i = 0; $i < $day_cap; $i++ ) {
			$dow = (int) $cursor->format( 'w' );
			if ( ! in_array( $dow, $weekdays, true ) ) {
				$cursor = $cursor->modify( '+1 day' );
				continue;
			}

			$local_wall_str = $cursor->format( 'Y-m-d H:i:s' );
			$utc_start      = $cursor->setTimezone( $utc_zone )->format( 'Y-m-d H:i:s' );
			$utc_end        = $cursor->setTimezone( $utc_zone )
									->modify( '+' . $duration . ' minutes' )
									->format( 'Y-m-d H:i:s' );

			// Defensive: never accept a candidate that is not strictly after
			// the last existing session in UTC.
			if ( $utc_start <= $last_utc ) {
				$cursor = $cursor->modify( '+1 day' );
				continue;
			}

			$date_iso     = $cursor->format( 'Y-m-d' );
			$availability = $this->repo->list_availability_for_teacher_on( $teacher_id, $date_iso );
			$absences     = $this->repo->list_absences_for_teacher_between( $teacher_id, $utc_start, $utc_end );
			$existing     = $this->repo->lock_teacher_sessions_between( $teacher_id, $utc_start, $utc_end );

			try {
				TimetableRules::assert_availability_covers( $availability, $utc_start, $utc_end );
				TimetableRules::assert_no_absence( $absences, $utc_start, $utc_end );
				TimetableRules::assert_no_double_book( $existing, $utc_start, $utc_end );

				return array(
					'local_start_wall'    => $local_wall_str,
					'scheduled_start_utc' => $utc_start,
					'scheduled_end_utc'   => $utc_end,
				);
			} catch ( RuleViolationException $e ) {
				unset( $e );
			}

			$cursor = $cursor->modify( '+1 day' );
		}

		return null;
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
