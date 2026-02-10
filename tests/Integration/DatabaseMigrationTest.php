<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Integration;

use WP4Odoo\Database_Migration;
use WP_UnitTestCase;

/**
 * Integration tests for Database_Migration.
 *
 * Verifies that dbDelta creates the correct tables, columns,
 * indexes, and that default options are seeded.
 *
 * @package WP4Odoo\Tests\Integration
 */
class DatabaseMigrationTest extends WP_UnitTestCase {

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
}
