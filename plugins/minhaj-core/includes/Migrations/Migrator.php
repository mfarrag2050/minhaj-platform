<?php
/**
 * Schema migrations runner.
 *
 * @package Minhaj\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Migrations;

defined( 'ABSPATH' ) || exit;

final class Migrator {

	private const VERSIONS_TABLE = 'minhaj_schema_versions';

	private static ?self $instance = null;

	/**
	 * Registered migrations, keyed by version number.
	 *
	 * @var array<int, Migration>
	 */
	private array $migrations = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		/**
		 * Filters the list of migration instances the runner will execute.
		 *
		 * Modules register their migrations by returning objects that extend
		 * `Minhaj\Migrations\Migration`. Ordering is by `version()` ascending.
		 *
		 * @param array<int, Migration> $migrations Existing migrations, keyed by version.
		 */
		$registered = apply_filters( 'minhaj_core_register_migrations', array() );

		foreach ( $registered as $migration ) {
			if ( $migration instanceof Migration ) {
				$this->migrations[ $migration->version() ] = $migration;
			}
		}

		ksort( $this->migrations, SORT_NUMERIC );
	}

	/**
	 * Runs on activation. Ensures the versions table exists, then applies pending migrations.
	 */
	public function install(): void {
		$this->ensure_versions_table();
		$this->maybe_upgrade();
	}

	/**
	 * Applies any migrations whose version is newer than the highest applied version.
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->versions_table_exists() ) {
			$this->ensure_versions_table();
		}

		$current = $this->current_version();

		foreach ( $this->migrations as $version => $migration ) {
			if ( $version <= $current ) {
				continue;
			}

			$migration->up();
			$this->record_version( $migration );
		}
	}

	public function current_version(): int {
		global $wpdb;

		if ( ! $this->versions_table_exists() ) {
			return 0;
		}

		$table = $this->versions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( "SELECT MAX(version) FROM {$table}" );

		return null === $value ? 0 : (int) $value;
	}

	private function ensure_versions_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $this->versions_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			version BIGINT UNSIGNED NOT NULL,
			name VARCHAR(191) NOT NULL,
			applied_at DATETIME NOT NULL,
			PRIMARY KEY  (version)
		) {$charset};";

		dbDelta( $sql );
	}

	private function record_version( Migration $migration ): void {
		global $wpdb;

		$wpdb->insert(
			$this->versions_table(),
			array(
				'version'    => $migration->version(),
				'name'       => $migration->name(),
				'applied_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s' )
		);
	}

	private function versions_table_exists(): bool {
		global $wpdb;

		$table = $this->versions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	private function versions_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::VERSIONS_TABLE;
	}
}
