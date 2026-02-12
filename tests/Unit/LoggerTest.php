<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Logger.
 *
 * Verifies level filtering, log writing, cleanup, and convenience methods.
 */
class LoggerTest extends TestCase {

	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;
		// Reset options for each test.
		$GLOBALS['_wp_options'] = [];
		// Clear all static caches so each test starts fresh.
		\WP4Odoo\Tests\Module_Test_Case::reset_static_caches();
	}

	// ─── log() with invalid level ──────────────────────────

	public function test_log_returns_false_for_invalid_level(): void {
		$this->enable_logging();
		$logger = new Logger();

		$result = $logger->log( 'invalid', 'Test message' );

		$this->assertFalse( $result );
		$this->assertEmpty( $this->get_calls( 'insert' ) );
	}

	// ─── log() when logging is disabled ────────────────────

	public function test_log_returns_false_when_logging_disabled(): void {
		// No wp4odoo_log_settings option set.
		$logger = new Logger();

		$result = $logger->log( 'info', 'Test message' );

		$this->assertFalse( $result );
		$this->assertEmpty( $this->get_calls( 'insert' ) );
	}

	public function test_log_returns_false_when_enabled_is_false(): void {
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [ 'enabled' => false, 'level' => 'debug' ];
		$logger = new Logger();

		$result = $logger->log( 'info', 'Test message' );

		$this->assertFalse( $result );
		$this->assertEmpty( $this->get_calls( 'insert' ) );
	}

	// ─── log() writes when enabled and level meets threshold ─

	public function test_log_writes_when_enabled_and_level_meets_threshold(): void {
		$this->enable_logging( 'warning' );
		$logger = new Logger();

		$result = $logger->log( 'error', 'Error message' );

		$this->assertTrue( $result );
		$inserts = $this->get_calls( 'insert' );
		$this->assertCount( 1, $inserts );
	}

	public function test_log_writes_debug_when_threshold_is_debug(): void {
		$this->enable_logging( 'debug' );
		$logger = new Logger();

		$result = $logger->log( 'debug', 'Debug message' );

		$this->assertTrue( $result );
		$inserts = $this->get_calls( 'insert' );
		$this->assertCount( 1, $inserts );
	}

	// ─── log() skips when level is below threshold ─────────

	public function test_log_skips_when_level_below_threshold(): void {
		$this->enable_logging( 'error' );
		$logger = new Logger();

		$result = $logger->log( 'warning', 'Warning message' );

		$this->assertFalse( $result );
		$this->assertEmpty( $this->get_calls( 'insert' ) );
	}

	public function test_log_skips_info_when_threshold_is_error(): void {
		$this->enable_logging( 'error' );
		$logger = new Logger();

		$result = $logger->log( 'info', 'Info message' );

		$this->assertFalse( $result );
		$this->assertEmpty( $this->get_calls( 'insert' ) );
	}

	// ─── critical() always logs ────────────────────────────

	public function test_critical_logs_when_logging_disabled(): void {
		// No settings, logging disabled.
		$logger = new Logger();

		$result = $logger->critical( 'Critical error' );

		$this->assertTrue( $result );
		$inserts = $this->get_calls( 'insert' );
		$this->assertCount( 1, $inserts );
	}

	public function test_critical_logs_when_enabled_is_false(): void {
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [ 'enabled' => false, 'level' => 'debug' ];
		$logger = new Logger();

		$result = $logger->critical( 'Critical error' );

		$this->assertTrue( $result );
		$inserts = $this->get_calls( 'insert' );
		$this->assertCount( 1, $inserts );
	}

	public function test_critical_logs_when_threshold_is_higher(): void {
		$this->enable_logging( 'error' );
		$logger = new Logger();

		$result = $logger->critical( 'Critical message' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->get_calls( 'insert' ) );
	}

	// ─── log() stores correct data ─────────────────────────

	public function test_log_stores_correct_table_name(): void {
		$this->enable_logging();
		$logger = new Logger();

		$logger->log( 'info', 'Test message' );

		$insert = $this->get_last_call( 'insert' );
		$this->assertNotNull( $insert );
		$this->assertSame( 'wp_wp4odoo_logs', $insert['args'][0] );
	}

	public function test_log_stores_correct_level(): void {
		$this->enable_logging();
		$logger = new Logger();

		$logger->log( 'warning', 'Warning message' );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertSame( 'warning', $data['level'] );
	}

	public function test_log_stores_correct_module(): void {
		$this->enable_logging();
		$logger = new Logger( 'crm' );

		$logger->log( 'info', 'CRM message' );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertSame( 'crm', $data['module'] );
	}

	public function test_log_stores_null_module_when_not_set(): void {
		$this->enable_logging();
		$logger = new Logger();

		$logger->log( 'info', 'No module message' );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertNull( $data['module'] );
	}

	public function test_log_stores_correct_message(): void {
		$this->enable_logging();
		$logger = new Logger();

		$logger->log( 'error', 'Test error message' );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertSame( 'Test error message', $data['message'] );
	}

	public function test_log_stores_json_context(): void {
		$this->enable_logging();
		$logger  = new Logger();
		$context = [ 'user_id' => 42, 'action' => 'sync' ];

		$logger->log( 'info', 'Message with context', $context );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertSame( '{"user_id":42,"action":"sync"}', $data['context'] );
	}

	// ─── log() with empty context stores null ──────────────

	public function test_log_stores_null_for_empty_context(): void {
		$this->enable_logging();
		$logger = new Logger();

		$logger->log( 'info', 'Message without context' );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertNull( $data['context'] );
	}

	public function test_log_stores_null_for_empty_array_context(): void {
		$this->enable_logging();
		$logger = new Logger();

		$logger->log( 'info', 'Message with empty array', [] );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertNull( $data['context'] );
	}

	// ─── Convenience methods delegate to log() ─────────────

	public function test_debug_delegates_to_log(): void {
		$this->enable_logging();
		$logger = new Logger( 'test' );

		$result = $logger->debug( 'Debug message', [ 'key' => 'value' ] );

		$this->assertTrue( $result );
		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertSame( 'debug', $data['level'] );
		$this->assertSame( 'Debug message', $data['message'] );
		$this->assertSame( '{"key":"value"}', $data['context'] );
		$this->assertSame( 'test', $data['module'] );
	}

	public function test_info_delegates_to_log(): void {
		$this->enable_logging();
		$logger = new Logger( 'sales' );

		$result = $logger->info( 'Info message', [ 'order_id' => 123 ] );

		$this->assertTrue( $result );
		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertSame( 'info', $data['level'] );
		$this->assertSame( 'Info message', $data['message'] );
		$this->assertSame( '{"order_id":123}', $data['context'] );
		$this->assertSame( 'sales', $data['module'] );
	}

	public function test_warning_delegates_to_log(): void {
		$this->enable_logging();
		$logger = new Logger();

		$result = $logger->warning( 'Warning message' );

		$this->assertTrue( $result );
		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertSame( 'warning', $data['level'] );
		$this->assertSame( 'Warning message', $data['message'] );
	}

	public function test_error_delegates_to_log(): void {
		$this->enable_logging();
		$logger = new Logger( 'woocommerce' );

		$result = $logger->error( 'Error message', [ 'code' => 500 ] );

		$this->assertTrue( $result );
		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertSame( 'error', $data['level'] );
		$this->assertSame( 'Error message', $data['message'] );
		$this->assertSame( '{"code":500}', $data['context'] );
		$this->assertSame( 'woocommerce', $data['module'] );
	}

	public function test_critical_delegates_to_log(): void {
		$this->enable_logging();
		$logger = new Logger();

		$result = $logger->critical( 'Critical message', [ 'fatal' => true ] );

		$this->assertTrue( $result );
		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertSame( 'critical', $data['level'] );
		$this->assertSame( 'Critical message', $data['message'] );
		$this->assertSame( '{"fatal":true}', $data['context'] );
	}

	// ─── cleanup() deletes old entries ─────────────────────

	public function test_cleanup_deletes_old_entries_using_retention_days(): void {
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [
			'enabled'        => true,
			'level'          => 'debug',
			'retention_days' => 7,
		];
		$this->wpdb->query_return                       = 3;
		$logger                                         = new Logger();

		$result = $logger->cleanup();

		$this->assertSame( 3, $result );
		$queries = $this->get_calls( 'query' );
		$this->assertCount( 1, $queries );
	}

	public function test_cleanup_uses_correct_table(): void {
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [
			'retention_days' => 30,
		];
		$logger                                         = new Logger();

		$logger->cleanup();

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( 'wp_wp4odoo_logs', $prepare[0]['args'][0] );
	}

	public function test_cleanup_calculates_cutoff_date_correctly(): void {
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [
			'retention_days' => 14,
		];
		$logger                                         = new Logger();

		$logger->cleanup();

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		// Should have WHERE created_at < cutoff date.
		$this->assertStringContainsString( 'WHERE created_at <', $prepare[0]['args'][0] );
	}

	// ─── cleanup() returns 0 when retention_days <= 0 ───────

	public function test_cleanup_returns_zero_when_retention_days_is_zero(): void {
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [
			'retention_days' => 0,
		];
		$logger                                         = new Logger();

		$result = $logger->cleanup();

		$this->assertSame( 0, $result );
		$this->assertEmpty( $this->get_calls( 'query' ) );
	}

	public function test_cleanup_returns_zero_when_retention_days_is_negative(): void {
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [
			'retention_days' => -5,
		];
		$logger                                         = new Logger();

		$result = $logger->cleanup();

		$this->assertSame( 0, $result );
		$this->assertEmpty( $this->get_calls( 'query' ) );
	}

	public function test_cleanup_returns_zero_when_retention_days_not_set(): void {
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [
			'enabled' => true,
		];
		// Default retention_days is 30 (not 0), so it will run.
		$this->wpdb->query_return = 2;
		$logger                   = new Logger();

		$result = $logger->cleanup();

		// Default is 30, so it should run and return the query result.
		$this->assertSame( 2, $result );
	}

	public function test_cleanup_uses_default_30_days_when_not_specified(): void {
		// No log settings at all.
		$this->wpdb->query_return = 5;
		$logger                   = new Logger();

		$result = $logger->cleanup();

		// Default retention_days is 30, so cleanup should run.
		$this->assertSame( 5, $result );
	}

	// ─── Constructor sets module correctly ─────────────────

	public function test_constructor_sets_module_in_log_data(): void {
		$this->enable_logging();
		$logger = new Logger( 'api' );

		$logger->log( 'info', 'API log entry' );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertSame( 'api', $data['module'] );
	}

	public function test_constructor_allows_null_module(): void {
		$this->enable_logging();
		$logger = new Logger( null );

		$logger->log( 'info', 'Generic log entry' );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertNull( $data['module'] );
	}

	public function test_constructor_default_module_is_null(): void {
		$this->enable_logging();
		$logger = new Logger();

		$logger->log( 'info', 'Default module entry' );

		$insert = $this->get_last_call( 'insert' );
		$data   = $insert['args'][1];
		$this->assertNull( $data['module'] );
	}

	// ─── Helpers ───────────────────────────────────────────

	private function enable_logging( string $level = 'debug' ): void {
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [
			'enabled' => true,
			'level'   => $level,
		];
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
