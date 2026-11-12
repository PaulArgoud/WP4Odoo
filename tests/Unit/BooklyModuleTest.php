<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Modules\Bookly_Module;

/**
 * Unit tests for Bookly_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Bookly_Module::class)]
class BooklyModuleTest extends BookingModuleTestBase {

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options'] = [];

		// Advisory lock returns '1' (acquired) so poll() proceeds past the lock.
		$wpdb->get_var_return = '1';

		$this->module = new Bookly_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── BookingModuleTestBase Configuration ────────────────

	protected function get_module_id(): string {
		return 'bookly';
	}

	protected function get_module_name(): string {
		return 'Bookly';
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
		// Cron polling info notice is expected (uses_cron_polling() → true).
		$warnings = array_filter( $status['notices'], fn( $n ) => 'warning' === $n['type'] || 'error' === $n['type'] );
		$this->assertEmpty( $warnings );
	}

	// ─── Poll ───────────────────────────────────────────────

	public function test_poll_does_not_crash(): void {
		$this->module->poll();
		$this->assertTrue( true );
	}
}
