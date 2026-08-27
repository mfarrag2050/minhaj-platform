<?php
/**
 * Single source of truth for two derived columns on `wp_minhaj_groups`:
 *
 *   • `expected_end_date`      — DATE(MAX(scheduled_start_utc)) across
 *                                sessions that still count toward the
 *                                commitment (excludes cancelled and
 *                                unscheduled). NULL when no dated session
 *                                exists yet — the derivation refuses to
 *                                guess.
 *   • `has_unscheduled_makeup` — 1 when the group carries at least one
 *                                pending unscheduled make-up; 0 otherwise.
 *                                Paired with expected_end_date so the UI
 *                                can flag "this end date may still shift".
 *
 * spec-groups-v1 §3.1 documents the rule; this listener is the single
 * point that writes both. Nothing in the view layer recomputes the values —
 * one number, everyone sees the same one.
 *
 * The listener reacts to six Timetable events, all of which have either
 * created / cancelled / rescheduled / scheduled / completed a session or
 * moved a make-up between the queue and the calendar. Every reaction is
 * a full recompute for the affected group_id — cheap, always correct.
 *
 * @package Minhaj\Modules\Timetable
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable;

use Minhaj\Modules\Timetable\Repository\TimetableRepository;

defined( 'ABSPATH' ) || exit;

final class SessionDerivedDatesListener {

	public function __construct( private readonly TimetableRepository $repo ) {}

	/**
	 * Subscribe once per event. The wrappers exist because Timetable events
	 * pass group_id in different positions — SESSIONS_GENERATED puts it
	 * first, every other event follows (session_id, group_id, …).
	 */
	public function register(): void {
		add_action(
			Events::SESSIONS_GENERATED,
			function ( int $group_id ): void {
				$this->recompute_for_group( $group_id );
			},
			10,
			1
		);

		$session_id_then_group = function ( int $session_id, int $group_id ): void {
			unset( $session_id );
			$this->recompute_for_group( $group_id );
		};

		add_action( Events::SESSION_CANCELLED, $session_id_then_group, 10, 2 );
		add_action( Events::SESSION_RESCHEDULED, $session_id_then_group, 10, 2 );
		add_action( Events::MAKEUP_UNSCHEDULED, $session_id_then_group, 10, 2 );
		add_action( Events::MAKEUP_SCHEDULED, $session_id_then_group, 10, 2 );
		add_action( Events::SESSION_COMPLETED, $session_id_then_group, 10, 2 );
	}

	/**
	 * Recompute both derived fields and persist them in one write. Public so
	 * tests can call it directly without going through add_action.
	 */
	public function recompute_for_group( int $group_id ): void {
		if ( $group_id <= 0 ) {
			return;
		}

		$expected_end_date      = $this->repo->max_active_scheduled_date_for_group( $group_id );
		$has_unscheduled_makeup = $this->repo->count_unscheduled_makeups_for_group( $group_id ) > 0 ? 1 : 0;

		$this->repo->update_group_derived_dates( $group_id, $expected_end_date, $has_unscheduled_makeup );
	}
}
