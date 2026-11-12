<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Modules\WPERP_CRM_Module;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for WPERP_CRM_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WPERP_CRM_Module::class)]
class WPERPCRMModuleTest extends Module_Test_Case {

	private WPERP_CRM_Module $module;

	protected function setUp(): void {
		parent::setUp();

		// Simulate all required tables exist (SHOW TABLES LIKE returns the name).
		$this->wpdb->get_var_return = 'wp_erp_peoples';

		$this->module = new WPERP_CRM_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id_is_wperp_crm(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'wperp_crm', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_wp_erp_crm(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'WP ERP CRM', $ref->getValue( $this->module ) );
	}

	public function test_exclusive_group_is_empty(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_lead_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'crm.lead', $ref->getValue( $this->module )['lead'] );
	}

	public function test_declares_activity_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'mail.activity', $ref->getValue( $this->module )['activity'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 2, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_leads(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_leads'] );
	}

	public function test_default_settings_has_pull_leads(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_leads'] );
	}

	public function test_default_settings_has_sync_activities(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_activities'] );
	}

	public function test_default_settings_has_exactly_three_keys(): void {
		$this->assertCount( 3, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_has_sync_leads(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_leads', $fields );
		$this->assertSame( 'checkbox', $fields['sync_leads']['type'] );
	}

	public function test_settings_fields_has_pull_leads(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_leads', $fields );
		$this->assertSame( 'checkbox', $fields['pull_leads']['type'] );
	}

	public function test_settings_fields_has_sync_activities(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_activities', $fields );
		$this->assertSame( 'checkbox', $fields['sync_activities']['type'] );
	}

	public function test_settings_fields_has_exactly_three_entries(): void {
		$this->assertCount( 3, $this->module->get_settings_fields() );
	}

	// ─── Field Mappings ─────────────────────────────────────

	public function test_lead_mapping_has_name(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'name', $mappings['lead']['name'] );
	}

	public function test_lead_mapping_has_email_from(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'email_from', $mappings['lead']['email_from'] );
	}

	public function test_lead_mapping_has_contact_name(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'contact_name', $mappings['lead']['contact_name'] );
	}

	public function test_lead_mapping_has_type(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'type', $mappings['lead']['type'] );
	}

	public function test_activity_mapping_has_summary(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'summary', $mappings['activity']['summary'] );
	}

	public function test_activity_mapping_has_date_deadline(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'date_deadline', $mappings['activity']['date_deadline'] );
	}

	// ─── map_to_odoo ────────────────────────────────────────

	public function test_map_lead_returns_raw_data(): void {
		$input = [
			'name'         => 'Acme Corp',
			'contact_name' => 'John Doe',
			'email_from'   => 'john@acme.com',
			'type'         => 'lead',
		];

		$data = $this->module->map_to_odoo( 'lead', $input );
		$this->assertSame( $input, $data );
	}

	public function test_map_activity_strips_type_name(): void {
		$input = [
			'summary'            => 'Follow up call',
			'note'               => 'Discuss contract',
			'date_deadline'      => '2025-07-15',
			'res_id'             => 42,
			'res_model'          => 'crm.lead',
			'activity_type_name' => 'Phone Call',
		];

		$data = $this->module->map_to_odoo( 'activity', $input );

		// activity_type_name should be removed (resolved to activity_type_id).
		$this->assertArrayNotHasKey( 'activity_type_name', $data );
		$this->assertSame( 'Follow up call', $data['summary'] );
		$this->assertSame( 42, $data['res_id'] );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_when_constant_defined(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Pull guard: activities are push-only ───────────────

	public function test_pull_activity_returns_success(): void {
		$result = $this->module->pull_from_odoo( 'activity', 'create', 1 );
		$this->assertTrue( $result->succeeded() );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}
}
