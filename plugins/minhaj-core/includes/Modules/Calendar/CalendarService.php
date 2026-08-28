<?php
/**
 * Calendar public interface — spec-calendar-v1 §1..§3 + §5. §4
 * (recalculate_from) waits for spec-zoom-sessions-v1 to ship: the
 * operation cancels a Zoom meeting and creates a new one; wiring it
 * before Zoom exists would mean writing the same code twice.
 *
 * Layering contract (mirrors every other service in this plugin):
 *   • Callers (admin/CLI/REST) enforce current_user_can + nonce BEFORE
 *     calling. Every write takes `int $actor_user_id` explicitly.
 *   • Domain rules surface as WP_Error at the outer boundary; the
 *     repository throws PersistenceException on DB failures.
 *   • Writes ride a single transaction. Audit rows land in the Groups
 *     audit table when the subject is a group, and in Events after
 *     commit — never inside a rollback.
 *
 * The four review corrections that shape this file:
 *   • C-2 (§acknowledge_no_calendar) · a group without a calendar cannot
 *     generate unless an explicit acknowledgement is on file.
 *   • C-3 (§list_stale_calendars) · calendars that dry up ≥ 90 days out
 *     are reported so silent staleness cannot creep in.
 *   • C-4 (§set_holiday_behavior) · skip_and_compress needs MANAGE_GROUPS
 *     + a reason + a paid-enrolment check. Never a global setting.
 *   • C-5 (§delete_day) · the "held session on that day" guard is a
 *     service-layer check inside a transaction with FOR UPDATE, because
 *     the relationship spans two tables (sessions × group_calendars) and
 *     MySQL cannot express it as a schema constraint.
 *   • C-6 (§create_calendar / §add_day / §delete_day) · public calendars
 *     (org_id NULL) are staff-only. A supplier org cannot edit them.
 *
 * @package Minhaj\Modules\Calendar
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Calendar;

use Minhaj\Access\Capabilities;
use Minhaj\Modules\Calendar\Domain\CalendarDayKind;
use Minhaj\Modules\Calendar\Domain\CalendarStatus;
use Minhaj\Modules\Calendar\Domain\HolidayBehavior;
use Minhaj\Modules\Calendar\Repository\CalendarRepository;
use Minhaj\Modules\Calendar\Repository\PersistenceException;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/*
 * WP_Error messages built here relay dev-facing rule codes and validated
 * enum values only — never user-supplied HTML — so the WPCS output-escape
 * sniff is disabled at this boundary. Presentation layers escape at render.
 *
 * do_action hook names come from Events constants, all prefixed minhaj_*.
 * The sniff cannot resolve dynamic hook names statically and flags them;
 * the prefix rule is satisfied by construction.
 */
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

final class CalendarService {

	public function __construct( private readonly CalendarRepository $repo ) {}

	// ============================================================= create_calendar.

	/**
	 * @param array<string, mixed> $args
	 * @return int|WP_Error
	 */
	public function create_calendar( int $actor_user_id, array $args ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$name = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'invalid_arg', __( 'Calendar name is required.', 'minhaj-core' ) );
		}

		$org_id_raw = $args['org_id'] ?? null;
		$org_id     = ( null === $org_id_raw || '' === $org_id_raw ) ? null : (int) $org_id_raw;

		// C-6 · public calendars are staff-only.
		if ( null === $org_id && ! user_can( $actor_user_id, Capabilities::MANAGE_GROUPS ) ) {
			return new WP_Error(
				'public_calendar_forbidden',
				__( 'Only platform staff may create a public (org_id NULL) calendar.', 'minhaj-core' )
			);
		}

		$country = strtoupper( sanitize_text_field( (string) ( $args['country'] ?? '' ) ) );
		if ( '' !== $country && 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return new WP_Error( 'invalid_country', __( 'country must be an ISO-3166 alpha-2 code.', 'minhaj-core' ) );
		}

		$now = current_time( 'mysql', true );

		$data = array(
			'name'       => $name,
			'org_id'     => $org_id,
			'country'    => $country,
			'status'     => CalendarStatus::ACTIVE,
			'created_by' => $actor_user_id,
			'created_at' => $now,
			'updated_at' => $now,
		);

		$id = 0;
		$this->repo->begin_transaction();
		try {
			$id = $this->repo->insert_calendar( $data );
			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::CALENDAR_CREATED, $id, $org_id, $actor_user_id );

		return $id;
	}

	// ============================================================= add_day.

	/**
	 * @return int|WP_Error Day id on success.
	 */
	public function add_day( int $actor_user_id, int $calendar_id, string $day_date, string $kind, string $label = '' ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_date ) ) {
			return new WP_Error( 'invalid_arg', __( 'day_date must be YYYY-MM-DD.', 'minhaj-core' ) );
		}

		if ( ! CalendarDayKind::is_valid( $kind ) ) {
			return new WP_Error( 'invalid_arg', __( 'Unknown calendar day kind.', 'minhaj-core' ) );
		}

		$calendar = $this->repo->find_calendar( $calendar_id );
		if ( null === $calendar ) {
			return new WP_Error( 'calendar_not_found', __( 'Calendar not found.', 'minhaj-core' ) );
		}

		// C-6 · public calendars accept edits from staff only.
		if ( null === $calendar['org_id'] && ! user_can( $actor_user_id, Capabilities::MANAGE_GROUPS ) ) {
			return new WP_Error(
				'public_calendar_forbidden',
				__( 'Only platform staff may edit a public calendar.', 'minhaj-core' )
			);
		}

		$label_clean = sanitize_text_field( $label );
		$now         = current_time( 'mysql', true );

		$id = 0;
		$this->repo->begin_transaction();
		try {
			$id = $this->repo->insert_day(
				array(
					'calendar_id' => $calendar_id,
					'day_date'    => $day_date,
					'kind'        => $kind,
					'label'       => $label_clean,
					'created_by'  => $actor_user_id,
					'created_at'  => $now,
				)
			);
			$this->repo->commit();
		} catch ( PersistenceException $e ) {
			$this->repo->rollback();
			if ( PersistenceException::DUPLICATE_DAY === $e->kind() ) {
				return new WP_Error( 'duplicate_day', __( 'That date is already on the calendar.', 'minhaj-core' ) );
			}
			return new WP_Error( 'persistence_error', $e->getMessage() );
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::DAY_ADDED, $calendar_id, $id, $day_date, $kind, $actor_user_id );

		return $id;
	}

	// ============================================================= delete_day.

	/**
	 * C-5 · deleting a calendar day is only safe if no session that was
	 * already `live` or `completed` fell on that same anchor-local date
	 * inside a group attached to this calendar. The check runs inside a
	 * single transaction with FOR UPDATE on the affected sessions so a
	 * concurrent completion cannot slip past the count and produce a
	 * ledger where a held session references a day that no longer exists
	 * on any of its group's calendars.
	 *
	 * @return true|WP_Error
	 */
	public function delete_day( int $actor_user_id, int $day_id, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$reason_clean = sanitize_text_field( $reason );
		if ( '' === trim( $reason_clean ) ) {
			return new WP_Error( 'reason_required', __( 'A reason is required.', 'minhaj-core' ) );
		}

		$this->repo->begin_transaction();
		try {
			$day = $this->repo->find_day( $day_id );
			if ( null === $day ) {
				$this->repo->rollback();
				return new WP_Error( 'day_not_found', __( 'Calendar day not found.', 'minhaj-core' ) );
			}

			$calendar_id = (int) $day['calendar_id'];
			$calendar    = $this->repo->find_calendar( $calendar_id );
			if ( null === $calendar ) {
				$this->repo->rollback();
				return new WP_Error( 'calendar_not_found', __( 'Calendar not found.', 'minhaj-core' ) );
			}

			// C-6 · public calendar edits are staff-only.
			if ( null === $calendar['org_id'] && ! user_can( $actor_user_id, Capabilities::MANAGE_GROUPS ) ) {
				$this->repo->rollback();
				return new WP_Error(
					'public_calendar_forbidden',
					__( 'Only platform staff may edit a public calendar.', 'minhaj-core' )
				);
			}

			// C-5 · held-session guard.
			$held = $this->repo->count_held_sessions_on_calendar_date_for_update(
				$calendar_id,
				(string) $day['day_date']
			);
			if ( $held > 0 ) {
				$this->repo->rollback();
				return new WP_Error(
					'held_sessions_present',
					sprintf(
						/* translators: 1: count of held sessions, 2: day date */
						__( 'Cannot delete: %1$d held session(s) on %2$s inside groups attached to this calendar.', 'minhaj-core' ),
						$held,
						(string) $day['day_date']
					)
				);
			}

			$this->repo->delete_day_by_id( $day_id );
			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::DAY_DELETED, $calendar_id, $day_id, (string) $day['day_date'], $reason_clean, $actor_user_id );

		return true;
	}

	// =============================================================== attach.

	/**
	 * @return int|WP_Error Attachment id on success.
	 */
	public function attach_to_group( int $actor_user_id, int $group_id, int $calendar_id ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $group_id <= 0 || $calendar_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'group_id and calendar_id are required.', 'minhaj-core' ) );
		}

		if ( null === $this->repo->find_calendar( $calendar_id ) ) {
			return new WP_Error( 'calendar_not_found', __( 'Calendar not found.', 'minhaj-core' ) );
		}

		$now = current_time( 'mysql', true );

		$id = 0;
		$this->repo->begin_transaction();
		try {
			$id = $this->repo->attach_calendar_to_group(
				array(
					'group_id'    => $group_id,
					'calendar_id' => $calendar_id,
					'attached_by' => $actor_user_id,
					'attached_at' => $now,
				)
			);
			$this->repo->commit();
		} catch ( PersistenceException $e ) {
			$this->repo->rollback();
			if ( PersistenceException::DUPLICATE_ATTACHED === $e->kind() ) {
				return new WP_Error( 'already_attached', __( 'Calendar already attached to this group.', 'minhaj-core' ) );
			}
			return new WP_Error( 'persistence_error', $e->getMessage() );
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::ATTACHED_TO_GROUP, $group_id, $calendar_id, $actor_user_id );

		return $id;
	}

	/**
	 * @return true|WP_Error
	 */
	public function detach_from_group( int $actor_user_id, int $group_id, int $calendar_id ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$this->repo->begin_transaction();
		try {
			$this->repo->detach_calendar_from_group( $group_id, $calendar_id );
			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::DETACHED_FROM_GROUP, $group_id, $calendar_id, $actor_user_id );

		return true;
	}

	// ================================================= acknowledge_no_calendar (C-2).

	/**
	 * Record an explicit acknowledgement that a group generates without a
	 * calendar. This is the *only* way spec-calendar-v1 permits generation
	 * to fall through the C-2 gate; if the field is null the timetable
	 * refuses. The acknowledgement is per-group, actor-attributed, and
	 * carries a reason — never a silent default.
	 *
	 * @return true|WP_Error
	 */
	public function acknowledge_no_calendar( int $actor_user_id, int $group_id, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$reason_clean = sanitize_text_field( $reason );
		if ( '' === trim( $reason_clean ) ) {
			return new WP_Error( 'reason_required', __( 'A reason for skipping the calendar requirement is required.', 'minhaj-core' ) );
		}

		if ( null === $this->repo->find_group_calendar_state( $group_id ) ) {
			return new WP_Error( 'group_not_found', __( 'Group not found.', 'minhaj-core' ) );
		}

		$now = current_time( 'mysql', true );

		$this->repo->begin_transaction();
		try {
			$this->repo->update_group_calendar_fields(
				$group_id,
				array(
					'no_calendar_ack_by'     => $actor_user_id,
					'no_calendar_ack_reason' => $reason_clean,
					'no_calendar_ack_at'     => $now,
					'updated_at'             => $now,
				)
			);
			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::NO_CALENDAR_ACK, $group_id, $actor_user_id, $reason_clean );

		return true;
	}

	// ============================================= set_holiday_behavior (C-4).

	/**
	 * Changing behaviour to `skip_and_compress` is the write path that
	 * every C-4 rule guards. Setting to `skip_and_extend` is the default,
	 * unrestricted, but still needs an actor for the audit trail.
	 *
	 * @return true|WP_Error
	 */
	public function set_holiday_behavior(
		int $actor_user_id,
		int $group_id,
		string $behavior,
		string $reason = '',
		bool $paid_override = false,
		string $paid_override_reason = ''
	) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( ! HolidayBehavior::is_valid( $behavior ) ) {
			return new WP_Error( 'invalid_arg', __( 'Unknown holiday behavior.', 'minhaj-core' ) );
		}

		if ( null === $this->repo->find_group_calendar_state( $group_id ) ) {
			return new WP_Error( 'group_not_found', __( 'Group not found.', 'minhaj-core' ) );
		}

		if ( HolidayBehavior::SKIP_AND_COMPRESS === $behavior ) {
			$reason_clean = sanitize_text_field( $reason );
			if ( '' === trim( $reason_clean ) ) {
				return new WP_Error(
					'reason_required',
					__( 'skip_and_compress requires a written reason.', 'minhaj-core' )
				);
			}

			if ( ! user_can( $actor_user_id, Capabilities::MANAGE_GROUPS ) ) {
				return new WP_Error(
					'insufficient_cap',
					__( 'skip_and_compress requires minhaj_manage_groups.', 'minhaj-core' )
				);
			}

			$paid = $this->repo->count_paid_enrolments_for_group( $group_id );
			if ( $paid > 0 ) {
				$override_reason_clean = sanitize_text_field( $paid_override_reason );
				if ( ! $paid_override || '' === trim( $override_reason_clean ) ) {
					return new WP_Error(
						'paid_enrolments_present',
						sprintf(
							/* translators: 1: count of paid enrolments */
							__( 'Group has %d paid enrolment(s); skip_and_compress needs an explicit paid override with its own reason.', 'minhaj-core' ),
							$paid
						)
					);
				}
			}
		}

		$now = current_time( 'mysql', true );

		$this->repo->begin_transaction();
		try {
			$this->repo->update_group_calendar_fields(
				$group_id,
				array(
					'holiday_behavior' => $behavior,
					'updated_at'       => $now,
				)
			);
			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::HOLIDAY_BEHAVIOR_SET, $group_id, $behavior, $actor_user_id );

		return true;
	}

	// ======================================================= read-only helpers.

	/**
	 * @return array<int, string>
	 */
	public function list_disabled_dates_for_group( int $group_id, string $from_iso, string $to_iso ): array {
		return $this->repo->list_disabled_dates_for_group( $group_id, $from_iso, $to_iso );
	}

	/**
	 * @return array<int, int>
	 */
	public function list_calendar_ids_for_group( int $group_id ): array {
		return $this->repo->list_calendar_ids_for_group( $group_id );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_group_calendar_state( int $group_id ): ?array {
		return $this->repo->find_group_calendar_state( $group_id );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function suggest_default_calendar_for_country( string $country ): ?array {
		return $this->repo->find_default_calendar_for_country( $country );
	}

	/**
	 * C-3 · the stale-calendar report. Any active calendar whose latest
	 * day_date is more than $threshold_days behind today (i.e. it carries
	 * no days beyond that horizon) surfaces here. Feeds a weekly admin
	 * digest so silent drift cannot go unnoticed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_stale_calendars( int $threshold_days = 90 ): array {
		return $this->repo->list_stale_calendars( current_time( 'Y-m-d', true ), $threshold_days );
	}

	public function is_group_calendar_stale( int $group_id, int $threshold_days = 90 ): bool {
		$today  = current_time( 'Y-m-d', true );
		$cutoff = ( new \DateTimeImmutable( $today, new \DateTimeZone( 'UTC' ) ) )
			->modify( '+' . $threshold_days . ' days' )
			->format( 'Y-m-d' );

		$calendar_ids = $this->list_calendar_ids_for_group( $group_id );
		if ( array() === $calendar_ids ) {
			return false;
		}

		$stale_map = array();
		foreach ( $this->list_stale_calendars( $threshold_days ) as $row ) {
			$stale_map[ (int) $row['id'] ] = true;
		}

		foreach ( $calendar_ids as $cid ) {
			if ( isset( $stale_map[ $cid ] ) ) {
				return true;
			}
		}

		return false;
	}

	// ------------------------------------------------------------------- Helpers.

	/**
	 * @return true|WP_Error
	 */
	private function require_actor( int $actor_user_id ) {
		if ( $actor_user_id <= 0 ) {
			return new WP_Error(
				'missing_actor',
				__( 'actor_user_id must be a positive integer — audit rows cannot be anonymous.', 'minhaj-core' )
			);
		}

		return true;
	}
}
