<?php
/**
 * Thin HTTP boundary over Zoom's Server-to-Server OAuth API. The
 * interface exists so tests inject a fake, and so a later swap to Meeting
 * SDK does not force a rewrite of MeetingsService.
 *
 * All bearer secrets (start_url, join_url, meeting password) are
 * intentionally passed BACK to the caller as return values and NEVER
 * persisted. The service consumes them in a 302 handler and drops them.
 *
 * @package Minhaj\Modules\Meetings\Zoom
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Zoom;

defined( 'ABSPATH' ) || exit;

interface ZoomClient {

	/**
	 * @param array<string, mixed> $args Zoom "create meeting" body.
	 * @return array<string, mixed> Meeting payload as returned by Zoom.
	 * @throws ZoomApiException On non-2xx.
	 */
	public function create_meeting( string $zoom_user_id, array $args ): array;

	/**
	 * @throws ZoomApiException On non-2xx.
	 */
	public function delete_meeting( string $zoom_meeting_id ): void;

	/**
	 * @param array<string, mixed> $registrant
	 * @return array<string, mixed> Zoom's registrant response (includes join_url).
	 * @throws ZoomApiException On non-2xx.
	 */
	public function add_registrant( string $zoom_meeting_id, array $registrant ): array;

	/**
	 * @return array<string, mixed>
	 * @throws ZoomApiException On non-2xx.
	 */
	public function get_meeting( string $zoom_meeting_id ): array;

	/**
	 * @return array<string, mixed> account settings as returned by GET /accounts/me/settings.
	 * @throws ZoomApiException On non-2xx.
	 */
	public function get_account_settings(): array;
}
