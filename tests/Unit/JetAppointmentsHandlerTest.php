<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Jet_Appointments_Handler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Jet_Appointments_Handler.
 *
 * Tests service/appointment loading from JetAppointments CPTs via
 * WordPress functions (get_post, get_post_meta).
 *
 */
#[CoversClass(\WP4Odoo\Modules\Jet_Appointments_Handler::class)]
class JetAppointmentsHandlerTest extends TestCase {

	private Jet_Appointments_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->handler = new Jet_Appointments_Handler( new Logger( 'test' ) );
	}

	// ─── load_service ──────────────────────────────────────

	public function test_load_service_returns_data(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'           => 10,
			'post_title'   => 'Consultation',
			'post_content' => 'One hour session',
			'post_status'  => 'publish',
			'post_type'    => 'jet-service',
		];
		$GLOBALS['_wp_post_meta'][10] = [ '_app_price' => '120.00' ];

		$data = $this->handler->load_service( 10 );

		$this->assertSame( 'Consultation', $data['name'] );
		$this->assertSame( 'One hour session', $data['description'] );
		$this->assertSame( 120.0, $data['price'] );
	}

	public function test_load_service_empty_for_nonexistent(): void {
		$data = $this->handler->load_service( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_service_empty_for_draft(): void {
		$GLOBALS['_wp_posts'][11] = (object) [
			'ID'           => 11,
			'post_title'   => 'Draft Service',
			'post_content' => '',
			'post_status'  => 'draft',
			'post_type'    => 'jet-service',
		];

		$data = $this->handler->load_service( 11 );
		$this->assertEmpty( $data );
	}

	// ─── extract_booking_fields ────────────────────────────

	public function test_extract_booking_fields_returns_data(): void {
		$GLOBALS['_wp_posts'][20] = (object) [
			'ID'           => 20,
			'post_title'   => 'Appointment',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'jet-appointment',
		];
		$GLOBALS['_wp_post_meta'][20] = [
			'_appointment_service_id'  => '10',
			'_appointment_date'        => '2025-06-15 10:00:00',
			'_appointment_end_date'    => '2025-06-15 11:00:00',
			'_appointment_user_email'  => 'john@example.com',
			'_appointment_user_name'   => 'John Doe',
			'_appointment_notes'       => 'First visit',
		];

		// Service post for name resolution.
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'           => 10,
			'post_title'   => 'Consultation',
			'post_content' => 'One hour',
			'post_status'  => 'publish',
			'post_type'    => 'jet-service',
		];

		$data = $this->handler->extract_booking_fields( 20 );

		$this->assertSame( 10, $data['service_id'] );
		$this->assertSame( 'john@example.com', $data['customer_email'] );
		$this->assertSame( 'John Doe', $data['customer_name'] );
		$this->assertSame( 'Consultation', $data['service_name'] );
		$this->assertSame( '2025-06-15 10:00:00', $data['start'] );
		$this->assertSame( '2025-06-15 11:00:00', $data['stop'] );
		$this->assertSame( 'First visit', $data['description'] );
	}

	public function test_extract_booking_fields_empty_for_nonexistent(): void {
		$data = $this->handler->extract_booking_fields( 999 );
		$this->assertEmpty( $data );
	}

	public function test_extract_booking_fields_handles_timestamp(): void {
		$GLOBALS['_wp_posts'][21] = (object) [
			'ID'           => 21,
			'post_title'   => 'Apt',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'jet-appointment',
		];
		$GLOBALS['_wp_post_meta'][21] = [
			'_appointment_service_id'  => '0',
			'_appointment_date'        => '1718445600',
			'_appointment_end_date'    => '1718449200',
			'_appointment_user_email'  => '',
			'_appointment_user_name'   => '',
			'_appointment_notes'       => '',
		];

		$data = $this->handler->extract_booking_fields( 21 );

		// Should convert Unix timestamp to Y-m-d H:i:s.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['start'] );
	}

	// ─── get_service_id ───────────────────────────────────

	public function test_get_service_id_returns_id(): void {
		$GLOBALS['_wp_post_meta'][20] = [
			'_appointment_service_id' => '10',
		];

		$this->assertSame( 10, $this->handler->get_service_id( 20 ) );
	}

	public function test_get_service_id_returns_zero_when_not_set(): void {
		$this->assertSame( 0, $this->handler->get_service_id( 999 ) );
	}

	// ─── parse_service_from_odoo ──────────────────────────

	public function test_parse_service_from_odoo_maps_fields(): void {
		$odoo_data = [
			'name'             => 'Yoga Class',
			'description_sale' => '<p>Relaxing yoga</p>',
			'list_price'       => 45.0,
		];

		$data = $this->handler->parse_service_from_odoo( $odoo_data );

		$this->assertSame( 'Yoga Class', $data['name'] );
		$this->assertSame( 'Relaxing yoga', $data['description'] );
		$this->assertSame( 45.0, $data['price'] );
	}

	public function test_parse_service_from_odoo_handles_missing_fields(): void {
		$data = $this->handler->parse_service_from_odoo( [] );

		$this->assertSame( '', $data['name'] );
		$this->assertSame( '', $data['description'] );
		$this->assertSame( 0.0, $data['price'] );
	}

	// ─── save_service ─────────────────────────────────────

	public function test_save_service_creates_new_post(): void {
		$id = $this->handler->save_service( [
			'name'        => 'Massage',
			'description' => 'Deep tissue',
			'price'       => 90.0,
		], 0 );

		// wp_insert_post stub returns 1 by default.
		$this->assertGreaterThan( 0, $id );
	}

	public function test_save_service_updates_existing_post(): void {
		$GLOBALS['_wp_posts'][30] = (object) [
			'ID'           => 30,
			'post_title'   => 'Old Service',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'jet-service',
		];

		$id = $this->handler->save_service( [
			'name'        => 'Updated Service',
			'description' => 'Updated desc',
			'price'       => 100.0,
		], 30 );

		$this->assertSame( 30, $id );
	}

	// ─── delete_service ───────────────────────────────────

	public function test_delete_service_returns_true(): void {
		$GLOBALS['_wp_posts'][40] = (object) [
			'ID'          => 40,
			'post_title'  => 'To Delete',
			'post_status' => 'publish',
			'post_type'   => 'jet-service',
		];

		$result = $this->handler->delete_service( 40 );
		$this->assertTrue( $result );
	}

	// ─── get_service_cpt ──────────────────────────────────

	public function test_get_service_cpt_default(): void {
		$this->assertSame( 'jet-service', $this->handler->get_service_cpt() );
	}

	public function test_get_service_cpt_from_settings(): void {
		$GLOBALS['_wp_options']['jet_apb_settings'] = [
			'services_cpt' => 'custom-service',
		];

		// Create new handler to reset cached CPT.
		$handler = new Jet_Appointments_Handler( new Logger( 'test' ) );
		$this->assertSame( 'custom-service', $handler->get_service_cpt() );
	}
}
