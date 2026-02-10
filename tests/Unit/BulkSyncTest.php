<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Queue_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for bulk product import/export logic.
 *
 * Tests the queue enqueue patterns used by bulk handlers.
 * Verifies entity mapping checks and Queue_Manager::pull()/push() invocations.
 */
class BulkSyncTest extends TestCase {

	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		\WP4Odoo\Entity_Map_Repository::flush_cache();
	}

	// ─── Entity Map lookups for bulk ──────────────────────

	public function test_entity_map_returns_null_for_unmapped_product(): void {
		$this->wpdb->get_var_return = null;

		$result = Entity_Map_Repository::get_wp_id( 'woocommerce', 'product', 999 );
		$this->assertNull( $result );
	}

	public function test_entity_map_returns_wp_id_for_mapped_product(): void {
		$this->wpdb->get_var_return = '42';

		$result = Entity_Map_Repository::get_wp_id( 'woocommerce', 'product', 100 );
		$this->assertSame( 42, $result );
	}

	public function test_entity_map_returns_odoo_id_for_mapped_product(): void {
		$this->wpdb->get_var_return = '200';

		$result = Entity_Map_Repository::get_odoo_id( 'woocommerce', 'product', 42 );
		$this->assertSame( 200, $result );
	}

	public function test_entity_map_returns_null_for_unmapped_odoo_id(): void {
		$this->wpdb->get_var_return = null;

		$result = Entity_Map_Repository::get_odoo_id( 'woocommerce', 'product', 999 );
		$this->assertNull( $result );
	}

	// ─── Queue Manager patterns (bulk import) ─────────────

	public function test_queue_pull_enqueues_job(): void {
		$this->wpdb->insert_id = 1;

		$result = Queue_Manager::pull( 'woocommerce', 'product', 'create', 100 );

		// Verifies insert was called on the sync_queue table.
		$inserts = array_filter( $this->wpdb->calls, fn( $c ) => 'insert' === $c['method'] );
		$this->assertNotEmpty( $inserts );
	}

	public function test_queue_push_enqueues_job(): void {
		$this->wpdb->insert_id = 2;

		$result = Queue_Manager::push( 'woocommerce', 'product', 'create', 42 );

		$inserts = array_filter( $this->wpdb->calls, fn( $c ) => 'insert' === $c['method'] );
		$this->assertNotEmpty( $inserts );
	}

	// ─── Bulk import logic pattern ────────────────────────

	public function test_bulk_import_enqueues_create_for_unmapped_products(): void {
		// Simulate checking mapping: returns null (not mapped).
		$this->wpdb->get_var_return = null;

		$wp_id  = Entity_Map_Repository::get_wp_id( 'woocommerce', 'product', 100 );
		$action = $wp_id ? 'update' : 'create';

		$this->assertNull( $wp_id );
		$this->assertSame( 'create', $action );
	}

	public function test_bulk_import_enqueues_update_for_mapped_products(): void {
		$this->wpdb->get_var_return = '42';

		$wp_id  = Entity_Map_Repository::get_wp_id( 'woocommerce', 'product', 100 );
		$action = $wp_id ? 'update' : 'create';

		$this->assertSame( 42, $wp_id );
		$this->assertSame( 'update', $action );
	}

	// ─── Bulk export logic pattern ────────────────────────

	public function test_bulk_export_enqueues_create_for_unmapped_wc_products(): void {
		$this->wpdb->get_var_return = null;

		$odoo_id = Entity_Map_Repository::get_odoo_id( 'woocommerce', 'product', 42 );
		$action  = $odoo_id ? 'update' : 'create';

		$this->assertNull( $odoo_id );
		$this->assertSame( 'create', $action );
	}

	public function test_bulk_export_enqueues_update_for_mapped_wc_products(): void {
		$this->wpdb->get_var_return = '200';

		$odoo_id = Entity_Map_Repository::get_odoo_id( 'woocommerce', 'product', 42 );
		$action  = $odoo_id ? 'update' : 'create';

		$this->assertSame( 200, $odoo_id );
		$this->assertSame( 'update', $action );
	}

	// ─── Batch entity_map lookups (used by Bulk_Handler) ──

	public function test_batch_wp_ids_returns_map_for_bulk_import(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'odoo_id' => '100', 'wp_id' => '10' ],
			(object) [ 'odoo_id' => '200', 'wp_id' => '20' ],
		];

		$map = Entity_Map_Repository::get_wp_ids_batch( 'woocommerce', 'product', [ 100, 200, 300 ] );

		$this->assertSame( 10, $map[100] );
		$this->assertSame( 20, $map[200] );
		$this->assertArrayNotHasKey( 300, $map );
	}

	public function test_batch_wp_ids_empty_returns_empty_map(): void {
		$result = Entity_Map_Repository::get_wp_ids_batch( 'woocommerce', 'product', [] );
		$this->assertSame( [], $result );
	}

	public function test_batch_odoo_ids_returns_map_for_bulk_export(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => '42', 'odoo_id' => '200' ],
		];

		$map = Entity_Map_Repository::get_odoo_ids_batch( 'woocommerce', 'product', [ 42, 99 ] );

		$this->assertSame( 200, $map[42] );
		$this->assertArrayNotHasKey( 99, $map );
	}

	public function test_bulk_import_determines_action_from_batch_map(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'odoo_id' => '100', 'wp_id' => '10' ],
		];

		$odoo_ids = [ 100, 200 ];
		$map      = Entity_Map_Repository::get_wp_ids_batch( 'woocommerce', 'product', $odoo_ids );

		$this->assertSame( 'update', ( $map[100] ?? 0 ) ? 'update' : 'create' );
		$this->assertSame( 'create', ( $map[200] ?? 0 ) ? 'update' : 'create' );
	}

	public function test_bulk_export_determines_action_from_batch_map(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => '42', 'odoo_id' => '200' ],
		];

		$wp_ids = [ 42, 99 ];
		$map    = Entity_Map_Repository::get_odoo_ids_batch( 'woocommerce', 'product', $wp_ids );

		$this->assertSame( 'update', ( $map[42] ?? 0 ) ? 'update' : 'create' );
		$this->assertSame( 'create', ( $map[99] ?? 0 ) ? 'update' : 'create' );
	}

	// ─── Variant entity mapping ───────────────────────────

	public function test_variant_entity_map_lookup(): void {
		$this->wpdb->get_var_return = '55';

		$result = Entity_Map_Repository::get_wp_id( 'woocommerce', 'variant', 300 );
		$this->assertSame( 55, $result );
	}

	public function test_variant_entity_map_save(): void {
		$result = Entity_Map_Repository::save(
			'woocommerce',
			'variant',
			55,
			300,
			'product.product',
			'abc123'
		);

		$this->assertTrue( $result );

		$replaces = array_filter( $this->wpdb->calls, fn( $c ) => 'replace' === $c['method'] );
		$this->assertNotEmpty( $replaces );
	}
}
