<?php
/**
 * Acceptance tests for spec-calendar-v1 §7 + the six review corrections
 * C-1 through C-6.
 *
 * DB-side proofs of the held-session guard (C-5) and cross-org public
 * calendar block (C-6) that walk real MariaDB live in the integration
 * script; the unit layer here checks the service's contract with a
 * mocked repository.
 *
 * @package Minhaj\Tests\Unit\Modules\Calendar
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Calendar;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Minhaj\Access\Capabilities;
use Minhaj\Modules\Calendar\CalendarService;
use Minhaj\Modules\Calendar\Domain\HolidayBehavior;
use Minhaj\Modules\Calendar\Repository\CalendarRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use WP_Error;

#[CoversClass( CalendarService::class )]
final class CalendarServiceTest extends TestCase {

	/**
	 * @var array<int, array<string, bool>>
	 */
	private array $caps = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( fn( string $tag, mixed $value ) => $value );
		Functions\when( 'do_action' )->justReturn();
		Functions\when( 'current_time' )->alias(
			fn( string $format ) => 'Y-m-d' === $format ? '2026-08-28' : '2026-08-28 12:00:00'
		);
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $v ) => json_encode( $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias( fn( mixed $t ) => $t instanceof WP_Error );
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $cap ): bool {
				return $this->caps[ $user_id ][ $cap ] ?? false;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$this->caps = array();
		parent::tearDown();
	}

	// ============================================================== C-2 acknowledgement.

	#[TestDox( 'C-2: acknowledge_no_calendar without a written reason is rejected' )]
	public function test_ack_requires_reason(): void {
		$repo = $this->createMock( CalendarRepository::class );
		$repo->expects( $this->never() )->method( 'update_group_calendar_fields' );

		$svc = new CalendarService( $repo );
		$out = $svc->acknowledge_no_calendar( 7, 100, '   ' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'reason_required', $out->get_error_code() );
	}

	#[TestDox( 'C-2: acknowledge_no_calendar writes actor, reason, and timestamp' )]
	public function test_ack_records_actor_and_reason(): void {
		$repo = $this->createMock( CalendarRepository::class );
		$repo->method( 'find_group_calendar_state' )->willReturn(
			array(
				'holiday_behavior'       => 'skip_and_extend',
				'no_calendar_ack_by'     => null,
				'no_calendar_ack_reason' => '',
				'no_calendar_ack_at'     => null,
			)
		);

		$captured = null;
		$repo->expects( $this->once() )
			->method( 'update_group_calendar_fields' )
			->willReturnCallback(
				function ( int $group_id, array $data ) use ( &$captured ): int {
					$captured = array( 'group_id' => $group_id, 'data' => $data );
					return 1;
				}
			);

		$svc = new CalendarService( $repo );
		$out = $svc->acknowledge_no_calendar( 7, 100, 'internal training group' );

		$this->assertTrue( $out );
		$this->assertSame( 100, $captured['group_id'] );
		$this->assertSame( 7, $captured['data']['no_calendar_ack_by'] );
		$this->assertSame( 'internal training group', $captured['data']['no_calendar_ack_reason'] );
		$this->assertNotNull( $captured['data']['no_calendar_ack_at'] );
	}

	// ============================================================== C-4 compress guards.

	#[TestDox( 'C-4: skip_and_compress without MANAGE_GROUPS is rejected' )]
	public function test_compress_requires_cap(): void {
		$repo = $this->createMock( CalendarRepository::class );
		$repo->method( 'find_group_calendar_state' )->willReturn(
			array( 'holiday_behavior' => 'skip_and_extend', 'no_calendar_ack_by' => null, 'no_calendar_ack_reason' => '', 'no_calendar_ack_at' => null )
		);
		$repo->expects( $this->never() )->method( 'update_group_calendar_fields' );

		$svc = new CalendarService( $repo );
		$out = $svc->set_holiday_behavior( 7, 100, HolidayBehavior::SKIP_AND_COMPRESS, 'contract_says_so' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'insufficient_cap', $out->get_error_code() );
	}

	#[TestDox( 'C-4: skip_and_compress without written reason is rejected even for staff' )]
	public function test_compress_requires_reason(): void {
		$this->caps[7] = array( Capabilities::MANAGE_GROUPS => true );

		$repo = $this->createMock( CalendarRepository::class );
		$repo->method( 'find_group_calendar_state' )->willReturn(
			array( 'holiday_behavior' => 'skip_and_extend', 'no_calendar_ack_by' => null, 'no_calendar_ack_reason' => '', 'no_calendar_ack_at' => null )
		);
		$repo->expects( $this->never() )->method( 'update_group_calendar_fields' );

		$svc = new CalendarService( $repo );
		$out = $svc->set_holiday_behavior( 7, 100, HolidayBehavior::SKIP_AND_COMPRESS, '  ' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'reason_required', $out->get_error_code() );
	}

	#[TestDox( 'C-4: skip_and_compress on a group with paid enrolments is rejected without explicit paid override' )]
	public function test_compress_refused_when_paid_enrolments(): void {
		$this->caps[7] = array( Capabilities::MANAGE_GROUPS => true );

		$repo = $this->createMock( CalendarRepository::class );
		$repo->method( 'find_group_calendar_state' )->willReturn(
			array( 'holiday_behavior' => 'skip_and_extend', 'no_calendar_ack_by' => null, 'no_calendar_ack_reason' => '', 'no_calendar_ack_at' => null )
		);
		$repo->method( 'count_paid_enrolments_for_group' )->willReturn( 2 );
		$repo->expects( $this->never() )->method( 'update_group_calendar_fields' );

		$svc = new CalendarService( $repo );
		$out = $svc->set_holiday_behavior( 7, 100, HolidayBehavior::SKIP_AND_COMPRESS, 'contract' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'paid_enrolments_present', $out->get_error_code() );
	}

	#[TestDox( 'C-4: skip_and_compress with paid override AND override reason AND cap AND reason — accepted' )]
	public function test_compress_accepted_with_full_override(): void {
		$this->caps[7] = array( Capabilities::MANAGE_GROUPS => true );

		$repo = $this->createMock( CalendarRepository::class );
		$repo->method( 'find_group_calendar_state' )->willReturn(
			array( 'holiday_behavior' => 'skip_and_extend', 'no_calendar_ack_by' => null, 'no_calendar_ack_reason' => '', 'no_calendar_ack_at' => null )
		);
		$repo->method( 'count_paid_enrolments_for_group' )->willReturn( 2 );
		$repo->expects( $this->once() )->method( 'update_group_calendar_fields' );

		$svc = new CalendarService( $repo );
		$out = $svc->set_holiday_behavior(
			7,
			100,
			HolidayBehavior::SKIP_AND_COMPRESS,
			'contract',
			true,
			'legal signed off'
		);

		$this->assertTrue( $out );
	}

	// ============================================================== C-6 public lock.

	#[TestDox( 'C-6: creating a public (org_id NULL) calendar without MANAGE_GROUPS is rejected' )]
	public function test_public_calendar_creation_requires_staff(): void {
		$repo = $this->createMock( CalendarRepository::class );
		$repo->expects( $this->never() )->method( 'insert_calendar' );

		$svc = new CalendarService( $repo );
		$out = $svc->create_calendar( 55, array( 'name' => 'Islamic holidays', 'org_id' => null, 'country' => '' ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'public_calendar_forbidden', $out->get_error_code() );
	}

	#[TestDox( 'C-6: staff can create a public calendar' )]
	public function test_public_calendar_creation_ok_for_staff(): void {
		$this->caps[1] = array( Capabilities::MANAGE_GROUPS => true );

		$repo = $this->createMock( CalendarRepository::class );
		$repo->expects( $this->once() )->method( 'insert_calendar' )->willReturn( 99 );

		$svc = new CalendarService( $repo );
		$out = $svc->create_calendar( 1, array( 'name' => 'Islamic holidays', 'org_id' => null ) );

		$this->assertSame( 99, $out );
	}

	#[TestDox( 'C-6: adding a day to a public calendar is rejected for non-staff' )]
	public function test_public_calendar_add_day_blocked_for_non_staff(): void {
		$repo = $this->createMock( CalendarRepository::class );
		$repo->method( 'find_calendar' )->willReturn( array( 'id' => 5, 'org_id' => null ) );
		$repo->expects( $this->never() )->method( 'insert_day' );

		$svc = new CalendarService( $repo );
		$out = $svc->add_day( 55, 5, '2027-01-01', 'closure', 'new year' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'public_calendar_forbidden', $out->get_error_code() );
	}

	// ============================================================== C-5 held-session guard.

	#[TestDox( 'C-5: delete_day refuses when held sessions exist on that date under an attached group' )]
	public function test_delete_day_blocked_by_held_sessions(): void {
		$this->caps[1] = array( Capabilities::MANAGE_GROUPS => true );

		$repo = $this->createMock( CalendarRepository::class );
		$repo->method( 'find_day' )->willReturn(
			array( 'id' => 9, 'calendar_id' => 5, 'day_date' => '2026-12-25' )
		);
		$repo->method( 'find_calendar' )->willReturn( array( 'id' => 5, 'org_id' => null ) );
		$repo->method( 'count_held_sessions_on_calendar_date_for_update' )->willReturn( 3 );
		$repo->expects( $this->never() )->method( 'delete_day_by_id' );

		$svc = new CalendarService( $repo );
		$out = $svc->delete_day( 1, 9, 'admin cleanup' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'held_sessions_present', $out->get_error_code() );
	}

	#[TestDox( 'C-5: delete_day proceeds when no held sessions block it' )]
	public function test_delete_day_ok_when_no_held(): void {
		$this->caps[1] = array( Capabilities::MANAGE_GROUPS => true );

		$repo = $this->createMock( CalendarRepository::class );
		$repo->method( 'find_day' )->willReturn(
			array( 'id' => 9, 'calendar_id' => 5, 'day_date' => '2027-12-25' )
		);
		$repo->method( 'find_calendar' )->willReturn( array( 'id' => 5, 'org_id' => null ) );
		$repo->method( 'count_held_sessions_on_calendar_date_for_update' )->willReturn( 0 );
		$repo->expects( $this->once() )->method( 'delete_day_by_id' );

		$svc = new CalendarService( $repo );
		$this->assertTrue( $svc->delete_day( 1, 9, 'admin cleanup' ) );
	}

	// ============================================================== §7-1 & §7-2 flows.

	#[TestDox( '§7-1: overlapping calendars — union of disabled dates is what the service exposes' )]
	public function test_union_of_calendar_days_is_returned_for_group(): void {
		$repo = $this->createMock( CalendarRepository::class );
		// Two calendars, one day each, distinct — service returns both.
		$repo->method( 'list_disabled_dates_for_group' )
			->willReturn( array( '2026-10-19', '2026-04-10' ) );

		$svc = new CalendarService( $repo );
		$out = $svc->list_disabled_dates_for_group( 100, '2026-01-01', '2026-12-31' );

		$this->assertContains( '2026-10-19', $out );
		$this->assertContains( '2026-04-10', $out );
	}

	// ============================================================== §7 default suggestion.

	#[TestDox( 'default calendar suggestion reads by ISO-2 country code' )]
	public function test_default_calendar_suggestion(): void {
		$repo = $this->createMock( CalendarRepository::class );
		$repo->expects( $this->once() )->method( 'find_default_calendar_for_country' )->with( 'NL' )->willReturn(
			array( 'id' => 3, 'name' => 'NL Holidays', 'country' => 'NL' )
		);

		$svc  = new CalendarService( $repo );
		$hint = $svc->suggest_default_calendar_for_country( 'NL' );

		$this->assertIsArray( $hint );
		$this->assertSame( 3, (int) $hint['id'] );
	}
}
