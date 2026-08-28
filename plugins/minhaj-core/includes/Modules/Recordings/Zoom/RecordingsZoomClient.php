<?php
/**
 * Thin HTTP boundary over the Zoom Cloud Recording API.
 *
 * The interface exists so tests inject a fake, and so a later swap to
 * Zoom Meeting SDK download endpoints does not force a rewrite of
 * RecordingsService.
 *
 * `download_token` handling: Zoom's `recording.completed` webhook
 * includes a short-lived token that must be sent as
 * `?access_token=<token>` when GETting `download_url`. The token is a
 * bearer secret; RecordingsService MUST NOT persist it and passes it
 * straight to `download()`.
 *
 * @package Minhaj\Modules\Recordings\Zoom
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Zoom;

defined( 'ABSPATH' ) || exit;

interface RecordingsZoomClient {

	/**
	 * Stream the recording bytes to a local path.
	 *
	 * @param string $download_url  From the webhook — MUST NOT be logged.
	 * @param string $download_token Short-lived bearer — MUST NOT be persisted.
	 * @param string $target_path   Local file to write.
	 *
	 * @return array{bytes:int, checksum_sha256:string} What we saw on the wire.
	 * @throws RecordingsZoomException On any HTTP failure.
	 */
	public function download( string $download_url, string $download_token, string $target_path ): array;

	/**
	 * Delete a single recording file from Zoom cloud. Called ONLY after
	 * the triple verification passes (spec G-1).
	 *
	 * @throws RecordingsZoomException On non-2xx (except 404, which is
	 *                                  idempotently swallowed by the caller).
	 */
	public function delete_recording_file( string $meeting_uuid, string $file_id ): void;

	/**
	 * Zoom account cloud recording usage. Returns:
	 *
	 *   [
	 *     'used_bytes'   => int,
	 *     'plan_bytes'   => int,    // 0 if unknown
	 *     'daily_bytes'  => int,    // observed intake, for the days-remaining estimate
	 *   ]
	 *
	 * @throws RecordingsZoomException On any HTTP failure.
	 */
	public function quota(): array;

	/**
	 * List recording files that Zoom currently holds — used by
	 * `orphan-check` to detect webhooks we missed.
	 *
	 * @return array<int, array{meeting_uuid:string, file_id:string, file_type:string, bytes:int, recording_end_utc:string}>
	 * @throws RecordingsZoomException On any HTTP failure.
	 */
	public function list_cloud_recordings( int $from_days = 30 ): array;
}
