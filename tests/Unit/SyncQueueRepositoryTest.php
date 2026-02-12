<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Sync_Queue_Repository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Sync_Queue_Repository.
 *
 * Tests validation, deduplication, and data assembly logic.
 */
class SyncQueueRepositoryTest extends TestCase {

	private \WP_DB_Stub $wpdb;
	private Sync_Queue_Repository $repo;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$this->repo = new Sync_Queue_Repository();
	}

	// ─── enqueue() — Validation ────────────────────────────

	public function test_enqueue_returns_false_for_empty_module(): void {
		$result = $this->repo->enqueue( [
			'module'      => '',
			'entity_type' => 'contact',
		] );

		$this->assertFalse( $result );
	}

	public function test_enqueue_returns_false_for_empty_entity_type(): void {
		$result = $this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => '',
		] );

		$this->assertFalse( $result );
	}

	// ─── enqueue() — Defaults ──────────────────────────────

	public function test_enqueue_defaults_direction_to_wp_to_odoo(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
		] );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 'wp_to_odoo', $insert['args'][1]['direction'] );
	}

	public function test_enqueue_accepts_odoo_to_wp_direction(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'direction'   => 'odoo_to_wp',
			'odoo_id'     => 5,
		] );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 'odoo_to_wp', $insert['args'][1]['direction'] );
	}

	public function test_enqueue_defaults_action_to_update(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
		] );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 'update', $insert['args'][1]['action'] );
	}

	public function test_enqueue_accepts_valid_action(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
			'action'      => 'delete',
		] );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 'delete', $insert['args'][1]['action'] );
	}

	public function test_enqueue_defaults_priority_to_5(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
		] );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 5, $insert['args'][1]['priority'] );
	}

	// ─── enqueue() — Deduplication ─────────────────────────

	public function test_enqueue_updates_existing_pending_job(): void {
		$this->wpdb->get_var_return = '99';

		$result = $this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
			'action'      => 'create',
		] );

		$this->assertSame( 99, $result );
		$this->assertNotEmpty( $this->get_calls( 'update' ), 'Should call update for dedup' );
		$this->assertEmpty( $this->get_calls( 'insert' ), 'Should not insert when deduplicating' );
	}

	public function test_enqueue_inserts_new_job_when_no_existing(): void {
		$this->wpdb->get_var_return = null;
		$this->wpdb->insert_id     = 42;

		$result = $this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
			'action'      => 'create',
		] );

		$this->assertSame( 42, $result );
		$this->assertNotEmpty( $this->get_calls( 'insert' ), 'Should insert new job' );
	}

	public function test_enqueue_returns_false_on_insert_failure(): void {
		$this->wpdb->get_var_return = null;
		$this->wpdb->insert_id     = 0;

		$result = $this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
		] );

		$this->assertFalse( $result );
	}

	// ─── enqueue() — Transaction safety ───────────────────

	public function test_enqueue_wraps_in_transaction(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
		] );

		$queries = $this->get_calls( 'query' );
		$sql     = array_map( fn( $c ) => $c['args'][0], $queries );
		$this->assertContains( 'START TRANSACTION', $sql );
		$this->assertContains( 'COMMIT', $sql );
	}

	public function test_enqueue_rollbacks_on_insert_failure(): void {
		$this->wpdb->get_var_return = null;
		$this->wpdb->insert_id     = 0;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
		] );

		$queries = $this->get_calls( 'query' );
		$sql     = array_map( fn( $c ) => $c['args'][0], $queries );
		$this->assertContains( 'ROLLBACK', $sql );
		$this->assertNotContains( 'COMMIT', $sql );
	}

	public function test_enqueue_dedup_select_uses_for_update(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
		] );

		$get_var = $this->get_calls( 'get_var' );
		$this->assertGreaterThanOrEqual( 2, count( $get_var ), 'Expected @@in_transaction + dedup SELECT' );
		$this->assertStringContainsString( 'FOR UPDATE', $get_var[1]['args'][0] );
	}

	// ─── enqueue() — Insert data ──────────────────────────

	public function test_enqueue_includes_wp_id_in_insert_data(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 77,
		] );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 77, $insert['args'][1]['wp_id'] );
	}

	public function test_enqueue_includes_odoo_id_in_insert_data(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'sales',
			'entity_type' => 'order',
			'direction'   => 'odoo_to_wp',
			'odoo_id'     => 55,
		] );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 55, $insert['args'][1]['odoo_id'] );
	}

	public function test_enqueue_sets_status_to_pending(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
		] );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 'pending', $insert['args'][1]['status'] );
	}

	public function test_enqueue_encodes_payload_as_json(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
			'payload'     => [ 'email' => 'test@example.com' ],
		] );

		$insert  = $this->get_last_call( 'insert' );
		$payload = $insert['args'][1]['payload'];
		$this->assertSame( '{"email":"test@example.com"}', $payload );
	}

	// ─── get_stats() ─────────────────────────────────────

	public function test_get_stats_includes_last_completed_at_with_timestamp(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'status' => 'completed', 'count' => '3' ],
		];
		$this->wpdb->get_var_return = '2025-06-15 14:30:00';

		$stats = $this->repo->get_stats();

		$this->assertArrayHasKey( 'last_completed_at', $stats );
		$this->assertSame( '2025-06-15 14:30:00', $stats['last_completed_at'] );
		$this->assertSame( 3, $stats['completed'] );
	}

	public function test_get_stats_returns_empty_last_completed_at_when_no_completed(): void {
		$this->wpdb->get_results_return = [];
		$this->wpdb->get_var_return     = null;

		$stats = $this->repo->get_stats();

		$this->assertArrayHasKey( 'last_completed_at', $stats );
		$this->assertSame( '', $stats['last_completed_at'] );
		$this->assertSame( 0, $stats['total'] );
	}

	// ─── cancel() ──────────────────────────────────────────

	public function test_cancel_returns_true_when_deleted(): void {
		$this->wpdb->delete_return = 1;
		$this->assertTrue( $this->repo->cancel( 42 ) );
	}

	public function test_cancel_returns_false_when_not_found(): void {
		$this->wpdb->delete_return = 0;
		$this->assertFalse( $this->repo->cancel( 999 ) );
	}

	// ─── fetch_pending() ──────────────────────────────────

	public function test_fetch_pending_returns_array_of_jobs(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'id' => '1', 'module' => 'crm', 'status' => 'pending' ],
			(object) [ 'id' => '2', 'module' => 'crm', 'status' => 'pending' ],
		];

		$jobs = $this->repo->fetch_pending( 50, '2025-06-15 12:00:00' );

		$this->assertCount( 2, $jobs );
		$this->assertSame( '1', $jobs[0]->id );
	}

	public function test_fetch_pending_returns_empty_when_no_jobs(): void {
		$this->wpdb->get_results_return = [];

		$jobs = $this->repo->fetch_pending( 50, '2025-06-15 12:00:00' );

		$this->assertSame( [], $jobs );
	}

	public function test_fetch_pending_queries_pending_with_schedule(): void {
		$this->wpdb->get_results_return = [];

		$this->repo->fetch_pending( 10, '2025-12-01 00:00:00' );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$sql = $prepare[0]['args'][0];
		$this->assertStringContainsString( "status = 'pending'", $sql );
		$this->assertStringContainsString( 'LIMIT', $sql );
	}

	// ─── update_status() ──────────────────────────────────

	public function test_update_status_calls_update_with_status(): void {
		$this->repo->update_status( 42, 'completed' );

		$update = $this->get_last_call( 'update' );
		$this->assertNotNull( $update );
		$this->assertSame( 'completed', $update['args'][1]['status'] );
		$this->assertSame( [ 'id' => 42 ], $update['args'][2] );
	}

	public function test_update_status_merges_extra_fields(): void {
		$this->repo->update_status( 42, 'failed', [
			'attempts'      => 3,
			'error_message' => 'Connection error',
		] );

		$update = $this->get_last_call( 'update' );
		$this->assertNotNull( $update );
		$this->assertSame( 'failed', $update['args'][1]['status'] );
		$this->assertSame( 3, $update['args'][1]['attempts'] );
		$this->assertSame( 'Connection error', $update['args'][1]['error_message'] );
	}

	// ─── cleanup() ────────────────────────────────────────

	public function test_cleanup_deletes_old_jobs(): void {
		$this->wpdb->query_return = 5;

		$result = $this->repo->cleanup( 7 );

		$this->assertSame( 5, $result );
		$queries = $this->get_calls( 'query' );
		$this->assertNotEmpty( $queries );
	}

	public function test_cleanup_returns_zero_when_nothing_deleted(): void {
		$this->wpdb->query_return = 0;

		$result = $this->repo->cleanup( 30 );

		$this->assertSame( 0, $result );
	}

	public function test_cleanup_uses_prepare_with_status_filter(): void {
		$this->wpdb->query_return = 0;

		$this->repo->cleanup( 7 );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( "IN ('completed', 'failed')", $prepare[0]['args'][0] );
	}

	// ─── retry_failed() ───────────────────────────────────

	public function test_retry_failed_resets_failed_jobs(): void {
		$this->wpdb->query_return = 3;

		$result = $this->repo->retry_failed();

		$this->assertSame( 3, $result );
		$queries = $this->get_calls( 'query' );
		$this->assertNotEmpty( $queries );
		$this->assertStringContainsString( 'status = %s', $queries[0]['args'][0] );
	}

	public function test_retry_failed_returns_zero_when_no_failed_jobs(): void {
		$this->wpdb->query_return = 0;

		$result = $this->repo->retry_failed();

		$this->assertSame( 0, $result );
	}

	// ─── get_pending() ────────────────────────────────────

	public function test_get_pending_returns_jobs_for_module(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'id' => '1', 'module' => 'crm', 'entity_type' => 'contact' ],
		];

		$jobs = $this->repo->get_pending( 'crm' );

		$this->assertCount( 1, $jobs );
	}

	public function test_get_pending_filters_by_entity_type(): void {
		$this->wpdb->get_results_return = [];

		$this->repo->get_pending( 'woocommerce', 'product' );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( 'entity_type', $prepare[0]['args'][0] );
	}

	public function test_get_pending_returns_empty_when_no_jobs(): void {
		$this->wpdb->get_results_return = [];

		$jobs = $this->repo->get_pending( 'sales' );

		$this->assertSame( [], $jobs );
	}

	// ─── Helpers ───────────────────────────────────────────

	/**
	 * Get the last call matching a method name.
	 *
	 * @return array{method: string, args: array}|null
	 */
	private function get_last_call( string $method ): ?array {
		$calls = $this->get_calls( $method );
		return $calls ? end( $calls ) : null;
	}

	/**
	 * Get all calls matching a method name.
	 *
	 * @return array<int, array{method: string, args: array}>
	 */
	private function get_calls( string $method ): array {
		return array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === $method )
		);
	}
}
