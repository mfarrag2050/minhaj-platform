<?php
/**
 * Repository-level persistence failure classification.
 *
 * @package Minhaj\Modules\Groups\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Repository;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class PersistenceException extends RuntimeException {

	public const DUPLICATE_SEAT    = 'duplicate_seat';
	public const DUPLICATE_STUDENT = 'duplicate_student';
	public const DUPLICATE_CODE    = 'duplicate_code';
	public const WRITE_FAILED      = 'write_failed';

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
