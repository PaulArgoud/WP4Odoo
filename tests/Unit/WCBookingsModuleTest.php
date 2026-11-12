<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Modules\WC_Bookings_Module;

/**
 * Unit tests for WC_Bookings_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WC_Bookings_Module::class)]
class WCBookingsModuleTest extends BookingModuleTestBase {

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']  = [];
		$GLOBALS['_wc_products'] = [];
		$GLOBALS['_wc_bookings'] = [];

		$this->module = new WC_Bookings_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── BookingModuleTestBase Configuration ────────────────

	protected function get_module_id(): string {
		return 'wc_bookings';
	}

	protected function get_module_name(): string {
		return 'WooCommerce Bookings';
	}

	protected function get_booking_entity(): string {
		return 'booking';
	}

	protected function get_sync_service_key(): string {
		return 'sync_products';
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

	public function test_booking_mapping_has_description(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'description', $mappings['booking']['description'] );
	}

	// ─── map_to_odoo ────────────────────────────────────────

	public function test_map_service_adds_type_service(): void {
		$data = $this->module->map_to_odoo( 'service', [
			'name'        => 'Guided Tour',
			'description' => 'Scenic tour',
			'price'       => 49.99,
		] );

		$this->assertSame( 'service', $data['type'] );
		$this->assertSame( 'Guided Tour', $data['name'] );
	}

	public function test_map_booking_returns_raw_data(): void {
		$input = [
			'name'        => 'Guided Tour — Jane',
			'start'       => '2025-06-15 10:00:00',
			'stop'        => '2025-06-15 12:00:00',
			'partner_ids' => [ [ 4, 42, 0 ] ],
			'description' => 'Persons: 3',
		];

		$data = $this->module->map_to_odoo( 'booking', $input );

		$this->assertSame( $input, $data );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_when_class_exists(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Handler Access ─────────────────────────────────────

	public function test_get_handler_returns_handler_instance(): void {
		$handler = $this->module->get_handler();
		$this->assertInstanceOf( \WP4Odoo\Modules\WC_Bookings_Handler::class, $handler );
	}
}
