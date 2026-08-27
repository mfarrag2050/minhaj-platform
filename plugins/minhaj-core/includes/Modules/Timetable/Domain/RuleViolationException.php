<?php
/**
 * Thrown when a spec-timetable-v1 §7 invariant is violated at the policy layer.
 *
 * @package Minhaj\Modules\Timetable\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Timetable\Domain;

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
