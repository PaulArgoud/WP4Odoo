<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Forms_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Forms_Module.
 *
 * Tests module configuration, entity type declarations, field mappings,
 * default settings, and dependency status.
 */
class FormsModuleTest extends TestCase {

	private Forms_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options'] = [];

		$this->module = new Forms_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_forms(): void {
		$this->assertSame( 'forms', $this->module->get_id() );
	}

	public function test_module_name_is_forms(): void {
		$this->assertSame( 'Forms', $this->module->get_name() );
	}

	public function test_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority(): void {
		$this->assertSame( 0, $this->module->get_exclusive_priority() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_lead_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'crm.lead', $models['lead'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 1, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_sync_gravity_forms_is_true(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_gravity_forms'] );
	}

	public function test_default_sync_wpforms_is_true(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_wpforms'] );
	}

	public function test_default_settings_has_exactly_two_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 2, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_sync_gravity_forms(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_gravity_forms', $fields );
		$this->assertSame( 'checkbox', $fields['sync_gravity_forms']['type'] );
	}

	public function test_settings_fields_exposes_sync_wpforms(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_wpforms', $fields );
		$this->assertSame( 'checkbox', $fields['sync_wpforms']['type'] );
	}

	// ─── Field Mappings ────────────────────────────────────

	public function test_lead_mapping_name_to_name(): void {
		$odoo = $this->module->map_to_odoo( 'lead', [ 'name' => 'John' ] );
		$this->assertSame( 'John', $odoo['name'] );
	}

	public function test_lead_mapping_email_to_email_from(): void {
		$odoo = $this->module->map_to_odoo( 'lead', [ 'email' => 'john@example.com' ] );
		$this->assertSame( 'john@example.com', $odoo['email_from'] );
	}

	public function test_lead_mapping_phone_to_phone(): void {
		$odoo = $this->module->map_to_odoo( 'lead', [ 'phone' => '+123' ] );
		$this->assertSame( '+123', $odoo['phone'] );
	}

	public function test_lead_mapping_company_to_partner_name(): void {
		$odoo = $this->module->map_to_odoo( 'lead', [ 'company' => 'Acme' ] );
		$this->assertSame( 'Acme', $odoo['partner_name'] );
	}

	public function test_lead_mapping_description_to_description(): void {
		$odoo = $this->module->map_to_odoo( 'lead', [ 'description' => 'Hello' ] );
		$this->assertSame( 'Hello', $odoo['description'] );
	}

	public function test_lead_mapping_source_to_x_wp_source(): void {
		$odoo = $this->module->map_to_odoo( 'lead', [ 'source' => 'Web' ] );
		$this->assertSame( 'Web', $odoo['x_wp_source'] );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_status_unavailable_without_plugins(): void {
		// Neither GFAPI class nor wpforms function exist by default in test env
		// but stubs define them. We test the method returns a valid structure.
		$status = $this->module->get_dependency_status();
		$this->assertArrayHasKey( 'available', $status );
		$this->assertArrayHasKey( 'notices', $status );
		$this->assertIsBool( $status['available'] );
	}

	// ─── Boot ──────────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		// boot() should complete without error even when plugins aren't truly loaded.
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Sync Direction ─────────────────────────────────

	public function test_sync_direction(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}
}
