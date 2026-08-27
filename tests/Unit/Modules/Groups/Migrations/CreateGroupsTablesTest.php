<?php
/**
 * R-1 is enforced at the database layer by the unique indexes on the members
 * table. These tests verify the generated schema carries them — no partial
 * indexes needed thanks to the mirror-column pattern (see
 * spec-groups-v1 §3.2).
 *
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Groups\Migrations;

use Minhaj\Modules\Groups\Migrations\CreateGroupsTables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( CreateGroupsTables::class )]
final class CreateGroupsTablesTest extends TestCase {

	#[TestDox( 'R-1: members table carries UNIQUE (group_id, active_seat_index) to enforce capacity_max' )]
	public function test_members_table_has_unique_active_seat_index(): void {
		$sql = CreateGroupsTables::members_table_sql( 'wp_', '' );

		$this->assertMatchesRegularExpression(
			'/UNIQUE\s+KEY\s+uq_active_seat\s*\(\s*group_id\s*,\s*active_seat_index\s*\)/i',
			$sql
		);
	}

	#[TestDox( 'R-1: members table carries UNIQUE (group_id, active_student_id) to block duplicate active memberships' )]
	public function test_members_table_has_unique_active_student(): void {
		$sql = CreateGroupsTables::members_table_sql( 'wp_', '' );

		$this->assertMatchesRegularExpression(
			'/UNIQUE\s+KEY\s+uq_active_student\s*\(\s*group_id\s*,\s*active_student_id\s*\)/i',
			$sql
		);
	}

	#[TestDox( 'R-1: active mirror columns are STORED generated columns computed from status — service layer cannot forget to sync them' )]
	public function test_members_table_defines_the_active_mirror_columns_as_generated(): void {
		$sql = CreateGroupsTables::members_table_sql( 'wp_', '' );

		$this->assertMatchesRegularExpression(
			"/active_seat_index\s+TINYINT\s+UNSIGNED\s+GENERATED\s+ALWAYS\s+AS\s*\(\s*IF\s*\(\s*status\s*=\s*'active'\s*,\s*seat_index\s*,\s*NULL\s*\)\s*\)\s+STORED/i",
			$sql
		);
		$this->assertMatchesRegularExpression(
			"/active_student_id\s+BIGINT\s+UNSIGNED\s+GENERATED\s+ALWAYS\s+AS\s*\(\s*IF\s*\(\s*status\s*=\s*'active'\s*,\s*student_id\s*,\s*NULL\s*\)\s*\)\s+STORED/i",
			$sql
		);
	}

	public function test_members_table_mirror_columns_have_no_default_clause(): void {
		$sql = CreateGroupsTables::members_table_sql( 'wp_', '' );

		// Generated columns may not carry DEFAULT — MySQL rejects it and would
		// break the migration silently under dbDelta.
		$this->assertDoesNotMatchRegularExpression( '/active_seat_index[^,]*DEFAULT/i', $sql );
		$this->assertDoesNotMatchRegularExpression( '/active_student_id[^,]*DEFAULT/i', $sql );
	}

	public function test_groups_table_freezes_capacity_columns_and_soft_delete(): void {
		$sql = CreateGroupsTables::groups_table_sql( 'wp_', '' );

		$this->assertStringContainsString( 'capacity_min TINYINT UNSIGNED NOT NULL', $sql );
		$this->assertStringContainsString( 'capacity_max TINYINT UNSIGNED NOT NULL', $sql );
		$this->assertStringContainsString( 'deleted_at DATETIME NULL', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY code (code)', $sql );
	}

	public function test_audit_table_has_actor_and_action_columns(): void {
		$sql = CreateGroupsTables::audit_table_sql( 'wp_', '' );

		$this->assertStringContainsString( 'actor_user_id BIGINT UNSIGNED NOT NULL', $sql );
		$this->assertStringContainsString( 'action VARCHAR(64) NOT NULL', $sql );
		$this->assertStringContainsString( 'payload_json LONGTEXT NULL', $sql );
	}

	#[TestDox( 'All tables specify ENGINE=InnoDB so transactions and row locks actually apply' )]
	public function test_all_tables_are_innodb(): void {
		$this->assertStringContainsString( 'ENGINE=InnoDB', CreateGroupsTables::groups_table_sql( 'wp_', '' ) );
		$this->assertStringContainsString( 'ENGINE=InnoDB', CreateGroupsTables::members_table_sql( 'wp_', '' ) );
		$this->assertStringContainsString( 'ENGINE=InnoDB', CreateGroupsTables::audit_table_sql( 'wp_', '' ) );
	}

	public function test_migration_metadata(): void {
		$migration = new CreateGroupsTables();

		$this->assertSame( 20260827100000, $migration->version() );
		$this->assertSame( 'groups.create_tables', $migration->name() );
	}
}
