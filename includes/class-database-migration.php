<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database table creation and default option seeding.
 *
 * Extracted from WP4Odoo_Plugin for SRP.
 *
 * @package WP4Odoo
 * @since   1.5.0
 */
final class Database_Migration {

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
			KEY idx_wp_id (wp_id),
			KEY idx_odoo_id (odoo_id)
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
			KEY idx_odoo_lookup (odoo_model, odoo_id)
		) $charset_collate;

		CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp4odoo_logs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			level ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
			module VARCHAR(50) DEFAULT NULL,
			message TEXT NOT NULL,
			context LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_level_date (level, created_at),
			KEY idx_module (module)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( Settings_Repository::OPT_DB_VERSION, WP4ODOO_VERSION );
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
