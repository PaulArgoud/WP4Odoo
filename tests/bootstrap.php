<?php
/**
 * PHPUnit bootstrap file.
 *
 * Defines constants, loads stub files, and registers the plugin autoloader
 * so that classes can be tested without a full WordPress environment.
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
define( 'WP4ODOO_VERSION', '3.2.0' );

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'KB_IN_BYTES' ) ) {
	define( 'KB_IN_BYTES', 1024 );
}

if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1048576 );
}

if ( ! defined( 'GB_IN_BYTES' ) ) {
	define( 'GB_IN_BYTES', 1073741824 );
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
$GLOBALS['_pmpro_levels']        = [];
$GLOBALS['_pmpro_orders']        = [];
$GLOBALS['_rcp_levels']          = [];
$GLOBALS['_rcp_payments']        = [];
$GLOBALS['_rcp_memberships']     = [];
$GLOBALS['_llms_orders']         = [];
$GLOBALS['_llms_enrollments']    = [];
$GLOBALS['_wc_subscriptions']    = [];
$GLOBALS['_wc_points_rewards']   = [];
$GLOBALS['_tribe_events']            = [];
$GLOBALS['_tribe_tickets']           = [];
$GLOBALS['_tribe_attendees']         = [];
$GLOBALS['_wpas_tickets']            = [];
$GLOBALS['_supportcandy_tickets']    = [];
$GLOBALS['_supportcandy_ticketmeta'] = [];
$GLOBALS['_wc_bundles']              = [];
$GLOBALS['_wc_composites']           = [];
$GLOBALS['_affwp_affiliates']        = [];
$GLOBALS['_affwp_referrals']         = [];
$GLOBALS['_wpai_import_id']          = 0;
$GLOBALS['_gamipress_points']        = [];

// ─── Load stubs ─────────────────────────────────────────
// Stubs must be loaded before the autoloader so that external
// classes (WP core, WC, EDD, etc.) are available when plugin
// classes are autoloaded.

require_once __DIR__ . '/stubs/wp-classes.php';
require_once __DIR__ . '/stubs/wp-functions.php';
require_once __DIR__ . '/stubs/wc-classes.php';
require_once __DIR__ . '/stubs/wp-db-stub.php';
require_once __DIR__ . '/stubs/plugin-stub.php';
require_once __DIR__ . '/stubs/wp-cli-utils.php';
require_once __DIR__ . '/stubs/edd-classes.php';
require_once __DIR__ . '/stubs/memberpress-classes.php';
require_once __DIR__ . '/stubs/pmpro-classes.php';
require_once __DIR__ . '/stubs/rcp-classes.php';
require_once __DIR__ . '/stubs/givewp-classes.php';
require_once __DIR__ . '/stubs/charitable-classes.php';
require_once __DIR__ . '/stubs/simplepay-classes.php';
require_once __DIR__ . '/stubs/wprm-classes.php';
require_once __DIR__ . '/stubs/form-classes.php';
require_once __DIR__ . '/stubs/amelia-classes.php';
require_once __DIR__ . '/stubs/bookly-classes.php';
require_once __DIR__ . '/stubs/sprout-invoices-classes.php';
require_once __DIR__ . '/stubs/wp-invoice-classes.php';
require_once __DIR__ . '/stubs/crowdfunding-classes.php';
require_once __DIR__ . '/stubs/ecwid-classes.php';
require_once __DIR__ . '/stubs/shopwp-classes.php';
require_once __DIR__ . '/stubs/learndash-classes.php';
require_once __DIR__ . '/stubs/lifterlms-classes.php';
require_once __DIR__ . '/stubs/wc-subscriptions-classes.php';
require_once __DIR__ . '/stubs/events-calendar-classes.php';
require_once __DIR__ . '/stubs/wc-bookings-classes.php';
require_once __DIR__ . '/stubs/job-manager-classes.php';
require_once __DIR__ . '/stubs/wc-points-rewards-classes.php';
require_once __DIR__ . '/stubs/acf-classes.php';
require_once __DIR__ . '/stubs/i18n-classes.php';
require_once __DIR__ . '/stubs/awesome-support-classes.php';
require_once __DIR__ . '/stubs/supportcandy-classes.php';
require_once __DIR__ . '/stubs/wc-bundles-classes.php';
require_once __DIR__ . '/stubs/affiliatewp-classes.php';
require_once __DIR__ . '/stubs/wpai-classes.php';
require_once __DIR__ . '/stubs/tutorlms-classes.php';
require_once __DIR__ . '/stubs/fluentcrm-classes.php';
require_once __DIR__ . '/stubs/buddyboss-classes.php';
require_once __DIR__ . '/stubs/gamipress-classes.php';
require_once __DIR__ . '/stubs/funnelkit-classes.php';

// ─── Composer autoloader ────────────────────────────────

require_once WP4ODOO_PLUGIN_DIR . 'vendor/autoload.php';

// ─── Plugin autoloader ──────────────────────────────────
// Same autoloader as wp4odoo.php — converts WP4Odoo namespace
// to WordPress-style filenames (class-foo-bar.php, trait-foo-bar.php).

spl_autoload_register(
	function ( $class ) {
		$prefix = 'WP4Odoo\\';

		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );

		$dir = '';
		if ( ! empty( $parts ) ) {
			$dir = strtolower( implode( '/', $parts ) ) . '/';
		}

		$slug = strtolower( str_replace( '_', '-', $name ) );
		$base = WP4ODOO_PLUGIN_DIR . 'includes/' . $dir;

		foreach ( [ 'class-', 'trait-', 'interface-' ] as $type_prefix ) {
			$file = $base . $type_prefix . $slug . '.php';
			if ( file_exists( $file ) ) {
				require $file;
				return;
			}
		}
	}
);

// ─── Test helpers ──────────────────────────────────────

require_once __DIR__ . '/helpers/test-functions.php';
require_once __DIR__ . '/helpers/Module_Test_Case.php';
require_once __DIR__ . '/helpers/MockTransport.php';
