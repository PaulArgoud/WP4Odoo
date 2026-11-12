<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database table creation, versioned migrations, and default option seeding.
 *
 * Creates the tables with plain CREATE TABLE IF NOT EXISTS statements, then numbered migration
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
	 * Option holding the last table-creation failure (tables still missing).
	 */
	public const OPT_DB_ERROR = 'wp4odoo_db_error';

	/**
	 * Transient set once the tables were verified, to avoid checking on every request.
	 */
	private const TRANSIENT_TABLES_OK = 'wp4odoo_tables_ok';

	/**
	 * Transient guarding against concurrent repairs.
	 */
	private const TRANSIENT_UPGRADING = 'wp4odoo_db_upgrading';

	/**
	 * Tables (without the WordPress prefix) the plugin cannot work without.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_TABLES = [ 'wp4odoo_sync_queue', 'wp4odoo_entity_map', 'wp4odoo_logs' ];

	/**
	 * Names of the required tables that do not exist.
	 *
	 * @return array<int, string> Table names without the WordPress prefix.
	 */
	public static function missing_tables(): array {
		global $wpdb;

		$missing = [];
		foreach ( self::REQUIRED_TABLES as $table ) {
			$name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
			if ( $found !== $name ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	/**
	 * Make sure the tables exist and the schema is current.
	 *
	 * Tables are normally created when the plugin is activated, but that hook does
	 * not run when the files are copied or updated in place, or when the database
	 * user lacked the CREATE privilege at that moment. This repairs those cases:
	 * it runs the table creation and pending migrations when the stored plugin
	 * version differs from the running one, or (in admin and cron requests, at most
	 * every 12 hours) when a table is missing.
	 *
	 * @param \Closure|null $create Table creator (defaults to create_tables()); injectable for tests.
	 * @return bool True when the tables are present afterwards.
	 */
	public static function ensure_tables( ?\Closure $create = null ): bool {
		global $wpdb;

		$current      = get_option( Settings_Repository::OPT_DB_VERSION ) === WP4ODOO_VERSION;
		$check_tables = is_admin() || wp_doing_cron();

		if ( $current && ( ! $check_tables || get_transient( self::TRANSIENT_TABLES_OK ) ) ) {
			return true;
		}

		if ( $current && ! self::missing_tables() ) {
			set_transient( self::TRANSIENT_TABLES_OK, '1', 12 * HOUR_IN_SECONDS );
			delete_option( self::OPT_DB_ERROR );
			return true;
		}

		if ( get_transient( self::TRANSIENT_UPGRADING ) ) {
			return true; // Another request is repairing right now.
		}
		set_transient( self::TRANSIENT_UPGRADING, '1', MINUTE_IN_SECONDS );

		try {
			( $create ?? [ self::class, 'create_tables' ] )();
		} catch ( \Throwable $e ) {
			$wpdb->last_error = $e->getMessage();
		}

		delete_transient( self::TRANSIENT_UPGRADING );

		$missing = self::missing_tables();
		if ( $missing ) {
			update_option(
				self::OPT_DB_ERROR,
				[
					'tables' => $missing,
					'error'  => (string) $wpdb->last_error,
				],
				false
			);
			return false;
		}

		delete_option( self::OPT_DB_ERROR );
		set_transient( self::TRANSIENT_TABLES_OK, '1', 12 * HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Show an admin notice when the tables could not be created.
	 *
	 * @return void
	 */
	public static function render_error_notice(): void {
		$error = get_option( self::OPT_DB_ERROR, [] );
		if ( empty( $error ) || ! is_array( $error ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>WP4Odoo:</strong> %s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: comma-separated table names, 2: database error message */
					__( 'The database tables (%1$s) could not be created, so synchronization is disabled. Check that the database user has the CREATE privilege, then reload this page. Database error: %2$s', 'wp4odoo' ),
					implode( ', ', (array) ( $error['tables'] ?? [] ) ),
					(string) ( $error['error'] ?? '' )
				)
			)
		);
	}

	/**
	 * Create plugin database tables.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Plain CREATE TABLE IF NOT EXISTS statements, executed one by one. dbDelta() must not
		// be used here: it indexes statements by the word after "CREATE TABLE", which is "IF"
		// for all three, so only the last statement ran and the queue and entity-map tables
		// were never created on current WordPress versions. Later schema changes go through
		// the numbered migrations, which dbDelta could not handle either.
		$statements = [
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp4odoo_sync_queue (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
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
			KEY idx_status_module (blog_id, status, module, priority, created_at),
			KEY idx_module_entity (module, entity_type),
			KEY idx_dedup_wp (blog_id, module, entity_type, direction, status, wp_id),
			KEY idx_dedup_odoo (blog_id, module, entity_type, direction, status, odoo_id),
			KEY idx_wp_id (wp_id),
			KEY idx_odoo_id (odoo_id),
			KEY idx_correlation (correlation_id),
			KEY idx_status_created (status, created_at)
		) $charset_collate;",
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp4odoo_entity_map (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			module VARCHAR(50) NOT NULL,
			entity_type VARCHAR(100) NOT NULL,
			wp_id BIGINT(20) UNSIGNED NOT NULL,
			odoo_id BIGINT(20) UNSIGNED NOT NULL,
			odoo_model VARCHAR(100) NOT NULL,
			sync_hash VARCHAR(64) DEFAULT NULL,
			last_synced_at DATETIME DEFAULT NULL,
			last_polled_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_unique_mapping (blog_id, module, entity_type, wp_id, odoo_id),
			KEY idx_wp_lookup (blog_id, module, entity_type, wp_id),
			KEY idx_odoo_lookup (blog_id, module, entity_type, odoo_id),
			KEY idx_poll_detection (blog_id, module, entity_type, last_polled_at)
		) $charset_collate;",
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp4odoo_logs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			correlation_id CHAR(36) DEFAULT NULL,
			level ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
			module VARCHAR(50) DEFAULT NULL,
			message TEXT NOT NULL,
			context LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_level_date (level, created_at),
			KEY idx_module (module),
			KEY idx_correlation (correlation_id),
			KEY idx_blog_cleanup (blog_id, created_at)
		) $charset_collate;",
		];

		foreach ( $statements as $statement ) {
			$wpdb->query( $statement ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		}

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
		global $wpdb;

		$current = (int) get_option( self::OPT_SCHEMA_VERSION, 0 );
		$applied = 0;

		$migrations = self::get_migrations();

		// Check if we're already inside a transaction (e.g. WordPress test
		// framework) to avoid an implicit commit of the outer transaction.
		$wpdb->suppress_errors( true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_suppress_errors
		$in_tx = $wpdb->get_var( 'SELECT @@in_transaction' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->suppress_errors( false );
		$use_savepoint = '1' === (string) $in_tx;

		foreach ( $migrations as $version => $callback ) {
			if ( $current >= $version ) {
				continue;
			}

			try {
				// Wrap each migration in a transaction so a partial failure
				// (e.g. first ALTER succeeds, second fails) does not leave
				// the schema in an inconsistent state. InnoDB DDL in MySQL
				// 8.0+ is atomic; on older versions the ROLLBACK is best-effort.
				if ( $use_savepoint ) {
					$wpdb->query( 'SAVEPOINT wp4odoo_migration' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				} else {
					$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}

				$callback();

				if ( $use_savepoint ) {
					$wpdb->query( 'RELEASE SAVEPOINT wp4odoo_migration' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				} else {
					$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
				++$applied;
				update_option( self::OPT_SCHEMA_VERSION, $version );
			} catch ( \Throwable $e ) {
				if ( $use_savepoint ) {
					$wpdb->query( 'ROLLBACK TO SAVEPOINT wp4odoo_migration' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				} else {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
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
			1  => [ self::class, 'migration_1' ],
			2  => [ self::class, 'migration_2' ],
			3  => [ self::class, 'migration_3' ],
			4  => [ self::class, 'migration_4' ],
			5  => [ self::class, 'migration_5' ],
			6  => [ self::class, 'migration_6' ],
			7  => [ self::class, 'migration_7' ],
			8  => [ self::class, 'migration_8' ],
			9  => [ self::class, 'migration_9' ],
			10 => [ self::class, 'migration_10' ],
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
	 * Migration 5: Optimize entity_map wp_lookup index and add sync_queue cleanup index.
	 *
	 * - idx_wp_lookup: adds `module` prefix column for queries that filter by module first.
	 * - idx_status_created: covers `WHERE status = … AND created_at < …` cleanup queries.
	 *
	 * @return void
	 */
	private static function migration_5(): void {
		global $wpdb;

		$entity_table = $wpdb->prefix . 'wp4odoo_entity_map';
		$queue_table  = $wpdb->prefix . 'wp4odoo_sync_queue';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange

		// Replace idx_wp_lookup (entity_type, wp_id) with (module, entity_type, wp_id).
		// Uses a single atomic ALTER TABLE to avoid a window without any index.
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$entity_table}" );
		$names   = array_column( $indexes, 'Key_name' );

		if ( in_array( 'idx_wp_lookup', $names, true ) ) {
			// Check if the index already has the correct columns (idempotent re-run).
			$idx_cols = array_column(
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL SHOW INDEX column names.
				array_filter( $indexes, fn( $idx ) => 'idx_wp_lookup' === $idx->Key_name ),
				'Column_name'
			);
			if ( ! in_array( 'module', $idx_cols, true ) ) {
				// Atomic drop+add in a single ALTER TABLE: no window without index.
				$wpdb->query( "ALTER TABLE {$entity_table} DROP KEY idx_wp_lookup, ADD KEY idx_wp_lookup (module, entity_type, wp_id)" );
			}
		} else {
			$wpdb->query( "ALTER TABLE {$entity_table} ADD KEY idx_wp_lookup (module, entity_type, wp_id)" );
		}

		// Add cleanup index for sync_queue.
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$queue_table}" );
		$names   = array_column( $indexes, 'Key_name' );

		if ( ! in_array( 'idx_status_created', $names, true ) ) {
			$wpdb->query( "ALTER TABLE {$queue_table} ADD KEY idx_status_created (status, created_at)" );
		}
		// phpcs:enable
	}

	/**
	 * Migration 6: Add last_polled_at column to entity_map for efficient poll deletion detection.
	 *
	 * Enables cron polling modules (Bookly, Ecwid) to detect deletions
	 * via `WHERE last_polled_at < poll_start` instead of loading all
	 * entity_map rows into memory.
	 *
	 * @return void
	 */
	private static function migration_6(): void {
		global $wpdb;

		$entity_table = $wpdb->prefix . 'wp4odoo_entity_map';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$entity_table}" );
		if ( ! in_array( 'last_polled_at', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$entity_table} ADD COLUMN last_polled_at DATETIME DEFAULT NULL AFTER last_synced_at" );
			$wpdb->query( "ALTER TABLE {$entity_table} ADD KEY idx_poll_detection (module, entity_type, last_polled_at)" );
		}
		// phpcs:enable
	}

	/**
	 * Migration 7: Add blog_id column to all three plugin tables for multisite support.
	 *
	 * In WordPress multisite, each site has its own options table but shares
	 * the plugin's custom tables. blog_id scopes entity_map, sync_queue, and
	 * logs to the originating site. DEFAULT 1 ensures single-site installs
	 * (where get_current_blog_id() always returns 1) are unaffected.
	 *
	 * @return void
	 */
	private static function migration_7(): void {
		global $wpdb;

		$entity_table = $wpdb->prefix . 'wp4odoo_entity_map';
		$queue_table  = $wpdb->prefix . 'wp4odoo_sync_queue';
		$logs_table   = $wpdb->prefix . 'wp4odoo_logs';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange

		// ── entity_map ──────────────────────────────────────
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$entity_table}" );
		if ( ! in_array( 'blog_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$entity_table} ADD COLUMN blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1 AFTER id" );

			// Rebuild unique key to include blog_id.
			$wpdb->query( "ALTER TABLE {$entity_table} DROP KEY idx_unique_mapping, ADD UNIQUE KEY idx_unique_mapping (blog_id, module, entity_type, wp_id, odoo_id)" );

			// Rebuild lookup indexes with blog_id prefix.
			$wpdb->query( "ALTER TABLE {$entity_table} DROP KEY idx_wp_lookup, ADD KEY idx_wp_lookup (blog_id, module, entity_type, wp_id)" );
			$wpdb->query( "ALTER TABLE {$entity_table} DROP KEY idx_odoo_lookup, ADD KEY idx_odoo_lookup (blog_id, module, entity_type, odoo_id)" );
		}

		// ── sync_queue ──────────────────────────────────────
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$queue_table}" );
		if ( ! in_array( 'blog_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$queue_table} ADD COLUMN blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1 AFTER id" );

			// Rebuild module-scoped indexes with blog_id prefix.
			$wpdb->query( "ALTER TABLE {$queue_table} DROP KEY idx_status_module, ADD KEY idx_status_module (blog_id, status, module, priority, created_at)" );
			$wpdb->query( "ALTER TABLE {$queue_table} DROP KEY idx_dedup_wp, ADD KEY idx_dedup_wp (blog_id, module, entity_type, direction, status, wp_id)" );
		}

		// ── logs ────────────────────────────────────────────
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$logs_table}" );
		if ( ! in_array( 'blog_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$logs_table} ADD COLUMN blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1 AFTER id" );
		}

		// phpcs:enable
	}

	/**
	 * Migration 8: Add composite index for stale job recovery queries.
	 *
	 * Covers `WHERE blog_id = ? AND status = 'processing' AND processed_at < ?`
	 * used by Sync_Queue_Repository::recover_stale_processing().
	 *
	 * @return void
	 */
	private static function migration_8(): void {
		global $wpdb;

		$queue_table = $wpdb->prefix . 'wp4odoo_sync_queue';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$queue_table}" );
		$names   = array_column( $indexes, 'Key_name' );

		if ( ! in_array( 'idx_stale_recovery', $names, true ) ) {
			$wpdb->query( "ALTER TABLE {$queue_table} ADD KEY idx_stale_recovery (blog_id, status, processed_at)" );
		}
		// phpcs:enable
	}

	/**
	 * Migration 9: Rebuild two indexes with blog_id prefix for multisite.
	 *
	 * idx_dedup_odoo on sync_queue and idx_poll_detection on entity_map
	 * were created without blog_id, causing MySQL to scan all blogs when
	 * queries filter by blog_id. Adds blog_id as the leftmost column.
	 *
	 * @return void
	 */
	private static function migration_9(): void {
		global $wpdb;

		$queue_table  = $wpdb->prefix . 'wp4odoo_sync_queue';
		$entity_table = $wpdb->prefix . 'wp4odoo_entity_map';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange

		// ── sync_queue: idx_dedup_odoo ──────────────────────
		$queue_indexes = $wpdb->get_results( "SHOW INDEX FROM {$queue_table}" );
		$queue_names   = array_column( $queue_indexes, 'Key_name' );

		if ( in_array( 'idx_dedup_odoo', $queue_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$queue_table} DROP KEY idx_dedup_odoo" );
		}
		$wpdb->query( "ALTER TABLE {$queue_table} ADD KEY idx_dedup_odoo (blog_id, module, entity_type, direction, status, odoo_id)" );

		// ── entity_map: idx_poll_detection ──────────────────
		$entity_indexes = $wpdb->get_results( "SHOW INDEX FROM {$entity_table}" );
		$entity_names   = array_column( $entity_indexes, 'Key_name' );

		if ( in_array( 'idx_poll_detection', $entity_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$entity_table} DROP KEY idx_poll_detection" );
		}
		$wpdb->query( "ALTER TABLE {$entity_table} ADD KEY idx_poll_detection (blog_id, module, entity_type, last_polled_at)" );

		// phpcs:enable
	}

	/**
	 * Migration 10: Add cleanup index on logs table + drop obsolete idx_dedup_composite.
	 *
	 * wp4odoo_logs cleanup queries filter by blog_id + created_at but no
	 * existing index covers this, causing full table scans in multisite.
	 *
	 * idx_dedup_composite (module, entity_type, direction, status) was added
	 * by migration 3 but became redundant after migration 7 added blog_id
	 * to idx_dedup_wp and idx_dedup_odoo. Only upgraded installs have it.
	 *
	 * @return void
	 */
	private static function migration_10(): void {
		global $wpdb;

		$logs_table  = $wpdb->prefix . 'wp4odoo_logs';
		$queue_table = $wpdb->prefix . 'wp4odoo_sync_queue';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange

		// ── logs: add blog_id cleanup index ────────────────
		$log_indexes = $wpdb->get_results( "SHOW INDEX FROM {$logs_table}" );
		$log_names   = array_column( $log_indexes, 'Key_name' );

		if ( ! in_array( 'idx_blog_cleanup', $log_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$logs_table} ADD KEY idx_blog_cleanup (blog_id, created_at)" );
		}

		// ── sync_queue: drop obsolete idx_dedup_composite ──
		$queue_indexes = $wpdb->get_results( "SHOW INDEX FROM {$queue_table}" );
		$queue_names   = array_column( $queue_indexes, 'Key_name' );

		if ( in_array( 'idx_dedup_composite', $queue_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$queue_table} DROP KEY idx_dedup_composite" );
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
