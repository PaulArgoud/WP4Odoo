<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Admin\Settings_Page;
use WP4Odoo\API\Odoo_Auth;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Settings_Page sanitize methods.
 *
 * Tests the validation and sanitization logic for connection settings,
 * sync settings, and log settings.
 *
 * @package WP4Odoo\Tests\Unit
 * @since   1.3.0
 */
class SettingsPageTest extends TestCase {

	private Settings_Page $page;

	/**
	 * Set up test environment before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		global $wpdb;
		$wpdb                       = new \WP_DB_Stub();
		$GLOBALS['_wp_options']     = [];
		\WP4Odoo_Plugin::reset_instance();
		$this->page = new Settings_Page();
	}

	// ─── sanitize_connection tests ─────────────────────────────

	/**
	 * Test that sanitize_connection returns all expected keys.
	 *
	 * @return void
	 */
	public function test_sanitize_connection_returns_all_keys(): void {
		$input  = [
			'url'      => 'https://example.odoo.com',
			'database' => 'testdb',
			'username' => 'admin',
			'api_key'  => 'test-key-123',
			'protocol' => 'jsonrpc',
			'timeout'  => 30,
		];
		$result = $this->page->sanitize_connection( $input );

		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'database', $result );
		$this->assertArrayHasKey( 'username', $result );
		$this->assertArrayHasKey( 'api_key', $result );
		$this->assertArrayHasKey( 'protocol', $result );
		$this->assertArrayHasKey( 'timeout', $result );
	}

	/**
	 * Test that api_key is encrypted when provided.
	 *
	 * The encrypted value should be non-empty and different from the plaintext input.
	 *
	 * @return void
	 */
	public function test_sanitize_connection_encrypts_api_key(): void {
		$plaintext = 'my-secret-api-key';
		$input     = [
			'url'      => 'https://example.odoo.com',
			'database' => 'testdb',
			'username' => 'admin',
			'api_key'  => $plaintext,
			'protocol' => 'jsonrpc',
			'timeout'  => 30,
		];
		$result    = $this->page->sanitize_connection( $input );

		$this->assertNotEmpty( $result['api_key'] );
		$this->assertNotEquals( $plaintext, $result['api_key'] );
	}

	/**
	 * Test that existing api_key is preserved when input is empty.
	 *
	 * @return void
	 */
	public function test_sanitize_connection_preserves_existing_api_key(): void {
		$existing_encrypted = 'existing-encrypted-key';
		update_option(
			'wp4odoo_connection',
			[
				'url'      => 'https://example.odoo.com',
				'database' => 'testdb',
				'username' => 'admin',
				'api_key'  => $existing_encrypted,
				'protocol' => 'jsonrpc',
				'timeout'  => 30,
			]
		);

		$input  = [
			'url'      => 'https://example.odoo.com',
			'database' => 'testdb',
			'username' => 'admin',
			'api_key'  => '',
			'protocol' => 'jsonrpc',
			'timeout'  => 30,
		];
		$result = $this->page->sanitize_connection( $input );

		$this->assertEquals( $existing_encrypted, $result['api_key'] );
	}

	/**
	 * Test that protocol defaults to jsonrpc for invalid input.
	 *
	 * @return void
	 */
	public function test_sanitize_connection_defaults_protocol_to_jsonrpc(): void {
		$input  = [
			'url'      => 'https://example.odoo.com',
			'database' => 'testdb',
			'username' => 'admin',
			'api_key'  => 'test-key',
			'protocol' => 'invalid-protocol',
			'timeout'  => 30,
		];
		$result = $this->page->sanitize_connection( $input );

		$this->assertEquals( 'jsonrpc', $result['protocol'] );
	}

	/**
	 * Test that xmlrpc protocol is accepted.
	 *
	 * @return void
	 */
	public function test_sanitize_connection_accepts_xmlrpc_protocol(): void {
		$input  = [
			'url'      => 'https://example.odoo.com',
			'database' => 'testdb',
			'username' => 'admin',
			'api_key'  => 'test-key',
			'protocol' => 'xmlrpc',
			'timeout'  => 30,
		];
		$result = $this->page->sanitize_connection( $input );

		$this->assertEquals( 'xmlrpc', $result['protocol'] );
	}

	/**
	 * Test that timeout is clamped to minimum value of 5.
	 *
	 * @return void
	 */
	public function test_sanitize_connection_clamps_timeout_min(): void {
		$input  = [
			'url'      => 'https://example.odoo.com',
			'database' => 'testdb',
			'username' => 'admin',
			'api_key'  => 'test-key',
			'protocol' => 'jsonrpc',
			'timeout'  => 2,
		];
		$result = $this->page->sanitize_connection( $input );

		$this->assertEquals( 5, $result['timeout'] );
	}

	/**
	 * Test that timeout is clamped to maximum value of 120.
	 *
	 * @return void
	 */
	public function test_sanitize_connection_clamps_timeout_max(): void {
		$input  = [
			'url'      => 'https://example.odoo.com',
			'database' => 'testdb',
			'username' => 'admin',
			'api_key'  => 'test-key',
			'protocol' => 'jsonrpc',
			'timeout'  => 200,
		];
		$result = $this->page->sanitize_connection( $input );

		$this->assertEquals( 120, $result['timeout'] );
	}

	/**
	 * Test that default timeout is 30.
	 *
	 * @return void
	 */
	public function test_sanitize_connection_default_timeout(): void {
		$input  = [
			'url'      => 'https://example.odoo.com',
			'database' => 'testdb',
			'username' => 'admin',
			'api_key'  => 'test-key',
			'protocol' => 'jsonrpc',
		];
		$result = $this->page->sanitize_connection( $input );

		$this->assertEquals( 30, $result['timeout'] );
	}

	// ─── sanitize_sync_settings tests ──────────────────────────

	/**
	 * Test that sanitize_sync_settings returns all expected keys.
	 *
	 * @return void
	 */
	public function test_sanitize_sync_settings_returns_all_keys(): void {
		$input  = [
			'direction'     => 'bidirectional',
			'conflict_rule' => 'newest_wins',
			'batch_size'    => 50,
			'sync_interval' => 'wp4odoo_five_minutes',
			'auto_sync'     => true,
		];
		$result = $this->page->sanitize_sync_settings( $input );

		$this->assertArrayHasKey( 'direction', $result );
		$this->assertArrayHasKey( 'conflict_rule', $result );
		$this->assertArrayHasKey( 'batch_size', $result );
		$this->assertArrayHasKey( 'sync_interval', $result );
		$this->assertArrayHasKey( 'auto_sync', $result );
	}

	/**
	 * Test that direction defaults to bidirectional for invalid input.
	 *
	 * @return void
	 */
	public function test_sanitize_sync_settings_defaults_direction(): void {
		$input  = [
			'direction'     => 'invalid-direction',
			'conflict_rule' => 'newest_wins',
			'batch_size'    => 50,
			'sync_interval' => 'wp4odoo_five_minutes',
			'auto_sync'     => true,
		];
		$result = $this->page->sanitize_sync_settings( $input );

		$this->assertEquals( 'bidirectional', $result['direction'] );
	}

	/**
	 * Test that valid direction values are accepted.
	 *
	 * @return void
	 */
	public function test_sanitize_sync_settings_accepts_valid_directions(): void {
		$valid_directions = [ 'bidirectional', 'wp_to_odoo', 'odoo_to_wp' ];

		foreach ( $valid_directions as $direction ) {
			$input  = [
				'direction'     => $direction,
				'conflict_rule' => 'newest_wins',
				'batch_size'    => 50,
				'sync_interval' => 'wp4odoo_five_minutes',
				'auto_sync'     => true,
			];
			$result = $this->page->sanitize_sync_settings( $input );

			$this->assertEquals( $direction, $result['direction'], "Failed for direction: {$direction}" );
		}
	}

	/**
	 * Test that conflict_rule defaults to newest_wins for invalid input.
	 *
	 * @return void
	 */
	public function test_sanitize_sync_settings_defaults_conflict_rule(): void {
		$input  = [
			'direction'     => 'bidirectional',
			'conflict_rule' => 'invalid-rule',
			'batch_size'    => 50,
			'sync_interval' => 'wp4odoo_five_minutes',
			'auto_sync'     => true,
		];
		$result = $this->page->sanitize_sync_settings( $input );

		$this->assertEquals( 'newest_wins', $result['conflict_rule'] );
	}

	/**
	 * Test that batch_size is clamped to minimum value of 1.
	 *
	 * @return void
	 */
	public function test_sanitize_sync_settings_clamps_batch_size_min(): void {
		$input  = [
			'direction'     => 'bidirectional',
			'conflict_rule' => 'newest_wins',
			'batch_size'    => 0,
			'sync_interval' => 'wp4odoo_five_minutes',
			'auto_sync'     => true,
		];
		$result = $this->page->sanitize_sync_settings( $input );

		$this->assertEquals( 1, $result['batch_size'] );
	}

	/**
	 * Test that batch_size is clamped to maximum value of 500.
	 *
	 * @return void
	 */
	public function test_sanitize_sync_settings_clamps_batch_size_max(): void {
		$input  = [
			'direction'     => 'bidirectional',
			'conflict_rule' => 'newest_wins',
			'batch_size'    => 1000,
			'sync_interval' => 'wp4odoo_five_minutes',
			'auto_sync'     => true,
		];
		$result = $this->page->sanitize_sync_settings( $input );

		$this->assertEquals( 500, $result['batch_size'] );
	}

	/**
	 * Test that auto_sync is correctly converted to boolean.
	 *
	 * @return void
	 */
	public function test_sanitize_sync_settings_auto_sync_is_boolean(): void {
		$input_true  = [
			'direction'     => 'bidirectional',
			'conflict_rule' => 'newest_wins',
			'batch_size'    => 50,
			'sync_interval' => 'wp4odoo_five_minutes',
			'auto_sync'     => '1',
		];
		$result_true = $this->page->sanitize_sync_settings( $input_true );

		$this->assertTrue( $result_true['auto_sync'] );

		$input_false  = [
			'direction'     => 'bidirectional',
			'conflict_rule' => 'newest_wins',
			'batch_size'    => 50,
			'sync_interval' => 'wp4odoo_five_minutes',
			'auto_sync'     => '',
		];
		$result_false = $this->page->sanitize_sync_settings( $input_false );

		$this->assertFalse( $result_false['auto_sync'] );
	}

	// ─── sanitize_log_settings tests ───────────────────────────

	/**
	 * Test that level defaults to info for invalid input.
	 *
	 * @return void
	 */
	public function test_sanitize_log_settings_defaults_level_to_info(): void {
		$input  = [
			'enabled'        => true,
			'level'          => 'invalid-level',
			'retention_days' => 30,
		];
		$result = $this->page->sanitize_log_settings( $input );

		$this->assertEquals( 'info', $result['level'] );
	}

	/**
	 * Test that valid level values are accepted.
	 *
	 * @return void
	 */
	public function test_sanitize_log_settings_accepts_valid_levels(): void {
		$valid_levels = [ 'debug', 'info', 'warning', 'error', 'critical' ];

		foreach ( $valid_levels as $level ) {
			$input  = [
				'enabled'        => true,
				'level'          => $level,
				'retention_days' => 30,
			];
			$result = $this->page->sanitize_log_settings( $input );

			$this->assertEquals( $level, $result['level'], "Failed for level: {$level}" );
		}
	}

	/**
	 * Test that retention_days is clamped to minimum value of 1.
	 *
	 * @return void
	 */
	public function test_sanitize_log_settings_clamps_retention_days_min(): void {
		$input  = [
			'enabled'        => true,
			'level'          => 'info',
			'retention_days' => 0,
		];
		$result = $this->page->sanitize_log_settings( $input );

		$this->assertEquals( 1, $result['retention_days'] );
	}

	/**
	 * Test that retention_days is clamped to maximum value of 365.
	 *
	 * @return void
	 */
	public function test_sanitize_log_settings_clamps_retention_days_max(): void {
		$input  = [
			'enabled'        => true,
			'level'          => 'info',
			'retention_days' => 500,
		];
		$result = $this->page->sanitize_log_settings( $input );

		$this->assertEquals( 365, $result['retention_days'] );
	}

	/**
	 * Test that enabled is correctly converted to boolean.
	 *
	 * @return void
	 */
	public function test_sanitize_log_settings_enabled_is_boolean(): void {
		$input_true  = [
			'enabled'        => '1',
			'level'          => 'info',
			'retention_days' => 30,
		];
		$result_true = $this->page->sanitize_log_settings( $input_true );

		$this->assertTrue( $result_true['enabled'] );

		$input_false  = [
			'enabled'        => '',
			'level'          => 'info',
			'retention_days' => 30,
		];
		$result_false = $this->page->sanitize_log_settings( $input_false );

		$this->assertFalse( $result_false['enabled'] );
	}

}
