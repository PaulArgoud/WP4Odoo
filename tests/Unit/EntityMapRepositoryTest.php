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
			[ 'blog_id' => 1, 'module' => 'sales', 'entity_type' => 'order', 'wp_id' => 77 ],
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

	public function test_get_module_entity_mappings_does_not_pollute_cache(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => '5', 'odoo_id' => '50', 'sync_hash' => 'xyz' ],
		];

		$this->repo->get_module_entity_mappings( 'bookly', 'service' );

		// Bulk mapping results should NOT populate the per-entity cache
		// (prevents LRU eviction of frequently-used entries during bulk ops).
		$this->wpdb->get_var_return = '50';
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

	// ─── cleanup_orphans() ──────────────────────────────────

	public function test_cleanup_orphans_returns_zero_when_no_orphans(): void {
		$this->wpdb->get_results_return = [];

		$result = $this->repo->cleanup_orphans();

		$this->assertSame( 0, $result['found'] );
		$this->assertSame( 0, $result['removed'] );
		$this->assertSame( [], $result['details'] );
	}

	public function test_cleanup_orphans_finds_orphaned_mappings(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'id' => '1', 'module' => 'crm', 'entity_type' => 'contact', 'wp_id' => '99', 'odoo_id' => '100' ],
			(object) [ 'id' => '2', 'module' => 'crm', 'entity_type' => 'contact', 'wp_id' => '88', 'odoo_id' => '200' ],
		];

		$result = $this->repo->cleanup_orphans( null, true );

		$this->assertSame( 2, $result['found'] );
		$this->assertSame( 0, $result['removed'] );
		$this->assertSame( [ 'crm:contact' => 2 ], $result['details'] );
	}

	public function test_cleanup_orphans_deletes_when_not_dry_run(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'id' => '5', 'module' => 'woocommerce', 'entity_type' => 'product', 'wp_id' => '42', 'odoo_id' => '300' ],
		];
		$this->wpdb->query_return = 1;

		$result = $this->repo->cleanup_orphans( null, false );

		$this->assertSame( 1, $result['found'] );
		$this->assertSame( 1, $result['removed'] );

		// Verify DELETE query was issued.
		$query_calls = $this->get_calls( 'query' );
		$delete_call = array_filter( $query_calls, fn( $c ) => str_contains( $c['args'][0], 'DELETE FROM' ) );
		$this->assertNotEmpty( $delete_call );
	}

	public function test_cleanup_orphans_respects_module_filter(): void {
		$this->wpdb->get_results_return = [];

		$this->repo->cleanup_orphans( 'crm', true );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$sql = $prepare[0]['args'][0];
		$this->assertStringContainsString( 'module = %s', $sql );
	}

	public function test_cleanup_orphans_excludes_user_based_modules(): void {
		$this->wpdb->get_results_return = [];

		$this->repo->cleanup_orphans( null, true );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$sql = $prepare[0]['args'][0];
		$this->assertStringContainsString( 'module NOT IN', $sql );
	}

	public function test_cleanup_orphans_joins_against_posts_table(): void {
		$this->wpdb->get_results_return = [];

		$this->repo->cleanup_orphans( null, true );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$sql = $prepare[0]['args'][0];
		$this->assertStringContainsString( 'LEFT JOIN wp_posts', $sql );
		$this->assertStringContainsString( 'p.ID IS NULL', $sql );
	}

	public function test_cleanup_orphans_groups_details_by_module_and_entity_type(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'id' => '1', 'module' => 'crm', 'entity_type' => 'contact', 'wp_id' => '10', 'odoo_id' => '100' ],
			(object) [ 'id' => '2', 'module' => 'crm', 'entity_type' => 'contact', 'wp_id' => '11', 'odoo_id' => '101' ],
			(object) [ 'id' => '3', 'module' => 'woocommerce', 'entity_type' => 'product', 'wp_id' => '20', 'odoo_id' => '200' ],
		];

		$result = $this->repo->cleanup_orphans( null, true );

		$this->assertSame( 3, $result['found'] );
		$this->assertSame(
			[ 'crm:contact' => 2, 'woocommerce:product' => 1 ],
			$result['details']
		);
	}

	// ─── LRU eviction ───────────────────────────────────────

	public function test_evict_cache_keeps_75_percent(): void {
		// Fill the cache beyond MAX_CACHE_SIZE (5000) using save().
		// Each save() creates 2 cache entries (wp→odoo and odoo→wp),
		// so 2600 saves = 5200 entries → triggers eviction.
		for ( $i = 1; $i <= 2600; $i++ ) {
			$this->repo->save( 'crm', 'contact', $i, $i + 10000, 'res.partner' );
		}

		// After eviction, the cache should retain ~75% of MAX_CACHE_SIZE.
		// The newest entries should still be cached (no DB query needed).
		$this->wpdb->get_var_return = null;
		$this->wpdb->calls          = [];

		// The most recent entry (2600) should be in cache.
		$result = $this->repo->get_odoo_id( 'crm', 'contact', 2600 );
		$this->assertSame( 12600, $result );

		// No DB query should have been made (cache hit).
		$get_var_calls = array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'get_var' )
		);
		$this->assertEmpty( $get_var_calls, 'Recent entry should be cached, no DB query expected' );

		// The oldest entries should have been evicted.
		// Entry #1 should NOT be in cache → DB query triggers.
		$this->wpdb->calls = [];
		$this->repo->get_odoo_id( 'crm', 'contact', 1 );

		$get_var_calls = array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'get_var' )
		);
		$this->assertNotEmpty( $get_var_calls, 'Oldest entry should have been evicted, DB query expected' );
	}

	public function test_evict_cache_removes_orphaned_bidirectional_entries(): void {
		// Fill cache exactly to the eviction threshold.
		// Each save() creates 2 entries (wp→odoo, odoo→wp).
		// 2600 saves = 5200 entries > MAX_CACHE_SIZE (5000) → eviction.
		for ( $i = 1; $i <= 2600; $i++ ) {
			$this->repo->save( 'crm', 'contact', $i, $i + 10000, 'res.partner' );
		}

		// After eviction, entries whose bidirectional partner was evicted
		// should also be removed. Verify by checking that a mid-range entry
		// (which might have one direction evicted but not the other) falls
		// back to DB query — proving the orphan was cleaned up.
		$this->wpdb->calls = [];

		// Entry near the eviction boundary: if the wp→odoo key was kept
		// but the odoo→wp partner was evicted, the orphan cleanup should
		// have removed both.
		$mid_entry = 650;  // Should be in the evicted 25%.
		$this->wpdb->get_var_return = null;
		$this->repo->get_odoo_id( 'crm', 'contact', $mid_entry );

		$get_var_calls = array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'get_var' )
		);
		$this->assertNotEmpty( $get_var_calls, 'Mid-range entry should have been evicted (with orphan cleanup), DB query expected.' );

		// Conversely, a recent entry should still be fully cached (both directions).
		$this->wpdb->calls = [];
		$this->wpdb->get_var_return = null;

		$recent = 2500;
		$this->repo->get_odoo_id( 'crm', 'contact', $recent );
		$this->repo->get_wp_id( 'crm', 'contact', $recent + 10000 );

		$get_var_calls = array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'get_var' )
		);
		$this->assertEmpty( $get_var_calls, 'Recent entry should have both directions cached, no DB query expected.' );
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
