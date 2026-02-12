<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Entity_Map_Repository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Entity_Map_Repository.
 *
 * Verifies correct delegation to $wpdb and return type handling.
 */
class EntityMapRepositoryTest extends TestCase {

	private \WP_DB_Stub $wpdb;
	private Entity_Map_Repository $repo;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$this->repo = new Entity_Map_Repository();
		$this->repo->flush_cache();
	}

	protected function tearDown(): void {
		$this->repo->flush_cache();
	}

	// ─── get_odoo_id() ─────────────────────────────────────

	public function test_get_odoo_id_returns_int_when_found(): void {
		$this->wpdb->get_var_return = '42';

		$result = $this->repo->get_odoo_id( 'crm', 'contact', 10 );

		$this->assertSame( 42, $result );
	}

	public function test_get_odoo_id_returns_null_when_not_found(): void {
		$this->wpdb->get_var_return = null;

		$result = $this->repo->get_odoo_id( 'crm', 'contact', 999 );

		$this->assertNull( $result );
	}

	public function test_get_odoo_id_queries_correct_table(): void {
		$this->wpdb->get_var_return = null;

		$this->repo->get_odoo_id( 'crm', 'contact', 10 );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( 'wp_wp4odoo_entity_map', $prepare[0]['args'][0] );
	}

	// ─── get_wp_id() ───────────────────────────────────────

	public function test_get_wp_id_returns_int_when_found(): void {
		$this->wpdb->get_var_return = '7';

		$result = $this->repo->get_wp_id( 'sales', 'order', 100 );

		$this->assertSame( 7, $result );
	}

	public function test_get_wp_id_returns_null_when_not_found(): void {
		$this->wpdb->get_var_return = null;

		$result = $this->repo->get_wp_id( 'sales', 'order', 999 );

		$this->assertNull( $result );
	}

	// ─── save() ────────────────────────────────────────────

	public function test_save_calls_upsert_with_correct_data(): void {
		$this->repo->save( 'crm', 'contact', 10, 42, 'res.partner', 'abc123' );

		$query_calls = $this->get_calls( 'query' );
		$this->assertNotEmpty( $query_calls );

		$sql = $query_calls[0]['args'][0];
		$this->assertStringContainsString( 'INSERT INTO', $sql );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $sql );
	}

	public function test_save_returns_true_on_success(): void {
		$result = $this->repo->save( 'crm', 'contact', 10, 42, 'res.partner' );
		$this->assertTrue( $result );
	}

	// ─── remove() ──────────────────────────────────────────

	public function test_remove_returns_true_when_deleted(): void {
		$this->wpdb->delete_return = 1;

		$result = $this->repo->remove( 'crm', 'contact', 10 );
		$this->assertTrue( $result );
	}

	public function test_remove_returns_false_when_not_found(): void {
		$this->wpdb->delete_return = 0;

		$result = $this->repo->remove( 'crm', 'contact', 999 );
		$this->assertFalse( $result );
	}

	public function test_remove_passes_correct_where_clause(): void {
		$this->wpdb->delete_return = 1;

		$this->repo->remove( 'sales', 'order', 77 );

		$delete = $this->get_last_call( 'delete' );
		$this->assertNotNull( $delete );
		$this->assertSame(
			[ 'module' => 'sales', 'entity_type' => 'order', 'wp_id' => 77 ],
			$delete['args'][1]
		);
	}

	// ─── get_wp_ids_batch() ───────────────────────────────

	public function test_get_wp_ids_batch_returns_empty_for_empty_input(): void {
		$result = $this->repo->get_wp_ids_batch( 'woocommerce', 'product', [] );
		$this->assertSame( [], $result );
	}

	public function test_get_wp_ids_batch_returns_map_of_odoo_to_wp(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'odoo_id' => '100', 'wp_id' => '10' ],
			(object) [ 'odoo_id' => '200', 'wp_id' => '20' ],
		];

		$result = $this->repo->get_wp_ids_batch( 'woocommerce', 'product', [ 100, 200, 300 ] );

		$this->assertSame( [ 100 => 10, 200 => 20 ], $result );
	}

	public function test_get_wp_ids_batch_returns_empty_when_no_matches(): void {
		$this->wpdb->get_results_return = [];

		$result = $this->repo->get_wp_ids_batch( 'woocommerce', 'product', [ 999 ] );

		$this->assertSame( [], $result );
	}

	public function test_get_wp_ids_batch_generates_correct_placeholders(): void {
		$this->wpdb->get_results_return = [];

		$this->repo->get_wp_ids_batch( 'woocommerce', 'product', [ 1, 2, 3 ] );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( '%d,%d,%d', $prepare[0]['args'][0] );
	}

	public function test_get_wp_ids_batch_single_id(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'odoo_id' => '42', 'wp_id' => '7' ],
		];

		$result = $this->repo->get_wp_ids_batch( 'woocommerce', 'product', [ 42 ] );

		$this->assertSame( [ 42 => 7 ], $result );
	}

	// ─── get_odoo_ids_batch() ──────────────────────────────

	public function test_get_odoo_ids_batch_returns_empty_for_empty_input(): void {
		$result = $this->repo->get_odoo_ids_batch( 'woocommerce', 'product', [] );
		$this->assertSame( [], $result );
	}

	public function test_get_odoo_ids_batch_returns_map_of_wp_to_odoo(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => '10', 'odoo_id' => '100' ],
			(object) [ 'wp_id' => '20', 'odoo_id' => '200' ],
		];

		$result = $this->repo->get_odoo_ids_batch( 'woocommerce', 'product', [ 10, 20, 30 ] );

		$this->assertSame( [ 10 => 100, 20 => 200 ], $result );
	}

	public function test_get_odoo_ids_batch_returns_empty_when_no_matches(): void {
		$this->wpdb->get_results_return = [];

		$result = $this->repo->get_odoo_ids_batch( 'woocommerce', 'product', [ 999 ] );

		$this->assertSame( [], $result );
	}

	public function test_get_odoo_ids_batch_generates_correct_placeholders(): void {
		$this->wpdb->get_results_return = [];

		$this->repo->get_odoo_ids_batch( 'woocommerce', 'product', [ 5, 6 ] );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( '%d,%d', $prepare[0]['args'][0] );
	}

	// ─── Batch dedup + cache optimization ───────────────

	public function test_get_wp_ids_batch_deduplicates_input(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'odoo_id' => '100', 'wp_id' => '10' ],
		];

		$result = $this->repo->get_wp_ids_batch( 'woocommerce', 'product', [ 100, 100, 100 ] );

		$this->assertSame( [ 100 => 10 ], $result );

		// Only one chunk query should be generated (3 dupes → 1 unique ID).
		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( '%d', $prepare[0]['args'][0] );
		$this->assertStringNotContainsString( '%d,%d', $prepare[0]['args'][0] );
	}

	public function test_get_wp_ids_batch_uses_cache_hits(): void {
		// Pre-populate cache via a single lookup.
		$this->wpdb->get_var_return = '10';
		$this->repo->get_wp_id( 'woocommerce', 'product', 100 );

		// Reset calls to track only the batch query.
		$this->wpdb->calls              = [];
		$this->wpdb->get_results_return = [
			(object) [ 'odoo_id' => '200', 'wp_id' => '20' ],
		];

		$result = $this->repo->get_wp_ids_batch( 'woocommerce', 'product', [ 100, 200 ] );

		// 100 from cache, 200 from DB.
		$this->assertSame( [ 100 => 10, 200 => 20 ], $result );

		// DB query should only contain ID 200 (100 was cached).
		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringNotContainsString( '%d,%d', $prepare[0]['args'][0] );
	}

	public function test_get_wp_ids_batch_all_cached_skips_db(): void {
		// Pre-populate cache.
		$this->wpdb->get_var_return = '10';
		$this->repo->get_wp_id( 'woocommerce', 'product', 100 );

		$this->wpdb->calls = [];

		$result = $this->repo->get_wp_ids_batch( 'woocommerce', 'product', [ 100 ] );

		$this->assertSame( [ 100 => 10 ], $result );

		// No DB queries should have been made.
		$prepare = $this->get_calls( 'prepare' );
		$this->assertEmpty( $prepare );
	}

	public function test_get_odoo_ids_batch_deduplicates_input(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => '10', 'odoo_id' => '100' ],
		];

		$result = $this->repo->get_odoo_ids_batch( 'woocommerce', 'product', [ 10, 10, 10 ] );

		$this->assertSame( [ 10 => 100 ], $result );
	}

	public function test_get_odoo_ids_batch_uses_cache_hits(): void {
		// Pre-populate cache via a single lookup.
		$this->wpdb->get_var_return = '100';
		$this->repo->get_odoo_id( 'woocommerce', 'product', 10 );

		$this->wpdb->calls              = [];
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => '20', 'odoo_id' => '200' ],
		];

		$result = $this->repo->get_odoo_ids_batch( 'woocommerce', 'product', [ 10, 20 ] );

		// 10 from cache, 20 from DB.
		$this->assertSame( [ 10 => 100, 20 => 200 ], $result );
	}

	// ─── get_module_entity_mappings() ────────────────────

	public function test_get_module_entity_mappings_returns_indexed_map(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => '1', 'odoo_id' => '100', 'sync_hash' => 'abc' ],
			(object) [ 'wp_id' => '2', 'odoo_id' => '200', 'sync_hash' => 'def' ],
		];

		$result = $this->repo->get_module_entity_mappings( 'bookly', 'service' );

		$this->assertSame( [
			1 => [ 'odoo_id' => 100, 'sync_hash' => 'abc' ],
			2 => [ 'odoo_id' => 200, 'sync_hash' => 'def' ],
		], $result );
	}

	public function test_get_module_entity_mappings_returns_empty_when_no_matches(): void {
		$this->wpdb->get_results_return = [];

		$result = $this->repo->get_module_entity_mappings( 'bookly', 'service' );

		$this->assertSame( [], $result );
	}

	public function test_get_module_entity_mappings_populates_cache(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => '5', 'odoo_id' => '50', 'sync_hash' => 'xyz' ],
		];

		$this->repo->get_module_entity_mappings( 'bookly', 'service' );

		// Cache should be populated — get_odoo_id should return without DB query.
		$this->wpdb->get_var_return = null;
		$result = $this->repo->get_odoo_id( 'bookly', 'service', 5 );

		$this->assertSame( 50, $result );
	}

	// ─── Cache behavior ──────────────────────────────────

	public function test_get_odoo_id_cache_hit_avoids_second_query(): void {
		$this->wpdb->get_var_return = '42';

		$this->repo->get_odoo_id( 'crm', 'contact', 10 );
		$first_count = count( $this->get_calls( 'get_var' ) );

		$result = $this->repo->get_odoo_id( 'crm', 'contact', 10 );
		$second_count = count( $this->get_calls( 'get_var' ) );

		$this->assertSame( 42, $result );
		$this->assertSame( $first_count, $second_count );
	}

	public function test_get_wp_id_cache_hit_avoids_second_query(): void {
		$this->wpdb->get_var_return = '7';

		$this->repo->get_wp_id( 'sales', 'order', 100 );
		$first_count = count( $this->get_calls( 'get_var' ) );

		$result = $this->repo->get_wp_id( 'sales', 'order', 100 );
		$second_count = count( $this->get_calls( 'get_var' ) );

		$this->assertSame( 7, $result );
		$this->assertSame( $first_count, $second_count );
	}

	public function test_save_populates_cache_for_subsequent_lookups(): void {
		$this->repo->save( 'crm', 'contact', 10, 42, 'res.partner' );

		$this->wpdb->get_var_return = null;
		$result = $this->repo->get_odoo_id( 'crm', 'contact', 10 );

		$this->assertSame( 42, $result );
	}

	public function test_remove_invalidates_cache(): void {
		$this->repo->save( 'crm', 'contact', 10, 42, 'res.partner' );

		$this->wpdb->delete_return = 1;
		$this->repo->remove( 'crm', 'contact', 10 );

		$this->wpdb->get_var_return = null;
		$result = $this->repo->get_odoo_id( 'crm', 'contact', 10 );

		$this->assertNull( $result );
	}

	public function test_flush_cache_clears_all_entries(): void {
		$this->wpdb->get_var_return = '42';
		$this->repo->get_odoo_id( 'crm', 'contact', 10 );

		$this->repo->flush_cache();

		$this->wpdb->get_var_return = '99';
		$result = $this->repo->get_odoo_id( 'crm', 'contact', 10 );

		$this->assertSame( 99, $result );
	}

	// ─── Helpers ───────────────────────────────────────────

	private function get_last_call( string $method ): ?array {
		$calls = $this->get_calls( $method );
		return $calls ? end( $calls ) : null;
	}

	private function get_calls( string $method ): array {
		return array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === $method )
		);
	}
}
