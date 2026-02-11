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

	// ── Cron health ──────────────────────────────────────

	public function test_get_last_cron_run_defaults_to_zero(): void {
		$this->assertSame( 0, $this->repo->get_last_cron_run() );
	}

	public function test_touch_cron_run_records_timestamp(): void {
		$this->repo->touch_cron_run();

		$this->assertGreaterThan( 0, $this->repo->get_last_cron_run() );
	}

	public function test_get_cron_warning_empty_when_never_run(): void {
		$this->assertSame( '', $this->repo->get_cron_warning() );
	}

	public function test_get_cron_warning_empty_when_recent(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_LAST_CRON_RUN ] = time() - 60;

		$this->assertSame( '', $this->repo->get_cron_warning() );
	}

	public function test_get_cron_warning_returns_message_when_stale(): void {
		// 5-minute interval, stale after 3× = 900s. Set last run 2000s ago.
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_LAST_CRON_RUN ] = time() - 2000;

		$warning = $this->repo->get_cron_warning();

		$this->assertNotSame( '', $warning );
		$this->assertStringContainsString( 'WP-Cron', $warning );
	}

	public function test_get_cron_warning_mentions_disable_when_constant_set(): void {
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true );
		}

		$GLOBALS['_wp_options'][ Settings_Repository::OPT_LAST_CRON_RUN ] = time() - 2000;

		$warning = $this->repo->get_cron_warning();

		$this->assertStringContainsString( 'DISABLE_WP_CRON', $warning );
	}

	public function test_get_cron_warning_uses_fifteen_minute_interval(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_SYNC_SETTINGS ] = [
			'sync_interval' => 'wp4odoo_fifteen_minutes',
		];
		// 15-min interval, stale after 3× = 2700s. Set last run 2000s ago — should be OK.
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_LAST_CRON_RUN ] = time() - 2000;

		$this->assertSame( '', $this->repo->get_cron_warning() );
	}

	// ── Defense-in-depth validation ──────────────────────

	public function test_connection_clamps_timeout_to_min(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'timeout' => 1,
		];

		$conn = $this->repo->get_connection();

		$this->assertSame( 5, $conn['timeout'] );
	}

	public function test_connection_clamps_timeout_to_max(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'timeout' => 999,
		];

		$conn = $this->repo->get_connection();

		$this->assertSame( 120, $conn['timeout'] );
	}

	public function test_connection_resets_invalid_protocol(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'protocol' => 'grpc',
		];

		$conn = $this->repo->get_connection();

		$this->assertSame( 'jsonrpc', $conn['protocol'] );
	}

	public function test_connection_accepts_xmlrpc_protocol(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'protocol' => 'xmlrpc',
		];

		$this->assertSame( 'xmlrpc', $this->repo->get_connection()['protocol'] );
	}

	public function test_sync_settings_clamps_batch_size_to_min(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_SYNC_SETTINGS ] = [
			'batch_size' => 0,
		];

		$this->assertSame( 1, $this->repo->get_sync_settings()['batch_size'] );
	}

	public function test_sync_settings_clamps_batch_size_to_max(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_SYNC_SETTINGS ] = [
			'batch_size' => 9999,
		];

		$this->assertSame( 500, $this->repo->get_sync_settings()['batch_size'] );
	}

	public function test_sync_settings_resets_invalid_direction(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_SYNC_SETTINGS ] = [
			'direction' => 'invalid',
		];

		$this->assertSame( 'bidirectional', $this->repo->get_sync_settings()['direction'] );
	}

	public function test_sync_settings_resets_invalid_conflict_rule(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_SYNC_SETTINGS ] = [
			'conflict_rule' => 'bad',
		];

		$this->assertSame( 'newest_wins', $this->repo->get_sync_settings()['conflict_rule'] );
	}

	public function test_sync_settings_resets_invalid_interval(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_SYNC_SETTINGS ] = [
			'sync_interval' => 'every_second',
		];

		$this->assertSame( 'wp4odoo_five_minutes', $this->repo->get_sync_settings()['sync_interval'] );
	}

	public function test_log_settings_resets_invalid_level(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_LOG_SETTINGS ] = [
			'level' => 'trace',
		];

		$this->assertSame( 'info', $this->repo->get_log_settings()['level'] );
	}

	public function test_log_settings_clamps_retention_days_to_min(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_LOG_SETTINGS ] = [
			'retention_days' => 0,
		];

		$this->assertSame( 1, $this->repo->get_log_settings()['retention_days'] );
	}

	public function test_log_settings_clamps_retention_days_to_max(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_LOG_SETTINGS ] = [
			'retention_days' => 1000,
		];

		$this->assertSame( 365, $this->repo->get_log_settings()['retention_days'] );
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
			Settings_Repository::OPT_LAST_CRON_RUN,
		];

		foreach ( $constants as $constant ) {
			$this->assertStringStartsWith( 'wp4odoo_', $constant );
		}
	}
}
