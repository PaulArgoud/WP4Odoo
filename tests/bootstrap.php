<?php
/**
 * PHPUnit bootstrap file.
 *
 * Defines constants, loads stub files, and requires plugin classes
 * so that they can be tested without a full WordPress environment.
 *
 * Stub files live in tests/stubs/:
 *   wp-classes.php   — WP_Error, WP_REST_*, WP_User, WP_CLI, AJAX helpers
 *   wp-functions.php — WordPress function stubs (options, hooks, users, posts…)
 *   wc-classes.php   — WooCommerce class & function stubs
 *   wp-db-stub.php   — WP_DB_Stub ($wpdb mock)
 *   plugin-stub.php  — WP4Odoo_Plugin test singleton
 *   wp-cli-utils.php — WP_CLI\Utils\format_items stub
 *
 * @package WP4Odoo\Tests
 */

// ─── Constants ──────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/stub/' );
}

define( 'WP4ODOO_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WP4ODOO_VERSION', '1.9.2' );

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-for-phpunit-only' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-for-phpunit-only' );
}

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', false );
}

if ( ! defined( 'WP4ODOO_PLUGIN_URL' ) ) {
	define( 'WP4ODOO_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp4odoo/' );
}

// ─── Global test stores ─────────────────────────────────

$GLOBALS['_wp_options'] = [];

// ─── Load stubs ─────────────────────────────────────────

require_once __DIR__ . '/stubs/wp-classes.php';
require_once __DIR__ . '/stubs/wp-functions.php';
require_once __DIR__ . '/stubs/wc-classes.php';
require_once __DIR__ . '/stubs/wp-db-stub.php';
require_once __DIR__ . '/stubs/plugin-stub.php';
require_once __DIR__ . '/stubs/wp-cli-utils.php';

// ─── Composer autoloader ────────────────────────────────

require_once WP4ODOO_PLUGIN_DIR . 'vendor/autoload.php';

// ─── Plugin classes under test ──────────────────────────

// Core infrastructure.
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-logger.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-field-mapper.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-cpt-helper.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-entity-map-repository.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-sync-queue-repository.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-query-service.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-partner-service.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-module-base.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-sync-engine.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-queue-manager.php';

// API layer.
require_once WP4ODOO_PLUGIN_DIR . 'includes/api/interface-transport.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/api/trait-retryable-http.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-auth.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-client.php';

// Modules.
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-contact-refiner.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-currency-guard.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-variant-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-image-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-product-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-order-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-woocommerce-module.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-contact-manager.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-lead-manager.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-crm-module.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-portal-manager.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-sales-module.php';

// Webhook handler.
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-webhook-handler.php';

// Admin layer.
require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/class-settings-page.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/class-admin-ajax.php';

// CLI (conditional in production, always loaded in tests).
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-cli.php';
