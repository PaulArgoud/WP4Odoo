<?php
/**
 * Integration test bootstrap.
 *
 * Loads the WordPress test framework (provided by wp-env),
 * activates the plugin, and creates its database tables.
 *
 * Run via: npm run test:integration
 *
 * @package WP4Odoo\Tests
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find WordPress test framework at {$_tests_dir}.\n";
	echo "Run integration tests inside wp-env: npm run test:integration\n";
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Force-load the plugin as a must-use plugin during the test run.
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/wp4odoo.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

// Create plugin tables AFTER WordPress is fully installed and booted.
// dbDelta() may fail silently in certain environments — verify and fallback.
global $wpdb;

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
\WP4Odoo\Database_Migration::create_tables();

// Verify tables were created. If dbDelta failed, use raw SQL.
$table_check = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wp4odoo_sync_queue'" );
if ( ! $table_check ) {
	fwrite( STDERR, "[WP4Odoo] dbDelta failed — creating tables with raw SQL.\n" );

	$charset_collate = $wpdb->get_charset_collate();

	$wpdb->query(
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
			KEY idx_dedup_odoo (module, entity_type, direction, status, odoo_id),
			KEY idx_wp_id (wp_id),
			KEY idx_odoo_id (odoo_id),
			KEY idx_correlation (correlation_id),
			KEY idx_status_created (status, created_at)
		) $charset_collate"
	);

	$wpdb->query(
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
			KEY idx_poll_detection (module, entity_type, last_polled_at)
		) $charset_collate"
	);

	$wpdb->query(
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
			KEY idx_correlation (correlation_id)
		) $charset_collate"
	);

	// Run migrations on raw-SQL tables (e.g. migration_7 for blog_id is already in schema above,
	// but this ensures schema_version is set correctly for future migrations).
	\WP4Odoo\Database_Migration::run_migrations();
}

// Load the PHPUnit 10+ compatibility base class (WP core Trac #62004 workaround).
require_once __DIR__ . '/Integration/WP4Odoo_TestCase.php';

// ── Test Helpers ─────────────────────────────────────────

require_once __DIR__ . '/helpers/test-functions.php';
require_once __DIR__ . '/helpers/SyncFlowTransport.php';
