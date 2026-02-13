<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database table creation, versioned migrations, and default option seeding.
 *
 * Uses dbDelta() for initial table creation, then numbered migration
 * callbacks for schema changes that dbDelta cannot handle (column
 * modifications, data transforms, etc.). Each migration runs at most
 * once, tracked via the `wp4odoo_schema_version` option.
 *
 * @package WP4Odoo
 * @since   1.5.0
 */
final class Database_Migration {

	/**
	 * Option key for the schema migration version counter.
	 *
	 * Distinct from OPT_DB_VERSION (which tracks the plugin version).
	 */
	public const OPT_SCHEMA_VERSION = 'wp4odoo_schema_version';

	/**
	 * Create plugin database tables.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp4odoo_sync_queue (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			correlation_id CHAR(36) DEFAULT NULL,
			module VARCHAR(50) NOT NULL,
			direction ENUM('wp_to_odoo','odoo_to_wp') NOT NULL,
			entity_type VARCHAR(100) NOT NULL,
			wp_id BIGINT(20) UNSIGNED DEFAULT NULL,
			odoo_id BIGINT(20) UNSIGNED DEFAULT NULL,
			action ENUM('create','update','delete') NOT NULL DEFAULT 'update',
			payload LONGTEXT DEFAULT NULL,
			priority TINYINT(3) UNSIGNED NOT NULL DEFAULT 5,
			status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
			attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			max_attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 3,
			error_message TEXT DEFAULT NULL,
			scheduled_at DATETIME DEFAULT NULL,
			processed_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_status_priority (status, priority, scheduled_at),
			KEY idx_module_entity (module, entity_type),
			KEY idx_dedup_wp (module, entity_type, direction, status, wp_id),
			KEY idx_dedup_odoo (module, entity_type, direction, status, odoo_id),
			KEY idx_wp_id (wp_id),
			KEY idx_odoo_id (odoo_id),
			KEY idx_correlation (correlation_id)
		) $charset_collate;

		CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp4odoo_entity_map (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			module VARCHAR(50) NOT NULL,
			entity_type VARCHAR(100) NOT NULL,
			wp_id BIGINT(20) UNSIGNED NOT NULL,
			odoo_id BIGINT(20) UNSIGNED NOT NULL,
			odoo_model VARCHAR(100) NOT NULL,
			sync_hash VARCHAR(64) DEFAULT NULL,
			last_synced_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_unique_mapping (module, entity_type, wp_id, odoo_id),
			KEY idx_wp_lookup (entity_type, wp_id),
			KEY idx_odoo_lookup (module, entity_type, odoo_id)
		) $charset_collate;

		CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp4odoo_logs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			correlation_id CHAR(36) DEFAULT NULL,
			level ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
			module VARCHAR(50) DEFAULT NULL,
			message TEXT NOT NULL,
			context LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_level_date (level, created_at),
			KEY idx_module (module),
			KEY idx_correlation (correlation_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::run_migrations();

		update_option( Settings_Repository::OPT_DB_VERSION, WP4ODOO_VERSION );
	}

	/**
	 * Run all pending numbered migrations.
	 *
	 * Each migration is a static method named `migration_{n}` where n
	 * is a sequential integer starting at 1. Migrations already applied
	 * (schema_version >= n) are skipped.
	 *
	 * @return int Number of migrations applied.
	 */
	public static function run_migrations(): int {
		$current = (int) get_option( self::OPT_SCHEMA_VERSION, 0 );
		$applied = 0;

		$migrations = self::get_migrations();

		foreach ( $migrations as $version => $callback ) {
			if ( $current >= $version ) {
				continue;
			}

			try {
				$callback();
				++$applied;
				update_option( self::OPT_SCHEMA_VERSION, $version );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'WP4Odoo migration %d failed: %s', $version, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging for migration failures.
				}
				break;
			}
		}

		return $applied;
	}

	/**
	 * Get the ordered map of migration version → callable.
	 *
	 * @return array<int, callable>
	 */
	private static function get_migrations(): array {
		return [
			1 => [ self::class, 'migration_1' ],
			2 => [ self::class, 'migration_2' ],
			3 => [ self::class, 'migration_3' ],
			4 => [ self::class, 'migration_4' ],
		];
	}

	/**
	 * Migration 1: Add correlation_id columns to sync_queue and logs tables.
	 *
	 * On fresh installs these columns already exist via dbDelta; this
	 * migration handles upgrades from pre-2.9.0 schemas.
	 *
	 * @return void
	 */
	private static function migration_1(): void {
		global $wpdb;

		$queue_table = $wpdb->prefix . 'wp4odoo_sync_queue';
		$logs_table  = $wpdb->prefix . 'wp4odoo_logs';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$queue_table}" );
		if ( ! in_array( 'correlation_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$queue_table} ADD COLUMN correlation_id CHAR(36) DEFAULT NULL AFTER id" );
			$wpdb->query( "ALTER TABLE {$queue_table} ADD KEY idx_correlation (correlation_id)" );
		}

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$logs_table}" );
		if ( ! in_array( 'correlation_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$logs_table} ADD COLUMN correlation_id CHAR(36) DEFAULT NULL AFTER id" );
			$wpdb->query( "ALTER TABLE {$logs_table} ADD KEY idx_correlation (correlation_id)" );
		}
		// phpcs:enable
	}

	/**
	 * Migration 2: Add missing indexes for retry and cleanup operations.
	 *
	 * - idx_status_attempts: speeds up retry_failed() and cleanup queries
	 * - idx_created_at: speeds up time-range cleanup operations
	 *
	 * @return void
	 */
	private static function migration_2(): void {
		global $wpdb;

		$queue_table = $wpdb->prefix . 'wp4odoo_sync_queue';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$queue_table}" );
		$names   = array_column( $indexes, 'Key_name' );

		if ( ! in_array( 'idx_status_attempts', $names, true ) ) {
			$wpdb->query( "ALTER TABLE {$queue_table} ADD KEY idx_status_attempts (status, attempts)" );
		}

		if ( ! in_array( 'idx_created_at', $names, true ) ) {
			$wpdb->query( "ALTER TABLE {$queue_table} ADD KEY idx_created_at (created_at)" );
		}
		// phpcs:enable
	}

	/**
	 * Migration 3: Add composite index for dedup SELECT … FOR UPDATE.
	 *
	 * The dedup mechanism in Sync_Queue_Repository uses
	 * `WHERE module AND entity_type AND direction AND status`
	 * which requires a covering index for reliable InnoDB gap locking.
	 *
	 * @return void
	 */
	private static function migration_3(): void {
		global $wpdb;

		$queue_table = $wpdb->prefix . 'wp4odoo_sync_queue';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$queue_table}" );
		$names   = array_column( $indexes, 'Key_name' );

		if ( ! in_array( 'idx_dedup_composite', $names, true ) ) {
			$wpdb->query( "ALTER TABLE {$queue_table} ADD KEY idx_dedup_composite (module, entity_type, direction, status)" );
		}
		// phpcs:enable
	}

	/**
	 * Migration 4: Add composite index for health metric queries.
	 *
	 * Covers the `WHERE status = 'completed' AND processed_at >= …`
	 * pattern used by get_health_metrics() for latency and success rate.
	 *
	 * @return void
	 */
	private static function migration_4(): void {
		global $wpdb;

		$queue_table = $wpdb->prefix . 'wp4odoo_sync_queue';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$queue_table}" );
		$names   = array_column( $indexes, 'Key_name' );

		if ( ! in_array( 'idx_processed_status', $names, true ) ) {
			$wpdb->query( "ALTER TABLE {$queue_table} ADD KEY idx_processed_status (status, processed_at)" );
		}
		// phpcs:enable
	}

	/**
	 * Get the current schema version.
	 *
	 * @return int
	 */
	public static function get_schema_version(): int {
		return (int) get_option( self::OPT_SCHEMA_VERSION, 0 );
	}

	/**
	 * Set default plugin options if not already present.
	 *
	 * Delegates to Settings_Repository::seed_defaults().
	 *
	 * @return void
	 */
	public static function set_default_options(): void {
		( new Settings_Repository() )->seed_defaults();
	}
}
