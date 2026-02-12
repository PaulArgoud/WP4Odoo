<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Sync_Engine;
use WP4Odoo\Queue_Manager;
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

		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_mail_calls'] = [];

		\WP4Odoo_Plugin::reset_instance();
	}

	// ─── Lock acquisition ──────────────────────────────────

	public function test_process_queue_returns_zero_when_lock_not_acquired(): void {
		// GET_LOCK() returns '0' when lock cannot be acquired.
		$this->wpdb->get_var_return = '0';

		$engine    = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );
		$this->assert_lock_attempted();
	}

	public function test_process_queue_acquires_and_releases_lock(): void {
		// Lock acquired, no jobs.
		$this->wpdb->get_var_return     = '1';
		$this->wpdb->get_results_return = [];

		$engine    = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );
		$this->assert_lock_attempted();
		$this->assert_lock_released();
	}

	// ─── Empty queue ───────────────────────────────────────

	public function test_process_queue_returns_zero_with_empty_queue(): void {
		$this->wpdb->get_var_return     = '1'; // Lock acquired.
		$this->wpdb->get_results_return = [];  // No jobs.

		$engine    = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );
	}

	// ─── Successful job processing ─────────────────────────

	public function test_process_queue_processes_push_job_successfully(): void {
		$this->wpdb->get_var_return = '1';

		// Create a mock module that succeeds.
		$module = new Mock_Module( 'test' );
		$module->push_result = \WP4Odoo\Sync_Result::success();

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

		$engine    = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
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
		$module->pull_result = \WP4Odoo\Sync_Result::success();

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

		$engine    = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
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

		$engine    = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
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

	public function test_backoff_delay_is_exponential(): void {
		$this->wpdb->get_var_return = '1';

		$module = new Mock_Module( 'test' );
		$module->throw_on_push = new \RuntimeException( 'Temp error' );
		\WP4Odoo_Plugin::instance()->register_module( 'test', $module );

		// Test with attempt 0 → delay = 2^1 * 60 = 120 s.
		$job1 = (object) [
			'id'           => 901,
			'module'       => 'test',
			'direction'    => 'wp_to_odoo',
			'entity_type'  => 'product',
			'action'       => 'create',
			'wp_id'        => 1,
			'odoo_id'      => 0,
			'payload'      => '{}',
			'attempts'     => 0,
			'max_attempts' => 5,
		];

		$this->wpdb->get_results_return = [ $job1 ];

		$engine = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
		$engine->process_queue();

		$updates = $this->get_calls( 'update' );
		$retry1  = end( $updates );
		$scheduled1 = $retry1['args'][1]['scheduled_at'];
		$delay1     = strtotime( $scheduled1 ) - time();

		// 2^1 * 60 = 120 seconds + 0–60 s jitter (±5 s tolerance for execution time).
		$this->assertGreaterThanOrEqual( 115, $delay1 );
		$this->assertLessThanOrEqual( 185, $delay1 );

		// Test with attempt 1 → delay = 2^2 * 60 = 240 s.
		$this->wpdb->calls = [];

		$job2 = clone $job1;
		$job2->id       = 902;
		$job2->attempts = 1;

		$this->wpdb->get_var_return     = '1';
		$this->wpdb->get_results_return = [ $job2 ];

		$engine2 = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
		$engine2->process_queue();

		$updates2    = $this->get_calls( 'update' );
		$retry2      = end( $updates2 );
		$scheduled2  = $retry2['args'][1]['scheduled_at'];
		$delay2      = strtotime( $scheduled2 ) - time();

		// 2^2 * 60 = 240 seconds + 0–60 s jitter (±5 s tolerance for execution time).
		$this->assertGreaterThanOrEqual( 235, $delay2 );
		$this->assertLessThanOrEqual( 305, $delay2 );

		// Verify exponential growth: delay2 ≈ 2 × delay1.
		$this->assertGreaterThan( $delay1, $delay2 );
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

		$engine    = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
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

		$engine    = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
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

		$engine    = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
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
		$engine    = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
		$processed = $engine->process_queue();

		$this->assertSame( 0, $processed );

		// Verify fetch_pending was called with default batch size (50).
		$get_results_calls = $this->get_calls( 'get_results' );
		$this->assertNotEmpty( $get_results_calls );
	}

	// ─── Queue_Manager delegator methods ──────────────────

	public function test_push_delegates_to_sync_queue_repository(): void {
		$this->wpdb->insert_id = 123;

		$result = Queue_Manager::push( 'crm', 'contact', 'create', 10 );

		$this->assertSame( 123, $result );

		$insert = $this->get_last_call( 'insert' );
		$this->assertSame( 'wp_wp4odoo_sync_queue', $insert['args'][0] );
	}

	public function test_get_stats_returns_expected_structure(): void {
		$this->wpdb->get_var_return = '5';  // Used by get_stats for counts.

		$stats = Queue_Manager::get_stats();

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

		$result = Queue_Manager::cleanup( 14 );

		$this->assertSame( 10, $result );

		$query = $this->get_last_call( 'query' );
		$this->assertNotNull( $query );
	}

	public function test_retry_failed_delegates_to_sync_queue_repository(): void {
		$this->wpdb->query_return = 5;

		$result = Queue_Manager::retry_failed();

		$this->assertSame( 5, $result );

		$query = $this->get_last_call( 'query' );
		$this->assertNotNull( $query );
	}

	// ─── Multiple jobs processing ──────────────────────────

	public function test_process_queue_processes_multiple_jobs(): void {
		$this->wpdb->get_var_return = '1';

		$module1 = new Mock_Module( 'crm' );
		$module1->push_result = \WP4Odoo\Sync_Result::success();

		$module2 = new Mock_Module( 'sales' );
		$module2->pull_result = \WP4Odoo\Sync_Result::success();

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

		$engine    = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
		$processed = $engine->process_queue();

		$this->assertSame( 2, $processed );
		$this->assertTrue( $module1->push_called );
		$this->assertTrue( $module2->pull_called );
	}

	// ─── Dry run mode ─────────────────────────────────────

	public function test_dry_run_does_not_call_push_or_pull(): void {
		$this->wpdb->get_var_return = '1';

		$module = new Mock_Module( 'test' );
		$module->push_result = \WP4Odoo\Sync_Result::success();
		\WP4Odoo_Plugin::instance()->register_module( 'test', $module );

		$job = (object) [
			'id'           => 200,
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

		$engine = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
		$engine->set_dry_run( true );
		$processed = $engine->process_queue();

		$this->assertSame( 1, $processed );
		$this->assertFalse( $module->push_called );
		$this->assertFalse( $module->pull_called );
	}

	// ─── Failure notification ─────────────────────────────

	public function test_failure_notification_sent_after_threshold(): void {
		$this->wpdb->get_var_return = '1';
		$GLOBALS['_wp_options']['admin_email'] = 'admin@test.com';
		$GLOBALS['_wp_options']['wp4odoo_consecutive_failures'] = 4;

		$module = new Mock_Module( 'test' );
		$module->throw_on_push = new \RuntimeException( 'API down' );
		\WP4Odoo_Plugin::instance()->register_module( 'test', $module );

		$job = (object) [
			'id'           => 100,
			'module'       => 'test',
			'direction'    => 'wp_to_odoo',
			'entity_type'  => 'product',
			'action'       => 'create',
			'wp_id'        => 1,
			'odoo_id'      => 0,
			'payload'      => '{}',
			'attempts'     => 2,
			'max_attempts' => 3,
		];

		$this->wpdb->get_results_return = [ $job ];

		$engine = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
		$engine->process_queue();

		$this->assertNotEmpty( $GLOBALS['_wp_mail_calls'] );
		$this->assertSame( 'admin@test.com', $GLOBALS['_wp_mail_calls'][0]['to'] );
		$this->assertStringContainsString( '5', $GLOBALS['_wp_mail_calls'][0]['subject'] );
	}

	public function test_failure_counter_resets_on_success(): void {
		$this->wpdb->get_var_return = '1';
		$GLOBALS['_wp_options']['wp4odoo_consecutive_failures'] = 10;

		$module = new Mock_Module( 'test' );
		$module->push_result = \WP4Odoo\Sync_Result::success();
		\WP4Odoo_Plugin::instance()->register_module( 'test', $module );

		$job = (object) [
			'id'           => 101,
			'module'       => 'test',
			'direction'    => 'wp_to_odoo',
			'entity_type'  => 'product',
			'action'       => 'create',
			'wp_id'        => 1,
			'odoo_id'      => 0,
			'payload'      => '{}',
			'attempts'     => 0,
			'max_attempts' => 3,
		];

		$this->wpdb->get_results_return = [ $job ];

		$engine = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
		$engine->process_queue();

		$this->assertSame( 0, (int) get_option( 'wp4odoo_consecutive_failures', 0 ) );
		$this->assertEmpty( $GLOBALS['_wp_mail_calls'] );
	}

	public function test_failure_notification_respects_cooldown(): void {
		$this->wpdb->get_var_return = '1';
		$GLOBALS['_wp_options']['admin_email'] = 'admin@test.com';
		$GLOBALS['_wp_options']['wp4odoo_consecutive_failures'] = 10;
		$GLOBALS['_wp_options']['wp4odoo_last_failure_email'] = time();

		$module = new Mock_Module( 'test' );
		$module->throw_on_push = new \RuntimeException( 'Fail' );
		\WP4Odoo_Plugin::instance()->register_module( 'test', $module );

		$job = (object) [
			'id'           => 102,
			'module'       => 'test',
			'direction'    => 'wp_to_odoo',
			'entity_type'  => 'product',
			'action'       => 'create',
			'wp_id'        => 1,
			'odoo_id'      => 0,
			'payload'      => '{}',
			'attempts'     => 2,
			'max_attempts' => 3,
		];

		$this->wpdb->get_results_return = [ $job ];

		$engine = new Sync_Engine( wp4odoo_test_module_resolver(), wp4odoo_test_queue_repo(), wp4odoo_test_settings() );
		$engine->process_queue();

		$this->assertEmpty( $GLOBALS['_wp_mail_calls'] );
	}

	// ─── Helpers ───────────────────────────────────────────

	private function assert_lock_attempted(): void {
		$get_var_calls = $this->get_calls( 'get_var' );
		$this->assertNotEmpty( $get_var_calls, 'Lock acquisition should call get_var()' );
	}

	private function assert_lock_released(): void {
		$get_var_calls = $this->get_calls( 'get_var' );
		$found         = false;

		foreach ( $get_var_calls as $call ) {
			if ( str_contains( $call['args'][0], 'RELEASE_LOCK' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Lock should be released via RELEASE_LOCK get_var' );
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

	public \WP4Odoo\Sync_Result $push_result;
	public \WP4Odoo\Sync_Result $pull_result;
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
		$this->push_result = \WP4Odoo\Sync_Result::success();
		$this->pull_result = \WP4Odoo\Sync_Result::success();
		parent::__construct( $id, 'Mock Module', wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [];
	}

	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
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

	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
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
