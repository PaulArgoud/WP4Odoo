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

// ── Create plugin tables ────────────────────────────────────
//
// Use the real code path: create_tables() creates the tables with plain
// CREATE TABLE IF NOT EXISTS statements and applies every numbered migration.
// (An earlier version created the tables here with raw SQL to work around
// dbDelta() silently skipping most of the plugin's tables; that bug lived in
// create_tables() itself and was therefore never exercised by these tests.)

\WP4Odoo\Database_Migration::create_tables();

// Load the PHPUnit 10+ compatibility base class (WP core Trac #62004 workaround).
require_once __DIR__ . '/Integration/WP4Odoo_TestCase.php';

// ── Test Helpers ─────────────────────────────────────────

require_once __DIR__ . '/helpers/test-functions.php';
require_once __DIR__ . '/helpers/SyncFlowTransport.php';
