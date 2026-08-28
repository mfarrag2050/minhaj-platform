<?php
/**
 * Groups public interface — spec-groups-v1 §6.
 *
 * Layering contract:
 *   • Callers (admin/REST) enforce current_user_can + nonce BEFORE calling
 *     this service. The service does not fetch the current user; every
 *     write takes `int $actor_user_id` explicitly so audits are attributable
 *     and unit tests can pass any user id.
 *   • The Domain throws RuleViolationException. This service catches it at
 *     the outer boundary and returns WP_Error. The two error styles do not
 *     leak across the boundary.
 *   • Writes run inside a single DB transaction with SELECT … FOR UPDATE on
 *     the group row. Audit rows are inserted BEFORE commit; do_action events
 *     fire AFTER commit — never inside a transaction that may roll back.
 *   • add_member is idempotent for repeated (group_id, student_id) — safe
 *     under webhook retries.
 *
 * @package Minhaj\Modules\Groups
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups;

use Minhaj\Modules\Groups\Domain\GroupCapacity;
use Minhaj\Modules\Groups\Domain\GroupRules;
use Minhaj\Modules\Groups\Domain\GroupStatus;
use Minhaj\Modules\Groups\Domain\GroupType;
use Minhaj\Modules\Groups\Domain\RuleViolationException;
use Minhaj\Modules\Groups\Repository\GroupRepository;
use Minhaj\Modules\Groups\Repository\PersistenceException;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/*
 * WP_Error messages built here relay dev-facing rule codes and validated
 * enum values — never user-supplied HTML — so the WPCS output-escape sniff
 * is disabled at this boundary. Presentation layers (admin notices, REST
 * responses) escape at the moment of rendering.
 *
 * do_action names come from the Events class constants, which are all
 * `minhaj_group_*`. The sniff cannot resolve that statically and flags
 * every dynamic hook name; the prefix rule is satisfied by construction.
 */
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

final class GroupService {

	public function __construct( private readonly GroupRepository $repo ) {}

	// ============================================================== Reads.

	public function available_seats( int $group_id ): int {
		$group = $this->repo->find_group( $group_id );
		if ( null === $group ) {
			return 0;
		}

		$active = $this->repo->count_active_members( $group_id );

		return max( 0, (int) $group['capacity_max'] - $active );
	}

	/**
	 * Dry check: MUST NOT touch any row. Returns true if a subsequent
	 * add_member call is expected to succeed (or return idempotently).
	 *
	 * @return true|WP_Error
	 */
	public function can_accept( int $group_id, int $student_id ) {
		if ( $group_id <= 0 || $student_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'group_id and student_id are required.', 'minhaj-core' ) );
		}

		$group = $this->repo->find_group( $group_id );
		if ( null === $group ) {
			return new WP_Error( 'group_not_found', __( 'Group not found.', 'minhaj-core' ) );
		}

		if ( in_array( $group['status'], array( GroupStatus::COMPLETED, GroupStatus::CANCELLED ), true ) ) {
			return new WP_Error( 'group_closed', __( 'Group is closed to new members.', 'minhaj-core' ) );
		}

		// Idempotency: already active is acceptable.
		if ( null !== $this->repo->find_active_member( $group_id, $student_id ) ) {
			return true;
		}

		$active = $this->repo->count_active_members( $group_id );
		if ( $active >= (int) $group['capacity_max'] ) {
			return new WP_Error( 'group_full', __( 'Group has no free seats.', 'minhaj-core' ) );
		}

		/**
		 * Allow external modules to veto acceptance (e.g., billing or GDPR gate).
		 *
		 * @param true|WP_Error       $verdict     Current verdict. WP_Error to reject.
		 * @param int                 $group_id    Target group id.
		 * @param int                 $student_id  Candidate student id.
		 * @param array<string,mixed> $group       Group row.
		 */
		$verdict = apply_filters( 'minhaj_group_can_accept_student', true, $group_id, $student_id, $group );

		if ( is_wp_error( $verdict ) ) {
			return $verdict;
		}

		if ( true !== $verdict ) {
			return new WP_Error( 'rejected', __( 'Acceptance vetoed by extension.', 'minhaj-core' ) );
		}

		return true;
	}

	// ============================================================= Writes.

	/**
	 * @param array<string, mixed> $args
	 * @return int|WP_Error
	 */
	public function create( int $actor_user_id, array $args ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$type = isset( $args['type'] ) ? (string) $args['type'] : GroupType::GROUP;
		if ( ! GroupType::is_valid( $type ) ) {
			return new WP_Error( 'invalid_type', __( 'Unknown group type.', 'minhaj-core' ) );
		}

		$defaults = GroupCapacity::defaults_for_type( $type );

		/**
		 * Filter the default capacity envelope for a group type.
		 *
		 * @param array{min:int,max:int} $defaults Default capacity_min / capacity_max for this type.
		 * @param string                 $type     GroupType constant.
		 */
		$defaults = (array) apply_filters( 'minhaj_group_default_capacity', $defaults, $type );

		$capacity_min = (int) ( $args['capacity_min'] ?? $defaults['min'] );
		$capacity_max = (int) ( $args['capacity_max'] ?? $defaults['max'] );

		try {
			GroupRules::assert_capacity_matches_type( $type, $capacity_min, $capacity_max );
		} catch ( RuleViolationException $e ) {
			return new WP_Error( 'invalid_capacity', $e->getMessage(), array( 'rule' => $e->rule_code() ) );
		}

		// Language coverage gate BEFORE save. If the proposed locale
		// has no assignable teacher (spec-people-v1 S-8), refuse
		// unless an explicit override with a written reason is
		// provided. The People module subscribes to the filter and
		// answers with count_assignable_teachers_for_locale.
		$teaching_language = isset( $args['teaching_language'] )
			? sanitize_text_field( (string) $args['teaching_language'] )
			: '';
		if ( '' !== $teaching_language ) {
			/**
			 * Filter · returns the number of teachers who could be
			 * assigned to a group teaching in $locale. `null` means no
			 * subscriber answered (People module not loaded); a value
			 * < 1 blocks group creation unless overridden.
			 *
			 * @param int|null $count  Null when no subscriber answered.
			 * @param string   $locale
			 */
			$coverage = apply_filters( 'minhaj_group_teaching_language_coverage', null, $teaching_language );
			if ( null !== $coverage && (int) $coverage < 1 ) {
				$override_reason = isset( $args['language_coverage_override_reason'] )
					? sanitize_text_field( (string) $args['language_coverage_override_reason'] )
					: '';
				if ( '' === trim( $override_reason ) ) {
					return new WP_Error(
						'no_assignable_teacher_for_language',
						sprintf(
							/* translators: %s: locale */
							__( 'No active teacher can be assigned in %s. Pass language_coverage_override_reason to override.', 'minhaj-core' ),
							$teaching_language
						),
						array(
							'locale'   => $teaching_language,
							'coverage' => 0,
						)
					);
				}
			}
		}

		// Capacity gate BEFORE save. The published promise is 3–5 seats;
		// anything higher is a deliberate policy exception and requires
		// an actor-signed override with a written reason.
		$default_ceiling = GroupCapacity::defaults_for_type( $type )['max'];
		if ( $capacity_max > $default_ceiling ) {
			$reason = isset( $args['capacity_over_promise_reason'] )
				? sanitize_text_field( (string) $args['capacity_over_promise_reason'] )
				: '';
			if ( '' === trim( $reason ) ) {
				return new WP_Error(
					'capacity_over_promise',
					sprintf(
						/* translators: 1: requested max, 2: default ceiling */
						__( 'capacity_max %1$d exceeds the published promise of %2$d seats. Pass capacity_over_promise_reason to override.', 'minhaj-core' ),
						$capacity_max,
						$default_ceiling
					),
					array( 'default_ceiling' => $default_ceiling )
				);
			}
		}

		// Group code is system-generated · CLAUDE.md § واجهات الإدخال.
		// The code is a historical label; humans do not choose it,
		// not even with a written reason. Any caller (admin, CLI,
		// REST) that tries to pass one is rejected here — no silent
		// discard, so the rule is visible in error logs.
		if ( isset( $args['code'] ) || isset( $args['code_override_reason'] ) ) {
			return new WP_Error(
				'code_arg_not_allowed',
				__( 'Group code is system-generated and cannot be set by the caller. A wrong code is fixed by cancelling the group and creating a new one.', 'minhaj-core' )
			);
		}

		// Level is closed by curriculum — see CreateCurriculumLevels
		// migration. A group belongs to a curriculum, and its level
		// must exist in that curriculum's catalogue. Default curriculum
		// today is manhaj-v1.
		$curriculum_id = isset( $args['curriculum_id'] ) && (int) $args['curriculum_id'] > 0
			? (int) $args['curriculum_id']
			: \Minhaj\Modules\Groups\Migrations\CreateCurriculumLevels::MANHAJ_V1_ID;
		$level         = sanitize_text_field( (string) ( $args['level'] ?? '' ) );
		if ( '' === $level || ! $this->repo->level_exists( $curriculum_id, $level ) ) {
			return new WP_Error(
				'invalid_level',
				sprintf(
					/* translators: 1: level code, 2: curriculum id */
					__( 'Level %1$s is not in curriculum %2$d. Pick from the curriculum\'s catalogue.', 'minhaj-core' ),
					'' === $level ? '(empty)' : $level,
					$curriculum_id
				),
				array(
					'level'         => $level,
					'curriculum_id' => $curriculum_id,
				)
			);
		}

		$now = current_time( 'mysql', true );

		$data_base = array(
			'type'                     => $type,
			'status'                   => GroupStatus::DRAFT,
			'batch_id'                 => isset( $args['batch_id'] ) ? absint( $args['batch_id'] ) : null,
			'curriculum_id'            => $curriculum_id,
			'level'                    => $level,
			'teacher_id'               => isset( $args['teacher_id'] ) ? absint( $args['teacher_id'] ) : null,
			'teaching_language'        => $teaching_language,
			'timezone'                 => sanitize_text_field( (string) ( $args['timezone'] ?? 'UTC' ) ),
			'capacity_min'             => $capacity_min,
			'capacity_max'             => $capacity_max,
			'session_duration_minutes' => (int) ( $args['session_duration_minutes'] ?? 0 ),
			'total_sessions'           => (int) ( $args['total_sessions'] ?? 0 ),
			'sessions_per_week'        => (int) ( $args['sessions_per_week'] ?? 3 ),
			'program_hours'            => (int) ( $args['program_hours'] ?? 36 ),
			'planned_start_date'       => $args['planned_start_date'] ?? null,
			'formation_deadline'       => $args['formation_deadline'] ?? null,
			'created_at'               => $now,
			'updated_at'               => $now,
		);

		$max_attempts = 5;
		$last_code    = '';
		$group_id     = 0;

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			// The formatter reserves a fresh seq slot on each call
			// (persistent counter · see GroupCodeFormatter). The
			// retry loop exists only for the UNIQUE-index race with
			// externally-inserted rows.
			$last_code = (string) apply_filters(
				'minhaj_group_code_format',
				'',
				array_merge( $args, array( 'attempt' => $attempt ) )
			);

			if ( '' === $last_code ) {
				return new WP_Error( 'invalid_code', __( 'A group code is required.', 'minhaj-core' ) );
			}

			$data         = $data_base;
			$data['code'] = $last_code;

			$this->repo->begin_transaction();
			try {
				$group_id = $this->repo->insert_group( $data );

				$this->repo->insert_audit(
					array(
						'group_id'      => $group_id,
						'actor_user_id' => $actor_user_id,
						'action'        => 'group.created',
						'subject_id'    => $group_id,
						'payload_json'  => (string) wp_json_encode(
							array(
								'code'         => $last_code,
								'type'         => $type,
								'capacity_min' => $capacity_min,
								'capacity_max' => $capacity_max,
								'attempt'      => $attempt,
							)
						),
						'created_at'    => $now,
					)
				);

				$this->repo->commit();
				break;
			} catch ( PersistenceException $e ) {
				$this->repo->rollback();

				if ( PersistenceException::DUPLICATE_CODE === $e->kind() ) {
					if ( $attempt === $max_attempts - 1 ) {
						return new WP_Error(
							'code_generation_exhausted',
							sprintf(
								/* translators: %d: attempts */
								__( 'Could not allocate a unique group code after %d attempts — check the code format filter.', 'minhaj-core' ),
								$max_attempts
							)
						);
					}
					continue;
				}

				return new WP_Error( 'persistence_error', $e->getMessage() );
			} catch ( Throwable $e ) {
				$this->repo->rollback();
				return new WP_Error( 'persistence_error', $e->getMessage() );
			}
		}

		return $group_id;
	}

	/**
	 * @return true|WP_Error
	 */
	public function transition( int $actor_user_id, int $group_id, string $to_status, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( ! GroupStatus::is_valid( $to_status ) ) {
			return new WP_Error( 'invalid_status', __( 'Unknown target status.', 'minhaj-core' ) );
		}

		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'reason_required', __( 'A reason for the transition is required.', 'minhaj-core' ) );
		}

		$this->repo->begin_transaction();
		try {
			$group = $this->repo->find_group_for_update( $group_id );
			if ( null === $group ) {
				$this->repo->rollback();
				return new WP_Error( 'group_not_found', __( 'Group not found.', 'minhaj-core' ) );
			}

			$from_status = (string) $group['status'];

			if ( ! GroupStatus::can_transition( $from_status, $to_status ) ) {
				$this->repo->rollback();
				return new WP_Error(
					'invalid_transition',
					/* translators: 1: current status, 2: attempted target status */
					sprintf( __( 'Cannot transition from %1$s to %2$s.', 'minhaj-core' ), $from_status, $to_status )
				);
			}

			// R-2 gate for the schedule transition.
			if ( GroupStatus::SCHEDULED === $to_status ) {
				$active = $this->repo->count_active_members( $group_id );
				try {
					GroupRules::assert_ready_to_schedule( $active, (int) $group['capacity_min'] );
				} catch ( RuleViolationException $e ) {
					$this->repo->rollback();
					return new WP_Error(
						'not_ready_to_schedule',
						$e->getMessage(),
						array( 'rule' => $e->rule_code() )
					);
				}
			}

			$now    = current_time( 'mysql', true );
			$update = array(
				'status'     => $to_status,
				'updated_at' => $now,
			);

			if ( GroupStatus::ACTIVE === $to_status && empty( $group['actual_start_date'] ) ) {
				$update['actual_start_date'] = current_time( 'Y-m-d', true );
			}

			$this->repo->update_group( $group_id, $update );

			$this->repo->insert_audit(
				array(
					'group_id'      => $group_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'group.status_changed',
					'subject_id'    => $group_id,
					'payload_json'  => (string) wp_json_encode(
						array(
							'from'   => $from_status,
							'to'     => $to_status,
							'reason' => $reason,
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

		// Events after commit only.
		$event_map = array(
			GroupStatus::SCHEDULED => Events::SCHEDULED,
			GroupStatus::ACTIVE    => Events::ACTIVATED,
			GroupStatus::SUSPENDED => Events::SUSPENDED,
			GroupStatus::COMPLETED => Events::COMPLETED,
			GroupStatus::CANCELLED => Events::CANCELLED,
		);

		// suspended → active is a resume, not the initial activation.
		$event = GroupStatus::SUSPENDED === $from_status && GroupStatus::ACTIVE === $to_status
			? Events::RESUMED
			: ( $event_map[ $to_status ] ?? null );

		if ( null !== $event ) {
			do_action( $event, $group_id, $from_status, $to_status, $reason, $actor_user_id );
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $ctx
	 * @return int|WP_Error Membership id (new or existing on idempotent path).
	 */
	public function add_member( int $actor_user_id, int $group_id, int $student_id, array $ctx = array() ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $group_id <= 0 || $student_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'group_id and student_id are required.', 'minhaj-core' ) );
		}

		$dry = $this->can_accept( $group_id, $student_id );
		if ( is_wp_error( $dry ) ) {
			return $dry;
		}

		// Fast path: already active outside the transaction.
		$existing = $this->repo->find_active_member( $group_id, $student_id );
		if ( null !== $existing ) {
			return (int) $existing['id'];
		}

		$membership_id = 0;
		$seat          = 0;

		$this->repo->begin_transaction();
		try {
			$group = $this->repo->find_group_for_update( $group_id );
			if ( null === $group ) {
				$this->repo->rollback();
				return new WP_Error( 'group_not_found', __( 'Group not found.', 'minhaj-core' ) );
			}

			// Recheck idempotency under the row lock.
			$existing = $this->repo->find_active_member( $group_id, $student_id );
			if ( null !== $existing ) {
				$this->repo->rollback();
				return (int) $existing['id'];
			}

			$active = $this->repo->count_active_members( $group_id );
			try {
				GroupRules::assert_seat_available( $active, (int) $group['capacity_max'] );
			} catch ( RuleViolationException $e ) {
				$this->repo->rollback();
				return new WP_Error( 'group_full', $e->getMessage(), array( 'rule' => $e->rule_code() ) );
			}

			$seat = $this->smallest_free_seat(
				$this->repo->find_used_seat_indices( $group_id ),
				(int) $group['capacity_max']
			);
			if ( null === $seat ) {
				$this->repo->rollback();
				return new WP_Error( 'group_full', __( 'No seat available.', 'minhaj-core' ) );
			}

			$now = current_time( 'mysql', true );

			try {
				$membership_id = $this->repo->insert_member(
					array(
						'group_id'   => $group_id,
						'student_id' => $student_id,
						'status'     => 'active',
						'joined_at'  => $now,
						'seat_index' => $seat,
						'order_id'   => isset( $ctx['order_id'] ) ? absint( $ctx['order_id'] ) : null,
					)
				);
			} catch ( PersistenceException $e ) {
				$this->repo->rollback();

				// Race won by another process — treat as idempotent success.
				if ( PersistenceException::DUPLICATE_STUDENT === $e->kind() ) {
					$row = $this->repo->find_active_member( $group_id, $student_id );
					if ( null !== $row ) {
						return (int) $row['id'];
					}
				}

				if ( PersistenceException::DUPLICATE_SEAT === $e->kind() ) {
					return new WP_Error(
						'group_full',
						__( 'Seat taken by concurrent request.', 'minhaj-core' )
					);
				}

				return new WP_Error( 'persistence_error', $e->getMessage() );
			}

			$this->repo->insert_audit(
				array(
					'group_id'      => $group_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'member.added',
					'subject_id'    => $membership_id,
					'payload_json'  => (string) wp_json_encode(
						array(
							'student_id' => $student_id,
							'seat_index' => $seat,
							'order_id'   => $ctx['order_id'] ?? null,
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

		do_action( Events::MEMBER_ADDED, $group_id, $membership_id, $student_id, $seat, $actor_user_id );

		return $membership_id;
	}

	/**
	 * @return true|WP_Error
	 */
	public function remove_member( int $actor_user_id, int $membership_id, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'reason_required', __( 'A reason for removal is required.', 'minhaj-core' ) );
		}

		$this->repo->begin_transaction();
		try {
			$membership = $this->repo->find_membership( $membership_id );
			if ( null === $membership ) {
				$this->repo->rollback();
				return new WP_Error( 'member_not_found', __( 'Membership not found.', 'minhaj-core' ) );
			}

			if ( 'active' !== $membership['status'] ) {
				$this->repo->rollback();
				return new WP_Error( 'member_not_active', __( 'Membership is not active.', 'minhaj-core' ) );
			}

			$now = current_time( 'mysql', true );

			$this->repo->update_member(
				$membership_id,
				array(
					'status'  => 'withdrawn',
					'left_at' => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'group_id'      => (int) $membership['group_id'],
					'actor_user_id' => $actor_user_id,
					'action'        => 'member.removed',
					'subject_id'    => $membership_id,
					'payload_json'  => (string) wp_json_encode(
						array(
							'student_id' => (int) $membership['student_id'],
							'reason'     => $reason,
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

		do_action(
			Events::MEMBER_REMOVED,
			(int) $membership['group_id'],
			$membership_id,
			(int) $membership['student_id'],
			$reason,
			$actor_user_id
		);

		return true;
	}

	/**
	 * @return int|WP_Error New membership id in the target group.
	 */
	public function transfer_member( int $actor_user_id, int $membership_id, int $to_group_id, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'reason_required', __( 'A reason for the transfer is required.', 'minhaj-core' ) );
		}

		$membership = $this->repo->find_membership( $membership_id );
		if ( null === $membership ) {
			return new WP_Error( 'member_not_found', __( 'Membership not found.', 'minhaj-core' ) );
		}

		$from_group_id = (int) $membership['group_id'];
		if ( $from_group_id === $to_group_id ) {
			return new WP_Error( 'invalid_transfer', __( 'Cannot transfer to the same group.', 'minhaj-core' ) );
		}

		$new_membership_id = 0;
		$seat              = 0;

		$this->repo->begin_transaction();
		try {
			// Consistent lock order avoids deadlocks between transfers going in opposite directions.
			$first  = min( $from_group_id, $to_group_id );
			$second = max( $from_group_id, $to_group_id );
			$this->repo->find_group_for_update( $first );
			$this->repo->find_group_for_update( $second );

			$target = $this->repo->find_group( $to_group_id );
			if ( null === $target ) {
				$this->repo->rollback();
				return new WP_Error( 'group_not_found', __( 'Target group not found.', 'minhaj-core' ) );
			}

			$active = $this->repo->count_active_members( $to_group_id );
			try {
				GroupRules::assert_seat_available( $active, (int) $target['capacity_max'] );
			} catch ( RuleViolationException $e ) {
				$this->repo->rollback();
				return new WP_Error( 'group_full', $e->getMessage(), array( 'rule' => $e->rule_code() ) );
			}

			$seat = $this->smallest_free_seat(
				$this->repo->find_used_seat_indices( $to_group_id ),
				(int) $target['capacity_max']
			);
			if ( null === $seat ) {
				$this->repo->rollback();
				return new WP_Error( 'group_full', __( 'No seat available in target group.', 'minhaj-core' ) );
			}

			$now = current_time( 'mysql', true );

			$this->repo->update_member(
				$membership_id,
				array(
					'status'                  => 'transferred_out',
					'left_at'                 => $now,
					'transferred_to_group_id' => $to_group_id,
				)
			);

			try {
				$new_membership_id = $this->repo->insert_member(
					array(
						'group_id'                  => $to_group_id,
						'student_id'                => (int) $membership['student_id'],
						'status'                    => 'active',
						'joined_at'                 => $now,
						'seat_index'                => $seat,
						'transferred_from_group_id' => $from_group_id,
					)
				);
			} catch ( PersistenceException $e ) {
				$this->repo->rollback();
				if ( PersistenceException::DUPLICATE_SEAT === $e->kind() ) {
					return new WP_Error( 'group_full', __( 'Seat taken by concurrent request.', 'minhaj-core' ) );
				}
				return new WP_Error( 'persistence_error', $e->getMessage() );
			}

			$audit_payload = array(
				'student_id'    => (int) $membership['student_id'],
				'from_group_id' => $from_group_id,
				'to_group_id'   => $to_group_id,
				'reason'        => $reason,
			);

			$this->repo->insert_audit(
				array(
					'group_id'      => $from_group_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'member.transferred_out',
					'subject_id'    => $membership_id,
					'payload_json'  => (string) wp_json_encode( $audit_payload ),
					'created_at'    => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'group_id'      => $to_group_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'member.transferred_in',
					'subject_id'    => $new_membership_id,
					'payload_json'  => (string) wp_json_encode( $audit_payload ),
					'created_at'    => $now,
				)
			);

			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action(
			Events::MEMBER_TRANSFERRED,
			(int) $membership['student_id'],
			$from_group_id,
			$to_group_id,
			$new_membership_id,
			$reason,
			$actor_user_id
		);

		return $new_membership_id;
	}

	/**
	 * @return true|WP_Error
	 */
	public function assign_teacher( int $actor_user_id, int $group_id, int $teacher_id, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $teacher_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'teacher_id is required.', 'minhaj-core' ) );
		}

		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'reason_required', __( 'A reason is required.', 'minhaj-core' ) );
		}

		/**
		 * Filter · assignability gate for the teacher. spec-people-v1 S-4
		 * hooks in here to reject teachers without a valid safeguarding
		 * check, a declared teaching language, or `active` status. We use
		 * a filter rather than a hard cross-module call so Groups stays
		 * loadable without People (tests + phased rollouts).
		 *
		 * Subscribers return `true` to allow, `WP_Error` to block, or a
		 * non-error falsy value which is treated as a generic rejection.
		 *
		 * @param true|WP_Error $verdict    Current verdict.
		 * @param int           $teacher_id Candidate teacher id.
		 * @param int           $group_id   Target group id.
		 */
		$verdict = apply_filters( 'minhaj_group_can_assign_teacher', true, $teacher_id, $group_id );

		if ( is_wp_error( $verdict ) ) {
			return $verdict;
		}

		if ( true !== $verdict ) {
			return new WP_Error( 'rejected', __( 'Teacher assignment vetoed by extension.', 'minhaj-core' ) );
		}

		$previous_teacher_id = 0;

		$this->repo->begin_transaction();
		try {
			$group = $this->repo->find_group_for_update( $group_id );
			if ( null === $group ) {
				$this->repo->rollback();
				return new WP_Error( 'group_not_found', __( 'Group not found.', 'minhaj-core' ) );
			}

			$previous_teacher_id = (int) ( $group['teacher_id'] ?? 0 );
			$now                 = current_time( 'mysql', true );

			$this->repo->update_group(
				$group_id,
				array(
					'teacher_id' => $teacher_id,
					'updated_at' => $now,
				)
			);

			$action = 0 === $previous_teacher_id ? 'group.teacher_assigned' : 'group.teacher_changed';

			$this->repo->insert_audit(
				array(
					'group_id'      => $group_id,
					'actor_user_id' => $actor_user_id,
					'action'        => $action,
					'subject_id'    => $teacher_id,
					'payload_json'  => (string) wp_json_encode(
						array(
							'from_teacher_id' => $previous_teacher_id,
							'to_teacher_id'   => $teacher_id,
							'reason'          => $reason,
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

		$event = 0 === $previous_teacher_id ? Events::TEACHER_ASSIGNED : Events::TEACHER_CHANGED;
		do_action( $event, $group_id, $teacher_id, $previous_teacher_id, $reason, $actor_user_id );

		return true;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return true|WP_Error
	 */
	public function update( int $actor_user_id, int $group_id, array $args ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$allowed = array( 'level', 'teaching_language', 'timezone', 'planned_start_date', 'formation_deadline' );
		$update  = array();
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$update[ $field ] = is_string( $args[ $field ] )
					? sanitize_text_field( $args[ $field ] )
					: $args[ $field ];
			}
		}

		if ( array() === $update ) {
			return new WP_Error( 'nothing_to_update', __( 'No allowed fields present.', 'minhaj-core' ) );
		}

		$this->repo->begin_transaction();
		try {
			$group = $this->repo->find_group_for_update( $group_id );
			if ( null === $group ) {
				$this->repo->rollback();
				return new WP_Error( 'group_not_found', __( 'Group not found.', 'minhaj-core' ) );
			}

			$now                  = current_time( 'mysql', true );
			$update['updated_at'] = $now;

			$this->repo->update_group( $group_id, $update );

			$this->repo->insert_audit(
				array(
					'group_id'      => $group_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'group.updated',
					'subject_id'    => $group_id,
					'payload_json'  => (string) wp_json_encode( $update ),
					'created_at'    => $now,
				)
			);

			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		return true;
	}

	// ------------------------------------------------------------ Helpers.

	/**
	 * @param array<int, int> $used_seats
	 */
	private function smallest_free_seat( array $used_seats, int $capacity_max ): ?int {
		$used = array_flip( $used_seats );
		for ( $i = 1; $i <= $capacity_max; $i++ ) {
			if ( ! isset( $used[ $i ] ) ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * @return true|WP_Error
	 */
	private function require_actor( int $actor_user_id ) {
		if ( $actor_user_id <= 0 ) {
			return new WP_Error( 'missing_actor', __( 'actor_user_id must be a positive integer — audit rows cannot be anonymous.', 'minhaj-core' ) );
		}

		return true;
	}
}
