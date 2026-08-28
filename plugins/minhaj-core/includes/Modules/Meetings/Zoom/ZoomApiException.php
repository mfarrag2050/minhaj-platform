<?php
/**
 * Thrown by ZoomClient implementations on any non-2xx response. Carries
 * both the HTTP status and Zoom's own error code string so callers can
 * decide between "temporary — retry" and "permanent — mark failed".
 *
 * @package Minhaj\Modules\Meetings\Zoom
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Zoom;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class ZoomApiException extends RuntimeException {

	public function __construct(
		private readonly int $status,
		private readonly string $zoom_error_code,
		string $message
	) {
		parent::__construct( $message );
	}

	public function status(): int {
		return $this->status;
	}

	public function zoom_error_code(): string {
		return $this->zoom_error_code;
	}
}
