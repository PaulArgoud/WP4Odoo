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

	// ─── Command declarations ─────────────────────────────

	/**
	 * WP-CLI validates arguments against the docblock synopsis: a command with an
	 * OPTIONS block that does not declare its positional argument fails with
	 * "Too many positional arguments" (this broke `sync run` and `cleanup orphans`).
	 */
	public function test_commands_with_an_options_block_declare_their_subcommand(): void {
		$checked = 0;
		foreach ( ( new \ReflectionClass( CLI::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			$doc = (string) $method->getDocComment();
			if ( ! str_contains( $doc, '@subcommand' ) || ! str_contains( $doc, '## OPTIONS' ) ) {
				continue;
			}

			$source = implode( '', array_slice( (array) file( (string) $method->getFileName() ), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
			if ( ! str_contains( $source, '$args' ) || ! str_contains( $source, '$args[' ) && ! preg_match( '/\(\s*\$args\b/', $source ) ) {
				continue; // The method does not read positional arguments.
			}

			++$checked;
			$this->assertMatchesRegularExpression( '/^\s*\*\s*\[?<[a-z_]+>\]?\s*$/m', $doc, $method->getName() . '() reads positional arguments but its docblock declares none.' );
		}

		$this->assertGreaterThanOrEqual( 4, $checked, 'sync, cleanup, logs and connection read a subcommand.' );
	}

	public function test_every_documented_command_exists(): void {
		foreach ( [ 'status', 'test', 'sync', 'queue', 'reconcile', 'cleanup', 'cache', 'module', 'health', 'db', 'breaker', 'logs', 'connection', 'webhook' ] as $command ) {
			$this->assertTrue( method_exists( CLI::class, $command ), "wp wp4odoo $command is missing" );
		}
	}

	// ─── health ───────────────────────────────────────────

	public function test_health_reports_missing_tables_and_exits_with_an_error_status(): void {
		$this->wpdb->get_var_return = null; // SHOW TABLES finds nothing.

		$this->cli->health( [], [] );

		$printed = $this->get_cli_calls( 'format_items' );
		$this->assertNotEmpty( $printed );
		$rows     = $printed[0]['args'][1];
		$database = array_values( array_filter( $rows, fn( $r ) => 'database' === $r['check'] ) )[0];
		$this->assertSame( 'error', $database['status'] );
		$this->assertStringContainsString( 'wp wp4odoo db repair', $database['detail'] );
		$this->assertSame( [ [ 'method' => 'halt', 'args' => [ 1 ] ] ], $this->get_cli_calls( 'halt' ) );
	}

	public function test_health_warns_when_the_connection_is_not_configured(): void {
		$this->cli->health( [], [] );

		$rows       = $this->get_cli_calls( 'format_items' )[0]['args'][1];
		$connection = array_values( array_filter( $rows, fn( $r ) => 'connection' === $r['check'] ) )[0];
		$this->assertSame( 'warning', $connection['status'] );
	}

	// ─── connection ───────────────────────────────────────

	public function test_connection_set_requires_at_least_one_option(): void {
		$this->cli->connection( [ 'set' ], [] );

		$this->assertStringContainsString( 'Nothing to change', (string) $this->get_cli_calls( 'error' )[0]['args'][0] );
	}

	public function test_connection_set_rejects_an_invalid_protocol(): void {
		$this->cli->connection( [ 'set' ], [ 'protocol' => 'soap' ] );

		$this->assertStringContainsString( 'Invalid protocol', (string) $this->get_cli_calls( 'error' )[0]['args'][0] );
	}

	public function test_connection_set_rejects_private_urls_unless_forced(): void {
		$this->cli->connection( [ 'set' ], [ 'odoo-url' => 'http://127.0.0.1:8069' ] );

		$this->assertStringContainsString( 'public address', (string) $this->get_cli_calls( 'error' )[0]['args'][0] );
		$this->assertArrayNotHasKey( 'wp4odoo_connection', $GLOBALS['_wp_options'] );
	}

	public function test_connection_set_stores_values_and_keeps_the_existing_api_key(): void {
		\WP4Odoo\API\Odoo_Auth::save_credentials(
			[
				'url'      => 'https://erp.example.com',
				'database' => 'prod',
				'username' => 'api',
				'api_key'  => 'secret-key',
				'protocol' => 'jsonrpc',
			]
		);
		\WP_CLI::reset();

		$this->cli->connection( [ 'set' ], [ 'protocol' => 'json2', 'company-id' => '4', 'timeout' => '60' ] );

		$credentials = \WP4Odoo\API\Odoo_Auth::get_credentials();
		$this->assertSame( 'json2', $credentials['protocol'] );
		$this->assertSame( 4, $credentials['company_id'] );
		$this->assertSame( 60, $credentials['timeout'] );
		$this->assertSame( 'secret-key', $credentials['api_key'], 'An omitted --api-key must not erase the stored key.' );
		$this->assertSame( 'https://erp.example.com', $credentials['url'] );
	}

	public function test_connection_show_never_prints_the_api_key(): void {
		\WP4Odoo\API\Odoo_Auth::save_credentials( [ 'url' => 'https://erp.example.com', 'database' => 'd', 'username' => 'u', 'api_key' => 'super-secret' ] );
		\WP_CLI::reset();

		$this->cli->connection( [ 'show' ], [] );

		$rows = $this->get_cli_calls( 'format_items' )[0]['args'][1];
		$this->assertStringNotContainsString( 'super-secret', (string) json_encode( $rows ) );
	}

	// ─── webhook ──────────────────────────────────────────

	public function test_webhook_rotate_generates_and_stores_a_new_token(): void {
		$settings = \WP4Odoo_Plugin::instance()->settings();
		$settings->save_webhook_token( 'old-token' );

		$this->cli->webhook( [ 'rotate' ], [ 'yes' => true ] );

		$new = $settings->get_webhook_token();
		$this->assertNotSame( 'old-token', $new );
		$this->assertSame( 48, strlen( $new ) );
		$this->assertNotEmpty( $this->get_cli_calls( 'confirm' ) );
	}

	// ─── unknown subcommands ──────────────────────────────

	public function test_new_commands_reject_unknown_subcommands(): void {
		$this->cli->db( [ 'bogus' ] );
		$this->cli->breaker( [ 'bogus' ] );
		$this->cli->logs( [ 'bogus' ], [] );
		$this->cli->connection( [ 'bogus' ], [] );
		$this->cli->webhook( [ 'bogus' ], [] );

		$this->assertCount( 5, $this->get_cli_calls( 'error' ) );
	}

	// ─── module info / settings / set ─────────────────────

	private function register_crm(): void {
		\WP4Odoo_Plugin::instance()->register_module( 'crm', new \WP4Odoo\Modules\CRM_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() ) );
	}

	public function test_module_set_updates_a_boolean_setting(): void {
		$this->register_crm();

		$this->cli->module( [ 'set', 'crm', 'archive_on_delete', 'false' ] );

		$stored = \WP4Odoo_Plugin::instance()->settings()->get_module_settings( 'crm' );
		$this->assertFalse( $stored['archive_on_delete'] );
		$this->assertNotEmpty( $this->get_cli_calls( 'success' ) );
	}

	public function test_module_set_rejects_unknown_keys_and_bad_types(): void {
		$this->register_crm();

		$this->cli->module( [ 'set', 'crm', 'nope', '1' ] );
		$this->cli->module( [ 'set', 'crm', 'archive_on_delete', 'maybe' ] );

		$errors = $this->get_cli_calls( 'error' );
		$this->assertStringContainsString( 'Unknown setting', (string) $errors[0]['args'][0] );
		$this->assertStringContainsString( 'boolean', (string) $errors[1]['args'][0] );
	}

	public function test_module_info_and_settings_list_the_module(): void {
		$this->register_crm();

		$this->cli->module( [ 'info', 'crm' ] );
		$this->cli->module( [ 'settings', 'crm' ] );

		$tables = $this->get_cli_calls( 'format_items' );
		$this->assertCount( 2, $tables );
		$this->assertContains( 'res.partner, crm.lead', array_column( $tables[0]['args'][1], 'value' ) );
		$this->assertContains( 'archive_on_delete', array_column( $tables[1]['args'][1], 'setting' ) );
	}

	public function test_module_commands_report_unknown_modules(): void {
		$this->cli->module( [ 'info', 'nope' ] );

		$this->assertStringContainsString( 'Unknown module', (string) $this->get_cli_calls( 'error' )[0]['args'][0] );
	}
}
