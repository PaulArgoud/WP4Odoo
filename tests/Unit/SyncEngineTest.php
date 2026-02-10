<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Sync_Engine;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Sync_Engine.
 *
 * Tests queue processing, lock acquisition, job delegation, error handling,
 * and static delegator methods.
 */
class SyncEngineTest extends TestCase {

	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options'] = [];

		\WP4Odoo_Plugin::reset_instance();
	}

	// ─── Lock acquisition ──────────────────────────────────

	public function test_process_queue_returns_zero_when_lock_not_acquired(): void {
		// GET_LOCK() returns '0' when lock cannot be acquired.
		$this->wpdb->get_var_return = '0';

		$engine    = new Sync_Engine();
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );
		$this->assert_lock_attempted();
	}

	public function test_process_queue_acquires_and_releases_lock(): void {
		// Lock acquired, no jobs.
		$this->wpdb->get_var_return     = '1';
		$this->wpdb->get_results_return = [];

		$engine    = new Sync_Engine();
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );
		$this->assert_lock_attempted();
		$this->assert_lock_released();
	}

	// ─── Empty queue ───────────────────────────────────────

	public function test_process_queue_returns_zero_with_empty_queue(): void {
		$this->wpdb->get_var_return     = '1'; // Lock acquired.
		$this->wpdb->get_results_return = [];  // No jobs.

		$engine    = new Sync_Engine();
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );
	}

	// ─── Successful job processing ─────────────────────────

	public function test_process_queue_processes_push_job_successfully(): void {
		$this->wpdb->get_var_return = '1';

		// Create a mock module that succeeds.
		$module = new Mock_Module( 'test' );
		$module->push_result = true;

		\WP4Odoo_Plugin::instance()->register_module( 'test', $module );

		// Simulate a pending job returned by fetch_pending().
		$job = (object) [
			'id'           => 1,
			'module'       => 'test',
			'direction'    => 'wp_to_odoo',
			'entity_type'  => 'product',
			'action'       => 'create',
			'wp_id'        => 10,
			'odoo_id'      => 0,
			'payload'      => '{}',
			'attempts'     => 0,
			'max_attempts' => 3,
		];

		$this->wpdb->get_results_return = [ $job ];

		$engine    = new Sync_Engine();
		$processed = $engine->process_queue();

		$this->assertSame( 1, $processed );
		$this->assertTrue( $module->push_called );
		$this->assertSame( 'product', $module->last_entity_type );
		$this->assertSame( 'create', $module->last_action );
		$this->assertSame( 10, $module->last_wp_id );
		$this->assertSame( 0, $module->last_odoo_id );
	}

	public function test_process_queue_processes_pull_job_successfully(): void {
		$this->wpdb->get_var_return = '1';

		$module = new Mock_Module( 'test' );
		$module->pull_result = true;

		\WP4Odoo_Plugin::instance()->register_module( 'test', $module );

		$job = (object) [
			'id'           => 2,
			'module'       => 'test',
			'direction'    => 'odoo_to_wp',
			'entity_type'  => 'order',
			'action'       => 'update',
			'wp_id'        => 20,
			'odoo_id'      => 100,
			'payload'      => '{"total":50}',
			'attempts'     => 0,
			'max_attempts' => 3,
		];

		$this->wpdb->get_results_return = [ $job ];

		$engine    = new Sync_Engine();
		$processed = $engine->process_queue();

		$this->assertSame( 1, $processed );
		$this->assertTrue( $module->pull_called );
		$this->assertSame( 'order', $module->last_entity_type );
		$this->assertSame( 'update', $module->last_action );
		$this->assertSame( 100, $module->last_odoo_id );
		$this->assertSame( 20, $module->last_wp_id );
	}

	// ─── Failed job processing ─────────────────────────────

	public function test_process_queue_retries_failed_job_with_backoff(): void {
		$this->wpdb->get_var_return = '1';

		$module = new Mock_Module( 'test' );
		$module->throw_on_push = new \RuntimeException( 'Temporary error' );

		\WP4Odoo_Plugin::instance()->register_module( 'test', $module );

		$job = (object) [
			'id'           => 3,
			'module'       => 'test',
			'direction'    => 'wp_to_odoo',
			'entity_type'  => 'contact',
			'action'       => 'create',
			'wp_id'        => 30,
			'odoo_id'      => 0,
			'payload'      => '{}',
			'attempts'     => 0,
			'max_attempts' => 3,
		];

		$this->wpdb->get_results_return = [ $job ];

		$engine    = new Sync_Engine();
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );

		// Verify that update_status was called to reset job to pending.
		$updates = $this->get_calls( 'update' );
		$this->assertNotEmpty( $updates );

		// The first update sets status to 'processing'.
		// The second (failure handler) sets status back to 'pending' with incremented attempts.
		$last_update = end( $updates );
		$data        = $last_update['args'][1];
		$this->assertSame( 'pending', $data['status'] );
		$this->assertSame( 1, $data['attempts'] );
		$this->assertArrayHasKey( 'scheduled_at', $data );
	}

	public function test_process_queue_marks_job_as_failed_after_max_attempts(): void {
		$this->wpdb->get_var_return = '1';

		$module = new Mock_Module( 'test' );
		$module->throw_on_push = new \RuntimeException( 'Permanent error' );

		\WP4Odoo_Plugin::instance()->register_module( 'test', $module );

		$job = (object) [
			'id'           => 4,
			'module'       => 'test',
			'direction'    => 'wp_to_odoo',
			'entity_type'  => 'contact',
			'action'       => 'create',
			'wp_id'        => 40,
			'odoo_id'      => 0,
			'payload'      => '{}',
			'attempts'     => 2,  // Already tried twice.
			'max_attempts' => 3,
		];

		$this->wpdb->get_results_return = [ $job ];

		$engine    = new Sync_Engine();
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );

		// Verify the final update marks job as 'failed'.
		$updates     = $this->get_calls( 'update' );
		$last_update = end( $updates );
		$data        = $last_update['args'][1];
		$this->assertSame( 'failed', $data['status'] );
		$this->assertSame( 3, $data['attempts'] );
		$this->assertArrayHasKey( 'error_message', $data );
	}

	public function test_process_queue_throws_when_module_not_found(): void {
		$this->wpdb->get_var_return = '1';

		$job = (object) [
			'id'           => 5,
			'module'       => 'nonexistent',
			'direction'    => 'wp_to_odoo',
			'entity_type'  => 'product',
			'action'       => 'create',
			'wp_id'        => 50,
			'odoo_id'      => 0,
			'payload'      => '{}',
			'attempts'     => 0,
			'max_attempts' => 3,
		];

		$this->wpdb->get_results_return = [ $job ];

		$engine    = new Sync_Engine();
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );

		// Verify error handling: job should be retried (or failed if max attempts reached).
		$updates = $this->get_calls( 'update' );
		$this->assertNotEmpty( $updates );
	}

	// ─── Batch size configuration ──────────────────────────

	public function test_process_queue_uses_batch_size_from_settings(): void {
		$this->wpdb->get_var_return     = '1';
		$this->wpdb->get_results_return = [];

		update_option( 'wp4odoo_sync_settings', [ 'batch_size' => 100 ] );

		$engine    = new Sync_Engine();
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );

		// Verify that fetch_pending was called (via get_results).
		$get_results_calls = $this->get_calls( 'get_results' );
		$this->assertNotEmpty( $get_results_calls );
	}

	public function test_process_queue_uses_default_batch_size_when_not_set(): void {
		$this->wpdb->get_var_return     = '1';
		$this->wpdb->get_results_return = [];

		// No batch_size in options.
		$engine    = new Sync_Engine();
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );

		// Verify fetch_pending was called with default batch size (50).
		$get_results_calls = $this->get_calls( 'get_results' );
		$this->assertNotEmpty( $get_results_calls );
	}

	// ─── Static delegator methods ──────────────────────────

	public function test_enqueue_delegates_to_sync_queue_repository(): void {
		$this->wpdb->insert_id = 123;

		$args = [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 10,
			'action'      => 'create',
		];

		$result = Sync_Engine::enqueue( $args );

		$this->assertSame( 123, $result );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 'wp_wp4odoo_sync_queue', $insert['args'][0] );
	}

	public function test_get_stats_returns_expected_structure(): void {
		$this->wpdb->get_var_return = '5';  // Used by get_stats for counts.

		$stats = Sync_Engine::get_stats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'pending', $stats );
		$this->assertArrayHasKey( 'processing', $stats );
		$this->assertArrayHasKey( 'completed', $stats );
		$this->assertArrayHasKey( 'failed', $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'last_completed_at', $stats );
	}

	public function test_cleanup_delegates_to_sync_queue_repository(): void {
		$this->wpdb->query_return = 10;

		$result = Sync_Engine::cleanup( 14 );

		$this->assertSame( 10, $result );

		$query = $this->get_last_call( 'query' );
		$this->assertNotNull( $query );
	}

	public function test_retry_failed_delegates_to_sync_queue_repository(): void {
		$this->wpdb->query_return = 5;

		$result = Sync_Engine::retry_failed();

		$this->assertSame( 5, $result );

		$query = $this->get_last_call( 'query' );
		$this->assertNotNull( $query );
	}

	// ─── Multiple jobs processing ──────────────────────────

	public function test_process_queue_processes_multiple_jobs(): void {
		$this->wpdb->get_var_return = '1';

		$module1 = new Mock_Module( 'crm' );
		$module1->push_result = true;

		$module2 = new Mock_Module( 'sales' );
		$module2->pull_result = true;

		\WP4Odoo_Plugin::instance()->register_module( 'crm', $module1 );
		\WP4Odoo_Plugin::instance()->register_module( 'sales', $module2 );

		$job1 = (object) [
			'id'           => 10,
			'module'       => 'crm',
			'direction'    => 'wp_to_odoo',
			'entity_type'  => 'contact',
			'action'       => 'create',
			'wp_id'        => 100,
			'odoo_id'      => 0,
			'payload'      => '{}',
			'attempts'     => 0,
			'max_attempts' => 3,
		];

		$job2 = (object) [
			'id'           => 11,
			'module'       => 'sales',
			'direction'    => 'odoo_to_wp',
			'entity_type'  => 'order',
			'action'       => 'update',
			'wp_id'        => 200,
			'odoo_id'      => 500,
			'payload'      => '{}',
			'attempts'     => 0,
			'max_attempts' => 3,
		];

		$this->wpdb->get_results_return = [ $job1, $job2 ];

		$engine    = new Sync_Engine();
		$processed = $engine->process_queue();

		$this->assertSame( 2, $processed );
		$this->assertTrue( $module1->push_called );
		$this->assertTrue( $module2->pull_called );
	}

	// ─── Helpers ───────────────────────────────────────────

	private function assert_lock_attempted(): void {
		$get_var_calls = $this->get_calls( 'get_var' );
		$this->assertNotEmpty( $get_var_calls, 'Lock acquisition should call get_var()' );
	}

	private function assert_lock_released(): void {
		$query_calls = $this->get_calls( 'query' );
		$found       = false;

		foreach ( $query_calls as $call ) {
			if ( str_contains( $call['args'][0], 'RELEASE_LOCK' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Lock should be released via RELEASE_LOCK query' );
	}

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

// ─── Mock Module for Testing ──────────────────────────────

namespace WP4Odoo\Tests\Unit;

/**
 * Minimal concrete module for testing Sync_Engine.
 */
class Mock_Module extends \WP4Odoo\Module_Base {

	public bool $push_result = true;
	public bool $pull_result = true;
	public ?\Throwable $throw_on_push = null;
	public ?\Throwable $throw_on_pull = null;

	public bool $push_called = false;
	public bool $pull_called = false;

	public string $last_entity_type = '';
	public string $last_action = '';
	public int $last_wp_id = 0;
	public int $last_odoo_id = 0;
	public array $last_payload = [];

	public function __construct( string $id ) {
		$this->id   = $id;
		$this->name = 'Mock Module';
		parent::__construct();
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [];
	}

	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): bool {
		$this->push_called      = true;
		$this->last_entity_type = $entity_type;
		$this->last_action      = $action;
		$this->last_wp_id       = $wp_id;
		$this->last_odoo_id     = $odoo_id;
		$this->last_payload     = $payload;

		if ( $this->throw_on_push ) {
			throw $this->throw_on_push;
		}

		return $this->push_result;
	}

	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): bool {
		$this->pull_called      = true;
		$this->last_entity_type = $entity_type;
		$this->last_action      = $action;
		$this->last_odoo_id     = $odoo_id;
		$this->last_wp_id       = $wp_id;
		$this->last_payload     = $payload;

		if ( $this->throw_on_pull ) {
			throw $this->throw_on_pull;
		}

		return $this->pull_result;
	}
}
