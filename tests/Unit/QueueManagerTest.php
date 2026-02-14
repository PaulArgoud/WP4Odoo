<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Queue_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Queue_Manager.
 *
 * Verifies that push/pull assemble the correct arguments
 * and that cancel/get_pending delegate properly.
 */
class QueueManagerTest extends TestCase {

	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;
		Queue_Manager::reset();
	}

	// ─── push() ────────────────────────────────────────────

	public function test_push_sets_direction_to_wp_to_odoo(): void {
		$this->wpdb->insert_id = 1;

		Queue_Manager::push( 'crm', 'contact', 'create', 10 );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 'wp_to_odoo', $insert['args'][1]['direction'] );
	}

	public function test_push_passes_all_args_correctly(): void {
		$this->wpdb->insert_id = 1;

		Queue_Manager::push( 'sales', 'order', 'update', 42, 99, [ 'total' => 100 ], 3 );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];

		$this->assertSame( 'sales', $data['module'] );
		$this->assertSame( 'order', $data['entity_type'] );
		$this->assertSame( 'update', $data['action'] );
		$this->assertSame( 42, $data['wp_id'] );
		$this->assertSame( 99, $data['odoo_id'] );
		$this->assertSame( 3, $data['priority'] );
		$this->assertSame( '{"total":100}', $data['payload'] );
	}

	// ─── pull() ────────────────────────────────────────────

	public function test_pull_sets_direction_to_odoo_to_wp(): void {
		$this->wpdb->insert_id = 1;

		Queue_Manager::pull( 'crm', 'contact', 'create', 55 );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 'odoo_to_wp', $insert['args'][1]['direction'] );
	}

	public function test_pull_passes_all_args_correctly(): void {
		$this->wpdb->insert_id = 1;

		Queue_Manager::pull( 'crm', 'contact', 'update', 55, 10, [ 'name' => 'John' ], 2 );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];

		$this->assertSame( 'crm', $data['module'] );
		$this->assertSame( 'contact', $data['entity_type'] );
		$this->assertSame( 'update', $data['action'] );
		$this->assertSame( 55, $data['odoo_id'] );
		$this->assertSame( 10, $data['wp_id'] );
		$this->assertSame( 2, $data['priority'] );
		$this->assertSame( '{"name":"John"}', $data['payload'] );
	}

	// ─── cancel() ──────────────────────────────────────────

	public function test_cancel_delegates_to_repository(): void {
		$this->wpdb->delete_return = 1;

		$result = Queue_Manager::cancel( 42 );

		$this->assertTrue( $result );
		$delete = $this->get_last_call( 'delete' );
		$this->assertSame( 42, $delete['args'][1]['id'] );
		$this->assertSame( 'pending', $delete['args'][1]['status'] );
	}

	// ─── get_pending() ─────────────────────────────────────

	public function test_get_pending_returns_results(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'id' => 1, 'module' => 'crm' ],
		];

		$result = Queue_Manager::get_pending( 'crm' );

		$this->assertCount( 1, $result );
		$this->assertSame( 1, $result[0]->id );
	}

	public function test_get_pending_returns_empty_when_no_jobs(): void {
		$this->wpdb->get_results_return = [];

		$result = Queue_Manager::get_pending( 'crm', 'contact' );

		$this->assertEmpty( $result );
	}

	// ─── reset() ──────────────────────────────────────────

	public function test_reset_allows_repo_reinit_with_new_wpdb(): void {
		$this->wpdb->insert_id = 1;

		// First push — uses current $wpdb.
		Queue_Manager::push( 'crm', 'contact', 'create', 10 );
		$count_before = count( $this->wpdb->calls );

		// Reset and swap $wpdb.
		Queue_Manager::reset();
		global $wpdb;
		$new_wpdb           = new \WP_DB_Stub();
		$new_wpdb->insert_id = 1;
		$wpdb               = $new_wpdb;

		// Second push — should use new $wpdb.
		Queue_Manager::push( 'crm', 'contact', 'create', 20 );

		// Old wpdb should NOT have new calls; new one should.
		$this->assertSame( $count_before, count( $this->wpdb->calls ) );
		$this->assertNotEmpty( $new_wpdb->calls );
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
