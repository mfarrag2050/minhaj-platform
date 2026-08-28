<?php
/**
 * `wp_minhaj_batches` — the entity `minhaj_groups.batch_id` used to
 * point at without any target. A batch is a launch cohort: a market,
 * a start date, an org that runs it. Groups select their batch from
 * a list; the batch's `market` and `code` feed the group-code format
 * so nobody types a market string by hand.
 *
 * @package Minhaj\Modules\Groups\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

final class CreateBatchesTable extends Migration {

	public const VERSION = 20260830300000;

	public const BATCHES_TABLE = 'minhaj_batches';

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'groups.create_batches';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		dbDelta( self::batches_table_sql( $prefix, $charset ) );
	}

	public static function batches_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::BATCHES_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(32) NOT NULL,
			org_id BIGINT UNSIGNED NULL DEFAULT NULL,
			market VARCHAR(10) NOT NULL DEFAULT '',
			starts_on DATE NULL DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'planned',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_code (code),
			KEY org_id (org_id),
			KEY market (market),
			KEY status (status)
		) ENGINE=InnoDB {$charset};";
	}
}
