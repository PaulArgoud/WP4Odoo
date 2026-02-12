<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Amelia_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Amelia_Handler.
 *
 * Tests service/appointment loading and customer data retrieval
 * from Amelia's custom database tables via $wpdb.
 *
 * @covers \WP4Odoo\Modules\Amelia_Handler
 */
class AmeliaHandlerTest extends TestCase {

	private Amelia_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options'] = [];

		$this->handler = new Amelia_Handler( new Logger( 'test' ) );
	}

	// ─── load_service ──────────────────────────────────────

	public function test_load_service_returns_data(): void {
		$this->wpdb->get_row_return = [
			'id'          => '1',
			'name'        => 'Massage 60min',
			'description' => 'Relaxing massage',
			'price'       => '75.00',
			'duration'    => '3600',
		];

		$data = $this->handler->load_service( 1 );

		$this->assertSame( 'Massage 60min', $data['name'] );
		$this->assertSame( 'Relaxing massage', $data['description'] );
		$this->assertSame( 75.0, $data['price'] );
		$this->assertSame( 3600, $data['duration'] );
	}

	public function test_load_service_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_service( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_service_handles_null_fields(): void {
		$this->wpdb->get_row_return = [
			'id'          => '2',
			'name'        => 'Consultation',
			'description' => null,
			'price'       => null,
			'duration'    => null,
		];

		$data = $this->handler->load_service( 2 );

		$this->assertSame( 'Consultation', $data['name'] );
		$this->assertSame( '', $data['description'] );
		$this->assertSame( 0.0, $data['price'] );
		$this->assertSame( 0, $data['duration'] );
	}

	// ─── load_appointment ──────────────────────────────────

	public function test_load_appointment_returns_data(): void {
		// First call: appointment row. Second call: customer booking.
		$call_count              = 0;
		$this->wpdb->get_row_return = null;
		$appointment_row         = [
			'id'            => '10',
			'serviceId'     => '1',
			'status'        => 'approved',
			'bookingStart'  => '2025-06-15 10:00:00',
			'bookingEnd'    => '2025-06-15 11:00:00',
			'internalNotes' => 'First visit',
		];
		$booking_row = [
			'customerId' => '5',
		];

		// We need to set up sequential returns. Since WP_DB_Stub only has
		// a single get_row_return, we override with a closure approach.
		// For simplicity, set appointment row first and test the structure.
		$this->wpdb->get_row_return = $appointment_row;

		$data = $this->handler->load_appointment( 10 );

		$this->assertSame( 10, $data['appointment_id'] );
		$this->assertSame( 1, $data['service_id'] );
		$this->assertSame( 'approved', $data['status'] );
		$this->assertSame( '2025-06-15 10:00:00', $data['bookingStart'] );
		$this->assertSame( '2025-06-15 11:00:00', $data['bookingEnd'] );
		$this->assertSame( 'First visit', $data['internalNotes'] );
	}

	public function test_load_appointment_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_appointment( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_appointment_handles_null_notes(): void {
		$this->wpdb->get_row_return = [
			'id'            => '11',
			'serviceId'     => '2',
			'status'        => 'pending',
			'bookingStart'  => '2025-06-16 14:00:00',
			'bookingEnd'    => '2025-06-16 15:00:00',
			'internalNotes' => null,
		];

		$data = $this->handler->load_appointment( 11 );

		$this->assertSame( '', $data['internalNotes'] );
	}

	// ─── get_customer_data ─────────────────────────────────

	public function test_get_customer_data_returns_full_data(): void {
		$this->wpdb->get_row_return = [
			'email'     => 'jane@example.com',
			'firstName' => 'Jane',
			'lastName'  => 'Doe',
		];

		$data = $this->handler->get_customer_data( 5 );

		$this->assertSame( 'jane@example.com', $data['email'] );
		$this->assertSame( 'Jane', $data['firstName'] );
		$this->assertSame( 'Doe', $data['lastName'] );
	}

	public function test_get_customer_data_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->get_customer_data( 999 );
		$this->assertEmpty( $data );
	}

	public function test_get_customer_data_handles_null_name(): void {
		$this->wpdb->get_row_return = [
			'email'     => 'anon@example.com',
			'firstName' => null,
			'lastName'  => null,
		];

		$data = $this->handler->get_customer_data( 6 );

		$this->assertSame( 'anon@example.com', $data['email'] );
		$this->assertSame( '', $data['firstName'] );
		$this->assertSame( '', $data['lastName'] );
	}

	// ─── get_service_id_for_appointment ────────────────────

	public function test_get_service_id_for_appointment_found(): void {
		$this->wpdb->get_var_return = '3';

		$service_id = $this->handler->get_service_id_for_appointment( 10 );
		$this->assertSame( 3, $service_id );
	}

	public function test_get_service_id_for_appointment_not_found(): void {
		$this->wpdb->get_var_return = null;

		$service_id = $this->handler->get_service_id_for_appointment( 999 );
		$this->assertSame( 0, $service_id );
	}

	// ─── Verifies $wpdb calls ──────────────────────────────

	public function test_load_service_queries_correct_table(): void {
		$this->wpdb->get_row_return = null;

		$this->handler->load_service( 42 );

		$prepare_call = $this->wpdb->calls[0];
		$this->assertSame( 'prepare', $prepare_call['method'] );
		$this->assertStringContainsString( 'wp_amelia_services', $prepare_call['args'][0] );
	}

	public function test_load_appointment_queries_correct_table(): void {
		$this->wpdb->get_row_return = null;

		$this->handler->load_appointment( 42 );

		$prepare_call = $this->wpdb->calls[0];
		$this->assertSame( 'prepare', $prepare_call['method'] );
		$this->assertStringContainsString( 'wp_amelia_appointments', $prepare_call['args'][0] );
	}

	public function test_get_customer_data_queries_correct_table(): void {
		$this->wpdb->get_row_return = null;

		$this->handler->get_customer_data( 42 );

		$prepare_call = $this->wpdb->calls[0];
		$this->assertSame( 'prepare', $prepare_call['method'] );
		$this->assertStringContainsString( 'wp_amelia_users', $prepare_call['args'][0] );
	}

	// ─── Pull: parse_service_from_odoo ────────────────────

	public function test_parse_service_from_odoo_maps_fields(): void {
		$odoo_data = [
			'name'             => 'Deep Tissue',
			'description_sale' => 'Intensive massage',
			'list_price'       => 90.0,
		];

		$data = $this->handler->parse_service_from_odoo( $odoo_data );

		$this->assertSame( 'Deep Tissue', $data['name'] );
		$this->assertSame( 'Intensive massage', $data['description'] );
		$this->assertSame( 90.0, $data['price'] );
	}

	public function test_parse_service_from_odoo_handles_missing_fields(): void {
		$data = $this->handler->parse_service_from_odoo( [] );

		$this->assertSame( '', $data['name'] );
		$this->assertSame( '', $data['description'] );
		$this->assertSame( 0.0, $data['price'] );
	}

	// ─── Pull: save_service ───────────────────────────────

	public function test_save_service_creates_new_row(): void {
		$this->wpdb->insert_id = 42;

		$id = $this->handler->save_service( [
			'name'        => 'Yoga',
			'description' => 'Hatha yoga',
			'price'       => 50.0,
		] );

		$this->assertSame( 42, $id );

		$insert_call = array_values( array_filter(
			$this->wpdb->calls,
			fn( $c ) => $c['method'] === 'insert'
		) );
		$this->assertNotEmpty( $insert_call );
		$this->assertStringContainsString( 'amelia_services', $insert_call[0]['args'][0] );
	}

	public function test_save_service_updates_existing_row(): void {
		$id = $this->handler->save_service( [
			'name'        => 'Yoga Updated',
			'description' => 'Updated',
			'price'       => 55.0,
		], 10 );

		$this->assertSame( 10, $id );

		$update_call = array_values( array_filter(
			$this->wpdb->calls,
			fn( $c ) => $c['method'] === 'update'
		) );
		$this->assertNotEmpty( $update_call );
		$this->assertStringContainsString( 'amelia_services', $update_call[0]['args'][0] );
	}

	// ─── Pull: delete_service ─────────────────────────────

	public function test_delete_service_returns_true(): void {
		$result = $this->handler->delete_service( 5 );
		$this->assertTrue( $result );

		$delete_call = array_values( array_filter(
			$this->wpdb->calls,
			fn( $c ) => $c['method'] === 'delete'
		) );
		$this->assertNotEmpty( $delete_call );
		$this->assertStringContainsString( 'amelia_services', $delete_call[0]['args'][0] );
	}
}
