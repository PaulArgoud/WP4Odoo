<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\SupportCandy_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SupportCandy_Module.
 *
 * Tests module configuration, entity type declarations, default settings,
 * settings fields, dependency status, exclusive group, and push/pull overrides.
 */
class SupportCandyModuleTest extends TestCase {

	private SupportCandy_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();
		// Simulate required tables exist (SHOW TABLES LIKE returns the name).
		$wpdb->get_var_return = 'wp_wpsc_ticket';

		$GLOBALS['_wp_options']              = [];
		$GLOBALS['_wp_users']                = [];
		$GLOBALS['_wp_user_meta']            = [];
		$GLOBALS['_supportcandy_tickets']    = [];
		$GLOBALS['_supportcandy_ticketmeta'] = [];

		$this->module = new SupportCandy_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ──────────────────────────────────

	public function test_module_id_is_supportcandy(): void {
		$this->assertSame( 'supportcandy', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'SupportCandy', $this->module->get_name() );
	}

	public function test_exclusive_group_is_helpdesk(): void {
		$this->assertSame( 'helpdesk', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority_is_15(): void {
		$this->assertSame( 15, $this->module->get_exclusive_priority() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ─────────────────────────────────────

	public function test_declares_ticket_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'helpdesk.ticket', $models['ticket'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 1, $models );
	}

	// ─── Default Settings ─────────────────────────────────

	public function test_default_settings_has_sync_tickets(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_tickets'] );
	}

	public function test_default_settings_has_pull_tickets(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_tickets'] );
	}

	public function test_default_settings_has_odoo_team_id(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 0, $settings['odoo_team_id'] );
	}

	public function test_default_settings_has_odoo_project_id(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 0, $settings['odoo_project_id'] );
	}

	public function test_default_settings_has_exactly_four_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 4, $settings );
	}

	// ─── Settings Fields ──────────────────────────────────

	public function test_settings_fields_exposes_sync_tickets(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_tickets', $fields );
		$this->assertSame( 'checkbox', $fields['sync_tickets']['type'] );
	}

	public function test_settings_fields_exposes_pull_tickets(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_tickets', $fields );
		$this->assertSame( 'checkbox', $fields['pull_tickets']['type'] );
	}

	public function test_settings_fields_exposes_odoo_team_id(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'odoo_team_id', $fields );
		$this->assertSame( 'number', $fields['odoo_team_id']['type'] );
	}

	public function test_settings_fields_exposes_odoo_project_id(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'odoo_project_id', $fields );
		$this->assertSame( 'number', $fields['odoo_project_id']['type'] );
	}

	public function test_settings_fields_has_exactly_four_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 4, $fields );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_available_when_constant_defined(): void {
		$status = $this->module->get_dependency_status();
		// WPSC_VERSION is defined in test stubs.
		$this->assertTrue( $status['available'] );
	}

	// ─── Pull Override ────────────────────────────────────

	public function test_pull_skips_when_pull_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => false,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$result = $this->module->pull_from_odoo( 'ticket', 'update', 55, 42 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_skips_non_update_action(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$result = $this->module->pull_from_odoo( 'ticket', 'create', 55, 42 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_skips_delete_action(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$result = $this->module->pull_from_odoo( 'ticket', 'delete', 55, 42 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Odoo Model Name ──────────────────────────────────

	public function test_ticket_odoo_model_is_helpdesk_ticket(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'helpdesk.ticket', $models['ticket'] );
	}
}
