<?php
/**
 * Curriculum-scoped level catalogue.
 *
 * `Level` on a group was free text — every typo became a new level,
 * every abbreviation invented a new one. Wrong domain, wrong owner:
 * levels are the **curriculum's** property, not the system's. This
 * migration introduces the table where a curriculum lists the levels
 * it teaches, plus a `curriculum_id` column on the group so a group
 * knows which curriculum's levels it may pick from.
 *
 * Only `code` is required to be filled in day one — the rest (`name`,
 * `ordinal`, and later entry/exit criteria columns) are the Minhaj
 * pedagogical team's territory and stay empty until they arrive.
 *
 * @package Minhaj\Modules\Groups\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

final class CreateCurriculumLevels extends Migration {

	public const VERSION      = 20260830300003;
	public const LEVELS_TABLE = 'minhaj_curriculum_levels';

	// A single curriculum today (manhaj-v1). A curriculum table proper
	// will come when a second curriculum is in scope — for now the id
	// is a documented constant.
	public const MANHAJ_V1_ID = 1;

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'groups.create_curriculum_levels';
	}

	public function up(): void {
		global $wpdb;

		$levels_table = $wpdb->prefix . self::LEVELS_TABLE;
		$groups_table = $wpdb->prefix . CreateGroupsTables::GROUPS_TABLE;
		$charset      = $wpdb->get_charset_collate();

		$create = "CREATE TABLE {$levels_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			curriculum_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(20) NOT NULL,
			name VARCHAR(120) NOT NULL DEFAULT '',
			ordinal SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_curriculum_code (curriculum_id, code),
			KEY curriculum_ordinal (curriculum_id, ordinal)
		) ENGINE=InnoDB {$charset};";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $create );

		// Add curriculum_id to groups. Default 1 → manhaj-v1 for
		// pre-existing rows.
		$col = "ALTER TABLE {$groups_table}
			ADD COLUMN curriculum_id BIGINT UNSIGNED NOT NULL DEFAULT " . self::MANHAJ_V1_ID . ' AFTER batch_id,
			ADD KEY curriculum_id (curriculum_id)';
		$wpdb->query( $col );

		// Seed manhaj-v1 with the six standard CEFR levels. Pedagogical
		// content (entry/exit criteria, hours) belongs on this row and
		// stays empty until the Minhaj team fills it.
		$now  = current_time( 'mysql', true );
		$seed = array( 'A1', 'A2', 'B1', 'B2', 'C1', 'C2' );
		foreach ( $seed as $ordinal => $code ) {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (curriculum_id, code, name, ordinal, created_at) VALUES (%d, %s, %s, %d, %s)',
					$levels_table,
					self::MANHAJ_V1_ID,
					$code,
					'',
					$ordinal + 1,
					$now
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}
}
