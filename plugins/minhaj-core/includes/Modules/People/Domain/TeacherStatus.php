<?php
/**
 * Teacher lifecycle states from spec-people-v1 §4.
 *
 * `active` is the only state that accepts a group assignment (§4 + S-4).
 * The state machine does not police the additional preconditions to reach
 * active (valid check, declared teaching language, timezone, availability) —
 * PeopleService::transition_teacher gates those, and PeopleService::
 * teacher_is_assignable is what the Groups module hooks to reject a
 * would-be assignment via filter.
 *
 * @package Minhaj\Modules\People\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People\Domain;

defined( 'ABSPATH' ) || exit;

final class TeacherStatus {

	public const APPLICANT      = 'applicant';
	public const SCREENING      = 'screening';
	public const CHECKS_PENDING = 'checks_pending';
	public const ACTIVE         = 'active';
	public const INACTIVE       = 'inactive';
	public const SUSPENDED      = 'suspended';
	public const REJECTED       = 'rejected';

	/**
	 * Allowed transitions per §4. `rejected` and `inactive` are terminal at
	 * the domain layer — reversing them is an admin decision that should
	 * spawn a new applicant record so the audit trail stays honest about
	 * the gap.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const TRANSITIONS = array(
		self::APPLICANT      => array( self::SCREENING ),
		self::SCREENING      => array( self::CHECKS_PENDING, self::REJECTED ),
		self::CHECKS_PENDING => array( self::ACTIVE, self::REJECTED ),
		self::ACTIVE         => array( self::INACTIVE, self::SUSPENDED ),
		self::SUSPENDED      => array( self::ACTIVE, self::INACTIVE ),
		self::INACTIVE       => array(),
		self::REJECTED       => array(),
	);

	public static function is_valid( string $status ): bool {
		return array_key_exists( $status, self::TRANSITIONS );
	}

	public static function is_terminal( string $status ): bool {
		return self::is_valid( $status ) && array() === self::TRANSITIONS[ $status ];
	}

	/**
	 * @return array<int, string>
	 */
	public static function allowed_transitions( string $from ): array {
		return self::TRANSITIONS[ $from ] ?? array();
	}

	public static function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::allowed_transitions( $from ), true );
	}

	/**
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array_keys( self::TRANSITIONS );
	}
}
