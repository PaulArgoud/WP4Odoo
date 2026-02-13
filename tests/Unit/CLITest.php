<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\CLI;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CLI class.
 *
 * @package WP4Odoo\Tests\Unit
 */
class CLITest extends TestCase {

	private CLI $cli;
	private \WP_DB_Stub $wpdb;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		global $wpdb;
		$this->wpdb             = new \WP_DB_Stub();
		$wpdb                   = $this->wpdb;
		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		\WP4Odoo\Tests\Module_Test_Case::reset_static_caches();
		\WP4Odoo_Plugin::reset_instance();
		\WP_CLI::reset();
		$this->cli = new CLI();
	}

	/**
	 * Helper: get all WP_CLI calls for a specific method.
	 *
	 * @param string $method Method name to filter by.
	 * @return array Filtered calls.
	 */
	private function get_cli_calls( string $method ): array {
		return array_values(
			array_filter( \WP_CLI::$calls, fn( $c ) => $c['method'] === $method )
		);
	}

	/**
	 * Test that status command outputs the plugin version.
	 */
	public function test_status_outputs_version(): void {
		$this->cli->status();

		$line_calls = $this->get_cli_calls( 'line' );
		$this->assertNotEmpty( $line_calls, 'status() should call WP_CLI::line()' );

		// Find the line containing the version.
		$found = false;
		foreach ( $line_calls as $call ) {
			if ( isset( $call['args'][0] ) && str_contains( (string) $call['args'][0], WP4ODOO_VERSION ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'status() should output the plugin version via WP_CLI::line()' );
	}

	/**
	 * Test that status shows warning when not configured.
	 */
	public function test_status_shows_warning_when_not_configured(): void {
		// No credentials stored — connection settings empty.
		$this->cli->status();

		$warning_calls = $this->get_cli_calls( 'warning' );
		$this->assertNotEmpty( $warning_calls, 'status() should call WP_CLI::warning() when not configured' );
	}

	/**
	 * Test that status shows success when configured.
	 */
	public function test_status_shows_success_when_configured(): void {
		// Store credentials via options.
		$GLOBALS['_wp_options']['wp4odoo_connection'] = [
			'url'      => 'https://odoo.test',
			'database' => 'test',
			'protocol' => 'jsonrpc',
		];

		$this->cli->status();

		$success_calls = $this->get_cli_calls( 'success' );
		$this->assertNotEmpty( $success_calls, 'status() should call WP_CLI::success() when configured' );
	}

	/**
	 * Test that test command shows error when no URL configured.
	 */
	public function test_test_shows_error_when_no_url(): void {
		// No credentials stored.
		$this->cli->test();

		$error_calls = $this->get_cli_calls( 'error' );
		$this->assertNotEmpty( $error_calls, 'test() should call WP_CLI::error() when no URL configured' );
	}

	/**
	 * Test that sync run processes the queue.
	 */
	public function test_sync_run_processes_queue(): void {
		// Set wpdb to return 0 for lock acquisition (lock fails, returns 0 processed).
		$this->wpdb->get_var_return = '0';

		$this->cli->sync( [ 'run' ] );

		$success_calls = $this->get_cli_calls( 'success' );
		$this->assertNotEmpty( $success_calls, 'sync(["run"]) should call WP_CLI::success()' );
		$this->assertStringContainsString( '0 job(s) processed', $success_calls[0]['args'][0] );
	}

	/**
	 * Test that sync with unknown subcommand shows error.
	 */
	public function test_sync_with_unknown_subcommand_shows_error(): void {
		$this->cli->sync( [ 'unknown' ] );

		$error_calls = $this->get_cli_calls( 'error' );
		$this->assertNotEmpty( $error_calls, 'sync(["unknown"]) should call WP_CLI::error()' );
	}

	/**
	 * Test that sync run with --dry-run flag outputs dry-run message.
	 */
	public function test_sync_run_with_dry_run_flag(): void {
		$this->wpdb->get_var_return = '0';

		$this->cli->sync( [ 'run' ], [ 'dry-run' => true ] );

		$success_calls = $this->get_cli_calls( 'success' );
		$this->assertNotEmpty( $success_calls );
		$this->assertStringContainsString( 'dry-run', $success_calls[0]['args'][0] );
	}

	/**
	 * Test that queue stats works without error.
	 */
	public function test_queue_stats_works(): void {
		$this->cli->queue( [ 'stats' ], [] );

		// Verify no error was thrown — format_items is a no-op in tests.
		$error_calls = $this->get_cli_calls( 'error' );
		$this->assertEmpty( $error_calls, 'queue(["stats"], []) should not call WP_CLI::error()' );
	}

	/**
	 * Test that queue list shows message when no jobs found.
	 */
	public function test_queue_list_works_with_empty_results(): void {
		// Query_Service::get_queue_jobs() returns empty items.
		$this->wpdb->get_var_return     = '0'; // Total count = 0.
		$this->wpdb->get_results_return = [];

		$this->cli->queue( [ 'list' ], [] );

		$line_calls = $this->get_cli_calls( 'line' );
		$found      = false;
		foreach ( $line_calls as $call ) {
			if ( isset( $call['args'][0] ) && str_contains( (string) $call['args'][0], 'No jobs found' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'queue(["list"], []) should call WP_CLI::line() with "No jobs found."' );
	}

	/**
	 * Test that queue retry works.
	 */
	public function test_queue_retry_works(): void {
		// Sync_Engine::retry_failed() uses $wpdb->update().
		$this->wpdb->get_var_return = '0'; // No failed jobs found.

		$this->cli->queue( [ 'retry' ], [] );

		$success_calls = $this->get_cli_calls( 'success' );
		$this->assertNotEmpty( $success_calls, 'queue(["retry"], []) should call WP_CLI::success()' );
	}

	/**
	 * Test that queue cancel without ID shows error.
	 */
	public function test_queue_cancel_without_id_shows_error(): void {
		// $args[1] is not set, so job_id = 0 → error.
		$this->cli->queue( [ 'cancel' ], [] );

		$error_calls = $this->get_cli_calls( 'error' );
		$this->assertNotEmpty( $error_calls, 'queue(["cancel"], []) should call WP_CLI::error() when no ID provided' );
	}

	/**
	 * Test that queue with unknown subcommand shows error.
	 */
	public function test_queue_unknown_subcommand_shows_error(): void {
		$this->cli->queue( [ 'unknown' ], [] );

		$error_calls = $this->get_cli_calls( 'error' );
		$this->assertNotEmpty( $error_calls, 'queue(["unknown"], []) should call WP_CLI::error()' );
	}

	/**
	 * Test that module list shows message when no modules registered.
	 */
	public function test_module_list_shows_no_modules_message(): void {
		// No modules registered — WP4Odoo_Plugin::instance()->get_modules() returns empty array.
		$this->cli->module( [ 'list' ] );

		$line_calls = $this->get_cli_calls( 'line' );
		$found      = false;
		foreach ( $line_calls as $call ) {
			if ( isset( $call['args'][0] ) && str_contains( (string) $call['args'][0], 'No modules registered' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'module(["list"]) should call WP_CLI::line() with "No modules registered."' );
	}

	/**
	 * Test that module enable without ID shows error.
	 */
	public function test_module_enable_without_id_shows_error(): void {
		$this->cli->module( [ 'enable' ] );

		$error_calls = $this->get_cli_calls( 'error' );
		$this->assertNotEmpty( $error_calls, 'module(["enable"]) should call WP_CLI::error() when no ID provided' );
	}

	/**
	 * Test that module enable with unknown module ID shows error.
	 */
	public function test_module_enable_unknown_module_shows_error(): void {
		$this->cli->module( [ 'enable', 'nonexistent' ] );

		$error_calls = $this->get_cli_calls( 'error' );
		$this->assertNotEmpty( $error_calls, 'module(["enable", "nonexistent"]) should call WP_CLI::error()' );
	}

	/**
	 * Test that module with unknown subcommand shows error.
	 */
	public function test_module_unknown_subcommand_shows_error(): void {
		$this->cli->module( [ 'unknown' ] );

		$error_calls = $this->get_cli_calls( 'error' );
		$this->assertNotEmpty( $error_calls, 'module(["unknown"]) should call WP_CLI::error()' );
	}
}
