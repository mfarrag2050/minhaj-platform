<?php
/**
 * Repository-level persistence failure classification for the People module.
 *
 * @package Minhaj\Modules\People\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People\Repository;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class PersistenceException extends RuntimeException {

	public const DUPLICATE_PRIMARY_GUARDIAN = 'duplicate_primary_guardian';
	public const DUPLICATE_TEACHER_LANGUAGE = 'duplicate_teacher_language';
	public const WRITE_FAILED               = 'write_failed';

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
