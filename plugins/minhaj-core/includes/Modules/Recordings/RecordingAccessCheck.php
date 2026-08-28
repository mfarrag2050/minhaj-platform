<?php
/**
 * Narrow port over the one AccessPolicy method the recordings service
 * actually needs. Exists so tests can double it without touching the
 * final AccessPolicy class, and so a future refactor can move the
 * decision out of Access\ without shifting the service signature.
 *
 * The production implementation is `AccessPolicyAdapter` (right below);
 * it simply forwards to `AccessPolicy::can_view_recording`.
 *
 * @package Minhaj\Modules\Recordings
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings;

defined( 'ABSPATH' ) || exit;

interface RecordingAccessCheck {
	public function can_view_recording( int $user_id, int $recording_id ): bool;
}
