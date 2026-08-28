<?php
/**
 * Orgs module — initial schema for spec-organizations-v1 §3.
 *
 * Four tables:
 *   • minhaj_orgs                    — supplier / licensee partner registry.
 *   • minhaj_org_registration_links  — the "link + code" attribution channel.
 *   • minhaj_org_members             — staff of an org (teacher / org_admin / coordinator).
 *   • minhaj_curricula               — single-row lookup, seeded with `manhaj-v1`.
 *
 * A STORED generated column (`active_user_id`) mirrors user_id only while a
 * membership row is active — MySQL cannot express a partial unique index
 * (WHERE ended_at IS NULL), so this is the same pattern the Groups and
 * People modules already use to force one-active-membership at the DB level.
 * The service layer cannot forget to sync it because it is DB-maintained.
 *
 * The `type` and `data_controller` columns exist on `minhaj_orgs` to hold
 * both `supplier` and `licensee`, but §5 O-11 locks `licensee` in the code
 * — the OrgService rejects any attempt to create one until the "software
 * vendor" bundle described in §9.5 lands. The column is here so the future
 * activation does not need a schema change.
 *
 * @package Minhaj\Modules\Orgs\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Orgs\Migrations;

use Minhaj\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

final class CreateOrgsTables extends Migration {

	public const VERSION = 20260828400000;

	public const ORGS_TABLE               = 'minhaj_orgs';
	public const REGISTRATION_LINKS_TABLE = 'minhaj_org_registration_links';
	public const ORG_MEMBERS_TABLE        = 'minhaj_org_members';
	public const CURRICULA_TABLE          = 'minhaj_curricula';

	public function version(): int {
		return self::VERSION;
	}

	public function name(): string {
		return 'orgs.create_tables';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		dbDelta( self::orgs_table_sql( $prefix, $charset ) );
		dbDelta( self::registration_links_table_sql( $prefix, $charset ) );
		dbDelta( self::org_members_table_sql( $prefix, $charset ) );
		dbDelta( self::curricula_table_sql( $prefix, $charset ) );

		$this->seed_default_curriculum();
	}

	public static function orgs_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::ORGS_TABLE;

		/*
		 * The four compensation columns come from spec-compensation-v1 §2. No
		 * code reads them today — they are landing here now so the follow-up
		 * spec never needs to migrate a live orgs table. VARCHAR(20) is used
		 * for `settlement_period` to match the status-column convention used
		 * elsewhere in the plugin (minhaj_groups, minhaj_teacher_profiles);
		 * ENUM would need an ALTER every time the compensation spec adds a
		 * settlement cadence.
		 */
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(32) NOT NULL,
			name VARCHAR(190) NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'supplier',
			data_controller VARCHAR(10) NOT NULL DEFAULT 'us',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			country CHAR(2) NOT NULL DEFAULT '',
			default_timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
			contract_ref VARCHAR(100) NOT NULL DEFAULT '',
			dpa_signed_at DATE NULL DEFAULT NULL,
			supplies_teachers TINYINT UNSIGNED NOT NULL DEFAULT 0,
			refers_students TINYINT UNSIGNED NOT NULL DEFAULT 0,
			default_currency CHAR(3) NOT NULL DEFAULT '',
			settlement_period VARCHAR(20) NOT NULL DEFAULT 'monthly',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_code (code),
			KEY type (type),
			KEY status (status)
		) ENGINE=InnoDB {$charset};";
	}

	public static function registration_links_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::REGISTRATION_LINKS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			org_id BIGINT UNSIGNED NOT NULL,
			token CHAR(22) NOT NULL,
			label VARCHAR(100) NOT NULL DEFAULT '',
			campaign VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			max_uses INT UNSIGNED NULL DEFAULT NULL,
			uses_count INT UNSIGNED NOT NULL DEFAULT 0,
			expires_at DATE NULL DEFAULT NULL,
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_token (token),
			KEY org_id (org_id),
			KEY status (status),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB {$charset};";
	}

	public static function org_members_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::ORG_MEMBERS_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			org_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role_in_org VARCHAR(20) NOT NULL,
			started_at DATETIME NOT NULL,
			ended_at DATETIME NULL DEFAULT NULL,
			active_user_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(ended_at IS NULL, user_id, NULL)) STORED,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_active_member (org_id, active_user_id),
			KEY org_id (org_id),
			KEY user_id (user_id),
			KEY ended_at (ended_at)
		) ENGINE=InnoDB {$charset};";
	}

	public static function curricula_table_sql( string $prefix, string $charset ): string {
		$table = $prefix . self::CURRICULA_TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(32) NOT NULL,
			name VARCHAR(190) NOT NULL,
			program_hours SMALLINT UNSIGNED NOT NULL DEFAULT 36,
			total_sessions SMALLINT UNSIGNED NOT NULL DEFAULT 36,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_code (code)
		) ENGINE=InnoDB {$charset};";
	}

	private function seed_default_curriculum(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::CURRICULA_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE code = %s',
				$table,
				'manhaj-v1'
			)
		);

		if ( null !== $existing ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'code'           => 'manhaj-v1',
				'name'           => 'Manhaj v1',
				'program_hours'  => 36,
				'total_sessions' => 36,
				'status'         => 'active',
				'created_at'     => current_time( 'mysql', true ),
			)
		);
	}
}
