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
		$wpdb->get_var_return = 'wp_fcal_calendar_events';

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

	// ─── Handler: real FluentBooking schema ─────────────────

	public function test_handler_load_booking_reads_event_id_and_message(): void {
		global $wpdb;
		$wpdb->get_row_return = [
			'id'          => 7,
			'calendar_id' => 2,
			'event_id'    => 5,
			'email'       => 'jo@example.com',
			'first_name'  => 'Jo',
			'last_name'   => 'Doe',
			'start_time'  => '2026-03-01 10:00:00',
			'end_time'    => '2026-03-01 10:30:00',
			'status'      => 'scheduled',
			'message'     => 'Please call',
		];

		$handler = new \WP4Odoo\Modules\Fluent_Booking_Handler( new \WP4Odoo\Logger( 'test' ) );
		$booking = $handler->load_booking( 7 );

		$this->assertSame( 5, $booking['event_id'] );
		$this->assertSame( 2, $booking['calendar_id'] );
		$this->assertSame( 'Please call', $booking['description'] );
	}

	public function test_handler_service_id_for_booking_is_the_event_id(): void {
		global $wpdb;
		$wpdb->get_var_return = '5';

		$handler = new \WP4Odoo\Modules\Fluent_Booking_Handler( new \WP4Odoo\Logger( 'test' ) );

		$this->assertSame( 5, $handler->get_service_id_for_booking( 7 ) );
	}

	public function test_handler_does_not_create_events_from_odoo(): void {
		global $wpdb;
		$wpdb->calls = [];

		$handler = new \WP4Odoo\Modules\Fluent_Booking_Handler( new \WP4Odoo\Logger( 'test' ) );

		$this->assertSame( 0, $handler->save_service( [ 'title' => 'New' ], 0 ) );
		$this->assertSame( [], array_filter( $wpdb->calls, fn( $c ) => 'insert' === $c['method'] ) );
	}

	public function test_handler_updates_existing_event_in_fcal_table(): void {
		global $wpdb;
		$wpdb->calls = [];

		$handler = new \WP4Odoo\Modules\Fluent_Booking_Handler( new \WP4Odoo\Logger( 'test' ) );

		$this->assertSame( 5, $handler->save_service( [ 'title' => 'Renamed', 'description' => 'd' ], 5 ) );
		$update = array_values( array_filter( $wpdb->calls, fn( $c ) => 'update' === $c['method'] ) )[0];
		$this->assertSame( 'wp_fcal_calendar_events', $update['args'][0] );
		$this->assertSame( [ 'id' => 5 ], $update['args'][2] );
	}

	public function test_handler_keeps_event_when_odoo_product_is_deleted(): void {
		global $wpdb;
		$wpdb->calls = [];

		$handler = new \WP4Odoo\Modules\Fluent_Booking_Handler( new \WP4Odoo\Logger( 'test' ) );

		$this->assertTrue( $handler->delete_service( 5 ) );
		$this->assertSame( [], array_filter( $wpdb->calls, fn( $c ) => 'delete' === $c['method'] ) );
	}

	public function test_required_tables_are_the_fcal_tables(): void {
		$method = new \ReflectionMethod( $this->module, 'get_required_tables' );

		$this->assertSame( [ 'fcal_calendar_events', 'fcal_bookings' ], $method->invoke( $this->module ) );
	}

	public function test_hooks_read_record_id_from_models_and_arrays(): void {
		$method = new \ReflectionMethod( $this->module, 'fluent_booking_record_id' );

		$this->assertSame( 12, $method->invoke( $this->module, (object) [ 'id' => 12 ] ) );
		$this->assertSame( 13, $method->invoke( $this->module, [ 'id' => 13 ] ) );
		$this->assertSame( 14, $method->invoke( $this->module, '14' ) );
		$this->assertSame( 0, $method->invoke( $this->module, null ) );
	}
}
