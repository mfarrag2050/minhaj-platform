<?php
/**
 * Attendance ↔ Meetings bridge.
 *
 * Subscribes to `minhaj_zoom_event_handled` and claims the three event
 * types the Attendance module owns:
 *
 *   • meeting.participant_joined — record an interval (R-1 · via
 *     zoom_registrant_id only; never by displayed name).
 *   • meeting.participant_left   — close the interval.
 *   • meeting.ended              — close leftover open intervals, then
 *                                  finalize_session so R-10 idempotency
 *                                  and the R-8 zero-attendance event
 *                                  land in one place.
 *
 * The realistic payload shape follows Zoom's webhook reference; §14 of
 * the review memo notes that verification against the LIVE payload is
 * deferred until first contact with Zoom.
 *
 * @package Minhaj\Modules\Attendance
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Attendance;

defined( 'ABSPATH' ) || exit;

final class EventListener {

	public function __construct( private readonly AttendanceService $service ) {}

	public function register(): void {
		add_filter( 'minhaj_zoom_event_handled', array( $this, 'maybe_handle' ), 10, 3 );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function maybe_handle( bool $handled, string $event_type, array $payload ): bool {
		switch ( $event_type ) {
			case 'meeting.participant_joined':
				$this->handle_join( $payload );
				return true;
			case 'meeting.participant_left':
				$this->handle_leave( $payload );
				return true;
			case 'meeting.ended':
				$this->handle_meeting_ended( $payload );
				// meeting.ended is also handled by Meetings for its own
				// state — we return $handled OR true so the "already
				// handled internally" flag stays true.
				return true;
		}

		return $handled;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function handle_join( array $payload ): void {
		$session_id = $this->extract_session_id( $payload );
		if ( 0 === $session_id ) {
			return;
		}

		$participant = (array) ( $payload['object']['participant'] ?? array() );
		$uuid        = (string) ( $participant['participant_uuid'] ?? $participant['id'] ?? '' );
		$registrant  = (string) ( $participant['registrant_id'] ?? $participant['user_id'] ?? '' );
		$join_time   = (string) ( $participant['join_time'] ?? '' );

		if ( '' === $uuid || '' === $registrant || '' === $join_time ) {
			return;
		}

		$this->service->record_interval(
			$session_id,
			$uuid,
			$registrant,
			$this->to_mysql_utc( $join_time ),
			null
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function handle_leave( array $payload ): void {
		$session_id = $this->extract_session_id( $payload );
		if ( 0 === $session_id ) {
			return;
		}

		$participant = (array) ( $payload['object']['participant'] ?? array() );
		$uuid        = (string) ( $participant['participant_uuid'] ?? $participant['id'] ?? '' );
		$leave_time  = (string) ( $participant['leave_time'] ?? '' );

		if ( '' === $uuid || '' === $leave_time ) {
			return;
		}

		$this->service->close_interval( $session_id, $uuid, $this->to_mysql_utc( $leave_time ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function handle_meeting_ended( array $payload ): void {
		$session_id = $this->extract_session_id( $payload );
		if ( 0 === $session_id ) {
			return;
		}

		$end_time = (string) ( $payload['object']['end_time'] ?? gmdate( 'Y-m-d\TH:i:s\Z' ) );
		$this->service->close_open_intervals( $session_id, $this->to_mysql_utc( $end_time ) );
		$this->service->finalize_session( $session_id );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function extract_session_id( array $payload ): int {
		// Prefer explicit session_id we round-tripped in the meeting topic
		// (spec §5.1: topic starts with "Minhaj · session {id}"). Fall
		// back to zoom_meeting_id lookup via the participants table.
		$topic = (string) ( $payload['object']['topic'] ?? '' );
		if ( preg_match( '/session (\d+)/', $topic, $m ) ) {
			return (int) $m[1];
		}

		$zoom_meeting_id = (string) ( $payload['object']['id'] ?? '' );
		if ( '' === $zoom_meeting_id ) {
			return 0;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sid = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT session_id FROM %i WHERE zoom_meeting_id = %s ORDER BY id DESC LIMIT 1',
				$wpdb->prefix . 'minhaj_session_meetings',
				$zoom_meeting_id
			)
		);

		return null === $sid ? 0 : (int) $sid;
	}

	private function to_mysql_utc( string $iso ): string {
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return $iso;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
