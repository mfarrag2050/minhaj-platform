<?php
/**
 * Safeguarding-check status values from spec-people-v1 §2.5.
 *
 * `valid` is the only status the assignability gate accepts (S-4). We keep
 * the header on `pending`/`expired`/`failed` for the audit trail but never
 * store the underlying check contents (§2.5) — the data is designed to let
 * us reason about validity without hoarding the police record itself.
 *
 * @package Minhaj\Modules\People\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People\Domain;

defined( 'ABSPATH' ) || exit;

final class SafeguardingCheckStatus {

	public const PENDING = 'pending';
	public const VALID   = 'valid';
	public const EXPIRED = 'expired';
	public const FAILED  = 'failed';

	public static function is_valid( string $status ): bool {
		return in_array( $status, array( self::PENDING, self::VALID, self::EXPIRED, self::FAILED ), true );
	}
}
