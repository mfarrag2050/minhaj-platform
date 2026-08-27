<?php
/**
 * Groups module role registration.
 *
 * Installs the bare `student` and `teacher` roles used by the admin
 * autocompletes and by future permission wiring. The roles carry no
 * capabilities today — they are membership tags. Real capabilities
 * (view_group, view_own_child_group, …) land alongside the parent
 * portal module.
 *
 * @package Minhaj\Modules\Groups
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups;

defined( 'ABSPATH' ) || exit;

final class Roles {

	public const STUDENT = 'minhaj_student';
	public const TEACHER = 'minhaj_teacher';

	public static function install(): void {
		if ( null === get_role( self::STUDENT ) ) {
			add_role(
				self::STUDENT,
				__( 'Student', 'minhaj-core' ),
				array( 'read' => true )
			);
		}

		if ( null === get_role( self::TEACHER ) ) {
			add_role(
				self::TEACHER,
				__( 'Teacher', 'minhaj-core' ),
				array( 'read' => true )
			);
		}
	}

	public static function student_role(): string {
		return (string) apply_filters( 'minhaj_groups_student_role', self::STUDENT );
	}

	public static function teacher_role(): string {
		return (string) apply_filters( 'minhaj_groups_teacher_role', self::TEACHER );
	}
}
