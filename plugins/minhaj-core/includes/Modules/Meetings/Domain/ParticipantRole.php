<?php
/**
 * spec-zoom-sessions-v1 §5.3 — one host, many participants.
 *
 * @package Minhaj\Modules\Meetings\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Domain;

defined( 'ABSPATH' ) || exit;

final class ParticipantRole {

	public const HOST        = 'host';
	public const PARTICIPANT = 'participant';

	public static function is_valid( string $role ): bool {
		return self::HOST === $role || self::PARTICIPANT === $role;
	}
}
