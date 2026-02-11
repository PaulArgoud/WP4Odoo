<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base test case for module tests.
 *
 * Provides common setUp() boilerplate: $wpdb stub, global store
 * initialization, and the 3 standard test helpers. Module tests
 * extending this class only need to add module-specific globals
 * and instantiate their module.
 *
 * @package WP4Odoo\Tests
 */
abstract class Module_Test_Case extends TestCase {

	/**
	 * WP_DB_Stub instance for database call tracking.
	 *
	 * @var \WP_DB_Stub
	 */
	protected \WP_DB_Stub $wpdb;

	/**
	 * Set up common test infrastructure.
	 *
	 * Initializes the $wpdb stub and clears all global stores.
	 * Subclasses should call parent::setUp() then add module-specific
	 * globals and create their module instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_mail_calls'] = [];
	}
}
