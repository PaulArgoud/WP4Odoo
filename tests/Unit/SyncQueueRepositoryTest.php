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

		// Clear transient cache between tests.
		$GLOBALS['_wp_transients'] = [];

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

	public function test_enqueue_returns_false_for_oversized_payload(): void {
		$result = $this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
			'payload'     => [ 'data' => str_repeat( 'x', 1048577 ) ],
		] );

		$this->assertFalse( $result );
	}

	public function test_enqueue_accepts_payload_within_limit(): void {
		$this->wpdb->insert_id = 1;

		$result = $this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
			'payload'     => [ 'data' => str_repeat( 'x', 1000 ) ],
		] );

		$this->assertIsInt( $result );
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
		$this->assertGreaterThanOrEqual( 2, count( $get_var ), 'Expected @@in_transaction check + dedup SELECT' );
		$this->assertStringContainsString( '@@in_transaction', $get_var[0]['args'][0] );
		$this->assertStringContainsString( 'FOR UPDATE', $get_var[1]['args'][0] );
	}

	public function test_enqueue_uses_savepoint_when_in_transaction(): void {
		$this->wpdb->insert_id = 1;

		$this->repo->enqueue( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'wp_id'       => 10,
		], true );

		$queries = $this->get_calls( 'query' );
		$sql     = array_map( fn( $c ) => $c['args'][0], $queries );
		$this->assertContains( 'SAVEPOINT wp4odoo_dedup', $sql );
		$this->assertContains( 'RELEASE SAVEPOINT wp4odoo_dedup', $sql );
		$this->assertNotContains( 'START TRANSACTION', $sql );
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
		// Single consolidated query returns all statuses with last_completed.
		$this->wpdb->get_results_return = [
			(object) [ 'status' => 'pending', 'count' => '2', 'last_completed' => null ],
			(object) [ 'status' => 'completed', 'count' => '3', 'last_completed' => '2024-01-15 10:30:00' ],
		];

		$stats = $this->repo->get_stats();

		$this->assertSame( 3, $stats['completed'] );
		$this->assertSame( 5, $stats['total'] ); // 2 pending + 3 completed.
		$this->assertSame( '2024-01-15 10:30:00', $stats['last_completed_at'] );
	}

	public function test_get_stats_returns_empty_last_completed_at_when_no_completed(): void {
		$this->wpdb->get_results_return = [];

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
		$this->assertSame( 1, $jobs[0]->id );
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
		// Simulate one chunk of 5 rows, then 0 to stop the loop.
		$this->wpdb->query_return_sequence = [ 5, 0 ];

		$result = $this->repo->cleanup( 7 );

		$this->assertSame( 5, $result );
		$queries = $this->get_calls( 'query' );
		$this->assertNotEmpty( $queries );
	}

	public function test_cleanup_returns_zero_when_nothing_deleted(): void {
		$this->wpdb->query_return_sequence = [ 0 ];

		$result = $this->repo->cleanup( 30 );

		$this->assertSame( 0, $result );
	}

	public function test_cleanup_uses_prepare_with_status_filter(): void {
		$this->wpdb->query_return_sequence = [ 0 ];

		$this->repo->cleanup( 7 );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( 'IN (%s, %s)', $prepare[0]['args'][0] );
	}

	public function test_cleanup_uses_limit_in_query(): void {
		$this->wpdb->query_return_sequence = [ 0 ];

		$this->repo->cleanup( 7 );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( 'LIMIT', $prepare[0]['args'][0] );
	}

	public function test_cleanup_accumulates_rows_across_chunks(): void {
		// Simulate two full chunks then a partial, then 0 to stop.
		$this->wpdb->query_return_sequence = [ 10000, 10000, 3000, 0 ];

		$result = $this->repo->cleanup( 7 );

		$this->assertSame( 23000, $result );

		// Verify query was called 4 times (3 chunks + final 0).
		$queries = $this->get_calls( 'query' );
		$this->assertCount( 4, $queries );
	}

	public function test_cleanup_loops_until_zero_rows_deleted(): void {
		// First chunk deletes 100, second deletes 0 → loop stops.
		$this->wpdb->query_return_sequence = [ 100, 0 ];

		$result = $this->repo->cleanup( 7 );

		$this->assertSame( 100, $result );
		$queries = $this->get_calls( 'query' );
		$this->assertCount( 2, $queries );
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

	// ─── recover_stale_processing() ─────────────────────────

	public function test_recover_stale_processing_increments_attempts(): void {
		// Simulate one stale processing job recovered + zero at max_attempts.
		$this->wpdb->query_return_sequence = [
			1, // START TRANSACTION
			1, // UPDATE ... SET status = 'pending' (retry — 1 row affected)
			0, // UPDATE ... SET status = 'failed' (max_attempts — 0 rows)
			1, // COMMIT
		];

		$result = $this->repo->recover_stale_processing( 600 );

		// Should recover 1 job total (1 retried + 0 failed).
		$this->assertSame( 1, $result );

		// Verify the recovery UPDATE SQL increments attempts to prevent
		// infinite stale recovery loops for persistently failing jobs.
		$queries = $this->get_calls( 'query' );
		$found   = false;
		foreach ( $queries as $call ) {
			$sql = $call['args'][0];
			if ( str_contains( $sql, 'Recovered' ) ) {
				$this->assertStringContainsString( 'attempts = attempts + 1', $sql, 'Recovery should increment attempts' );
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Recovery UPDATE query not found' );
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
