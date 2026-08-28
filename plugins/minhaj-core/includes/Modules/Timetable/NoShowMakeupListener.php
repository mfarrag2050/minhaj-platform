<?php
/**
 * Post-commit listener that turns a `minhaj_session_no_show` event
 * (fired by AttendanceService — spec-attendance-v1 R-7) into an
 * unscheduled make-up row inside `minhaj_sessions`, so the session
 * shows up in the debt queue and gets scheduled through the same
 * flow the cancellation path uses.
 *
 * IMPORTANT · post-commit hooks are best-effort. A fatal in this
 * listener, a killed worker, or a deferred queue that dropped the
 * job all leave a no_show session without a make-up row. That gap
 * is the reason
 * `TimetableRepository::list_no_show_sessions_without_makeup` exists
 * and is surfaced by `wp minhaj timetable unscheduled-makeups`.
 *
 * @package Minhaj\Modules\Timetable
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable;

use Minhaj\Modules\Timetable\Domain\SessionStatus;
use Minhaj\Modules\Timetable\Repository\TimetableRepository;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class NoShowMakeupListener {

	public function __construct( private readonly TimetableRepository $repo ) {}

	public function register(): void {
		add_action( 'minhaj_session_no_show', array( $this, 'on_no_show' ), 10, 2 );
	}

	public function on_no_show( int $session_id, int $teacher_id ): void {
		if ( $session_id <= 0 ) {
			return;
		}

		$session = $this->repo->find_session( $session_id );
		if ( null === $session ) {
			return;
		}

		$existing = $this->repo->find_makeup_for( $session_id );
		if ( null !== $existing ) {
			return; // idempotent — already reconciled.
		}

		$now = current_time( 'mysql', true );

		$this->repo->begin_transaction();
		try {
			$next_seq         = $this->repo->max_sequence_no_for_group( (int) $session['group_id'] ) + 1;
			$makeup_lesson_no = $this->repo->max_lesson_no_for_group( (int) $session['group_id'] );

			$this->repo->insert_session(
				array(
					'group_id'            => (int) $session['group_id'],
					'pattern_id'          => (int) $session['pattern_id'],
					'sequence_no'         => $next_seq,
					'lesson_no'           => $makeup_lesson_no,
					'scheduled_start_utc' => null,
					'scheduled_end_utc'   => null,
					'local_start_wall'    => null,
					'anchor_timezone'     => (string) $session['anchor_timezone'],
					'teacher_id'          => $teacher_id > 0 ? $teacher_id : (int) $session['teacher_id'],
					'status'              => SessionStatus::UNSCHEDULED,
					'makeup_for_id'       => $session_id,
					'created_at'          => $now,
					'updated_at'          => $now,
				)
			);
			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			// Fail silently — the reconciliation CLI is the safety net.
		}
	}
}
