<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Modules\Amelia_Module;

/**
 * Unit tests for Amelia_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Amelia_Module::class)]
class AmeliaModuleTest extends BookingModuleTestBase {

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();
		// Simulate all required tables exist (SHOW TABLES LIKE returns the name).
		$wpdb->get_var_return = 'wp_amelia_services';

		$GLOBALS['_wp_options'] = [];

		$this->module = new Amelia_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── BookingModuleTestBase Configuration ────────────────

	protected function get_module_id(): string {
		return 'amelia';
	}

	protected function get_module_name(): string {
		return 'Amelia';
	}

	protected function get_booking_entity(): string {
		return 'appointment';
	}

	protected function get_sync_service_key(): string {
		return 'sync_services';
	}

	protected function get_sync_booking_key(): string {
		return 'sync_appointments';
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
}
