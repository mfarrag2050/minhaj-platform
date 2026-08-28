<?php
/**
 * Forwards RecordingAccessCheck::can_view_recording to the real
 * AccessPolicy. Exists so RecordingsService depends on a small port
 * we can double in tests, while production still runs the shared
 * AccessPolicy with all its side effects (finalise() logging, filter
 * dispatch).
 *
 * @package Minhaj\Modules\Recordings
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings;

use Minhaj\Access\AccessPolicy;

defined( 'ABSPATH' ) || exit;

final class AccessPolicyAdapter implements RecordingAccessCheck {

	public function __construct( private readonly AccessPolicy $policy ) {}

	public function can_view_recording( int $user_id, int $recording_id ): bool {
		return $this->policy->can_view_recording( $user_id, $recording_id );
	}
}
