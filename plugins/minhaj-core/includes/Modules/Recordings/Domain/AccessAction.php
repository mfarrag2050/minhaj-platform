<?php
/**
 * Access-log verbs — spec §3.2.
 *
 * The log is the compliance boundary: every attempted read of a minor's
 * recording — successful or refused — has a row here. Admin is NOT
 * exempt (G-12).
 *
 * @package Minhaj\Modules\Recordings\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Domain;

defined( 'ABSPATH' ) || exit;

final class AccessAction {
	public const VIEW   = 'view';
	public const DENIED = 'denied';
	public const PURGE  = 'purge';
	public const EXPORT = 'export';

	/** @return array<int, string> */
	public static function all(): array {
		return array( self::VIEW, self::DENIED, self::PURGE, self::EXPORT );
	}
}
