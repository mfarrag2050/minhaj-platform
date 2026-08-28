<?php
/**
 * Thrown when a spec-zoom-sessions-v1 §5 rule is violated at the policy
 * layer (before persistence). The service catches at the outer boundary
 * and converts to WP_Error — same convention every other module uses.
 *
 * @package Minhaj\Modules\Meetings\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Domain;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class RuleViolationException extends RuntimeException {

	public function __construct(
		private readonly string $rule_code,
		string $message,
		?Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function rule_code(): string {
		return $this->rule_code;
	}
}
