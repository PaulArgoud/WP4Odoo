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
// dbDelta() is not reliably available during muplugins_loaded in the
// wp-env test framework — calling it here ensures $wpdb and upgrade.php
// are both ready.
\WP4Odoo\Database_Migration::create_tables();

// Load the PHPUnit 10+ compatibility base class (WP core Trac #62004 workaround).
require_once __DIR__ . '/Integration/WP4Odoo_TestCase.php';
