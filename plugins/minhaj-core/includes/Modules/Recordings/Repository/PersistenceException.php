<?php
/**
 * @package Minhaj\Modules\Recordings\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Repository;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class PersistenceException extends RuntimeException {

	public const DUPLICATE_ZOOM_FILE = 'duplicate_zoom_file';
	public const WRITE_FAILED        = 'write_failed';

	public function __construct( private readonly string $kind, string $message ) {
		parent::__construct( $message );
	}

	public function kind(): string {
		return $this->kind;
	}
}
