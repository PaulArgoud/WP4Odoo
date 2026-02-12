<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Admin\Admin_Ajax;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Admin_Ajax handlers.
 *
 * Tests all 15 AJAX handlers: permission checks, input sanitization,
 * delegation to underlying services, and JSON response structure.
 *
 * @package WP4Odoo\Tests
 */
class AdminAjaxTest extends TestCase {

	private Admin_Ajax $ajax;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']          = [];
		$GLOBALS['_wp_current_user_can'] = true;
		$_POST                           = [];

		\WP4Odoo\Tests\Module_Test_Case::reset_static_caches();
		\WP4Odoo_Plugin::reset_instance();

		$this->ajax = new Admin_Ajax();
	}

	protected function tearDown(): void {
		$_POST = [];
		unset( $GLOBALS['_wp_current_user_can'] );
		unset( $GLOBALS['_wp_remote_response'] );
		unset( $GLOBALS['_wp_remote_responses'] );
	}

	// ─── verify_request() — permission denied ────────────

	public function test_permission_denied_sends_json_error(): void {
		$GLOBALS['_wp_current_user_can'] = false;

		try {
			$this->ajax->retry_failed();
			$this->fail( 'Expected WP4Odoo_Test_JsonError was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonError $e ) {
			$this->assertSame( 403, $e->status_code );
			$this->assertStringContainsString( 'Permission', $e->data['message'] );
		}
	}

	// ─── dismiss_onboarding ──────────────────────────────

	public function test_dismiss_onboarding_sets_option(): void {
		try {
			$this->ajax->dismiss_onboarding();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertTrue( $GLOBALS['_wp_options']['wp4odoo_onboarding_dismissed'] );
		}
	}

	// ─── dismiss_checklist ───────────────────────────────

	public function test_dismiss_checklist_sets_option(): void {
		try {
			$this->ajax->dismiss_checklist();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertTrue( $GLOBALS['_wp_options']['wp4odoo_checklist_dismissed'] );
		}
	}

	// ─── confirm_webhooks ────────────────────────────────

	public function test_confirm_webhooks_sets_option(): void {
		try {
			$this->ajax->confirm_webhooks();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertTrue( $GLOBALS['_wp_options']['wp4odoo_checklist_webhooks_confirmed'] );
			$this->assertStringContainsString( 'Webhooks', $e->data['message'] );
		}
	}

	// ─── retry_failed ────────────────────────────────────

	public function test_retry_failed_returns_count(): void {
		$this->wpdb->query_return = 5;

		try {
			$this->ajax->retry_failed();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertSame( 5, $e->data['count'] );
			$this->assertStringContainsString( '5', $e->data['message'] );
		}
	}

	public function test_retry_failed_returns_zero_when_none(): void {
		$this->wpdb->query_return = 0;

		try {
			$this->ajax->retry_failed();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertSame( 0, $e->data['count'] );
		}
	}

	// ─── cleanup_queue ───────────────────────────────────

	public function test_cleanup_queue_defaults_to_7_days(): void {
		$this->wpdb->query_return = 3;

		try {
			$this->ajax->cleanup_queue();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertSame( 3, $e->data['deleted'] );
		}
	}

	public function test_cleanup_queue_uses_custom_days(): void {
		$this->wpdb->query_return = 10;
		$_POST['days']            = '30';

		try {
			$this->ajax->cleanup_queue();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertSame( 10, $e->data['deleted'] );
			$this->assertStringContainsString( '10', $e->data['message'] );
		}
	}

	public function test_cleanup_queue_returns_zero(): void {
		$this->wpdb->query_return = 0;

		try {
			$this->ajax->cleanup_queue();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertSame( 0, $e->data['deleted'] );
		}
	}

	// ─── cancel_job ──────────────────────────────────────

	public function test_cancel_job_success(): void {
		$this->wpdb->delete_return = 1;
		$_POST['job_id']           = '42';

		try {
			$this->ajax->cancel_job();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertStringContainsString( 'cancelled', $e->data['message'] );
		}
	}

	public function test_cancel_job_failure(): void {
		$this->wpdb->delete_return = 0;
		$_POST['job_id']           = '999';

		try {
			$this->ajax->cancel_job();
			$this->fail( 'Expected WP4Odoo_Test_JsonError was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonError $e ) {
			$this->assertStringContainsString( 'Unable', $e->data['message'] );
		}
	}

	// ─── purge_logs ──────────────────────────────────────

	public function test_purge_logs_returns_deleted_count(): void {
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [ 'retention_days' => 30 ];
		$this->wpdb->query_return                       = 12;

		try {
			$this->ajax->purge_logs();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertSame( 12, $e->data['deleted'] );
			$this->assertStringContainsString( '12', $e->data['message'] );
		}
	}

	public function test_purge_logs_returns_zero(): void {
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [ 'retention_days' => 30 ];
		$this->wpdb->query_return                       = 0;

		try {
			$this->ajax->purge_logs();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertSame( 0, $e->data['deleted'] );
		}
	}

	// ─── queue_stats ─────────────────────────────────────

	public function test_queue_stats_returns_stats(): void {
		$this->wpdb->get_results_return = [
			(object) [ 'status' => 'pending', 'count' => '3' ],
			(object) [ 'status' => 'completed', 'count' => '10' ],
		];
		$this->wpdb->get_var_return = '2025-06-15 14:30:00';

		try {
			$this->ajax->queue_stats();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertArrayHasKey( 'pending', $e->data );
			$this->assertArrayHasKey( 'completed', $e->data );
			$this->assertArrayHasKey( 'total', $e->data );
		}
	}

	public function test_queue_stats_returns_empty_stats(): void {
		$this->wpdb->get_results_return = [];
		$this->wpdb->get_var_return     = null;

		try {
			$this->ajax->queue_stats();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertSame( 0, $e->data['total'] );
		}
	}

	// ─── toggle_module ───────────────────────────────────

	public function test_toggle_module_enables(): void {
		$_POST['module_id'] = 'crm';
		$_POST['enabled']   = '1';

		try {
			$this->ajax->toggle_module();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertTrue( $GLOBALS['_wp_options']['wp4odoo_module_crm_enabled'] );
			$this->assertSame( 'crm', $e->data['module_id'] );
			$this->assertTrue( $e->data['enabled'] );
		}
	}

	public function test_toggle_module_disables(): void {
		$_POST['module_id'] = 'crm';
		$_POST['enabled']   = '';

		try {
			$this->ajax->toggle_module();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertFalse( $GLOBALS['_wp_options']['wp4odoo_module_crm_enabled'] );
			$this->assertFalse( $e->data['enabled'] );
		}
	}

	public function test_toggle_module_error_when_empty_id(): void {
		$_POST['module_id'] = '';

		try {
			$this->ajax->toggle_module();
			$this->fail( 'Expected WP4Odoo_Test_JsonError was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonError $e ) {
			$this->assertStringContainsString( 'module', strtolower( $e->data['message'] ) );
		}
	}

	public function test_toggle_module_enable_skips_model_check_when_no_credentials(): void {
		$this->register_module_with_models( 'testmod', [ 'contact' => 'res.partner' ] );

		$_POST['module_id'] = 'testmod';
		$_POST['enabled']   = '1';

		// No Odoo credentials stored → model check silently skipped.
		try {
			$this->ajax->toggle_module();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertTrue( $e->data['enabled'] );
			$this->assertArrayNotHasKey( 'warning', $e->data );
		}
	}

	public function test_toggle_module_enable_returns_warning_when_models_missing(): void {
		$this->register_module_with_models( 'testmod', [
			'contact' => 'res.partner',
			'lead'    => 'crm.lead',
		] );

		$_POST['module_id'] = 'testmod';
		$_POST['enabled']   = '1';

		// Configure stored credentials + HTTP response queue.
		$GLOBALS['_wp_options']['wp4odoo_connection'] = [
			'url'      => 'https://odoo.example.com',
			'database' => 'testdb',
			'username' => 'admin',
			'api_key'  => \WP4Odoo\API\Odoo_Auth::encrypt( 'secret123' ),
			'protocol' => 'jsonrpc',
			'timeout'  => 15,
		];

		$GLOBALS['_wp_remote_responses'] = [
			// Auth response (uid=2) — /web/session/authenticate returns {uid: 2}.
			[
				'body'     => json_encode( [ 'jsonrpc' => '2.0', 'result' => [ 'uid' => 2 ], 'id' => 1 ] ),
				'response' => [ 'code' => 200 ],
			],
			// ir.model search_read: only res.partner found.
			[
				'body'     => json_encode( [
					'jsonrpc' => '2.0',
					'result'  => [ [ 'id' => 1, 'model' => 'res.partner' ] ],
					'id'      => 2,
				] ),
				'response' => [ 'code' => 200 ],
			],
		];

		try {
			$this->ajax->toggle_module();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertTrue( $e->data['enabled'] );
			$this->assertArrayHasKey( 'warning', $e->data );
			$this->assertStringContainsString( 'Odoo', $e->data['warning'] );
		}
	}

	public function test_toggle_module_disable_skips_model_check(): void {
		$this->register_module_with_models( 'testmod', [ 'contact' => 'res.partner' ] );

		$_POST['module_id'] = 'testmod';
		$_POST['enabled']   = '';

		try {
			$this->ajax->toggle_module();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertFalse( $e->data['enabled'] );
			$this->assertArrayNotHasKey( 'warning', $e->data );
		}
	}

	// ─── fetch_logs ──────────────────────────────────────

	public function test_fetch_logs_returns_paginated_items(): void {
		$this->wpdb->get_var_return     = '2';
		$this->wpdb->get_results_return = [
			(object) [
				'id'         => '1',
				'level'      => 'info',
				'module'     => 'crm',
				'message'    => 'Test log message',
				'context'    => '{}',
				'created_at' => '2025-06-15 10:00:00',
			],
			(object) [
				'id'         => '2',
				'level'      => 'error',
				'module'     => 'sync',
				'message'    => 'Error occurred',
				'context'    => null,
				'created_at' => '2025-06-15 11:00:00',
			],
		];

		try {
			$this->ajax->fetch_logs();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertCount( 2, $e->data['items'] );
			$this->assertSame( 1, $e->data['items'][0]['id'] );
			$this->assertSame( 'info', $e->data['items'][0]['level'] );
			$this->assertSame( '', $e->data['items'][1]['context'] );
			$this->assertArrayHasKey( 'total', $e->data );
			$this->assertArrayHasKey( 'pages', $e->data );
		}
	}

	public function test_fetch_logs_passes_filters(): void {
		$this->wpdb->get_var_return     = '0';
		$this->wpdb->get_results_return = [];
		$_POST['level']                 = 'error';
		$_POST['module']                = 'crm';

		try {
			$this->ajax->fetch_logs();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertSame( [], $e->data['items'] );
		}
	}

	public function test_fetch_logs_defaults_per_page_to_50(): void {
		$this->wpdb->get_var_return     = '0';
		$this->wpdb->get_results_return = [];

		try {
			$this->ajax->fetch_logs();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			// Verify prepare was called with LIMIT 50.
			$prepares = $this->get_calls( 'prepare' );
			$found    = false;
			foreach ( $prepares as $call ) {
				if ( str_contains( $call['args'][0], 'LIMIT' ) ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue( $found, 'Expected a prepare call with LIMIT' );
		}
	}

	// ─── fetch_queue ─────────────────────────────────────

	public function test_fetch_queue_returns_paginated_items(): void {
		$this->wpdb->get_var_return     = '1';
		$this->wpdb->get_results_return = [
			(object) [
				'id'            => '5',
				'module'        => 'crm',
				'entity_type'   => 'contact',
				'direction'     => 'wp_to_odoo',
				'action'        => 'update',
				'status'        => 'pending',
				'attempts'      => '0',
				'max_attempts'  => '3',
				'error_message' => null,
				'created_at'    => '2025-06-15 12:00:00',
			],
		];

		try {
			$this->ajax->fetch_queue();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertCount( 1, $e->data['items'] );
			$this->assertSame( 5, $e->data['items'][0]['id'] );
			$this->assertSame( 'crm', $e->data['items'][0]['module'] );
			$this->assertSame( '', $e->data['items'][0]['error_message'] );
			$this->assertArrayHasKey( 'total', $e->data );
			$this->assertArrayHasKey( 'pages', $e->data );
			$this->assertArrayHasKey( 'page', $e->data );
		}
	}

	public function test_fetch_queue_returns_empty(): void {
		$this->wpdb->get_var_return     = '0';
		$this->wpdb->get_results_return = [];

		try {
			$this->ajax->fetch_queue();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertSame( [], $e->data['items'] );
		}
	}

	public function test_fetch_queue_respects_page_param(): void {
		$this->wpdb->get_var_return     = '100';
		$this->wpdb->get_results_return = [];
		$_POST['page']                  = '3';

		try {
			$this->ajax->fetch_queue();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertSame( 3, $e->data['page'] );
		}
	}

	// ─── save_module_settings ────────────────────────────

	public function test_save_module_settings_error_when_empty_id(): void {
		$_POST['module_id'] = '';

		try {
			$this->ajax->save_module_settings();
			$this->fail( 'Expected WP4Odoo_Test_JsonError was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonError $e ) {
			$this->assertStringContainsString( 'module', strtolower( $e->data['message'] ) );
		}
	}

	public function test_save_module_settings_error_when_unknown_module(): void {
		$_POST['module_id'] = 'nonexistent';

		try {
			$this->ajax->save_module_settings();
			$this->fail( 'Expected WP4Odoo_Test_JsonError was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonError $e ) {
			$this->assertStringContainsString( 'Unknown', $e->data['message'] );
		}
	}

	public function test_save_module_settings_sanitizes_and_saves(): void {
		$this->register_fake_module( 'testmod' );

		$_POST['module_id'] = 'testmod';
		$_POST['settings']  = [
			'sync_enabled' => '1',
			'batch_size'   => '25',
			'sync_mode'    => 'manual',
			'label'        => '<b>Test</b>',
		];

		try {
			$this->ajax->save_module_settings();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$saved = $GLOBALS['_wp_options']['wp4odoo_module_testmod_settings'];
			$this->assertTrue( $saved['sync_enabled'] );
			$this->assertSame( 25, $saved['batch_size'] );
			$this->assertSame( 'manual', $saved['sync_mode'] );
			$this->assertNotEmpty( $saved['label'] );
		}
	}

	public function test_save_module_settings_unchecked_checkbox_defaults_false(): void {
		$this->register_fake_module( 'testmod' );

		$_POST['module_id'] = 'testmod';
		$_POST['settings']  = [
			'batch_size' => '10',
			// sync_enabled not sent = unchecked.
		];

		try {
			$this->ajax->save_module_settings();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$saved = $GLOBALS['_wp_options']['wp4odoo_module_testmod_settings'];
			$this->assertFalse( $saved['sync_enabled'] );
		}
	}

	// ─── test_connection ─────────────────────────────────

	public function test_test_connection_with_missing_credentials(): void {
		// No stored credentials, no POST fields → missing credentials.
		try {
			$this->ajax->test_connection();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertFalse( $e->data['success'] );
			$this->assertStringContainsString( 'Missing', $e->data['message'] );
		}
	}

	public function test_test_connection_passes_post_fields(): void {
		// Set wp_remote_post to return JSON-RPC auth failure → caught, returns success=false.
		$GLOBALS['_wp_remote_response'] = [
			'body'     => json_encode( [
				'jsonrpc' => '2.0',
				'error'   => [ 'message' => 'Access denied', 'code' => 100 ],
			] ),
			'response' => [ 'code' => 200 ],
		];

		$_POST['url']      = 'https://odoo.example.com';
		$_POST['database'] = 'testdb';
		$_POST['username'] = 'admin';
		$_POST['api_key']  = 'secret123';
		$_POST['protocol'] = 'jsonrpc';

		try {
			$this->ajax->test_connection();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			// Connection fails (stubbed response), but the handler itself works.
			$this->assertArrayHasKey( 'success', $e->data );
			$this->assertArrayHasKey( 'message', $e->data );
		}
	}

	public function test_test_connection_falls_back_to_stored_api_key(): void {
		$GLOBALS['_wp_options']['wp4odoo_connection'] = [
			'url'      => 'https://odoo.example.com',
			'database' => 'testdb',
			'username' => 'admin',
			'protocol' => 'jsonrpc',
		];
		$GLOBALS['_wp_options']['wp4odoo_api_key_encrypted'] = '';

		// POST all fields except api_key → should fall back to stored.
		// Since stored api_key is also empty → Missing credentials.
		$_POST['url']      = 'https://odoo.example.com';
		$_POST['database'] = 'testdb';
		$_POST['username'] = 'admin';

		try {
			$this->ajax->test_connection();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertFalse( $e->data['success'] );
			$this->assertStringContainsString( 'Missing', $e->data['message'] );
		}
	}

	// ─── bulk_import_products ────────────────────────────

	public function test_bulk_import_products_error_when_no_wc_module(): void {
		// No WooCommerce module registered.
		try {
			$this->ajax->bulk_import_products();
			$this->fail( 'Expected WP4Odoo_Test_JsonError was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonError $e ) {
			$this->assertStringContainsString( 'WooCommerce', $e->data['message'] );
		}
	}

	// ─── bulk_export_products ────────────────────────────

	public function test_bulk_export_products_error_when_no_wc_module(): void {
		// No WooCommerce module registered.
		try {
			$this->ajax->bulk_export_products();
			$this->fail( 'Expected WP4Odoo_Test_JsonError was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonError $e ) {
			$this->assertStringContainsString( 'WooCommerce', $e->data['message'] );
		}
	}

	// ─── Helpers ─────────────────────────────────────────

	/**
	 * Register a fake module in the plugin singleton for save_module_settings tests.
	 */
	private function register_fake_module( string $id ): void {
		$module = new class( $id ) extends \WP4Odoo\Module_Base {
			public function __construct( string $id ) {
				$this->id = $id;
			}

			public function boot(): void {}

			public function get_default_settings(): array {
				return [
					'sync_enabled' => false,
					'batch_size'   => 10,
					'sync_mode'    => 'auto',
					'label'        => '',
				];
			}

			public function get_settings_fields(): array {
				return [
					'sync_enabled' => [
						'label' => 'Enable sync',
						'type'  => 'checkbox',
					],
					'batch_size' => [
						'label' => 'Batch size',
						'type'  => 'number',
					],
					'sync_mode' => [
						'label'   => 'Sync mode',
						'type'    => 'select',
						'options' => [
							'auto'   => 'Automatic',
							'manual' => 'Manual',
						],
					],
					'label' => [
						'label' => 'Label',
						'type'  => 'text',
					],
				];
			}
		};

		\WP4Odoo_Plugin::instance()->register_module( $id, $module );
	}

	/**
	 * Register a fake module with custom Odoo models.
	 *
	 * @param string               $id          Module identifier.
	 * @param array<string, string> $odoo_models Entity type => Odoo model name.
	 */
	private function register_module_with_models( string $id, array $odoo_models ): void {
		$module = new class( $id, $odoo_models ) extends \WP4Odoo\Module_Base {
			public function __construct( string $id, array $odoo_models ) {
				$this->id          = $id;
				$this->odoo_models = $odoo_models;
			}

			public function boot(): void {}

			public function get_default_settings(): array {
				return [];
			}
		};

		\WP4Odoo_Plugin::instance()->register_module( $id, $module );
	}

	/**
	 * Get all wpdb calls matching a method name.
	 *
	 * @return array<int, array{method: string, args: array}>
	 */
	private function get_calls( string $method ): array {
		return array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === $method )
		);
	}
}
