<?php
/**
 * Zoom `recording.completed` bridge.
 *
 * Subscribes to `minhaj_zoom_event_handled` (dispatched by Meetings
 * module's process_pending_events — the same pattern Attendance uses).
 * When the event type is `recording.completed`, this listener resolves
 * the session/group/org from `minhaj_meetings` + `minhaj_sessions` +
 * `minhaj_groups` and hands a scrubbed payload to
 * RecordingsService::register_from_webhook.
 *
 * The payload passed to the service EXCLUDES `download_url`,
 * `play_url`, and `download_token` (§7). Those are bearer secrets and
 * must not be persisted. In production the caller keeps them in
 * memory only long enough to call `download()` right after.
 *
 * @package Minhaj\Modules\Recordings
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings;

use Throwable;

defined( 'ABSPATH' ) || exit;

final class WebhookListener {

	public function __construct( private readonly RecordingsService $service ) {}

	public function register(): void {
		add_filter( 'minhaj_zoom_event_handled', array( $this, 'on_event' ), 10, 3 );
	}

	/**
	 * @param bool $handled
	 * @param string $event_type
	 * @param array<string, mixed> $payload
	 */
	public function on_event( bool $handled, string $event_type, array $payload ): bool {
		if ( $handled ) {
			return $handled;
		}
		if ( 'recording.completed' !== $event_type ) {
			return false;
		}

		try {
			$object = (array) ( $payload['object'] ?? $payload );

			$meeting_uuid = (string) ( $object['uuid'] ?? '' );
			if ( '' === $meeting_uuid ) {
				return false;
			}

			$context = $this->resolve_session_context( $meeting_uuid );
			if ( null === $context ) {
				// No matching session — a webhook for a meeting we don't
				// own. Leave it `ignored` by returning false; the
				// orphan-check CLI is the follow-up mechanism.
				return false;
			}

			$scrubbed = array(
				'meeting_uuid'    => $meeting_uuid,
				'session_id'      => $context['session_id'],
				'group_id'        => $context['group_id'],
				'org_id'          => $context['org_id'],
				'kind'            => 'session',
				'recording_files' => array_map(
					static function ( $f ) {
						$fa = (array) $f;
						return array(
							'id'              => (string) ( $fa['id'] ?? '' ),
							'file_type'       => (string) ( $fa['file_type'] ?? '' ),
							'file_size'       => (int) ( $fa['file_size'] ?? 0 ),
							'recording_start' => (string) ( $fa['recording_start'] ?? '' ),
							'recording_end'   => (string) ( $fa['recording_end'] ?? '' ),
						);
					},
					(array) ( $object['recording_files'] ?? array() )
				),
			);

			$ids = $this->service->register_from_webhook( $scrubbed );

			return array() !== $ids;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * @return array{session_id:int, group_id:int, org_id:?int}|null
	 */
	private function resolve_session_context( string $meeting_uuid ): ?array {
		global $wpdb;

		$meetings = $wpdb->prefix . 'minhaj_meetings';
		$sessions = $wpdb->prefix . 'minhaj_sessions';
		$groups   = $wpdb->prefix . 'minhaj_groups';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT s.id AS session_id, s.group_id, g.org_id
				 FROM %i m
				 JOIN %i s ON s.id = m.session_id
				 JOIN %i g ON g.id = s.group_id
				 WHERE m.zoom_meeting_uuid = %s
				 LIMIT 1',
				$meetings,
				$sessions,
				$groups,
				$meeting_uuid
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'session_id' => (int) $row['session_id'],
			'group_id'   => (int) $row['group_id'],
			'org_id'     => isset( $row['org_id'] ) ? (int) $row['org_id'] : null,
		);
	}
}
