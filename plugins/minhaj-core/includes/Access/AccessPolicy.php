<?php
/**
 * spec-access-v1 §6 — single decision engine for the platform.
 *
 * Every call takes `int $user_id` and `int $subject_id` explicitly (A-3):
 *   `get_current_user_id()` breaks in cron, CLI, and Zoom webhooks — the
 *   places that most need access decisions. The rule is enforced by the
 *   CI grep test in tests/Unit/Access/NoImplicitActorGrepTest.php so the
 *   convention cannot regress.
 *
 * Per-request memoisation only. A decision from a previous request is never
 * reused — guardianship changes must land in the very next call (A-7).
 *
 * `AccessRepository` writes nothing outside `record_denial()`; this class
 * makes no other writes and opens no transactions.
 *
 * @package Minhaj\Access
 */

declare( strict_types=1 );

namespace Minhaj\Access;

defined( 'ABSPATH' ) || exit;

/*
 * The messages built here relay validated capability strings and integer ids
 * only — never user-supplied HTML — so the WPCS output-escape sniff is
 * disabled at this boundary. Callers escape at render.
 */
// phpcs:disable WordPress.Security.EscapeOutput

final class AccessPolicy {

	/**
	 * Per-request memoisation for the list queries. Keyed by user id. Never
	 * survives the request — see class doc for why.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $visible_groups_cache = array();

	/**
	 * @var array<int, array<int, int>>
	 */
	private array $visible_students_cache = array();

	/**
	 * @var array<int, null|array<int, int>>
	 */
	private array $org_ids_cache = array();

	public function __construct( private readonly AccessRepository $repo ) {}

	// ================================================================== Decisions.

	public function can_view_group( int $user_id, int $group_id ): bool {
		if ( $user_id <= 0 || $group_id <= 0 ) {
			return $this->finalise( false, 'view_group', $user_id, $group_id, 'group' );
		}

		$group = $this->repo->find_group( $group_id );
		if ( null === $group ) {
			// group_not_found is not a subject leak — no denial audit.
			return $this->apply_filter( false, 'view_group', $user_id, $group_id );
		}

		if ( $this->is_platform_admin( $user_id ) ) {
			return $this->apply_filter( true, 'view_group', $user_id, $group_id );
		}

		if ( $this->is_org_admin_for( $user_id, (int) ( $group['org_id'] ?? 0 ) ) ) {
			return $this->apply_filter( true, 'view_group', $user_id, $group_id );
		}

		$caps = Capabilities::map();

		if (
			user_can( $user_id, $caps[ Capabilities::VIEW_GROUP ] )
			&& (int) ( $group['teacher_id'] ?? 0 ) === $user_id
		) {
			return $this->apply_filter( true, 'view_group', $user_id, $group_id );
		}

		if ( user_can( $user_id, $caps[ Capabilities::VIEW_OWN_CHILD_GROUP ] ) ) {
			$wards = $this->repo->list_active_ward_ids_of_guardian( $user_id );
			foreach ( $wards as $ward_id ) {
				if ( $this->repo->is_student_anonymized( $ward_id ) ) {
					continue;
				}
				if ( $this->repo->is_active_member( $group_id, $ward_id ) ) {
					return $this->apply_filter( true, 'view_group', $user_id, $group_id );
				}
			}
		}

		return $this->finalise( false, 'view_group', $user_id, $group_id, 'group' );
	}

	public function can_view_student( int $user_id, int $student_id ): bool {
		if ( $user_id <= 0 || $student_id <= 0 ) {
			return $this->finalise( false, 'view_student', $user_id, $student_id, 'student' );
		}

		if ( $this->repo->is_student_anonymized( $student_id ) && ! $this->is_platform_admin( $user_id ) ) {
			return $this->finalise( false, 'view_student', $user_id, $student_id, 'student' );
		}

		if ( $this->is_platform_admin( $user_id ) ) {
			return $this->apply_filter( true, 'view_student', $user_id, $student_id );
		}

		// Self-view · decision 18 · the actor is a WordPress user id and
		// students.id is not; the pre-decision `$user_id === $student_id`
		// was silently coincidental only when children happened to be WP
		// users. Now we look up the optional user_id link and compare
		// against that. NULL user_id (the default) means "child, cannot
		// self-view" — and the check is skipped.
		$student_profile = $this->repo->find_student_profile( $student_id );
		if ( null !== $student_profile ) {
			$linked_user_id = null === $student_profile['user_id'] ? 0 : (int) $student_profile['user_id'];
			if ( $linked_user_id > 0 && $linked_user_id === $user_id ) {
				return $this->apply_filter( true, 'view_student', $user_id, $student_id );
			}
		}

		if ( $this->repo->is_active_guardian_with_view( $user_id, $student_id ) ) {
			return $this->apply_filter( true, 'view_student', $user_id, $student_id );
		}

		// Teacher may see students in groups they teach (first-name + family-initial
		// only per A-4 — that is a presentation constraint enforced at the query
		// layer, not here). The permission itself lives on the group membership.
		$caps = Capabilities::map();
		if ( user_can( $user_id, $caps[ Capabilities::VIEW_GROUP ] ) ) {
			$teacher_groups = $this->repo->list_group_ids_for_teacher( $user_id );
			$student_groups = $this->repo->list_active_group_ids_of_student( $student_id );
			if ( array() !== array_intersect( $teacher_groups, $student_groups ) ) {
				return $this->apply_filter( true, 'view_student', $user_id, $student_id );
			}
		}

		// Org admin sees the student iff the student's origin org is in scope.
		if ( null !== $student_profile ) {
			$origin_org = (int) ( $student_profile['origin_org_id'] ?? 0 );
			if ( $origin_org > 0 && $this->is_org_admin_for( $user_id, $origin_org ) ) {
				return $this->apply_filter( true, 'view_student', $user_id, $student_id );
			}
		}

		return $this->finalise( false, 'view_student', $user_id, $student_id, 'student' );
	}

	public function can_view_session( int $user_id, int $session_id ): bool {
		if ( $user_id <= 0 || $session_id <= 0 ) {
			return $this->finalise( false, 'view_session', $user_id, $session_id, 'session' );
		}

		$session = $this->repo->find_session( $session_id );
		if ( null === $session ) {
			return $this->apply_filter( false, 'view_session', $user_id, $session_id );
		}

		// The row-scope check reuses group visibility so a teacher of the group
		// or a parent of any active ward in it passes without duplicating rules.
		$group_id = (int) ( $session['group_id'] ?? 0 );
		if ( $group_id > 0 && $this->can_view_group( $user_id, $group_id ) ) {
			return $this->apply_filter( true, 'view_session', $user_id, $session_id );
		}

		return $this->finalise( false, 'view_session', $user_id, $session_id, 'session' );
	}

	public function can_record_attendance( int $user_id, int $session_id ): bool {
		if ( $user_id <= 0 || $session_id <= 0 ) {
			return $this->finalise( false, 'record_attendance', $user_id, $session_id, 'attendance' );
		}

		$caps = Capabilities::map();
		if ( ! user_can( $user_id, $caps[ Capabilities::RECORD_ATTENDANCE ] ) ) {
			return $this->finalise( false, 'record_attendance', $user_id, $session_id, 'attendance' );
		}

		$session = $this->repo->find_session( $session_id );
		if ( null === $session ) {
			return $this->apply_filter( false, 'record_attendance', $user_id, $session_id );
		}

		if ( $this->is_platform_admin( $user_id ) ) {
			return $this->apply_filter( true, 'record_attendance', $user_id, $session_id );
		}

		if ( (int) ( $session['teacher_id'] ?? 0 ) === $user_id ) {
			return $this->apply_filter( true, 'record_attendance', $user_id, $session_id );
		}

		return $this->finalise( false, 'record_attendance', $user_id, $session_id, 'attendance' );
	}

	public function can_view_recording( int $user_id, int $recording_id ): bool {
		if ( $user_id <= 0 || $recording_id <= 0 ) {
			return $this->finalise( false, 'view_recording', $user_id, $recording_id, 'recording' );
		}

		$caps = Capabilities::map();
		if ( ! user_can( $user_id, $caps[ Capabilities::VIEW_RECORDING ] ) ) {
			return $this->finalise( false, 'view_recording', $user_id, $recording_id, 'recording' );
		}

		/**
		 * Filter · spec-recordings-v1 plugs the concrete lookup in here. Until
		 * that module ships, no code path can hand out a recording — the
		 * default remains false.
		 *
		 * A subscriber returns true only after verifying the caller belongs
		 * to the recording's group per its own §-A-5 rules.
		 *
		 * @param bool $decision   Default false.
		 * @param int  $user_id
		 * @param int  $recording_id
		 */
		$decision = (bool) apply_filters( 'minhaj_access_can_view_recording', false, $user_id, $recording_id );

		if ( $decision ) {
			return $this->apply_filter( true, 'view_recording', $user_id, $recording_id );
		}

		return $this->finalise( false, 'view_recording', $user_id, $recording_id, 'recording' );
	}

	/**
	 * @return 'host'|'participant'|false
	 */
	public function join_role( int $user_id, int $session_id, ?int $subject_student_id = null ): string|false {
		if ( $user_id <= 0 || $session_id <= 0 ) {
			$this->finalise( false, 'join_session', $user_id, $session_id, 'join' );
			return false;
		}

		$session = $this->repo->find_session( $session_id );
		if ( null === $session ) {
			return false;
		}

		$caps = Capabilities::map();
		if ( ! user_can( $user_id, $caps[ Capabilities::JOIN_SESSION ] ) ) {
			$this->finalise( false, 'join_session', $user_id, $session_id, 'join' );
			return false;
		}

		$teacher_id = (int) ( $session['teacher_id'] ?? 0 );
		if ( $teacher_id === $user_id ) {
			return 'host';
		}

		$group_id = (int) ( $session['group_id'] ?? 0 );
		if ( $group_id <= 0 ) {
			$this->finalise( false, 'join_session', $user_id, $session_id, 'join' );
			return false;
		}

		// Student joining themselves.
		if ( null === $subject_student_id || $subject_student_id === $user_id ) {
			if ( $this->repo->is_student_anonymized( $user_id ) ) {
				$this->finalise( false, 'join_session', $user_id, $session_id, 'join' );
				return false;
			}
			if ( $this->repo->is_active_member( $group_id, $user_id ) ) {
				return 'participant';
			}
		}

		// Guardian joining on behalf of a specific ward.
		if ( null !== $subject_student_id && $subject_student_id !== $user_id ) {
			if ( $this->repo->is_student_anonymized( $subject_student_id ) ) {
				$this->finalise( false, 'join_session', $user_id, $session_id, 'join' );
				return false;
			}
			if ( ! $this->repo->is_active_guardian_with_view( $user_id, $subject_student_id ) ) {
				$this->finalise( false, 'join_session', $user_id, $session_id, 'join' );
				return false;
			}
			if ( $this->repo->is_active_member( $group_id, $subject_student_id ) ) {
				return 'participant';
			}
		}

		$this->finalise( false, 'join_session', $user_id, $session_id, 'join' );
		return false;
	}

	// ===================================================================== Lists.

	/**
	 * @return array<int, int>
	 */
	public function visible_group_ids_for( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		if ( isset( $this->visible_groups_cache[ $user_id ] ) ) {
			return $this->visible_groups_cache[ $user_id ];
		}

		$ids                                    = $this->compute_visible_group_ids( $user_id );
		$this->visible_groups_cache[ $user_id ] = $ids;

		return $ids;
	}

	/**
	 * @return array<int, int>
	 */
	public function visible_student_ids_for( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		if ( isset( $this->visible_students_cache[ $user_id ] ) ) {
			return $this->visible_students_cache[ $user_id ];
		}

		$ids                                      = $this->compute_visible_student_ids( $user_id );
		$this->visible_students_cache[ $user_id ] = $ids;

		return $ids;
	}

	/**
	 * The user's org-dimension scope. Two distinct return values:
	 *
	 *   • **`null`** — the org dimension does not restrict this user. Platform
	 *     staff (`MANAGE_GROUPS`), parents, and teachers without a
	 *     `MANAGE_ORG` cap all fall here. Their access is scoped by other
	 *     axes (guardianship, teacher_id) not by org membership.
	 *
	 *   • **`array<int, int>`** — the exact set of orgs the user is scoped
	 *     to. Only `MANAGE_ORG` holders receive an array. An **empty array**
	 *     means "org-scoped user with zero active memberships" — they see
	 *     nothing, which is the correct outcome.
	 *
	 * Never conflate the two: `[]` and `null` mean opposite things. Callers
	 * that need a boolean gate use {@see is_org_scoped()} explicitly.
	 *
	 * @return null|array<int, int>
	 */
	public function org_ids_for( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		if ( array_key_exists( $user_id, $this->org_ids_cache ) ) {
			return $this->org_ids_cache[ $user_id ];
		}

		if ( $this->is_platform_admin( $user_id ) ) {
			$this->org_ids_cache[ $user_id ] = null;
			return null;
		}

		$caps = Capabilities::map();
		if ( ! user_can( $user_id, $caps[ Capabilities::MANAGE_ORG ] ) ) {
			$this->org_ids_cache[ $user_id ] = null;
			return null;
		}

		$ids                             = $this->repo->list_org_ids_for_user( $user_id );
		$this->org_ids_cache[ $user_id ] = $ids;

		return $ids;
	}

	/**
	 * True iff this user's access is restricted by the org dimension. False
	 * for platform staff (they see every org), parents, and independent
	 * teachers. True for every `MANAGE_ORG` holder — even ones whose
	 * scope is empty (a suspended org admin sees nothing, correctly).
	 */
	public function is_org_scoped( int $user_id ): bool {
		return null !== $this->org_ids_for( $user_id );
	}

	// ================================================================== Helpers.

	public function is_active_guardian_of( int $guardian_id, int $student_id ): bool {
		if ( $guardian_id <= 0 || $student_id <= 0 ) {
			return false;
		}

		return $this->repo->is_active_guardian_with_view( $guardian_id, $student_id );
	}

	/**
	 * Throw when decision is false. Logs the denial via the same audit path a
	 * silent negative would take, so callers who wrap this catch AccessDenied
	 * without losing the audit trail.
	 *
	 * @throws AccessDeniedException When $decision is false.
	 */
	public function assert( bool $decision, string $context, int $user_id, int $subject_id ): void {
		if ( $decision ) {
			return;
		}

		$this->repo->record_denial(
			$this->subject_type_for( $context ),
			$user_id,
			$subject_id,
			$context,
			array( 'via' => 'assert' )
		);

		throw new AccessDeniedException( $context, $user_id, $subject_id );
	}

	// ------------------------------------------------------ Internal computation.

	/**
	 * @return array<int, int>
	 */
	private function compute_visible_group_ids( int $user_id ): array {
		// Platform staff transcend the org dimension — return every live group.
		if ( $this->is_platform_admin( $user_id ) ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE deleted_at IS NULL ORDER BY id',
					$wpdb->prefix . 'minhaj_groups'
				)
			);

			return array_map( 'intval', (array) $rows );
		}

		$union = array();
		$caps  = Capabilities::map();

		// Org-scoped admin: intersect with their org membership set. An
		// empty membership set correctly yields zero rows — a suspended
		// org admin sees nothing.
		if ( user_can( $user_id, $caps[ Capabilities::MANAGE_ORG ] ) ) {
			$orgs = $this->org_ids_for( $user_id );
			if ( is_array( $orgs ) && array() !== $orgs ) {
				foreach ( $this->repo->list_group_ids_in_orgs( $orgs ) as $gid ) {
					$union[ $gid ] = $gid;
				}
			}
		}

		if ( user_can( $user_id, $caps[ Capabilities::VIEW_GROUP ] ) ) {
			foreach ( $this->repo->list_group_ids_for_teacher( $user_id ) as $gid ) {
				$union[ $gid ] = $gid;
			}
		}

		if ( user_can( $user_id, $caps[ Capabilities::VIEW_OWN_CHILD_GROUP ] ) ) {
			foreach ( $this->repo->list_active_ward_ids_of_guardian( $user_id ) as $ward_id ) {
				if ( $this->repo->is_student_anonymized( $ward_id ) ) {
					continue;
				}
				foreach ( $this->repo->list_active_group_ids_of_student( $ward_id ) as $gid ) {
					$union[ $gid ] = $gid;
				}
			}
		}

		$union = array_values( $union );
		sort( $union, SORT_NUMERIC );

		return $union;
	}

	/**
	 * @return array<int, int>
	 */
	private function compute_visible_student_ids( int $user_id ): array {
		$union = array();
		$caps  = Capabilities::map();

		if ( $this->is_platform_admin( $user_id ) ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM %i ORDER BY id',
					$wpdb->prefix . 'minhaj_students'
				)
			);

			return array_map( 'intval', (array) $rows );
		}

		if ( user_can( $user_id, $caps[ Capabilities::VIEW_OWN_CHILD_GROUP ] ) ) {
			foreach ( $this->repo->list_active_ward_ids_of_guardian( $user_id ) as $ward_id ) {
				if ( $this->repo->is_student_anonymized( $ward_id ) ) {
					continue;
				}
				$union[ $ward_id ] = $ward_id;
			}
		}

		if ( user_can( $user_id, $caps[ Capabilities::VIEW_GROUP ] ) ) {
			// Teacher sees students of every group they teach.
			global $wpdb;

			$group_ids = $this->repo->list_group_ids_for_teacher( $user_id );
			if ( array() !== $group_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$rows = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT student_id FROM %i WHERE status = 'active' AND group_id IN ({$placeholders})",
						$wpdb->prefix . 'minhaj_group_members',
						...array_map( 'intval', $group_ids )
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

				foreach ( (array) $rows as $sid ) {
					$sid_int = (int) $sid;
					if ( $this->repo->is_student_anonymized( $sid_int ) ) {
						continue;
					}
					$union[ $sid_int ] = $sid_int;
				}
			}
		}

		if ( user_can( $user_id, $caps[ Capabilities::MANAGE_ORG ] ) ) {
			$orgs = $this->org_ids_for( $user_id );
			if ( is_array( $orgs ) && array() !== $orgs ) {
				global $wpdb;

				$placeholders = implode( ',', array_fill( 0, count( $orgs ), '%d' ) );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$rows = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM %i WHERE anonymized_at IS NULL AND origin_org_id IN ({$placeholders})",
						$wpdb->prefix . 'minhaj_students',
						...array_map( 'intval', $orgs )
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

				foreach ( (array) $rows as $sid ) {
					$sid_int           = (int) $sid;
					$union[ $sid_int ] = $sid_int;
				}
			}
		}

		$union = array_values( $union );
		sort( $union, SORT_NUMERIC );

		return $union;
	}

	private function is_platform_admin( int $user_id ): bool {
		$caps = Capabilities::map();
		return user_can( $user_id, $caps[ Capabilities::MANAGE_GROUPS ] );
	}

	private function is_org_admin_for( int $user_id, int $org_id ): bool {
		if ( $org_id <= 0 ) {
			return false;
		}

		$caps = Capabilities::map();
		if ( ! user_can( $user_id, $caps[ Capabilities::MANAGE_ORG ] ) ) {
			return false;
		}

		$scope = $this->org_ids_for( $user_id );

		// A MANAGE_ORG holder never receives null from org_ids_for — the
		// null path is reserved for unbounded users, and they short-circuit
		// through is_platform_admin() well before reaching here. Guard it
		// anyway so a future refactor cannot introduce a silent grant.
		if ( null === $scope ) {
			return false;
		}

		return in_array( $org_id, $scope, true );
	}

	/**
	 * Wrap a decision in the tighten-only filter, then audit if it turned out
	 * to be a denial. Everything that returns from a can_* passes through here.
	 */
	private function finalise(
		bool $decision,
		string $context,
		int $user_id,
		int $subject_id,
		string $subject_type
	): bool {
		$final = $this->apply_filter( $decision, $context, $user_id, $subject_id );

		if ( ! $final && $user_id > 0 && $subject_id > 0 ) {
			$this->repo->record_denial(
				$subject_type,
				$user_id,
				$subject_id,
				$context,
				array()
			);
		}

		return $final;
	}

	/**
	 * spec §6 · the filter is allowed to tighten (true → false) but never to
	 * loosen. An attempt to flip false → true is dropped and a warning is
	 * emitted so a misbehaving plugin cannot silently escalate access.
	 */
	private function apply_filter( bool $base, string $context, int $user_id, int $subject_id ): bool {
		/**
		 * Filter · one interception point for every access decision.
		 *
		 * @param bool   $decision  Base decision.
		 * @param string $action    'view_group' / 'view_session' / ...
		 * @param int    $user_id
		 * @param int    $subject_id
		 */
		$filtered = (bool) apply_filters( 'minhaj_access_decision', $base, $context, $user_id, $subject_id );

		if ( false === $base && true === $filtered ) {
			/**
			 * Action · fired when a subscriber to `minhaj_access_decision`
			 * attempts to loosen a denial and is overridden. Sites that want
			 * to persist this signal wire a handler (write to error_log,
			 * push to a SIEM, page on-call). The class itself does not log
			 * unconditionally — that would drag every unit test into fighting
			 * PHPUnit's failOnWarning.
			 *
			 * @param string $context
			 * @param int    $user_id
			 * @param int    $subject_id
			 */
			do_action( 'minhaj_access_decision_loosen_ignored', $context, $user_id, $subject_id );

			return false;
		}

		return $filtered;
	}

	private function subject_type_for( string $context ): string {
		return match ( $context ) {
			'view_group'        => 'group',
			'view_student'      => 'student',
			'view_session'      => 'session',
			'record_attendance' => 'attendance',
			'view_recording'    => 'recording',
			'join_session'      => 'join',
			default             => 'group',
		};
	}
}
