<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\Bookly_Module;

/**
 * Unit tests for Bookly_Module.
 *
 * @covers \WP4Odoo\Modules\Bookly_Module
 */
class BooklyModuleTest extends TestCase {

	private Bookly_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options'] = [];

		$this->module = new Bookly_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id_is_bookly(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'bookly', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_bookly(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'Bookly', $ref->getValue( $this->module ) );
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

	public function test_declares_booking_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'calendar.event', $ref->getValue( $this->module )['booking'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 2, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_services(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_services'] );
	}

	public function test_default_settings_has_sync_bookings(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_bookings'] );
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

	public function test_settings_fields_has_sync_bookings(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_bookings', $fields );
		$this->assertSame( 'checkbox', $fields['sync_bookings']['type'] );
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

	public function test_service_mapping_has_title(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'name', $mappings['service']['title'] );
	}

	public function test_service_mapping_has_info(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'description_sale', $mappings['service']['info'] );
	}

	public function test_service_mapping_has_price(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'list_price', $mappings['service']['price'] );
	}

	public function test_booking_mapping_has_start(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'start', $mappings['booking']['start_date'] );
	}

	public function test_booking_mapping_has_stop(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'stop', $mappings['booking']['end_date'] );
	}

	public function test_booking_mapping_has_partner_ids(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'partner_ids', $mappings['booking']['partner_ids'] );
	}

	public function test_booking_mapping_has_description(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'description', $mappings['booking']['internal_note'] );
	}

	// ─── map_to_odoo ────────────────────────────────────────

	public function test_map_service_adds_type_service(): void {
		$data = $this->module->map_to_odoo( 'service', [
			'title' => 'Haircut',
			'info'  => 'Standard haircut',
			'price' => 35.0,
		] );

		$this->assertSame( 'service', $data['type'] );
		$this->assertSame( 'Haircut', $data['name'] );
	}

	public function test_map_booking_returns_raw_data(): void {
		$input = [
			'name'        => 'Haircut — Jane',
			'start'       => '2025-06-15 10:00:00',
			'stop'        => '2025-06-15 10:30:00',
			'partner_ids' => [ [ 4, 42, 0 ] ],
			'description' => 'Notes',
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

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash_with_bookly(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Poll ───────────────────────────────────────────────

	public function test_poll_does_not_crash(): void {
		$this->module->poll();
		$this->assertTrue( true );
	}
}
