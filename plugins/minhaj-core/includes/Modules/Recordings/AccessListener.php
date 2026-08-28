<?php
/**
 * The Recordings-side answer for `minhaj_access_can_view_recording`
 * (registered by `Minhaj\Access\AccessPolicy::can_view_recording`).
 *
 * Rule per قرار 11 (spec §5.3 G-10):
 *   • administrator: YES.
 *   • teacher owning the recording's session (session.teacher_id ==
 *     $user_id): YES.
 *   • anyone else (other teacher, parent, student, org admin): NO —
 *     regardless of any group / org relationship.
 *
 * The parent portal reads the WRITTEN REPORT (spec §2.1) — not the
 * video. This subscriber does not grant a fallback for `minhaj_parent`
 * even though قرار 11 allows parents on their own child's group; the
 * clip contains other children (§2.1).
 *
 * @package Minhaj\Modules\Recordings
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings;

use Minhaj\Modules\Recordings\Repository\RecordingsRepository;

defined( 'ABSPATH' ) || exit;

final class AccessListener {

	public function __construct( private readonly RecordingsRepository $repo ) {}

	public function register(): void {
		add_filter( 'minhaj_access_can_view_recording', array( $this, 'answer' ), 10, 3 );
	}

	public function answer( bool $decision, int $user_id, int $recording_id ): bool {
		if ( $decision ) {
			// Access module already said no (baseline is false); a later
			// subscriber can only tighten, not loosen. Nothing to do.
			return $decision;
		}
		if ( $user_id <= 0 || $recording_id <= 0 ) {
			return false;
		}

		// A caller with the site's top-level admin capability is
		// always allowed to view — the roles that hold `manage_options`
		// are the ones our Access module recognises as administrators.
		// The role name itself is not the gate; the capability is.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$row = $this->repo->find( $recording_id );
		if ( null === $row ) {
			return false;
		}

		$session_id = (int) ( $row['session_id'] ?? 0 );
		if ( $session_id <= 0 ) {
			return false;
		}

		global $wpdb;

		// The Timetable module owns `minhaj_sessions`; we read only the
		// teacher_id column. No dependency on TimetableRepository —
		// staying loose-coupled per the spec's layering contract.
		$sessions_table = $wpdb->prefix . 'minhaj_sessions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$teacher_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT teacher_id FROM %i WHERE id = %d',
				$sessions_table,
				$session_id
			)
		);

		return $teacher_id > 0 && $teacher_id === $user_id;
	}
}
