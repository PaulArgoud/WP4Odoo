<?php
/**
 * Shared test helper functions.
 *
 * Loaded by both the unit test bootstrap (tests/bootstrap.php)
 * and the integration test bootstrap (tests/bootstrap-integration.php).
 *
 * @package WP4Odoo\Tests
 */

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
 * @return \Closure Returns the module registered on the singleton.
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
