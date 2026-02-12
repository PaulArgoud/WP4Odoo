<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\Amelia_Module;

/**
 * Unit tests for Amelia_Module.
 *
 * @covers \WP4Odoo\Modules\Amelia_Module
 */
class AmeliaModuleTest extends TestCase {

	private Amelia_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options'] = [];

		$this->module = new Amelia_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id_is_amelia(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'amelia', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_amelia(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'Amelia', $ref->getValue( $this->module ) );
	}

	public function test_exclusive_group_is_empty(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority_is_zero(): void {
		$this->assertSame( 0, $this->module->get_exclusive_priority() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_service_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'product.product', $ref->getValue( $this->module )['service'] );
	}

	public function test_declares_appointment_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'calendar.event', $ref->getValue( $this->module )['appointment'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 2, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_services(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_services'] );
	}

	public function test_default_settings_has_sync_appointments(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_appointments'] );
	}

	public function test_default_settings_has_pull_services(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_services'] );
	}

	public function test_default_settings_has_exactly_three_keys(): void {
		$this->assertCount( 3, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_has_sync_services(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_services', $fields );
		$this->assertSame( 'checkbox', $fields['sync_services']['type'] );
	}

	public function test_settings_fields_has_sync_appointments(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_appointments', $fields );
		$this->assertSame( 'checkbox', $fields['sync_appointments']['type'] );
	}

	public function test_settings_fields_has_pull_services(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_services', $fields );
		$this->assertSame( 'checkbox', $fields['pull_services']['type'] );
	}

	public function test_settings_fields_has_exactly_three_entries(): void {
		$this->assertCount( 3, $this->module->get_settings_fields() );
	}

	// ─── Field Mappings ─────────────────────────────────────

	public function test_service_mapping_has_name(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'name', $mappings['service']['name'] );
	}

	public function test_service_mapping_has_description(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'description_sale', $mappings['service']['description'] );
	}

	public function test_service_mapping_has_price(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'list_price', $mappings['service']['price'] );
	}

	public function test_appointment_mapping_has_start(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'start', $mappings['appointment']['bookingStart'] );
	}

	public function test_appointment_mapping_has_stop(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'stop', $mappings['appointment']['bookingEnd'] );
	}

	public function test_appointment_mapping_has_partner_ids(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'partner_ids', $mappings['appointment']['partner_ids'] );
	}

	public function test_appointment_mapping_has_description(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'description', $mappings['appointment']['internalNotes'] );
	}

	// ─── map_to_odoo ────────────────────────────────────────

	public function test_map_service_adds_type_service(): void {
		$data = $this->module->map_to_odoo( 'service', [
			'name'        => 'Massage',
			'description' => 'Full body',
			'price'       => 75.0,
		] );

		$this->assertSame( 'service', $data['type'] );
		$this->assertSame( 'Massage', $data['name'] );
	}

	public function test_map_appointment_returns_raw_data(): void {
		$input = [
			'name'        => 'Massage — Jane',
			'start'       => '2025-06-15 10:00:00',
			'stop'        => '2025-06-15 11:00:00',
			'partner_ids' => [ [ 4, 42, 0 ] ],
			'description' => 'Notes',
		];

		$data = $this->module->map_to_odoo( 'appointment', $input );

		$this->assertSame( $input, $data );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_when_constant_defined(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash_with_amelia(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}
}
