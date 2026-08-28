<?php
/**
 * Contract for how the "open the class" button turns into a Zoom join.
 *
 * The default implementation (SignedLinkStrategy) hits Zoom Registrant
 * API and returns a JoinTicket carrying the short-lived join_url.
 * Switching to Meeting SDK later means replacing one class, not
 * rewriting attendance / recordings.
 *
 * @package Minhaj\Modules\Meetings
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings;

use Minhaj\Modules\Meetings\Domain\JoinTicket;

defined( 'ABSPATH' ) || exit;

interface JoinStrategy {

	public function issue( int $session_id, int $user_id, ?int $subject_student_id = null ): JoinTicket;
}
