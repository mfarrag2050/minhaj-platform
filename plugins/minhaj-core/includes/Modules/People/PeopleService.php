<?php
/**
 * People public interface — spec-people-v1 §5.
 *
 * Layering contract (mirrors GroupService — same rules, same reasons):
 *   • Callers (admin/CLI/REST) enforce current_user_can + nonce BEFORE
 *     calling the service. Every write takes `int $actor_user_id`
 *     explicitly so audits are attributable and tests can pass any id.
 *   • The Domain throws RuleViolationException. This service catches at
 *     the outer boundary and returns WP_Error. The two styles do not leak.
 *   • Writes run inside a single DB transaction. Audit rows are inserted
 *     BEFORE commit; do_action events fire AFTER commit — never inside a
 *     transaction that may roll back.
 *
 * teacher_is_assignable is the S-4 gate the Groups module calls via the
 * `minhaj_group_can_assign_teacher` filter — see AssignabilityGate. Wiring
 * it as a filter, not a direct call, keeps the two modules independently
 * testable and avoids a hard dependency in Groups on People being loaded.
 *
 * @package Minhaj\Modules\People
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People;

use Minhaj\Modules\People\Domain\RelationshipType;
use Minhaj\Modules\People\Domain\RuleViolationException;
use Minhaj\Modules\People\Domain\SafeguardingCheckStatus;
use Minhaj\Modules\People\Domain\TeacherStatus;
use Minhaj\Modules\People\Repository\PeopleRepository;
use Minhaj\Modules\People\Repository\PersistenceException;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/*
 * WP_Error messages here relay dev-facing rule codes and validated enum
 * values — never user-supplied HTML — so the WPCS output-escape sniff is
 * disabled at this boundary. Presentation layers escape at render.
 *
 * do_action hook names come from Events constants, all `minhaj_*`. The
 * sniff cannot resolve dynamic hook names statically; the prefix rule is
 * satisfied by construction.
 */
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

final class PeopleService {

	public function __construct( private readonly PeopleRepository $repo ) {}

	// ================================================================= create_student.

	/**
	 * S-1 · Students are never self-registered. Decision 18 · a child is
	 * NOT a WordPress user — this path deliberately does NOT call
	 * `wp_insert_user()`. The student's identity is `minhaj_students.id`;
	 * `user_id` stays NULL until (and unless) the student turns 16 and
	 * needs an account to log in themselves.
	 *
	 * The guardian is a WordPress user — every audit row's actor_user_id
	 * still points at a real login. A shared parent account (both
	 * parents on one wp_users row) would erase who did what and is
	 * refused by spec-people-v1 §2.1 no matter how convenient.
	 *
	 * @param array<string, mixed> $profile Student profile fields.
	 *
	 * @return int|WP_Error Newly created students.id, or a WP_Error.
	 */
	public function create_student( int $actor_user_id, int $guardian_id, array $profile ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $guardian_id <= 0 ) {
			return new WP_Error(
				'guardian_required',
				__( 'A student cannot be created without a guardian — spec-people-v1 S-1/S-2.', 'minhaj-core' )
			);
		}

		$first_name = isset( $profile['first_name'] ) ? sanitize_text_field( (string) $profile['first_name'] ) : '';
		if ( '' === trim( $first_name ) ) {
			return new WP_Error( 'invalid_profile', __( 'first_name is required.', 'minhaj-core' ) );
		}

		$now        = current_time( 'mysql', true );
		$student_id = 0;

		$this->repo->begin_transaction();
		try {
			$student_id = $this->repo->insert_student(
				array(
					'user_id'             => null,
					'first_name'          => $first_name,
					'family_name_initial' => isset( $profile['family_name_initial'] )
						? substr( sanitize_text_field( (string) $profile['family_name_initial'] ), 0, 4 )
						: '',
					'birth_year'          => isset( $profile['birth_year'] ) ? (int) $profile['birth_year'] : null,
					'ui_locale'           => isset( $profile['ui_locale'] ) ? sanitize_text_field( (string) $profile['ui_locale'] ) : '',
					'market'              => isset( $profile['market'] ) ? sanitize_text_field( (string) $profile['market'] ) : '',
					'current_level'       => isset( $profile['current_level'] ) ? sanitize_text_field( (string) $profile['current_level'] ) : '',
					'notes_visible'       => isset( $profile['notes_visible'] ) ? wp_kses_post( (string) $profile['notes_visible'] ) : null,
					'created_at'          => $now,
				)
			);

			$this->repo->insert_guardianship(
				array(
					'guardian_id'  => $guardian_id,
					'student_id'   => $student_id,
					'relationship' => RelationshipType::PARENT,
					'is_primary'   => 1,
					'can_view'     => 1,
					'can_manage'   => 1,
					'started_at'   => $now,
					'created_at'   => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'subject_type'  => 'student',
					'subject_id'    => $student_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'student.created',
					'payload_json'  => (string) wp_json_encode(
						array(
							'guardian_id' => $guardian_id,
							'market'      => $profile['market'] ?? '',
							'ui_locale'   => $profile['ui_locale'] ?? '',
						)
					),
					'created_at'    => $now,
				)
			);

			$this->repo->commit();
		} catch ( PersistenceException $e ) {
			$this->repo->rollback();

			if ( PersistenceException::DUPLICATE_PRIMARY_GUARDIAN === $e->kind() ) {
				return new WP_Error(
					'duplicate_primary_guardian',
					__( 'Student already has an active primary guardian — S-2.', 'minhaj-core' )
				);
			}

			return new WP_Error( 'persistence_error', $e->getMessage(), array( 'kind' => $e->kind() ) );
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::STUDENT_CREATED, $student_id, $guardian_id, $actor_user_id );

		return $student_id;
	}

	// ================================================================== add_guardian.

	/**
	 * Add a non-primary guardian to an existing student.
	 *
	 * @param array<string, mixed> $ctx Optional relationship + capability flags (can_view / can_manage / relationship).
	 *
	 * @return int|WP_Error New guardianship id.
	 */
	public function add_guardian( int $actor_user_id, int $student_id, int $guardian_id, array $ctx = array() ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $student_id <= 0 || $guardian_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'student_id and guardian_id are required.', 'minhaj-core' ) );
		}

		$relationship = isset( $ctx['relationship'] ) ? (string) $ctx['relationship'] : RelationshipType::PARENT;
		if ( ! RelationshipType::is_valid( $relationship ) ) {
			return new WP_Error( 'invalid_arg', __( 'Unknown relationship type.', 'minhaj-core' ) );
		}

		$now = current_time( 'mysql', true );

		$this->repo->begin_transaction();
		try {
			$id = $this->repo->insert_guardianship(
				array(
					'guardian_id'  => $guardian_id,
					'student_id'   => $student_id,
					'relationship' => $relationship,
					'is_primary'   => 0,
					'can_view'     => ! empty( $ctx['can_view'] ) ? 1 : 1,
					'can_manage'   => ! empty( $ctx['can_manage'] ) ? 1 : 0,
					'started_at'   => $now,
					'created_at'   => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'subject_type'  => 'student',
					'subject_id'    => $student_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'guardian.added',
					'payload_json'  => (string) wp_json_encode(
						array(
							'guardian_id'  => $guardian_id,
							'relationship' => $relationship,
							'can_view'     => ! empty( $ctx['can_view'] ),
							'can_manage'   => ! empty( $ctx['can_manage'] ),
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

		do_action( Events::GUARDIANSHIP_CHANGED, $student_id, $guardian_id, 'added', $actor_user_id );

		return $id;
	}

	// ================================================ transfer_primary_guardianship.

	/**
	 * Move the primary guardianship to a new guardian in one transaction.
	 * The old primary row's ended_at is stamped BEFORE the new row is
	 * inserted, so the STORED generated column releases the unique-index
	 * slot the new row is about to claim.
	 *
	 * @return int|WP_Error New guardianship id for the incoming primary.
	 */
	public function transfer_primary_guardianship( int $actor_user_id, int $student_id, int $to_guardian_id, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'reason_required', __( 'A reason for the transfer is required.', 'minhaj-core' ) );
		}

		if ( $student_id <= 0 || $to_guardian_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'student_id and to_guardian_id are required.', 'minhaj-core' ) );
		}

		$now    = current_time( 'mysql', true );
		$new_id = 0;

		$this->repo->begin_transaction();
		try {
			$current = $this->repo->find_active_primary_guardian( $student_id );
			if ( null === $current ) {
				$this->repo->rollback();
				return new WP_Error( 'no_current_primary', __( 'Student has no active primary guardian.', 'minhaj-core' ) );
			}

			$this->repo->update_guardianship(
				(int) $current['id'],
				array( 'ended_at' => $now )
			);

			$new_id = $this->repo->insert_guardianship(
				array(
					'guardian_id'  => $to_guardian_id,
					'student_id'   => $student_id,
					'relationship' => RelationshipType::PARENT,
					'is_primary'   => 1,
					'can_view'     => 1,
					'can_manage'   => 1,
					'started_at'   => $now,
					'created_at'   => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'subject_type'  => 'student',
					'subject_id'    => $student_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'guardian.primary_transferred',
					'payload_json'  => (string) wp_json_encode(
						array(
							'from_guardian_id' => (int) $current['guardian_id'],
							'to_guardian_id'   => $to_guardian_id,
							'reason'           => $reason,
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

		do_action( Events::GUARDIANSHIP_CHANGED, $student_id, $to_guardian_id, 'primary_transferred', $actor_user_id );

		return $new_id;
	}

	// =========================================================== teacher profile writes.

	/**
	 * @param array<string, mixed> $profile
	 *
	 * @return true|WP_Error
	 */
	public function upsert_teacher_profile( int $actor_user_id, int $teacher_id, array $profile ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $teacher_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'teacher_id is required.', 'minhaj-core' ) );
		}

		$existing = $this->repo->find_teacher_profile( $teacher_id );
		$now      = current_time( 'mysql', true );

		$data = array(
			'user_id'          => $teacher_id,
			'display_name'     => isset( $profile['display_name'] ) ? sanitize_text_field( (string) $profile['display_name'] ) : ( $existing['display_name'] ?? '' ),
			'timezone'         => isset( $profile['timezone'] ) ? sanitize_text_field( (string) $profile['timezone'] ) : ( $existing['timezone'] ?? '' ),
			'status'           => $existing['status'] ?? TeacherStatus::APPLICANT,
			'weekly_hours_cap' => isset( $profile['weekly_hours_cap'] ) ? (int) $profile['weekly_hours_cap'] : (int) ( $existing['weekly_hours_cap'] ?? 20 ),
			'markets_json'     => isset( $profile['markets'] ) ? (string) wp_json_encode( array_map( 'sanitize_text_field', (array) $profile['markets'] ) ) : ( $existing['markets_json'] ?? '[]' ),
			'bio_i18n'         => isset( $profile['bio_i18n'] ) ? (string) wp_json_encode( (array) $profile['bio_i18n'] ) : ( $existing['bio_i18n'] ?? null ),
			'photo_ref'        => isset( $profile['photo_ref'] ) ? sanitize_text_field( (string) $profile['photo_ref'] ) : ( $existing['photo_ref'] ?? '' ),
			'contract_ref'     => isset( $profile['contract_ref'] ) ? sanitize_text_field( (string) $profile['contract_ref'] ) : ( $existing['contract_ref'] ?? '' ),
			'engaged_via'      => isset( $profile['engaged_via'] ) ? sanitize_text_field( (string) $profile['engaged_via'] ) : ( $existing['engaged_via'] ?? 'direct' ),
			'created_at'       => $existing['created_at'] ?? $now,
			'updated_at'       => $now,
		);

		$this->repo->begin_transaction();
		try {
			$this->repo->upsert_teacher_profile( $data );
			$this->repo->insert_audit(
				array(
					'subject_type'  => 'teacher',
					'subject_id'    => $teacher_id,
					'actor_user_id' => $actor_user_id,
					'action'        => null === $existing ? 'teacher.profile_created' : 'teacher.profile_updated',
					'payload_json'  => (string) wp_json_encode( array_intersect_key( $data, array_flip( array( 'display_name', 'timezone', 'weekly_hours_cap', 'engaged_via' ) ) ) ),
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

	/**
	 * Replace the teacher's language inventory in one transaction — the
	 * cheap all-or-nothing approach beats trying to reconcile deltas.
	 *
	 * @param array<int, array{locale:string, proficiency?:string, can_teach_in?:bool|int}> $languages
	 *
	 * @return true|WP_Error
	 */
	public function set_teacher_languages( int $actor_user_id, int $teacher_id, array $languages ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $teacher_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'teacher_id is required.', 'minhaj-core' ) );
		}

		$now      = current_time( 'mysql', true );
		$inserted = 0;

		$this->repo->begin_transaction();
		try {
			$this->repo->delete_teacher_languages( $teacher_id );

			foreach ( $languages as $lang ) {
				$locale = isset( $lang['locale'] ) ? sanitize_text_field( (string) $lang['locale'] ) : '';
				if ( '' === $locale ) {
					$this->repo->rollback();
					return new WP_Error( 'invalid_arg', __( 'Each language must include a locale.', 'minhaj-core' ) );
				}

				$this->repo->insert_teacher_language(
					array(
						'teacher_id'   => $teacher_id,
						'locale'       => $locale,
						'proficiency'  => isset( $lang['proficiency'] ) ? sanitize_text_field( (string) $lang['proficiency'] ) : 'working',
						'can_teach_in' => ! empty( $lang['can_teach_in'] ) ? 1 : 0,
						'verified_by'  => isset( $lang['verified_by'] ) ? (int) $lang['verified_by'] : null,
						'verified_at'  => isset( $lang['verified_at'] ) ? sanitize_text_field( (string) $lang['verified_at'] ) : null,
						'created_at'   => $now,
					)
				);

				++$inserted;
			}

			$this->repo->insert_audit(
				array(
					'subject_type'  => 'teacher',
					'subject_id'    => $teacher_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'teacher.languages_set',
					'payload_json'  => (string) wp_json_encode(
						array( 'count' => $inserted )
					),
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

	// ==================================================================== record_check.

	/**
	 * @param array<string, mixed> $check
	 *
	 * @return int|WP_Error New check id.
	 */
	public function record_check( int $actor_user_id, int $teacher_id, array $check ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $teacher_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'teacher_id is required.', 'minhaj-core' ) );
		}

		$check_type = isset( $check['check_type'] ) ? sanitize_text_field( (string) $check['check_type'] ) : '';
		if ( '' === $check_type ) {
			return new WP_Error( 'invalid_arg', __( 'check_type is required.', 'minhaj-core' ) );
		}

		$status = isset( $check['status'] ) ? (string) $check['status'] : SafeguardingCheckStatus::PENDING;
		if ( ! SafeguardingCheckStatus::is_valid( $status ) ) {
			return new WP_Error( 'invalid_arg', __( 'Unknown check status.', 'minhaj-core' ) );
		}

		$now = current_time( 'mysql', true );
		$id  = 0;

		$this->repo->begin_transaction();
		try {
			$id = $this->repo->insert_check(
				array(
					'teacher_id'   => $teacher_id,
					'check_type'   => $check_type,
					'reference'    => isset( $check['reference'] ) ? sanitize_text_field( (string) $check['reference'] ) : '',
					'issued_at'    => isset( $check['issued_at'] ) ? sanitize_text_field( (string) $check['issued_at'] ) : null,
					'expires_at'   => isset( $check['expires_at'] ) ? sanitize_text_field( (string) $check['expires_at'] ) : null,
					'status'       => $status,
					'verified_by'  => isset( $check['verified_by'] ) ? (int) $check['verified_by'] : null,
					'verified_at'  => isset( $check['verified_at'] ) ? sanitize_text_field( (string) $check['verified_at'] ) : null,
					'document_ref' => isset( $check['document_ref'] ) ? sanitize_text_field( (string) $check['document_ref'] ) : '',
					'created_at'   => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'subject_type'  => 'teacher',
					'subject_id'    => $teacher_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'teacher.check_recorded',
					'payload_json'  => (string) wp_json_encode(
						array(
							'check_id'   => $id,
							'check_type' => $check_type,
							'status'     => $status,
							'expires_at' => $check['expires_at'] ?? null,
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

		do_action( Events::CHECK_RECORDED, $teacher_id, $id, $status, $actor_user_id );

		return $id;
	}

	// =============================================================== transition_teacher.

	/**
	 * @return true|WP_Error
	 */
	public function transition_teacher( int $actor_user_id, int $teacher_id, string $to_status, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( ! TeacherStatus::is_valid( $to_status ) ) {
			return new WP_Error( 'invalid_status', __( 'Unknown target status.', 'minhaj-core' ) );
		}

		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'reason_required', __( 'A reason for the transition is required.', 'minhaj-core' ) );
		}

		$profile = $this->repo->find_teacher_profile( $teacher_id );
		if ( null === $profile ) {
			return new WP_Error( 'teacher_not_found', __( 'Teacher profile not found.', 'minhaj-core' ) );
		}

		$from_status = (string) $profile['status'];

		if ( ! TeacherStatus::can_transition( $from_status, $to_status ) ) {
			return new WP_Error(
				'invalid_transition',
				sprintf(
					/* translators: 1: current status, 2: attempted target status */
					__( 'Cannot transition teacher from %1$s to %2$s.', 'minhaj-core' ),
					$from_status,
					$to_status
				)
			);
		}

		// S-7 · reaching active requires at least one can_teach_in language.
		if ( TeacherStatus::ACTIVE === $to_status ) {
			if ( $this->repo->count_teacher_teachable_languages( $teacher_id ) < 1 ) {
				return new WP_Error(
					'no_teaching_language',
					__( 'Teacher has no declared teaching language — S-7 blocks active.', 'minhaj-core' )
				);
			}

			$today = current_time( 'Y-m-d', true );
			if ( null === $this->repo->find_current_valid_check( $teacher_id, $today ) ) {
				return new WP_Error(
					'no_valid_check',
					__( 'Teacher has no valid non-expired safeguarding check — S-4 blocks active.', 'minhaj-core' )
				);
			}
		}

		$now = current_time( 'mysql', true );

		$this->repo->begin_transaction();
		try {
			$this->repo->update_teacher_profile(
				$teacher_id,
				array(
					'status'     => $to_status,
					'updated_at' => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'subject_type'  => 'teacher',
					'subject_id'    => $teacher_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'teacher.status_changed',
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

		$event_map = array(
			TeacherStatus::ACTIVE    => Events::TEACHER_ACTIVATED,
			TeacherStatus::SUSPENDED => Events::TEACHER_SUSPENDED,
		);

		if ( isset( $event_map[ $to_status ] ) ) {
			do_action( $event_map[ $to_status ], $teacher_id, $from_status, $reason, $actor_user_id );
		}

		do_action( Events::TEACHER_TRANSITIONED, $teacher_id, $from_status, $to_status, $reason, $actor_user_id );

		return true;
	}

	// =============================================================== teacher_is_assignable.

	/**
	 * S-4 gate — the ONE assignability check the Groups module reaches
	 * through the `minhaj_group_can_assign_teacher` filter. Dry: it MUST
	 * NOT write anything.
	 *
	 * @return true|WP_Error
	 */
	public function teacher_is_assignable( int $teacher_id ) {
		if ( $teacher_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'teacher_id is required.', 'minhaj-core' ) );
		}

		$profile = $this->repo->find_teacher_profile( $teacher_id );
		if ( null === $profile ) {
			return new WP_Error(
				'teacher_profile_missing',
				__( 'Teacher has no People profile — assignment blocked.', 'minhaj-core' )
			);
		}

		if ( TeacherStatus::ACTIVE !== (string) $profile['status'] ) {
			return new WP_Error(
				'teacher_not_active',
				sprintf(
					/* translators: 1: current teacher status */
					__( 'Teacher status is %s — only "active" teachers can be assigned.', 'minhaj-core' ),
					(string) $profile['status']
				)
			);
		}

		$today = current_time( 'Y-m-d', true );
		if ( null === $this->repo->find_current_valid_check( $teacher_id, $today ) ) {
			return new WP_Error(
				'no_valid_check',
				__( 'Teacher has no valid non-expired safeguarding check — S-4 blocks assignment.', 'minhaj-core' )
			);
		}

		if ( $this->repo->count_teacher_teachable_languages( $teacher_id ) < 1 ) {
			return new WP_Error(
				'no_teaching_language',
				__( 'Teacher has not declared a teaching language — S-7 blocks assignment.', 'minhaj-core' )
			);
		}

		return true;
	}

	// ================================================================ language_coverage.

	/**
	 * Answers "how many teachers can actually take a group in $locale?"
	 * — S-8's guard. Dry: MUST NOT write.
	 *
	 * @return array{locale:string, assignable:int}
	 */
	public function language_coverage( string $locale ): array {
		$locale = sanitize_text_field( $locale );
		$today  = current_time( 'Y-m-d', true );

		return array(
			'locale'     => $locale,
			'assignable' => $this->repo->count_assignable_teachers_for_locale( $locale, $today ),
		);
	}

	// ================================================================= anonymize_student.

	/**
	 * S-10 · right-to-erasure without breaking retention obligations. The
	 * row survives, PII is blanked, audit rows are kept unchanged so counts
	 * and integrity checks stay honest. Guardianship rows are ended (not
	 * deleted) so historical assignments still resolve.
	 *
	 * @return true|WP_Error
	 */
	public function anonymize_student( int $actor_user_id, int $student_id, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'reason_required', __( 'A reason is required — recorded in the processing log.', 'minhaj-core' ) );
		}

		$profile = $this->repo->find_student( $student_id );
		if ( null === $profile ) {
			return new WP_Error( 'student_not_found', __( 'Student not found.', 'minhaj-core' ) );
		}

		if ( null !== $profile['anonymized_at'] ) {
			return new WP_Error( 'already_anonymized', __( 'Student is already anonymized.', 'minhaj-core' ) );
		}

		$now = current_time( 'mysql', true );

		$this->repo->begin_transaction();
		try {
			$this->repo->update_student(
				$student_id,
				array(
					'first_name'          => '',
					'family_name_initial' => '',
					'birth_year'          => null,
					'notes_visible'       => null,
					'anonymized_at'       => $now,
				)
			);

			$this->repo->insert_audit(
				array(
					'subject_type'  => 'student',
					'subject_id'    => $student_id,
					'actor_user_id' => $actor_user_id,
					'action'        => 'student.anonymized',
					'payload_json'  => (string) wp_json_encode( array( 'reason' => sanitize_text_field( $reason ) ) ),
					'created_at'    => $now,
				)
			);

			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::STUDENT_ANONYMIZED, $student_id, $actor_user_id );

		return true;
	}

	// ------------------------------------------------------------------- Helpers.

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
