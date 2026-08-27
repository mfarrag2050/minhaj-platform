<?php
/**
 * Daily scanner that fires `minhaj_check_expiring` for each safeguarding
 * check whose expiry falls within the next 60 days (S-5). Subscribers do
 * the actual admin notification / dashboard update — this class only
 * publishes the event; it does not mutate the schedule.
 *
 * Hook: WP-Cron 'daily' event registered in Module::register().
 *
 * @package Minhaj\Modules\People\Cron
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People\Cron;

use Minhaj\Modules\People\Events;
use Minhaj\Modules\People\Repository\PeopleRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

final class ExpiringChecksScanner {

	public const CRON_HOOK = 'minhaj_people_expiring_checks_daily';

	private const LOOK_AHEAD_DAYS = 60;

	public function __construct( private readonly PeopleRepository $repo ) {}

	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'scan' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public function scan(): void {
		$today    = gmdate( 'Y-m-d' );
		$deadline = gmdate( 'Y-m-d', strtotime( '+' . self::LOOK_AHEAD_DAYS . ' days' ) );

		$rows = $this->repo->list_checks_expiring_between( $today, $deadline );

		foreach ( $rows as $row ) {
			do_action(
				Events::CHECK_EXPIRING,
				(int) $row['id'],
				(int) $row['teacher_id'],
				(string) $row['expires_at']
			);
		}
	}
}
