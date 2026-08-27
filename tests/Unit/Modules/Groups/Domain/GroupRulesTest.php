<?php
/**
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Groups\Domain;

use Minhaj\Modules\Groups\Domain\GroupCapacity;
use Minhaj\Modules\Groups\Domain\GroupRules;
use Minhaj\Modules\Groups\Domain\GroupType;
use Minhaj\Modules\Groups\Domain\RuleViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( GroupRules::class )]
final class GroupRulesTest extends TestCase {

	// -------------------------------------------------------------------- R-1.

	#[TestDox( 'R-1: rejects seat allocation once active count reaches capacity_max' )]
	public function test_r1_rejects_when_full(): void {
		$this->expectException( RuleViolationException::class );
		$this->expectExceptionMessageMatches( '/R-1|no seat available/' );

		GroupRules::assert_seat_available( 5, 5 );
	}

	#[TestDox( 'R-1: allows seat allocation while active count is below capacity_max' )]
	public function test_r1_allows_when_seat_free(): void {
		GroupRules::assert_seat_available( 4, 5 );

		$this->addToAssertionCount( 1 );
	}

	public function test_r1_rejects_negative_active_count(): void {
		$this->expectException( RuleViolationException::class );

		GroupRules::assert_seat_available( -1, 5 );
	}

	// -------------------------------------------------------------------- R-2.

	#[TestDox( 'R-2: schedule transition allowed once active_count ≥ capacity_min' )]
	public function test_r2_allows_at_or_above_min(): void {
		GroupRules::assert_ready_to_schedule( GroupCapacity::GROUP_DEFAULT_MIN, GroupCapacity::GROUP_DEFAULT_MIN );

		$this->addToAssertionCount( 1 );
	}

	#[TestDox( 'R-2: schedule transition rejected below capacity_min' )]
	public function test_r2_rejects_below_min(): void {
		$this->expectException( RuleViolationException::class );
		$this->expectExceptionMessageMatches( '/R-2|cannot schedule/' );

		GroupRules::assert_ready_to_schedule( 2, GroupCapacity::GROUP_DEFAULT_MIN );
	}

	public function test_r2_rejects_nonsense_capacity_min(): void {
		$this->expectException( RuleViolationException::class );

		GroupRules::assert_ready_to_schedule( 0, 0 );
	}

	// -------------------------------------------------------------------- R-3.

	#[TestDox( 'R-3: individual groups accept exactly capacity_min = capacity_max = 1' )]
	public function test_r3_individual_size_one(): void {
		GroupRules::assert_capacity_matches_type( GroupType::INDIVIDUAL, 1, 1 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @return array<string, array{0:int, 1:int}>
	 */
	public static function invalid_individual_capacities(): array {
		return array(
			'min too big'   => array( 2, 2 ),
			'max too big'   => array( 1, 3 ),
			'min zero'      => array( 0, 1 ),
			'both zero'     => array( 0, 0 ),
			'min gt max'    => array( 3, 1 ),
		);
	}

	#[DataProvider( 'invalid_individual_capacities' )]
	#[TestDox( 'R-3: individual groups reject any capacity other than 1/1' )]
	public function test_r3_individual_rejects_other_sizes( int $min, int $max ): void {
		$this->expectException( RuleViolationException::class );
		$this->expectExceptionMessageMatches( '/R-3|individual/' );

		GroupRules::assert_capacity_matches_type( GroupType::INDIVIDUAL, $min, $max );
	}

	#[TestDox( 'R-3: group type accepts operational-numbers default 3/5' )]
	public function test_r3_group_default_capacities_are_valid(): void {
		GroupRules::assert_capacity_matches_type(
			GroupType::GROUP,
			GroupCapacity::GROUP_DEFAULT_MIN,
			GroupCapacity::GROUP_DEFAULT_MAX
		);

		$this->addToAssertionCount( 1 );
	}

	public function test_r3_group_rejects_max_over_hard_cap(): void {
		$this->expectException( RuleViolationException::class );
		$this->expectExceptionMessageMatches( '/hard cap/' );

		GroupRules::assert_capacity_matches_type( GroupType::GROUP, 3, GroupCapacity::HARD_CAP + 1 );
	}

	public function test_r3_group_rejects_min_greater_than_max(): void {
		$this->expectException( RuleViolationException::class );

		GroupRules::assert_capacity_matches_type( GroupType::GROUP, 5, 3 );
	}

	public function test_r3_rejects_unknown_type(): void {
		$this->expectException( RuleViolationException::class );

		GroupRules::assert_capacity_matches_type( 'clan', 1, 1 );
	}
}
