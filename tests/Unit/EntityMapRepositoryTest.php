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

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		Entity_Map_Repository::flush_cache();
	}

	protected function tearDown(): void {
		Entity_Map_Repository::flush_cache();
	}

	// ─── get_odoo_id() ─────────────────────────────────────

	public function test_get_odoo_id_returns_int_when_found(): void {
		$this->wpdb->get_var_return = '42';

		$result = Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 10 );

		$this->assertSame( 42, $result );
	}

	public function test_get_odoo_id_returns_null_when_not_found(): void {
		$this->wpdb->get_var_return = null;

		$result = Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 999 );

		$this->assertNull( $result );
	}

	public function test_get_odoo_id_queries_correct_table(): void {
		$this->wpdb->get_var_return = null;

		Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 10 );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( 'wp_wp4odoo_entity_map', $prepare[0]['args'][0] );
	}

	// ─── get_wp_id() ───────────────────────────────────────

	public function test_get_wp_id_returns_int_when_found(): void {
		$this->wpdb->get_var_return = '7';

		$result = Entity_Map_Repository::get_wp_id( 'sales', 'order', 100 );

		$this->assertSame( 7, $result );
	}

	public function test_get_wp_id_returns_null_when_not_found(): void {
		$this->wpdb->get_var_return = null;

		$result = Entity_Map_Repository::get_wp_id( 'sales', 'order', 999 );

		$this->assertNull( $result );
	}

	// ─── save() ────────────────────────────────────────────

	public function test_save_calls_replace_with_correct_data(): void {
		Entity_Map_Repository::save( 'crm', 'contact', 10, 42, 'res.partner', 'abc123' );

		$replace = $this->get_last_call( 'replace' );
		$this->assertNotNull( $replace );

		$data = $replace['args'][1];
		$this->assertSame( 'crm', $data['module'] );
		$this->assertSame( 'contact', $data['entity_type'] );
		$this->assertSame( 10, $data['wp_id'] );
		$this->assertSame( 42, $data['odoo_id'] );
		$this->assertSame( 'res.partner', $data['odoo_model'] );
		$this->assertSame( 'abc123', $data['sync_hash'] );
		$this->assertArrayHasKey( 'last_synced_at', $data );
	}

	public function test_save_returns_true_on_success(): void {
		$result = Entity_Map_Repository::save( 'crm', 'contact', 10, 42, 'res.partner' );
		$this->assertTrue( $result );
	}

	// ─── remove() ──────────────────────────────────────────

	public function test_remove_returns_true_when_deleted(): void {
		$this->wpdb->delete_return = 1;

		$result = Entity_Map_Repository::remove( 'crm', 'contact', 10 );
		$this->assertTrue( $result );
	}

	public function test_remove_returns_false_when_not_found(): void {
		$this->wpdb->delete_return = 0;

		$result = Entity_Map_Repository::remove( 'crm', 'contact', 999 );
		$this->assertFalse( $result );
	}

	public function test_remove_passes_correct_where_clause(): void {
		$this->wpdb->delete_return = 1;

		Entity_Map_Repository::remove( 'sales', 'order', 77 );

		$delete = $this->get_last_call( 'delete' );
		$this->assertNotNull( $delete );
		$this->assertSame(
			[ 'module' => 'sales', 'entity_type' => 'order', 'wp_id' => 77 ],
			$delete['args'][1]
		);
	}

	// ─── get_wp_ids_batch() ───────────────────────────────

	public function test_get_wp_ids_batch_returns_empty_for_empty_input(): void {
		$result = Entity_Map_Repository::get_wp_ids_batch( 'woocommerce', 'product', [] );
		$this->assertSame( [], $result );
	}

	public function test_get_wp_ids_batch_returns_map_of_odoo_to_wp(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'odoo_id' => '100', 'wp_id' => '10' ],
			(object) [ 'odoo_id' => '200', 'wp_id' => '20' ],
		];

		$result = Entity_Map_Repository::get_wp_ids_batch( 'woocommerce', 'product', [ 100, 200, 300 ] );

		$this->assertSame( [ 100 => 10, 200 => 20 ], $result );
	}

	public function test_get_wp_ids_batch_returns_empty_when_no_matches(): void {
		$this->wpdb->get_results_return = [];

		$result = Entity_Map_Repository::get_wp_ids_batch( 'woocommerce', 'product', [ 999 ] );

		$this->assertSame( [], $result );
	}

	public function test_get_wp_ids_batch_generates_correct_placeholders(): void {
		$this->wpdb->get_results_return = [];

		Entity_Map_Repository::get_wp_ids_batch( 'woocommerce', 'product', [ 1, 2, 3 ] );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( '%d,%d,%d', $prepare[0]['args'][0] );
	}

	public function test_get_wp_ids_batch_single_id(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'odoo_id' => '42', 'wp_id' => '7' ],
		];

		$result = Entity_Map_Repository::get_wp_ids_batch( 'woocommerce', 'product', [ 42 ] );

		$this->assertSame( [ 42 => 7 ], $result );
	}

	// ─── get_odoo_ids_batch() ──────────────────────────────

	public function test_get_odoo_ids_batch_returns_empty_for_empty_input(): void {
		$result = Entity_Map_Repository::get_odoo_ids_batch( 'woocommerce', 'product', [] );
		$this->assertSame( [], $result );
	}

	public function test_get_odoo_ids_batch_returns_map_of_wp_to_odoo(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => '10', 'odoo_id' => '100' ],
			(object) [ 'wp_id' => '20', 'odoo_id' => '200' ],
		];

		$result = Entity_Map_Repository::get_odoo_ids_batch( 'woocommerce', 'product', [ 10, 20, 30 ] );

		$this->assertSame( [ 10 => 100, 20 => 200 ], $result );
	}

	public function test_get_odoo_ids_batch_returns_empty_when_no_matches(): void {
		$this->wpdb->get_results_return = [];

		$result = Entity_Map_Repository::get_odoo_ids_batch( 'woocommerce', 'product', [ 999 ] );

		$this->assertSame( [], $result );
	}

	public function test_get_odoo_ids_batch_generates_correct_placeholders(): void {
		$this->wpdb->get_results_return = [];

		Entity_Map_Repository::get_odoo_ids_batch( 'woocommerce', 'product', [ 5, 6 ] );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( '%d,%d', $prepare[0]['args'][0] );
	}

	// ─── Cache behavior ──────────────────────────────────

	public function test_get_odoo_id_cache_hit_avoids_second_query(): void {
		$this->wpdb->get_var_return = '42';

		Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 10 );
		$first_count = count( $this->get_calls( 'get_var' ) );

		$result = Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 10 );
		$second_count = count( $this->get_calls( 'get_var' ) );

		$this->assertSame( 42, $result );
		$this->assertSame( $first_count, $second_count );
	}

	public function test_get_wp_id_cache_hit_avoids_second_query(): void {
		$this->wpdb->get_var_return = '7';

		Entity_Map_Repository::get_wp_id( 'sales', 'order', 100 );
		$first_count = count( $this->get_calls( 'get_var' ) );

		$result = Entity_Map_Repository::get_wp_id( 'sales', 'order', 100 );
		$second_count = count( $this->get_calls( 'get_var' ) );

		$this->assertSame( 7, $result );
		$this->assertSame( $first_count, $second_count );
	}

	public function test_save_populates_cache_for_subsequent_lookups(): void {
		Entity_Map_Repository::save( 'crm', 'contact', 10, 42, 'res.partner' );

		$this->wpdb->get_var_return = null;
		$result = Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 10 );

		$this->assertSame( 42, $result );
	}

	public function test_remove_invalidates_cache(): void {
		Entity_Map_Repository::save( 'crm', 'contact', 10, 42, 'res.partner' );

		$this->wpdb->delete_return = 1;
		Entity_Map_Repository::remove( 'crm', 'contact', 10 );

		$this->wpdb->get_var_return = null;
		$result = Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 10 );

		$this->assertNull( $result );
	}

	public function test_flush_cache_clears_all_entries(): void {
		$this->wpdb->get_var_return = '42';
		Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 10 );

		Entity_Map_Repository::flush_cache();

		$this->wpdb->get_var_return = '99';
		$result = Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 10 );

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
