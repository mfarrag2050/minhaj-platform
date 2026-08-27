<?php
/**
 * Repository-level persistence failure classification for the Timetable module.
 *
 * @package Minhaj\Modules\Timetable\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable\Repository;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class PersistenceException extends RuntimeException {

	public const DUPLICATE_SEQUENCE = 'duplicate_sequence';
	public const WRITE_FAILED       = 'write_failed';

	public function __construct(
		private readonly string $kind,
		string $message,
		?Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function kind(): string {
		return $this->kind;
	}
}
