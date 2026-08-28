<?php
/**
 * Persistence-layer errors for the Calendar module. Kinds surface the DB
 * constraint that actually rejected the write so the service can translate
 * cleanly to a WP_Error without inspecting error strings twice.
 *
 * @package Minhaj\Modules\Calendar\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Calendar\Repository;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class PersistenceException extends RuntimeException {

	public const WRITE_FAILED       = 'write_failed';
	public const DUPLICATE_DAY      = 'duplicate_day';
	public const DUPLICATE_ATTACHED = 'duplicate_attached';

	public function __construct( private readonly string $kind, string $message ) {
		parent::__construct( $message );
	}

	public function kind(): string {
		return $this->kind;
	}
}
