<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Settings_Repository;
use PHPUnit\Framework\TestCase;

class SettingsRepositoryTest extends TestCase {

	private Settings_Repository $repo;

	protected function setUp(): void {
		$GLOBALS['_wp_options'] = [];
		$this->repo = new Settings_Repository();
	}

	// ── Connection ────────────────────────────────────────

	public function test_get_connection_returns_defaults_when_empty(): void {
		$conn = $this->repo->get_connection();

		$this->assertSame( '', $conn['url'] );
		$this->assertSame( '', $conn['database'] );
		$this->assertSame( 'jsonrpc', $conn['protocol'] );
		$this->assertSame( 30, $conn['timeout'] );
	}

	public function test_get_connection_merges_stored_values(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'url'      => 'https://odoo.example.com',
			'database' => 'mydb',
		];

		$conn = $this->repo->get_connection();

		$this->assertSame( 'https://odoo.example.com', $conn['url'] );
		$this->assertSame( 'mydb', $conn['database'] );
		$this->assertSame( 'jsonrpc', $conn['protocol'] );
	}

	public function test_save_connection(): void {
		$data = [ 'url' => 'https://test.com', 'database' => 'db' ];

		$this->repo->save_connection( $data );

		$this->assertSame( $data, $GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] );
	}

	// ── Sync settings ─────────────────────────────────────

	public function test_get_sync_settings_returns_defaults(): void {
		$sync = $this->repo->get_sync_settings();

		$this->assertSame( 50, $sync['batch_size'] );
		$this->assertSame( 'bidirectional', $sync['direction'] );
		$this->assertSame( 'newest_wins', $sync['conflict_rule'] );
		$this->assertFalse( $sync['auto_sync'] );
	}

	public function test_get_sync_settings_merges_stored(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_SYNC_SETTINGS ] = [
			'batch_size' => 100,
		];

		$sync = $this->repo->get_sync_settings();

		$this->assertSame( 100, $sync['batch_size'] );
		$this->assertSame( 'bidirectional', $sync['direction'] );
	}

	// ── Log settings ──────────────────────────────────────

	public function test_get_log_settings_returns_defaults(): void {
		$log = $this->repo->get_log_settings();

		$this->assertTrue( $log['enabled'] );
		$this->assertSame( 'info', $log['level'] );
		$this->assertSame( 30, $log['retention_days'] );
	}

	public function test_get_log_settings_merges_stored(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_LOG_SETTINGS ] = [
			'level' => 'debug',
		];

		$log = $this->repo->get_log_settings();

		$this->assertSame( 'debug', $log['level'] );
		$this->assertTrue( $log['enabled'] );
	}

	// ── Module helpers ────────────────────────────────────

	public function test_is_module_enabled_defaults_to_false(): void {
		$this->assertFalse( $this->repo->is_module_enabled( 'crm' ) );
	}

	public function test_set_module_enabled(): void {
		$this->repo->set_module_enabled( 'crm', true );

		$this->assertTrue( $this->repo->is_module_enabled( 'crm' ) );
	}

	public function test_set_module_disabled(): void {
		$this->repo->set_module_enabled( 'crm', true );
		$this->repo->set_module_enabled( 'crm', false );

		$this->assertFalse( $this->repo->is_module_enabled( 'crm' ) );
	}

	public function test_get_module_settings_defaults_to_empty(): void {
		$this->assertSame( [], $this->repo->get_module_settings( 'crm' ) );
	}

	public function test_save_and_get_module_settings(): void {
		$settings = [ 'sync_roles' => [ 'subscriber', 'customer' ] ];

		$this->repo->save_module_settings( 'crm', $settings );

		$this->assertSame( $settings, $this->repo->get_module_settings( 'crm' ) );
	}

	public function test_get_module_mappings_defaults_to_empty(): void {
		$this->assertSame( [], $this->repo->get_module_mappings( 'crm' ) );
	}

	// ── Webhook token ─────────────────────────────────────

	public function test_get_webhook_token_defaults_to_empty(): void {
		$this->assertSame( '', $this->repo->get_webhook_token() );
	}

	public function test_save_and_get_webhook_token(): void {
		$this->repo->save_webhook_token( 'abc123' );

		$this->assertSame( 'abc123', $this->repo->get_webhook_token() );
	}

	// ── Failure tracking ──────────────────────────────────

	public function test_consecutive_failures_defaults_to_zero(): void {
		$this->assertSame( 0, $this->repo->get_consecutive_failures() );
	}

	public function test_save_and_get_consecutive_failures(): void {
		$this->repo->save_consecutive_failures( 5 );

		$this->assertSame( 5, $this->repo->get_consecutive_failures() );
	}

	public function test_last_failure_email_defaults_to_zero(): void {
		$this->assertSame( 0, $this->repo->get_last_failure_email() );
	}

	public function test_save_and_get_last_failure_email(): void {
		$ts = 1700000000;
		$this->repo->save_last_failure_email( $ts );

		$this->assertSame( $ts, $this->repo->get_last_failure_email() );
	}

	// ── Onboarding / Checklist ────────────────────────────

	public function test_onboarding_defaults_not_dismissed(): void {
		$this->assertFalse( $this->repo->is_onboarding_dismissed() );
	}

	public function test_dismiss_onboarding(): void {
		$this->repo->dismiss_onboarding();

		$this->assertTrue( $this->repo->is_onboarding_dismissed() );
	}

	public function test_checklist_defaults_not_dismissed(): void {
		$this->assertFalse( $this->repo->is_checklist_dismissed() );
	}

	public function test_dismiss_checklist(): void {
		$this->repo->dismiss_checklist();

		$this->assertTrue( $this->repo->is_checklist_dismissed() );
	}

	public function test_webhooks_defaults_not_confirmed(): void {
		$this->assertFalse( $this->repo->is_webhooks_confirmed() );
	}

	public function test_confirm_webhooks(): void {
		$this->repo->confirm_webhooks();

		$this->assertTrue( $this->repo->is_webhooks_confirmed() );
	}

	// ── DB version ────────────────────────────────────────

	public function test_save_db_version(): void {
		$this->repo->save_db_version( '2.0.0' );

		$this->assertSame(
			'2.0.0',
			$GLOBALS['_wp_options'][ Settings_Repository::OPT_DB_VERSION ]
		);
	}

	// ── seed_defaults ─────────────────────────────────────

	public function test_seed_defaults_creates_options_when_absent(): void {
		$this->repo->seed_defaults();

		$this->assertIsArray( $GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] );
		$this->assertIsArray( $GLOBALS['_wp_options'][ Settings_Repository::OPT_SYNC_SETTINGS ] );
		$this->assertIsArray( $GLOBALS['_wp_options'][ Settings_Repository::OPT_LOG_SETTINGS ] );
	}

	public function test_seed_defaults_does_not_overwrite_existing(): void {
		$custom = [ 'url' => 'https://custom.com' ];
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = $custom;

		$this->repo->seed_defaults();

		$this->assertSame( $custom, $GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] );
	}

	// ── Static default accessors ──────────────────────────

	public function test_connection_defaults(): void {
		$defaults = Settings_Repository::connection_defaults();

		$this->assertArrayHasKey( 'url', $defaults );
		$this->assertArrayHasKey( 'protocol', $defaults );
		$this->assertSame( 'jsonrpc', $defaults['protocol'] );
	}

	public function test_sync_defaults(): void {
		$defaults = Settings_Repository::sync_defaults();

		$this->assertArrayHasKey( 'batch_size', $defaults );
		$this->assertSame( 50, $defaults['batch_size'] );
	}

	public function test_log_defaults(): void {
		$defaults = Settings_Repository::log_defaults();

		$this->assertArrayHasKey( 'enabled', $defaults );
		$this->assertTrue( $defaults['enabled'] );
	}

	// ── Non-array stored values ───────────────────────────

	public function test_get_connection_handles_non_array_stored(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = 'invalid';

		$conn = $this->repo->get_connection();

		$this->assertSame( '', $conn['url'] );
		$this->assertSame( 'jsonrpc', $conn['protocol'] );
	}

	public function test_get_sync_settings_handles_non_array_stored(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_SYNC_SETTINGS ] = 42;

		$sync = $this->repo->get_sync_settings();

		$this->assertSame( 50, $sync['batch_size'] );
	}

	public function test_get_log_settings_handles_non_array_stored(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_LOG_SETTINGS ] = false;

		$log = $this->repo->get_log_settings();

		$this->assertTrue( $log['enabled'] );
	}

	public function test_get_module_settings_handles_non_array_stored(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_crm_settings'] = 'bad';

		$this->assertSame( [], $this->repo->get_module_settings( 'crm' ) );
	}

	public function test_get_module_mappings_handles_non_array_stored(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_crm_mappings'] = null;

		$this->assertSame( [], $this->repo->get_module_mappings( 'crm' ) );
	}

	// ── Constants ─────────────────────────────────────────

	public function test_option_key_constants_are_prefixed(): void {
		$constants = [
			Settings_Repository::OPT_CONNECTION,
			Settings_Repository::OPT_SYNC_SETTINGS,
			Settings_Repository::OPT_LOG_SETTINGS,
			Settings_Repository::OPT_WEBHOOK_TOKEN,
			Settings_Repository::OPT_CONSECUTIVE_FAILURES,
			Settings_Repository::OPT_LAST_FAILURE_EMAIL,
			Settings_Repository::OPT_ONBOARDING_DISMISSED,
			Settings_Repository::OPT_CHECKLIST_DISMISSED,
			Settings_Repository::OPT_CHECKLIST_WEBHOOKS,
			Settings_Repository::OPT_DB_VERSION,
		];

		foreach ( $constants as $constant ) {
			$this->assertStringStartsWith( 'wp4odoo_', $constant );
		}
	}
}
