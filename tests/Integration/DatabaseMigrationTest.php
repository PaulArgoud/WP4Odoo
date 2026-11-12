<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Integration;

use WP4Odoo\Database_Migration;

/**
 * Integration tests for Database_Migration.
 *
 * Verifies that create_tables() creates every table with the correct columns,
 * indexes, and that default options are seeded.
 *
 * @package WP4Odoo\Tests\Integration
 */
class DatabaseMigrationTest extends WP4Odoo_TestCase {

	// ─── Table creation ────────────────────────────────────

	public function test_create_tables_creates_sync_queue_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'wp4odoo_sync_queue';
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$names = array_map( fn( $c ) => $c->Field, $columns );

		$this->assertContains( 'id', $names );
		$this->assertContains( 'module', $names );
		$this->assertContains( 'direction', $names );
		$this->assertContains( 'entity_type', $names );
		$this->assertContains( 'wp_id', $names );
		$this->assertContains( 'odoo_id', $names );
		$this->assertContains( 'action', $names );
		$this->assertContains( 'payload', $names );
		$this->assertContains( 'priority', $names );
		$this->assertContains( 'status', $names );
		$this->assertContains( 'attempts', $names );
		$this->assertContains( 'max_attempts', $names );
		$this->assertContains( 'error_message', $names );
		$this->assertContains( 'scheduled_at', $names );
		$this->assertContains( 'processed_at', $names );
		$this->assertContains( 'created_at', $names );
	}

	public function test_create_tables_creates_entity_map_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'wp4odoo_entity_map';
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$names = array_map( fn( $c ) => $c->Field, $columns );

		$this->assertContains( 'module', $names );
		$this->assertContains( 'entity_type', $names );
		$this->assertContains( 'wp_id', $names );
		$this->assertContains( 'odoo_id', $names );
		$this->assertContains( 'odoo_model', $names );
		$this->assertContains( 'sync_hash', $names );
		$this->assertContains( 'last_synced_at', $names );
	}

	public function test_entity_map_has_unique_key(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'wp4odoo_entity_map';
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_unique_mapping'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertNotEmpty( $indexes );
		$this->assertSame( '0', $indexes[0]->Non_unique );
	}

	public function test_create_tables_creates_logs_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'wp4odoo_logs';
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$names = array_map( fn( $c ) => $c->Field, $columns );

		$this->assertContains( 'level', $names );
		$this->assertContains( 'module', $names );
		$this->assertContains( 'message', $names );
		$this->assertContains( 'context', $names );
	}

	public function test_create_tables_is_idempotent(): void {
		$this->use_real_tables();
		Database_Migration::create_tables();
		Database_Migration::create_tables();

		global $wpdb;
		$table = $wpdb->prefix . 'wp4odoo_sync_queue';

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				$table
			)
		);

		$this->assertSame( '1', $exists );
	}

	// ─── Default options ──────────────────────────────────

	public function test_set_default_options_seeds_connection(): void {
		delete_option( 'wp4odoo_connection' );

		Database_Migration::set_default_options();

		$connection = get_option( 'wp4odoo_connection' );
		$this->assertIsArray( $connection );
		$this->assertArrayHasKey( 'url', $connection );
		$this->assertArrayHasKey( 'protocol', $connection );
		$this->assertSame( 'jsonrpc', $connection['protocol'] );
	}

	public function test_set_default_options_does_not_overwrite_existing(): void {
		update_option( 'wp4odoo_connection', [ 'url' => 'https://custom.example.com' ] );

		Database_Migration::set_default_options();

		$connection = get_option( 'wp4odoo_connection' );
		$this->assertSame( 'https://custom.example.com', $connection['url'] );
	}

	// ─── Regression: GitHub issue #4 ───────────────────────

	/**
	 * Recreate the tables after a test that dropped them (DDL cannot be rolled back).
	 */
	public function tear_down(): void {
		$this->use_real_tables();
		Database_Migration::create_tables();
		parent::tear_down();
	}

	/**
	 * WP_UnitTestCase rewrites CREATE/DROP TABLE into their TEMPORARY variants so that
	 * tests leave no tables behind. These tests must act on the real tables.
	 */
	private function use_real_tables(): void {
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	private function drop_plugin_tables( array $tables ): void {
		global $wpdb;

		$this->use_real_tables();

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	public function test_create_tables_creates_all_three_tables_from_scratch(): void {
		$this->drop_plugin_tables( [ 'wp4odoo_sync_queue', 'wp4odoo_entity_map', 'wp4odoo_logs' ] );
		$this->assertCount( 3, Database_Migration::missing_tables() );

		Database_Migration::create_tables();

		// With dbDelta() only the last statement ran, leaving the queue and entity-map tables missing.
		$this->assertSame( [], Database_Migration::missing_tables() );
	}

	public function test_ensure_tables_repairs_missing_tables_when_the_activation_hook_was_skipped(): void {
		$this->drop_plugin_tables( [ 'wp4odoo_sync_queue', 'wp4odoo_entity_map' ] );
		delete_option( \WP4Odoo\Settings_Repository::OPT_DB_VERSION );
		delete_transient( 'wp4odoo_tables_ok' );

		$this->assertTrue( Database_Migration::ensure_tables() );
		$this->assertSame( [], Database_Migration::missing_tables() );
		$this->assertSame( WP4ODOO_VERSION, get_option( \WP4Odoo\Settings_Repository::OPT_DB_VERSION ) );
	}
}
