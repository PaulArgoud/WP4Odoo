<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Webhook_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Point 3 — Webhook Reliability (try/catch around pull()).
 * Unit tests for Point 5 — Health Endpoint.
 */
class WebhookReliabilityTest extends TestCase {

	private \WP_DB_Stub $wpdb;
	private Webhook_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;
		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_cache']      = [];
		$GLOBALS['_wp_actions']    = [];
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [ 'enabled' => true, 'level' => 'debug' ];
		$GLOBALS['_wp_options']['wp4odoo_webhook_token'] = 'test-token-abc123';

		\WP4Odoo_Plugin::reset_instance();

		$this->handler = new Webhook_Handler( wp4odoo_test_settings(), \WP4Odoo_Plugin::instance()->module_registry() );
	}

	protected function tearDown(): void {
		\WP4Odoo_Plugin::reset_instance();
	}

	// ─── Point 3: Webhook enqueue failure → 503 ─────────────

	public function test_webhook_returns_503_when_pull_throws_exception(): void {
		// Only throw for sync_queue inserts; Logger inserts to logs table should succeed.
		$this->wpdb->insert_throws       = new \RuntimeException( 'Database unavailable' );
		$this->wpdb->insert_throws_table = 'sync_queue';

		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request->set_json_params( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'odoo_id'     => 42,
			'action'      => 'update',
		] );

		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 503, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'error', $data );
	}

	public function test_webhook_fires_action_on_enqueue_failure(): void {
		$this->wpdb->insert_throws       = new \RuntimeException( 'DB down' );
		$this->wpdb->insert_throws_table = 'sync_queue';

		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request->set_json_params( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'odoo_id'     => 42,
		] );

		$this->handler->handle_webhook( $request );

		// The do_action is called in the catch block; our stub tracks action names.
		$this->assertContains( 'wp4odoo_webhook_enqueue_failed', $GLOBALS['_wp_actions'] ?? [] );
	}

	public function test_webhook_logs_critical_on_enqueue_failure(): void {
		$this->wpdb->insert_throws       = new \RuntimeException( 'DB down' );
		$this->wpdb->insert_throws_table = 'sync_queue';

		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request->set_json_params( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'odoo_id'     => 42,
		] );

		$this->handler->handle_webhook( $request );
		\WP4Odoo\Logger::flush_buffer();

		// Check that a CRITICAL-level log was inserted to the logs table.
		$critical_inserts = array_values(
			array_filter( $this->wpdb->calls, fn( $c ) =>
				$c['method'] === 'insert'
				&& str_contains( $c['args'][0], 'logs' )
				&& 'critical' === ( $c['args'][1]['level'] ?? '' )
			)
		);
		$this->assertNotEmpty( $critical_inserts );
	}

	public function test_webhook_still_returns_202_on_success(): void {
		$this->wpdb->insert_id = 99;

		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request->set_json_params( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'odoo_id'     => 42,
			'action'      => 'update',
		] );

		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 202, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 99, $data['job_id'] );
	}

	// ─── Point 5: Health Endpoint ───────────────────────────

	public function test_health_returns_all_expected_keys(): void {
		$this->wpdb->get_var_return = '0';

		$request  = new \WP_REST_Request( 'GET', '/wp4odoo/v1/health' );
		$response = $this->handler->handle_health( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'status', $data );
		$this->assertArrayHasKey( 'version', $data );
		$this->assertArrayHasKey( 'queue_pending', $data );
		$this->assertArrayHasKey( 'queue_failed', $data );
		$this->assertArrayHasKey( 'circuit_breaker', $data );
		$this->assertArrayHasKey( 'modules_booted', $data );
		$this->assertArrayHasKey( 'modules_total', $data );
		$this->assertArrayHasKey( 'timestamp', $data );
	}

	public function test_health_returns_healthy_when_all_ok(): void {
		$this->wpdb->get_var_return = '0';

		$request  = new \WP_REST_Request( 'GET', '/wp4odoo/v1/health' );
		$response = $this->handler->handle_health( $request );

		$data = $response->get_data();
		$this->assertSame( 'healthy', $data['status'] );
		$this->assertSame( 'closed', $data['circuit_breaker'] );
	}

	public function test_health_returns_degraded_when_circuit_breaker_open(): void {
		$this->wpdb->get_var_return = '0';
		$GLOBALS['_wp_options']['wp4odoo_cb_state'] = [
			'opened_at' => time(),
		];

		$request  = new \WP_REST_Request( 'GET', '/wp4odoo/v1/health' );
		$response = $this->handler->handle_health( $request );

		$data = $response->get_data();
		$this->assertSame( 'degraded', $data['status'] );
		$this->assertSame( 'open', $data['circuit_breaker'] );
	}

	public function test_health_returns_version(): void {
		$this->wpdb->get_var_return = '0';

		$request  = new \WP_REST_Request( 'GET', '/wp4odoo/v1/health' );
		$response = $this->handler->handle_health( $request );

		$data = $response->get_data();
		$this->assertSame( WP4ODOO_VERSION, $data['version'] );
	}
}
