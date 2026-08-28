<?php
/**
 * Value object returned by JoinStrategy::issue. Carries only what the
 * caller needs to redirect; `join_url` is copied to `redirect_to` (a
 * write-once field) and consumed by the 302 handler, never rendered
 * into HTML or logged.
 *
 * @package Minhaj\Modules\Meetings\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Domain;

defined( 'ABSPATH' ) || exit;

final class JoinTicket {

	public function __construct(
		public readonly int $participant_id,
		public readonly string $role,
		public readonly string $redirect_to,
		public readonly int $expires_at_ts
	) {}
}
