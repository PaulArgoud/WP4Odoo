<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Modules\Fluent_Booking_Module;

/**
 * Unit tests for Fluent_Booking_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Fluent_Booking_Module::class)]
class FluentBookingModuleTest extends BookingModuleTestBase {

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();
		// Simulate required tables exist.
		$wpdb->get_var_return = 'wp_fluentbooking_calendars';

		$GLOBALS['_wp_options'] = [];

		$this->module = new Fluent_Booking_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── BookingModuleTestBase Configuration ────────────────

	protected function get_module_id(): string {
		return 'fluent_booking';
	}

	protected function get_module_name(): string {
		return 'FluentBooking';
	}

	protected function get_booking_entity(): string {
		return 'booking';
	}

	protected function get_sync_service_key(): string {
		return 'sync_services';
	}

	protected function get_sync_booking_key(): string {
		return 'sync_bookings';
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

	public function test_booking_mapping_has_start(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'start', $mappings['booking']['start'] );
	}

	public function test_booking_mapping_has_stop(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'stop', $mappings['booking']['stop'] );
	}

	public function test_booking_mapping_has_partner_ids(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'partner_ids', $mappings['booking']['partner_ids'] );
	}

	// ─── map_to_odoo ────────────────────────────────────────

	public function test_map_service_adds_type_service(): void {
		$data = $this->module->map_to_odoo( 'service', [
			'name'        => 'Consultation',
			'description' => '30 min',
			'price'       => 50.0,
		] );

		$this->assertSame( 'service', $data['type'] );
		$this->assertSame( 'Consultation', $data['name'] );
	}

	public function test_map_booking_returns_raw_data(): void {
		$input = [
			'name'        => 'Consultation — John',
			'start'       => '2026-03-01 10:00:00',
			'stop'        => '2026-03-01 10:30:00',
			'partner_ids' => [ [ 4, 42, 0 ] ],
			'description' => 'Notes',
		];

		$data = $this->module->map_to_odoo( 'booking', $input );

		$this->assertSame( $input, $data );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_when_constant_defined(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Handler Extraction ─────────────────────────────────

	public function test_handler_extract_booking_fields_returns_empty_for_missing(): void {
		global $wpdb;
		$wpdb->get_row_return = null;

		$ref    = new \ReflectionMethod( $this->module, 'handler_extract_booking_fields' );
		$result = $ref->invoke( $this->module, 999 );

		$this->assertSame( [], $result );
	}

	public function test_handler_load_service_returns_empty_for_missing(): void {
		global $wpdb;
		$wpdb->get_row_return = null;

		$ref    = new \ReflectionMethod( $this->module, 'handler_load_service' );
		$result = $ref->invoke( $this->module, 999 );

		$this->assertSame( [], $result );
	}
}
