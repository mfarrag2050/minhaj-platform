<?php
/**
 * Persistent per-(batch, level) counter that never rewinds.
 *
 * Prior implementation derived {seq} from `count(groups WHERE deleted_at
 * IS NULL)`. Deleting a group frees its slot, so the next create reuses
 * a code that once belonged to another entity. A code is a public label
 * printed on invoices and referenced in support tickets — it is not
 * safe to reuse.
 *
 * The counter is bumped **outside** the group INSERT transaction, so a
 * rollback (unique-index race, validation failure) leaves the seq
 * burned. Better a small gap in the sequence than a duplicated label.
 *
 * @package Minhaj\Modules\Groups\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

final class CreateGroupCodeCounters extends Migration {

	public const VERSION       = 20260830300002;
	public const COUNTER_TABLE = 'minhaj_group_code_counters';

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'groups.create_group_code_counters';
	}

	public function up(): void {
		global $wpdb;

		$table   = $wpdb->prefix . self::COUNTER_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			batch_id BIGINT UNSIGNED NOT NULL,
			level VARCHAR(20) NOT NULL,
			next_seq INT UNSIGNED NOT NULL DEFAULT 1,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (batch_id, level)
		) ENGINE=InnoDB {$charset};";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}
}
