<?php
/**
 * People module role registration.
 *
 * Installs the bare `minhaj_parent` role — a membership tag with `read`
 * only, mirroring the same pattern used for `minhaj_student` and
 * `minhaj_teacher` in the Groups module. Real capabilities land alongside
 * the parent portal.
 *
 * Called from Activator alongside Groups\Roles::install(); like the other
 * roles it is never removed on deactivation — losing the tag would strip
 * every existing parent user of their assignment.
 *
 * @package Minhaj\Modules\People
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People;

defined( 'ABSPATH' ) || exit;

final class Roles {

	public const PARENT = 'minhaj_parent';

	public static function install(): void {
		if ( null === get_role( self::PARENT ) ) {
			add_role(
				self::PARENT,
				__( 'Parent', 'minhaj-core' ),
				array( 'read' => true )
			);
		}
	}

	public static function parent_role(): string {
		return (string) apply_filters( 'minhaj_people_parent_role', self::PARENT );
	}
}
