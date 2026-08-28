<?php
/**
 * Capability register for spec-access-v1 §3.
 *
 * Capabilities gate the *class* of action ("this user's role may view
 * groups"); AccessPolicy gates the *row* ("… and this specific group is
 * theirs to see"). Neither is sufficient alone — spec §2, A-4/A-5, A-9.
 *
 * Not removed on plugin deactivation — the roles the caps live on are
 * tenure tags; stripping them would silently unassign every teacher and
 * parent already in the system.
 *
 * @package Minhaj\Access
 */

declare( strict_types=1 );

namespace Minhaj\Access;

use Minhaj\Modules\Groups\Roles as GroupsRoles;
use Minhaj\Modules\People\Roles as PeopleRoles;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const MANAGE_GROUPS        = 'minhaj_manage_groups';
	public const MANAGE_SESSIONS      = 'minhaj_manage_sessions';
	public const VIEW_GROUP           = 'minhaj_view_group';
	public const VIEW_OWN_CHILD_GROUP = 'minhaj_view_own_child_group';
	public const RECORD_ATTENDANCE    = 'minhaj_record_attendance';
	public const VIEW_RECORDING       = 'minhaj_view_recording';
	public const JOIN_SESSION         = 'minhaj_join_session';
	public const MANAGE_ORG           = 'minhaj_manage_org';

	/**
	 * Reserved for the quality-review role that arrives in the next phase
	 * (spec §9 Q3). Declared here so nothing else claims the name; NOT
	 * granted to any role — reservation, not activation.
	 */
	public const REVIEW_QUALITY = 'minhaj_review_quality';

	/**
	 * Add every capability that spec §3 grants to a role. Idempotent — checks
	 * `has_cap` before adding so multiple activations do not thrash the
	 * user_meta cache.
	 */
	public static function install(): void {
		self::grant(
			'administrator',
			array(
				self::MANAGE_GROUPS,
				self::MANAGE_SESSIONS,
				self::RECORD_ATTENDANCE,
				self::VIEW_RECORDING,
			)
		);

		self::grant(
			GroupsRoles::teacher_role(),
			array(
				self::VIEW_GROUP,
				self::RECORD_ATTENDANCE,
				self::VIEW_RECORDING,
				self::JOIN_SESSION,
			)
		);

		self::grant(
			PeopleRoles::parent_role(),
			array(
				self::VIEW_OWN_CHILD_GROUP,
				self::JOIN_SESSION,
			)
		);

		self::grant(
			GroupsRoles::student_role(),
			array(
				self::JOIN_SESSION,
			)
		);
	}

	/**
	 * @return array<string, string>  cap => cap map (identity today — carries the §6 filter later).
	 */
	public static function map(): array {
		$map = array(
			self::MANAGE_GROUPS        => self::MANAGE_GROUPS,
			self::MANAGE_SESSIONS      => self::MANAGE_SESSIONS,
			self::VIEW_GROUP           => self::VIEW_GROUP,
			self::VIEW_OWN_CHILD_GROUP => self::VIEW_OWN_CHILD_GROUP,
			self::RECORD_ATTENDANCE    => self::RECORD_ATTENDANCE,
			self::VIEW_RECORDING       => self::VIEW_RECORDING,
			self::JOIN_SESSION         => self::JOIN_SESSION,
			self::MANAGE_ORG           => self::MANAGE_ORG,
		);

		/**
		 * Filter · rename capabilities (spec §6). Alignment with the
		 * `minhaj_groups_student_role` pattern already in use.
		 *
		 * @param array<string, string> $map
		 */
		$filtered = (array) apply_filters( 'minhaj_access_capability_map', $map );

		return array_map( 'strval', $filtered );
	}

	/**
	 * @param array<int, string> $caps
	 */
	private static function grant( string $role_slug, array $caps ): void {
		$role = get_role( $role_slug );
		if ( null === $role ) {
			return;
		}

		foreach ( $caps as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}
}
