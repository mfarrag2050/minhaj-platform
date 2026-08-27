<?php
/**
 * Pure numbering logic for spec-timetable-v1 §5 + §7 R-9.
 *
 * `sequence_no` is a calendar position — assigned at generation, never moves.
 * `lesson_no` is a curriculum position — advances only when a session is
 * actually held. Cancelling a session clears its `lesson_no` to NULL and
 * shifts down every subsequent held session by one, then a make-up session
 * is appended at the very end of the program (§7 R-9). Nothing between the
 * cancelled slot and the new tail is moved.
 *
 * Kept dependency-free on purpose: the mutation is testable without a
 * database, and the same helper feeds both the service (once implemented)
 * and the reporting layer.
 *
 * @package Minhaj\Modules\Timetable\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable\Domain;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/*
 * Exception messages here are developer-facing (assertion failures on
 * invalid integer inputs). They carry validated integers only — never
 * user-supplied HTML — so the WPCS output-escape sniff is disabled at
 * this boundary. The service layer converts violations to WP_Error.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class LessonNumbering {

	/**
	 * Recompute lesson_no across the program after a single cancellation and
	 * describe the make-up session that must be appended.
	 *
	 * @param int $total_sessions   Total generated sessions (sequence_no runs 1..N).
	 * @param int $cancelled_seq_no The sequence_no being cancelled — 1-indexed.
	 *
	 * @return array{
	 *   renumbering: array<int, int|null>,
	 *   makeup: array{sequence_no:int, lesson_no:int, makeup_for_sequence_no:int}
	 * }
	 */
	public static function cancel_with_makeup( int $total_sessions, int $cancelled_seq_no ): array {
		if ( $total_sessions < 1 ) {
			throw new InvalidArgumentException( 'total_sessions must be ≥ 1' );
		}

		if ( $cancelled_seq_no < 1 || $cancelled_seq_no > $total_sessions ) {
			throw new InvalidArgumentException(
				sprintf( 'cancelled sequence_no %d out of range 1..%d', $cancelled_seq_no, $total_sessions )
			);
		}

		$renumbering = array();

		for ( $seq = 1; $seq <= $total_sessions; $seq++ ) {
			if ( $seq < $cancelled_seq_no ) {
				$renumbering[ $seq ] = $seq;
			} elseif ( $seq === $cancelled_seq_no ) {
				$renumbering[ $seq ] = null;
			} else {
				$renumbering[ $seq ] = $seq - 1;
			}
		}

		return array(
			'renumbering' => $renumbering,
			'makeup'      => array(
				'sequence_no'            => $total_sessions + 1,
				'lesson_no'              => $total_sessions,
				'makeup_for_sequence_no' => $cancelled_seq_no,
			),
		);
	}
}
