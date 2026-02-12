<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Webhook_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Webhook_Handler.
 *
 * Tests token validation, webhook handling, and health check.
 */
class WebhookHandlerTest extends TestCase {

	private \WP_DB_Stub $wpdb;
	private Webhook_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;
		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_cache']      = [];
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [ 'enabled' => true, 'level' => 'debug' ];

		// Set a webhook token so ensure_webhook_token() uses it.
		$GLOBALS['_wp_options']['wp4odoo_webhook_token'] = 'test-token-abc123';

		$this->handler = new Webhook_Handler( wp4odoo_test_settings() );
	}

	// ─── validate_webhook_token ─────────────────────────────

	public function test_validate_token_returns_true_for_valid_token(): void {
		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request->set_header( 'X-Odoo-Token', 'test-token-abc123' );

		$result = $this->handler->validate_webhook_token( $request );

		$this->assertTrue( $result );
	}

	public function test_validate_token_returns_error_for_invalid_token(): void {
		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request->set_header( 'X-Odoo-Token', 'wrong-token' );

		$result = $this->handler->validate_webhook_token( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp4odoo_invalid_token', $result->get_error_code() );
	}

	public function test_validate_token_returns_error_when_no_token_configured(): void {
		// Clear token AFTER construction (constructor generates one if empty).
		$GLOBALS['_wp_options']['wp4odoo_webhook_token'] = '';

		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request->set_header( 'X-Odoo-Token', 'any-token' );

		$result = $this->handler->validate_webhook_token( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp4odoo_no_token', $result->get_error_code() );
	}

	public function test_validate_token_returns_error_when_no_header_sent(): void {
		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );

		$result = $this->handler->validate_webhook_token( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp4odoo_invalid_token', $result->get_error_code() );
	}

	// ─── handle_test ────────────────────────────────────────

	public function test_handle_test_returns_ok(): void {
		$request  = new \WP_REST_Request( 'GET', '/wp4odoo/v1/webhook/test' );
		$response = $this->handler->handle_test( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'ok', $data['status'] );
		$this->assertArrayHasKey( 'version', $data );
		$this->assertArrayHasKey( 'time', $data );
	}

	// ─── handle_webhook ─────────────────────────────────────

	public function test_handle_webhook_returns_400_for_missing_fields(): void {
		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request->set_json_params( [] );

		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
	}

	public function test_handle_webhook_returns_400_when_module_missing(): void {
		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request->set_json_params( [
			'entity_type' => 'contact',
			'odoo_id'     => 42,
		] );

		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_handle_webhook_returns_400_when_odoo_id_zero(): void {
		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request->set_json_params( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'odoo_id'     => 0,
		] );

		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_handle_webhook_enqueues_job_for_valid_payload(): void {
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

	public function test_handle_webhook_defaults_action_to_update(): void {
		$this->wpdb->insert_id = 1;

		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request->set_json_params( [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'odoo_id'     => 10,
		] );

		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 202, $response->get_status() );

		// Verify the enqueued job has action 'update' — filter for sync_queue inserts only.
		$queue_inserts = array_values(
			array_filter( $this->wpdb->calls, fn( $c ) =>
				$c['method'] === 'insert' && str_contains( $c['args'][0], 'sync_queue' )
			)
		);
		$this->assertNotEmpty( $queue_inserts );
		$this->assertSame( 'update', $queue_inserts[0]['args'][1]['action'] );
	}

	// ─── handle_sync_trigger ────────────────────────────────

	public function test_sync_trigger_returns_404_for_unknown_module(): void {
		\WP4Odoo_Plugin::reset_instance();

		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/sync/crm/contact' );
		$request->set_param( 'module', 'crm' );
		$request->set_param( 'entity', 'contact' );
		$request->set_json_params( [ 'odoo_id' => 42 ] );

		$response = $this->handler->handle_sync_trigger( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_sync_trigger_returns_400_without_ids(): void {
		\WP4Odoo_Plugin::reset_instance();

		// Register a dummy module to pass the module check.
		$module = $this->createMock( \WP4Odoo\Module_Base::class );
		\WP4Odoo_Plugin::instance()->register_module( 'crm', $module );

		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/sync/crm/contact' );
		$request->set_param( 'module', 'crm' );
		$request->set_param( 'entity', 'contact' );
		$request->set_json_params( [] );

		$response = $this->handler->handle_sync_trigger( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// ─── check_admin_permission ─────────────────────────────

	public function test_check_admin_permission_returns_true_for_admin(): void {
		$GLOBALS['_wp_current_user_can'] = true;

		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/sync/crm/contact' );
		$result  = $this->handler->check_admin_permission( $request );

		$this->assertTrue( $result );
	}

	public function test_check_admin_permission_returns_error_for_non_admin(): void {
		$GLOBALS['_wp_current_user_can'] = false;

		$request = new \WP_REST_Request( 'POST', '/wp4odoo/v1/sync/crm/contact' );
		$result  = $this->handler->check_admin_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp4odoo_forbidden', $result->get_error_code() );
	}

	// ─── ensure_webhook_token ───────────────────────────────

	public function test_register_routes_generates_token_when_none_exists(): void {
		unset( $GLOBALS['_wp_options']['wp4odoo_webhook_token'] );

		$handler = new Webhook_Handler( wp4odoo_test_settings() );
		$handler->register_routes();

		$token = $GLOBALS['_wp_options']['wp4odoo_webhook_token'] ?? '';
		$this->assertNotEmpty( $token );
		$this->assertSame( 48, strlen( $token ) );
	}

	public function test_register_routes_preserves_existing_token(): void {
		$GLOBALS['_wp_options']['wp4odoo_webhook_token'] = 'existing-token';

		$handler = new Webhook_Handler( wp4odoo_test_settings() );
		$handler->register_routes();

		$this->assertSame( 'existing-token', $GLOBALS['_wp_options']['wp4odoo_webhook_token'] );
	}

	// ─── Deduplication ─────────────────────────────────────

	public function test_handle_webhook_deduplicates_identical_payload(): void {
		$this->wpdb->insert_id = 99;

		$payload = [
			'module'      => 'crm',
			'entity_type' => 'contact',
			'odoo_id'     => 42,
			'action'      => 'update',
		];

		$request1 = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request1->set_json_params( $payload );

		$response1 = $this->handler->handle_webhook( $request1 );
		$this->assertSame( 202, $response1->get_status() );

		$request2 = new \WP_REST_Request( 'POST', '/wp4odoo/v1/webhook' );
		$request2->set_json_params( $payload );

		$response2 = $this->handler->handle_webhook( $request2 );
		$this->assertSame( 200, $response2->get_status() );

		$data = $response2->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['deduplicated'] );
	}
}
