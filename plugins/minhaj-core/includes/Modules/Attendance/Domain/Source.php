<?php
/**
 * Where the row came from. `zoom` for the derivation path, `manual`
 * for human amendments, `system` for automated backfills (reconcile).
 *
 * @package Minhaj\Modules\Attendance\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Attendance\Domain;

defined( 'ABSPATH' ) || exit;

final class Source {

	public const ZOOM   = 'zoom';
	public const MANUAL = 'manual';
	public const SYSTEM = 'system';
}
