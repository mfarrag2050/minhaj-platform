<?php
/**
 * @package Minhaj\Modules\Attendance\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Attendance\Repository;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class PersistenceException extends RuntimeException {

	public const WRITE_FAILED       = 'write_failed';
	public const DUPLICATE_INTERVAL = 'duplicate_interval';
	public const DUPLICATE_ROW      = 'duplicate_row';

	public function __construct( private readonly string $kind, string $message ) {
		parent::__construct( $message );
	}

	public function kind(): string {
		return $this->kind;
	}
}
