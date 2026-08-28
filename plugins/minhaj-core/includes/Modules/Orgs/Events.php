<?php
/**
 * do_action names for Orgs. Every event fires AFTER commit — spec §5 O-10.
 *
 * @package Minhaj\Modules\Orgs
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Orgs;

defined( 'ABSPATH' ) || exit;

final class Events {

	public const ORG_CREATED        = 'minhaj_org_created';
	public const ORG_STATUS_CHANGED = 'minhaj_org_status_changed';
	public const ORG_DPA_RECORDED   = 'minhaj_org_dpa_recorded';

	public const LINK_ISSUED  = 'minhaj_org_link_issued';
	public const LINK_REVOKED = 'minhaj_org_link_revoked';

	public const STUDENT_ATTRIBUTED = 'minhaj_student_attributed';

	public const MEMBER_ADDED = 'minhaj_org_member_added';
	public const MEMBER_ENDED = 'minhaj_org_member_ended';
}
