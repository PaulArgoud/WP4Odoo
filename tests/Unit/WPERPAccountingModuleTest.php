<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Modules\WPERP_Accounting_Module;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for WPERP_Accounting_Module.
 *
 * Covers module identity, Odoo models, default settings, settings fields,
 * dependency status, boot guard, dedup domains, pull guards, map_to_odoo
 * passthrough, map_from_odoo delegation, save/delete/load delegation.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WPERP_Accounting_Module::class)]
#[CoversClass(\WP4Odoo\Modules\WPERP_Accounting_Hooks::class)]
class WPERPAccountingModuleTest extends Module_Test_Case {

	private WPERP_Accounting_Module $module;

	protected function setUp(): void {
		parent::setUp();

		// Simulate all required tables exist (SHOW TABLES LIKE returns the name).
		$this->wpdb->get_var_return = 'wp_erp_acct_journals';

		$this->module = new WPERP_Accounting_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ────────────────────────────────────

	public function test_module_id_is_wperp_accounting(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'wperp_accounting', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_wp_erp_accounting(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'WP ERP Accounting', $ref->getValue( $this->module ) );
	}

	public function test_exclusive_group_is_empty(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_journal_entry_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.move', $models['journal_entry'] );
	}

	public function test_declares_chart_account_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.account', $models['chart_account'] );
	}

	public function test_declares_journal_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.journal', $models['journal'] );
	}

	public function test_declares_exactly_three_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 3, $models );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_sync_journal_entries_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_journal_entries'] );
	}

	public function test_sync_chart_accounts_disabled_by_default(): void {
		$this->assertFalse( $this->module->get_default_settings()['sync_chart_accounts'] );
	}

	public function test_sync_journals_disabled_by_default(): void {
		$this->assertFalse( $this->module->get_default_settings()['sync_journals'] );
	}

	public function test_pull_journal_entries_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_journal_entries'] );
	}

	public function test_pull_chart_accounts_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_chart_accounts'] );
	}

	public function test_pull_journals_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_journals'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 6, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_sync_journal_entries_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_journal_entries']['type'] );
	}

	public function test_settings_fields_sync_chart_accounts_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_chart_accounts']['type'] );
	}

	public function test_settings_fields_sync_journals_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_journals']['type'] );
	}

	public function test_settings_fields_pull_journal_entries_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['pull_journal_entries']['type'] );
	}

	public function test_settings_fields_pull_chart_accounts_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['pull_chart_accounts']['type'] );
	}

	public function test_settings_fields_pull_journals_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['pull_journals']['type'] );
	}

	public function test_settings_fields_have_labels(): void {
		$fields = $this->module->get_settings_fields();
		foreach ( $fields as $field ) {
			$this->assertNotEmpty( $field['label'] );
		}
	}

	public function test_settings_fields_count(): void {
		$this->assertCount( 6, $this->module->get_settings_fields() );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_with_wperp(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices_when_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Plugin Version ─────────────────────────────────────

	public function test_plugin_version_returns_wperp_version(): void {
		$method = new \ReflectionMethod( $this->module, 'get_plugin_version' );

		$this->assertSame( WPERP_VERSION, $method->invoke( $this->module ) );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Dedup Domains ──────────────────────────────────────

	public function test_dedup_journal_entry_by_ref(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'journal_entry', [ 'ref' => 'WPERP-JE-42' ] );

		$this->assertSame( [ [ 'ref', '=', 'WPERP-JE-42' ] ], $domain );
	}

	public function test_dedup_chart_account_by_code(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'chart_account', [ 'code' => '1100' ] );

		$this->assertSame( [ [ 'code', '=', '1100' ] ], $domain );
	}

	public function test_dedup_journal_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'journal', [ 'name' => 'Sales Journal' ] );

		$this->assertSame( [ [ 'name', '=', 'Sales Journal' ] ], $domain );
	}

	public function test_dedup_unknown_entity_returns_empty(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'unknown', [ 'name' => 'Test' ] );

		$this->assertEmpty( $domain );
	}

	public function test_dedup_journal_entry_empty_ref_returns_empty(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'journal_entry', [ 'ref' => '' ] );

		$this->assertEmpty( $domain );
	}

	// ─── Pull Guards ────────────────────────────────────────

	public function test_pull_journal_entry_disabled_returns_success(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_accounting_settings'] = [
			'sync_journal_entries' => true,
			'sync_chart_accounts'  => false,
			'sync_journals'        => false,
			'pull_journal_entries' => false,
			'pull_chart_accounts'  => true,
			'pull_journals'        => true,
		];

		$result = $this->module->pull_from_odoo( 'journal_entry', 'create', 1 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_chart_account_disabled_returns_success(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_accounting_settings'] = [
			'sync_journal_entries' => true,
			'sync_chart_accounts'  => false,
			'sync_journals'        => false,
			'pull_journal_entries' => true,
			'pull_chart_accounts'  => false,
			'pull_journals'        => true,
		];

		$result = $this->module->pull_from_odoo( 'chart_account', 'create', 1 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_journal_disabled_returns_success(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_accounting_settings'] = [
			'sync_journal_entries' => true,
			'sync_chart_accounts'  => false,
			'sync_journals'        => false,
			'pull_journal_entries' => true,
			'pull_chart_accounts'  => true,
			'pull_journals'        => false,
		];

		$result = $this->module->pull_from_odoo( 'journal', 'create', 1 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── map_to_odoo passthrough ────────────────────────────

	public function test_map_to_odoo_journal_entry_is_passthrough(): void {
		$data = [
			'move_type' => 'entry',
			'ref'       => 'WPERP-JE-1',
			'date'      => '2026-01-15',
			'state'     => 'draft',
		];

		$mapped = $this->module->map_to_odoo( 'journal_entry', $data );

		$this->assertSame( $data, $mapped );
	}

	public function test_map_to_odoo_chart_account_is_passthrough(): void {
		$data = [
			'name'         => 'Cash',
			'code'         => '1000',
			'account_type' => 'asset_current',
		];

		$mapped = $this->module->map_to_odoo( 'chart_account', $data );

		$this->assertSame( $data, $mapped );
	}

	public function test_map_to_odoo_journal_is_passthrough(): void {
		$data = [
			'name' => 'Sales Journal',
			'type' => 'sale',
			'code' => 'SAL',
		];

		$mapped = $this->module->map_to_odoo( 'journal', $data );

		$this->assertSame( $data, $mapped );
	}

	// ─── map_from_odoo ──────────────────────────────────────

	public function test_map_from_odoo_journal_entry_delegates_to_handler(): void {
		$data = $this->module->map_from_odoo( 'journal_entry', [
			'ref'   => 'JE-100',
			'date'  => '2026-03-01',
			'state' => 'posted',
		] );

		$this->assertSame( 'JE-100', $data['ref'] );
		$this->assertSame( '2026-03-01', $data['trn_date'] );
		$this->assertSame( 'awaiting_payment', $data['status'] );
	}

	public function test_map_from_odoo_chart_account_delegates_to_handler(): void {
		$data = $this->module->map_from_odoo( 'chart_account', [
			'name'         => 'Cash',
			'code'         => '1000',
			'account_type' => 'asset_current',
		] );

		$this->assertSame( 'Cash', $data['name'] );
		$this->assertSame( '1000', $data['code'] );
		$this->assertSame( 'asset_current', $data['type'] );
	}

	public function test_map_from_odoo_journal_delegates_to_handler(): void {
		$data = $this->module->map_from_odoo( 'journal', [
			'name' => 'Purchase Journal',
			'type' => 'purchase',
			'code' => 'PUR',
		] );

		$this->assertSame( 'Purchase Journal', $data['name'] );
		$this->assertSame( 'purchase', $data['type'] );
		$this->assertSame( 'PUR', $data['code'] );
	}

	// ─── save_wp_data delegation ────────────────────────────

	public function test_save_wp_data_journal_entry_delegates(): void {
		$method = new \ReflectionMethod( $this->module, 'save_wp_data' );

		$this->wpdb->insert_id = 10;

		$result = $method->invoke( $this->module, 'journal_entry', [
			'trn_date' => '2026-03-01',
			'status'   => 'draft',
		] );

		$this->assertSame( 10, $result );
	}

	public function test_save_wp_data_chart_account_delegates(): void {
		$method = new \ReflectionMethod( $this->module, 'save_wp_data' );

		$this->wpdb->insert_id = 20;

		$result = $method->invoke( $this->module, 'chart_account', [
			'name' => 'Bank Account',
			'code' => '1100',
			'type' => 'asset',
		] );

		$this->assertSame( 20, $result );
	}

	public function test_save_wp_data_journal_delegates(): void {
		$method = new \ReflectionMethod( $this->module, 'save_wp_data' );

		$result = $method->invoke( $this->module, 'journal', [
			'name' => 'General',
			'type' => 'general',
			'code' => 'GEN',
		] );

		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_wp_data_unknown_returns_zero(): void {
		$method = new \ReflectionMethod( $this->module, 'save_wp_data' );

		$result = $method->invoke( $this->module, 'unknown', [ 'foo' => 'bar' ] );

		$this->assertSame( 0, $result );
	}

	// ─── delete_wp_data delegation ──────────────────────────

	public function test_delete_wp_data_journal_entry_delegates(): void {
		$method = new \ReflectionMethod( $this->module, 'delete_wp_data' );

		$result = $method->invoke( $this->module, 'journal_entry', 1 );

		$this->assertTrue( $result );
	}

	public function test_delete_wp_data_chart_account_delegates(): void {
		$method = new \ReflectionMethod( $this->module, 'delete_wp_data' );

		$result = $method->invoke( $this->module, 'chart_account', 1 );

		$this->assertTrue( $result );
	}

	public function test_delete_wp_data_journal_delegates(): void {
		$method = new \ReflectionMethod( $this->module, 'delete_wp_data' );

		// Set up the option so delete_option returns true.
		$GLOBALS['_wp_options']['wp4odoo_erp_journal_1'] = [ 'name' => 'Test', 'type' => 'general', 'code' => 'TST' ];

		$result = $method->invoke( $this->module, 'journal', 1 );

		$this->assertTrue( $result );
	}

	public function test_delete_wp_data_unknown_returns_false(): void {
		$method = new \ReflectionMethod( $this->module, 'delete_wp_data' );

		$result = $method->invoke( $this->module, 'unknown', 1 );

		$this->assertFalse( $result );
	}

	// ─── load_wp_data delegation ────────────────────────────

	public function test_load_wp_data_journal_entry_delegates(): void {
		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );

		$this->wpdb->get_row_return = [
			'id'       => 1,
			'trn_date' => '2026-03-01',
			'status'   => 'draft',
		];

		$result = $method->invoke( $this->module, 'journal_entry', 1 );

		$this->assertNotEmpty( $result );
		$this->assertSame( 'entry', $result['move_type'] );
	}

	public function test_load_wp_data_chart_account_delegates(): void {
		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );

		$this->wpdb->get_row_return = [
			'id'   => 1,
			'name' => 'Cash',
			'code' => '1000',
			'type' => 'asset',
		];

		$result = $method->invoke( $this->module, 'chart_account', 1 );

		$this->assertNotEmpty( $result );
		$this->assertSame( 'Cash', $result['name'] );
	}

	public function test_load_wp_data_journal_delegates(): void {
		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );

		$GLOBALS['_wp_options']['wp4odoo_erp_journal_1'] = [
			'name' => 'General',
			'type' => 'general',
			'code' => 'GEN',
		];

		$result = $method->invoke( $this->module, 'journal', 1 );

		$this->assertNotEmpty( $result );
		$this->assertSame( 'General', $result['name'] );
	}

	public function test_load_wp_data_unknown_returns_empty(): void {
		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );

		$result = $method->invoke( $this->module, 'unknown', 1 );

		$this->assertEmpty( $result );
	}

	// ─── Required Tables ────────────────────────────────────

	public function test_required_tables_includes_journals(): void {
		$method = new \ReflectionMethod( $this->module, 'get_required_tables' );

		$tables = $method->invoke( $this->module );

		$this->assertContains( 'erp_acct_journals', $tables );
	}

	public function test_required_tables_includes_ledger_details(): void {
		$method = new \ReflectionMethod( $this->module, 'get_required_tables' );

		$tables = $method->invoke( $this->module );

		$this->assertContains( 'erp_acct_ledger_details', $tables );
	}

	public function test_required_tables_includes_chart_of_accounts(): void {
		$method = new \ReflectionMethod( $this->module, 'get_required_tables' );

		$tables = $method->invoke( $this->module );

		$this->assertContains( 'erp_acct_chart_of_accounts', $tables );
	}
}
