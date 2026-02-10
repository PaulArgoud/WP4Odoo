<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads all plugin class files.
 *
 * Extracted from WP4Odoo_Plugin::load_dependencies() for SRP.
 * Loaded via direct require_once in wp4odoo.php (bootstrap problem).
 *
 * @package WP4Odoo
 * @since   1.5.0
 */
final class Dependency_Loader {

	/**
	 * Load all required class files.
	 *
	 * @return void
	 */
	public static function load(): void {
		// Core API
		require_once WP4ODOO_PLUGIN_DIR . 'includes/api/interface-transport.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/api/trait-retryable-http.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-client.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-jsonrpc.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-xmlrpc.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-auth.php';

		// Core classes
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-logger.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-entity-map-repository.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-sync-queue-repository.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-partner-service.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-module-base.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-failure-notifier.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-sync-engine.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-field-mapper.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-cpt-helper.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-queue-manager.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-query-service.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-webhook-handler.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-database-migration.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/class-module-registry.php';

		// Modules (traits before classes)
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-crm-user-hooks.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-woocommerce-hooks.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-lead-manager.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-contact-refiner.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-contact-manager.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-crm-module.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-portal-manager.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-sales-module.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-currency-guard.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-variant-handler.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-image-handler.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-product-handler.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-order-handler.php';
		require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-woocommerce-module.php';

		// Admin
		if ( is_admin() ) {
			require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/class-admin.php';
			require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/class-bulk-handler.php';
			require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/trait-ajax-monitor-handlers.php';
			require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/trait-ajax-module-handlers.php';
			require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/trait-ajax-setup-handlers.php';
			require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/class-admin-ajax.php';
			require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/class-settings-page.php';
		}
	}
}
