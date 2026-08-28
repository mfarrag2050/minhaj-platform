<?php
/**
 * Thrown by AccessPolicy::assert() when a decision is false. Callers who
 * want a boolean check hit the individual can_* methods; callers who want
 * a fail-fast guard (admin controllers, REST handlers, CLI commands) call
 * assert() and catch this.
 *
 * @package Minhaj\Access
 */

declare( strict_types=1 );

namespace Minhaj\Access;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class AccessDeniedException extends RuntimeException {

	public function __construct(
		private readonly string $context,
		private readonly int $user_id,
		private readonly int $subject_id
	) {
		parent::__construct(
			sprintf( 'access denied: %s user=%d subject=%d', $context, $user_id, $subject_id )
		);
	}

	public function context(): string {
		return $this->context;
	}

	public function actor_user_id(): int {
		return $this->user_id;
	}

	public function subject_id(): int {
		return $this->subject_id;
	}
}
