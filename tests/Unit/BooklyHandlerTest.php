<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Bookly_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Bookly_Handler.
 *
 * Tests service/booking loading, customer data retrieval,
 * and batch queries from Bookly's custom database tables via $wpdb.
 *
 * @covers \WP4Odoo\Modules\Bookly_Handler
 */
class BooklyHandlerTest extends TestCase {

	private Bookly_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options'] = [];

		$this->handler = new Bookly_Handler( new Logger( 'test' ) );
	}

	// ─── load_service ──────────────────────────────────────

	public function test_load_service_returns_data(): void {
		$this->wpdb->get_row_return = [
			'id'       => '1',
			'title'    => 'Haircut',
			'info'     => 'Standard haircut',
			'price'    => '35.00',
			'duration' => '1800',
		];

		$data = $this->handler->load_service( 1 );

		$this->assertSame( 'Haircut', $data['title'] );
		$this->assertSame( 'Standard haircut', $data['info'] );
		$this->assertSame( 35.0, $data['price'] );
		$this->assertSame( 1800, $data['duration'] );
	}

	public function test_load_service_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_service( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_service_handles_null_fields(): void {
		$this->wpdb->get_row_return = [
			'id'       => '2',
			'title'    => 'Consultation',
			'info'     => null,
			'price'    => null,
			'duration' => null,
		];

		$data = $this->handler->load_service( 2 );

		$this->assertSame( 'Consultation', $data['title'] );
		$this->assertSame( '', $data['info'] );
		$this->assertSame( 0.0, $data['price'] );
		$this->assertSame( 0, $data['duration'] );
	}

	public function test_load_service_queries_correct_table(): void {
		$this->wpdb->get_row_return = null;

		$this->handler->load_service( 42 );

		$prepare_call = $this->wpdb->calls[0];
		$this->assertSame( 'prepare', $prepare_call['method'] );
		$this->assertStringContainsString( 'wp_bookly_services', $prepare_call['args'][0] );
	}

	// ─── load_booking ─────────────────────────────────────

	public function test_load_booking_returns_data(): void {
		$this->wpdb->get_row_return = [
			'id'             => '10',
			'appointment_id' => '5',
			'customer_id'    => '3',
			'status'         => 'approved',
			'service_id'     => '1',
			'start_date'     => '2025-06-15 10:00:00',
			'end_date'       => '2025-06-15 10:30:00',
			'internal_note'  => 'First visit',
		];

		$data = $this->handler->load_booking( 10 );

		$this->assertSame( 10, $data['id'] );
		$this->assertSame( 5, $data['appointment_id'] );
		$this->assertSame( 3, $data['customer_id'] );
		$this->assertSame( 'approved', $data['status'] );
		$this->assertSame( 1, $data['service_id'] );
		$this->assertSame( '2025-06-15 10:00:00', $data['start_date'] );
		$this->assertSame( '2025-06-15 10:30:00', $data['end_date'] );
		$this->assertSame( 'First visit', $data['internal_note'] );
	}

	public function test_load_booking_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_booking( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_booking_handles_null_notes(): void {
		$this->wpdb->get_row_return = [
			'id'             => '11',
			'appointment_id' => '6',
			'customer_id'    => '4',
			'status'         => 'done',
			'service_id'     => '2',
			'start_date'     => '2025-06-16 14:00:00',
			'end_date'       => '2025-06-16 15:00:00',
			'internal_note'  => null,
		];

		$data = $this->handler->load_booking( 11 );

		$this->assertSame( '', $data['internal_note'] );
	}

	public function test_load_booking_queries_correct_tables(): void {
		$this->wpdb->get_row_return = null;

		$this->handler->load_booking( 42 );

		$prepare_call = $this->wpdb->calls[0];
		$this->assertSame( 'prepare', $prepare_call['method'] );
		$this->assertStringContainsString( 'wp_bookly_customer_appointments', $prepare_call['args'][0] );
		$this->assertStringContainsString( 'wp_bookly_appointments', $prepare_call['args'][0] );
	}

	// ─── get_customer_data ────────────────────────────────

	public function test_get_customer_data_returns_full_data(): void {
		$this->wpdb->get_row_return = [
			'email'      => 'jane@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
			'full_name'  => 'Jane Doe',
		];

		$data = $this->handler->get_customer_data( 5 );

		$this->assertSame( 'jane@example.com', $data['email'] );
		$this->assertSame( 'Jane', $data['first_name'] );
		$this->assertSame( 'Doe', $data['last_name'] );
		$this->assertSame( 'Jane Doe', $data['full_name'] );
	}

	public function test_get_customer_data_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->get_customer_data( 999 );
		$this->assertEmpty( $data );
	}

	public function test_get_customer_data_handles_null_names(): void {
		$this->wpdb->get_row_return = [
			'email'      => 'anon@example.com',
			'first_name' => null,
			'last_name'  => null,
			'full_name'  => null,
		];

		$data = $this->handler->get_customer_data( 6 );

		$this->assertSame( 'anon@example.com', $data['email'] );
		$this->assertSame( '', $data['first_name'] );
		$this->assertSame( '', $data['last_name'] );
		$this->assertSame( '', $data['full_name'] );
	}

	public function test_get_customer_data_queries_correct_table(): void {
		$this->wpdb->get_row_return = null;

		$this->handler->get_customer_data( 42 );

		$prepare_call = $this->wpdb->calls[0];
		$this->assertSame( 'prepare', $prepare_call['method'] );
		$this->assertStringContainsString( 'wp_bookly_customers', $prepare_call['args'][0] );
	}

	// ─── get_service_id_for_booking ───────────────────────

	public function test_get_service_id_for_booking_found(): void {
		$this->wpdb->get_var_return = '3';

		$service_id = $this->handler->get_service_id_for_booking( 10 );
		$this->assertSame( 3, $service_id );
	}

	public function test_get_service_id_for_booking_not_found(): void {
		$this->wpdb->get_var_return = null;

		$service_id = $this->handler->get_service_id_for_booking( 999 );
		$this->assertSame( 0, $service_id );
	}

	// ─── get_all_services (batch) ─────────────────────────

	public function test_get_all_services_returns_list(): void {
		$this->wpdb->get_results_return = [
			[ 'id' => '1', 'title' => 'Haircut', 'info' => 'Standard', 'price' => '35.00', 'duration' => '1800' ],
			[ 'id' => '2', 'title' => 'Color', 'info' => 'Full color', 'price' => '80.00', 'duration' => '3600' ],
		];

		$services = $this->handler->get_all_services();

		$this->assertCount( 2, $services );
		$this->assertSame( 1, $services[0]['id'] );
		$this->assertSame( 'Haircut', $services[0]['title'] );
		$this->assertSame( 35.0, $services[0]['price'] );
		$this->assertSame( 2, $services[1]['id'] );
		$this->assertSame( 'Color', $services[1]['title'] );
	}

	public function test_get_all_services_returns_empty(): void {
		$this->wpdb->get_results_return = [];

		$services = $this->handler->get_all_services();
		$this->assertSame( [], $services );
	}

	// ─── get_active_bookings (batch) ──────────────────────

	public function test_get_active_bookings_returns_list(): void {
		$this->wpdb->get_results_return = [
			[
				'id'             => '10',
				'appointment_id' => '5',
				'customer_id'    => '3',
				'status'         => 'approved',
				'service_id'     => '1',
				'start_date'     => '2025-06-15 10:00:00',
				'end_date'       => '2025-06-15 10:30:00',
				'internal_note'  => '',
			],
		];

		$bookings = $this->handler->get_active_bookings();

		$this->assertCount( 1, $bookings );
		$this->assertSame( 10, $bookings[0]['id'] );
		$this->assertSame( 'approved', $bookings[0]['status'] );
		$this->assertSame( 1, $bookings[0]['service_id'] );
	}

	public function test_get_active_bookings_returns_empty(): void {
		$this->wpdb->get_results_return = [];

		$bookings = $this->handler->get_active_bookings();
		$this->assertSame( [], $bookings );
	}

	public function test_get_active_bookings_queries_correct_tables(): void {
		$this->wpdb->get_results_return = [];

		$this->handler->get_active_bookings();

		$get_results_call = array_values( array_filter(
			$this->wpdb->calls,
			fn( $c ) => $c['method'] === 'get_results'
		) );
		$this->assertNotEmpty( $get_results_call );
		$query = $get_results_call[0]['args'][0];
		$this->assertStringContainsString( 'wp_bookly_customer_appointments', $query );
		$this->assertStringContainsString( 'wp_bookly_appointments', $query );
		$this->assertStringContainsString( "IN ('approved', 'done')", $query );
	}

	// ─── Pull: parse_service_from_odoo ────────────────────

	public function test_parse_service_from_odoo_maps_fields(): void {
		$odoo_data = [
			'name'             => 'Deep Tissue',
			'description_sale' => 'Intensive massage',
			'list_price'       => 90.0,
		];

		$data = $this->handler->parse_service_from_odoo( $odoo_data );

		$this->assertSame( 'Deep Tissue', $data['title'] );
		$this->assertSame( 'Intensive massage', $data['info'] );
		$this->assertSame( 90.0, $data['price'] );
	}

	public function test_parse_service_from_odoo_handles_missing_fields(): void {
		$data = $this->handler->parse_service_from_odoo( [] );

		$this->assertSame( '', $data['title'] );
		$this->assertSame( '', $data['info'] );
		$this->assertSame( 0.0, $data['price'] );
	}

	// ─── Pull: save_service ───────────────────────────────

	public function test_save_service_creates_new_row(): void {
		$this->wpdb->insert_id = 42;

		$id = $this->handler->save_service( [
			'title' => 'Yoga',
			'info'  => 'Hatha yoga',
			'price' => 50.0,
		] );

		$this->assertSame( 42, $id );

		$insert_call = array_values( array_filter(
			$this->wpdb->calls,
			fn( $c ) => $c['method'] === 'insert'
		) );
		$this->assertNotEmpty( $insert_call );
		$this->assertStringContainsString( 'bookly_services', $insert_call[0]['args'][0] );
	}

	public function test_save_service_updates_existing_row(): void {
		$id = $this->handler->save_service( [
			'title' => 'Yoga Updated',
			'info'  => 'Updated',
			'price' => 55.0,
		], 10 );

		$this->assertSame( 10, $id );

		$update_call = array_values( array_filter(
			$this->wpdb->calls,
			fn( $c ) => $c['method'] === 'update'
		) );
		$this->assertNotEmpty( $update_call );
		$this->assertStringContainsString( 'bookly_services', $update_call[0]['args'][0] );
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
		$this->assertStringContainsString( 'bookly_services', $delete_call[0]['args'][0] );
	}
}
