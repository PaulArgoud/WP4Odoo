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
 * Load the plugin and create tables before WordPress finishes booting.
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/wp4odoo.php';
		\WP4Odoo\Database_Migration::create_tables();
	}
);

require $_tests_dir . '/includes/bootstrap.php';
