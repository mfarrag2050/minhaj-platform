<?php
/**
 * Timetable ↔ Calendar bridge.
 *
 * The Timetable module never imports Calendar; it emits filters at two
 * boundary points and Calendar answers them. This keeps Timetable
 * loadable in tests that do not carry the Calendar migrations, and
 * keeps the C-2 / §3.1 / C-3 rules physically inside the Calendar
 * module — the review notes are the contract this class implements.
 *
 * @package Minhaj\Modules\Calendar
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Calendar;

use Minhaj\Modules\Calendar\Repository\CalendarRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GenerationHooks {

	public function __construct( private readonly CalendarService $service ) {}

	public function register(): void {
		add_filter( 'minhaj_timetable_pre_generate_gate', array( $this, 'gate' ), 10, 3 );
		add_filter( 'minhaj_timetable_skip_dates_for_group', array( $this, 'skip_dates' ), 10, 5 );
		add_action( 'minhaj_timetable_check_calendar_staleness', array( $this, 'maybe_emit_stale_warning' ) );
	}

	/**
	 * @param true|WP_Error $verdict
	 * @return true|WP_Error
	 */
	public function gate( $verdict, int $group_id, int $actor_user_id ) {
		unset( $actor_user_id ); // Reserved for future org-scope checks; used today only for signature stability.

		if ( is_wp_error( $verdict ) ) {
			return $verdict;
		}

		$state = $this->service->get_group_calendar_state( $group_id );
		if ( null === $state ) {
			return $verdict;
		}

		$has_calendar = array() !== $this->service->list_calendar_ids_for_group( $group_id );
		if ( $has_calendar ) {
			return $verdict;
		}

		if ( null !== $state['no_calendar_ack_by'] ) {
			return $verdict;
		}

		return new WP_Error(
			'no_calendar',
			__( 'Group has no calendar attached and no explicit no-calendar acknowledgement — refuse to generate.', 'minhaj-core' )
		);
	}

	/**
	 * @param array<int, string> $skip_dates
	 * @return array<int, string>
	 */
	public function skip_dates( array $skip_dates, int $group_id, string $anchor_tz, string $from_iso, string $to_iso ): array {
		unset( $anchor_tz ); // Calendar days are stored as naive DATE — matched against anchor-local date in SessionTimeCalculator.

		return array_values(
			array_unique(
				array_merge(
					$skip_dates,
					$this->service->list_disabled_dates_for_group( $group_id, $from_iso, $to_iso )
				)
			)
		);
	}

	public function maybe_emit_stale_warning( int $group_id ): void {
		if ( ! $this->service->is_group_calendar_stale( $group_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Events::STALE_WARNING is a compile-time const with the minhaj_ prefix; the sniff cannot see through the constant reference.
		do_action( Events::STALE_WARNING, $group_id );
	}
}
