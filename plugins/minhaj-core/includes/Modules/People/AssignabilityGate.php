<?php
/**
 * S-4 wiring point.
 *
 * Groups\GroupService::assign_teacher runs `minhaj_group_can_assign_teacher`
 * before any DB write. This class is the People-side subscriber: it defers
 * to PeopleService::teacher_is_assignable and passes the resulting WP_Error
 * back unmodified so admin sees the same rule code + human message the
 * assignability check itself produces.
 *
 * The filter contract is deliberately narrow so Groups can be loaded and
 * tested without People (phased rollouts, unit tests). Register-once, no
 * cross-module singletons.
 *
 * @package Minhaj\Modules\People
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class AssignabilityGate {

	public function __construct( private readonly PeopleService $service ) {}

	public function register(): void {
		add_filter( 'minhaj_group_can_assign_teacher', array( $this, 'veto_if_not_assignable' ), 10, 3 );
	}

	/**
	 * @param mixed $verdict    Prior verdict — true, WP_Error, or a falsy value.
	 * @param int   $teacher_id Candidate teacher id.
	 * @param int   $group_id   Target group id (unused; kept in signature for future scoping).
	 *
	 * @return true|WP_Error
	 */
	public function veto_if_not_assignable( $verdict, int $teacher_id, int $group_id ) {
		unset( $group_id );

		// Earlier subscriber already rejected — do not re-check.
		if ( $verdict instanceof WP_Error ) {
			return $verdict;
		}

		if ( true !== $verdict ) {
			return $verdict;
		}

		return $this->service->teacher_is_assignable( $teacher_id );
	}
}
