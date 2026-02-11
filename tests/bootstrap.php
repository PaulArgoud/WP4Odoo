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
define( 'WP4ODOO_VERSION', '2.0.0' );

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
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

$GLOBALS['_wp_options']          = [];
$GLOBALS['_wp_transients']       = [];
$GLOBALS['_wp_mail_calls']       = [];
$GLOBALS['_wc_memberships']      = [];
$GLOBALS['_wc_membership_plans'] = [];
$GLOBALS['_edd_orders']          = [];
$GLOBALS['_mepr_transactions']   = [];
$GLOBALS['_mepr_subscriptions']  = [];

// ─── Load stubs ─────────────────────────────────────────

require_once __DIR__ . '/stubs/wp-classes.php';
require_once __DIR__ . '/stubs/wp-functions.php';
require_once __DIR__ . '/stubs/wc-classes.php';
require_once __DIR__ . '/stubs/wp-db-stub.php';
require_once __DIR__ . '/stubs/plugin-stub.php';
require_once __DIR__ . '/stubs/wp-cli-utils.php';
require_once __DIR__ . '/stubs/edd-classes.php';
require_once __DIR__ . '/stubs/memberpress-classes.php';
require_once __DIR__ . '/stubs/givewp-classes.php';
require_once __DIR__ . '/stubs/charitable-classes.php';
require_once __DIR__ . '/stubs/simplepay-classes.php';
require_once __DIR__ . '/stubs/wprm-classes.php';

// ─── Composer autoloader ────────────────────────────────

require_once WP4ODOO_PLUGIN_DIR . 'vendor/autoload.php';

// ─── Plugin classes under test ──────────────────────────

// Core infrastructure.
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-settings-repository.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-logger.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-field-mapper.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-cpt-helper.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-entity-map-repository.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-sync-queue-repository.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-query-service.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-partner-service.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-module-base.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-module-registry.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-failure-notifier.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-sync-engine.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-queue-manager.php';

// API layer.
require_once WP4ODOO_PLUGIN_DIR . 'includes/api/interface-transport.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/api/trait-retryable-http.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-jsonrpc.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-xmlrpc.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-auth.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/api/class-odoo-client.php';

// Modules (traits before classes).
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-crm-user-hooks.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-woocommerce-hooks.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-contact-refiner.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-currency-guard.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-exchange-rate-service.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-variant-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-image-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-product-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-order-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-invoice-helper.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-woocommerce-module.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-edd-hooks.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-edd-download-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-edd-order-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-edd-module.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-contact-manager.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-lead-manager.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-crm-module.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-portal-manager.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-sales-module.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-membership-hooks.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-membership-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-memberships-module.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-memberpress-hooks.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-memberpress-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-memberpress-module.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-dual-accounting-model.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-odoo-accounting-formatter.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-givewp-hooks.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-givewp-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-givewp-module.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-charitable-hooks.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-charitable-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-charitable-module.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-simplepay-hooks.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-simplepay-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-simplepay-module.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/trait-wprm-hooks.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-wprm-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-wprm-module.php';
require_once __DIR__ . '/stubs/form-classes.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-form-handler.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-forms-module.php';

// Webhook handler.
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-webhook-handler.php';

// Admin layer.
require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/class-settings-page.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/trait-ajax-monitor-handlers.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/trait-ajax-module-handlers.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/trait-ajax-setup-handlers.php';
require_once WP4ODOO_PLUGIN_DIR . 'includes/admin/class-admin-ajax.php';

// CLI (conditional in production, always loaded in tests).
require_once WP4ODOO_PLUGIN_DIR . 'includes/class-cli.php';

// ─── Test helpers ──────────────────────────────────────

/**
 * Returns a test client provider closure for module instantiation.
 *
 * @return \Closure Returns a fresh Odoo_Client stub.
 */
function wp4odoo_test_client_provider(): \Closure {
	return fn() => new \WP4Odoo\API\Odoo_Client();
}

/**
 * Returns a test module resolver closure for Sync_Engine instantiation.
 *
 * @return \Closure Returns null for any module ID.
 */
function wp4odoo_test_module_resolver(): \Closure {
	return fn( string $id ) => \WP4Odoo_Plugin::instance()->get_module( $id );
}

/**
 * Returns a fresh Entity_Map_Repository for test isolation.
 *
 * @return \WP4Odoo\Entity_Map_Repository
 */
function wp4odoo_test_entity_map(): \WP4Odoo\Entity_Map_Repository {
	return new \WP4Odoo\Entity_Map_Repository();
}

/**
 * Returns a fresh Sync_Queue_Repository for test isolation.
 *
 * @return \WP4Odoo\Sync_Queue_Repository
 */
function wp4odoo_test_queue_repo(): \WP4Odoo\Sync_Queue_Repository {
	return new \WP4Odoo\Sync_Queue_Repository();
}

/**
 * Returns a fresh Settings_Repository for test isolation.
 *
 * @return \WP4Odoo\Settings_Repository
 */
function wp4odoo_test_settings(): \WP4Odoo\Settings_Repository {
	return new \WP4Odoo\Settings_Repository();
}
