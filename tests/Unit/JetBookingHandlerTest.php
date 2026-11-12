<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Jet_Booking_Handler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Jet_Booking_Handler.
 *
 * Tests service loading from CPT and booking extraction from
 * JetBooking's custom `jet_apartment_bookings` table via $wpdb.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Jet_Booking_Handler::class)]
class JetBookingHandlerTest extends TestCase {

	private Jet_Booking_Handler $handler;

	/** @var \WP_DB_Stub */
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wp_users']     = [];

		$this->handler = new Jet_Booking_Handler( new Logger( 'test' ) );
	}

	// ─── load_service ──────────────────────────────────────

	public function test_load_service_returns_data(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'           => 10,
			'post_title'   => 'Beach House',
			'post_content' => 'Seaside rental with pool',
			'post_status'  => 'publish',
			'post_type'    => 'jet-abaf-apartment',
		];
		$GLOBALS['_wp_post_meta'][10] = [ '_apartment_price' => '200.00' ];

		$data = $this->handler->load_service( 10 );

		$this->assertSame( 'Beach House', $data['name'] );
		$this->assertSame( 'Seaside rental with pool', $data['description'] );
		$this->assertSame( 200.0, $data['price'] );
	}

	public function test_load_service_empty_for_nonexistent(): void {
		$data = $this->handler->load_service( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_service_empty_for_draft(): void {
		$GLOBALS['_wp_posts'][11] = (object) [
			'ID'           => 11,
			'post_title'   => 'Draft Apartment',
			'post_content' => '',
			'post_status'  => 'draft',
			'post_type'    => 'jet-abaf-apartment',
		];

		$data = $this->handler->load_service( 11 );
		$this->assertEmpty( $data );
	}

	// ─── extract_booking_fields ────────────────────────────

	public function test_extract_booking_fields_returns_data(): void {
		// Set up $wpdb->get_row() to return a booking row.
		$this->wpdb->get_row_return = (object) [
			'apartment_id'   => 10,
			'check_in_date'  => '2025-07-01 14:00:00',
			'check_out_date' => '2025-07-05 10:00:00',
			'status'         => 'confirmed',
			'user_id'        => 5,
		];

		// Service post for name resolution.
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'           => 10,
			'post_title'   => 'Beach House',
			'post_content' => 'Seaside rental',
			'post_status'  => 'publish',
			'post_type'    => 'jet-abaf-apartment',
		];

		// User for customer resolution.
		$GLOBALS['_wp_users'][5] = (object) [
			'ID'           => 5,
			'user_email'   => 'john@example.com',
			'first_name'   => 'John',
			'last_name'    => 'Doe',
			'display_name' => 'johndoe',
		];

		$data = $this->handler->extract_booking_fields( 1 );

		$this->assertSame( 10, $data['service_id'] );
		$this->assertSame( 'john@example.com', $data['customer_email'] );
		$this->assertSame( 'John Doe', $data['customer_name'] );
		$this->assertSame( 'Beach House', $data['service_name'] );
		$this->assertSame( '2025-07-01 14:00:00', $data['start'] );
		$this->assertSame( '2025-07-05 10:00:00', $data['stop'] );
		$this->assertSame( 'confirmed', $data['description'] );
	}

	public function test_extract_booking_fields_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->extract_booking_fields( 999 );
		$this->assertEmpty( $data );
	}

	public function test_extract_booking_fields_handles_timestamp(): void {
		$this->wpdb->get_row_return = (object) [
			'apartment_id'   => 0,
			'check_in_date'  => '1718445600',
			'check_out_date' => '1718449200',
			'status'         => 'pending',
			'user_id'        => 0,
		];

		$data = $this->handler->extract_booking_fields( 1 );

		// Should convert Unix timestamp to Y-m-d H:i:s.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['start'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['stop'] );
	}

	public function test_extract_booking_fields_handles_date_only(): void {
		$this->wpdb->get_row_return = (object) [
			'apartment_id'   => 0,
			'check_in_date'  => '2025-08-01',
			'check_out_date' => '2025-08-05',
			'status'         => 'confirmed',
			'user_id'        => 0,
		];

		$data = $this->handler->extract_booking_fields( 1 );

		$this->assertSame( '2025-08-01 00:00:00', $data['start'] );
		$this->assertSame( '2025-08-05 00:00:00', $data['stop'] );
	}

	public function test_extract_booking_fields_uses_display_name_fallback(): void {
		$this->wpdb->get_row_return = (object) [
			'apartment_id'   => 0,
			'check_in_date'  => '2025-07-01 14:00:00',
			'check_out_date' => '2025-07-05 10:00:00',
			'status'         => 'confirmed',
			'user_id'        => 6,
		];

		$GLOBALS['_wp_users'][6] = (object) [
			'ID'           => 6,
			'user_email'   => 'jane@example.com',
			'first_name'   => '',
			'last_name'    => '',
			'display_name' => 'jane_d',
		];

		$data = $this->handler->extract_booking_fields( 1 );

		$this->assertSame( 'jane_d', $data['customer_name'] );
	}

	// ─── get_service_id ───────────────────────────────────

	public function test_get_service_id_returns_id(): void {
		$this->wpdb->get_var_return = '10';

		$this->assertSame( 10, $this->handler->get_service_id( 1 ) );
	}

	public function test_get_service_id_returns_zero_when_not_found(): void {
		$this->wpdb->get_var_return = null;

		$this->assertSame( 0, $this->handler->get_service_id( 999 ) );
	}

	// ─── parse_service_from_odoo ──────────────────────────

	public function test_parse_service_from_odoo_maps_fields(): void {
		$odoo_data = [
			'name'             => 'Mountain Chalet',
			'description_sale' => '<p>Cozy mountain retreat</p>',
			'list_price'       => 350.0,
		];

		$data = $this->handler->parse_service_from_odoo( $odoo_data );

		$this->assertSame( 'Mountain Chalet', $data['name'] );
		$this->assertSame( 'Cozy mountain retreat', $data['description'] );
		$this->assertSame( 350.0, $data['price'] );
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
			'name'        => 'Lake Cabin',
			'description' => 'Waterfront cabin',
			'price'       => 180.0,
		], 0 );

		// wp_insert_post stub returns 1 by default.
		$this->assertGreaterThan( 0, $id );
	}

	public function test_save_service_updates_existing_post(): void {
		$GLOBALS['_wp_posts'][30] = (object) [
			'ID'           => 30,
			'post_title'   => 'Old Apartment',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'jet-abaf-apartment',
		];

		$id = $this->handler->save_service( [
			'name'        => 'Updated Apartment',
			'description' => 'Renovated',
			'price'       => 250.0,
		], 30 );

		$this->assertSame( 30, $id );
	}

	// ─── delete_service ───────────────────────────────────

	public function test_delete_service_returns_true(): void {
		$GLOBALS['_wp_posts'][40] = (object) [
			'ID'          => 40,
			'post_title'  => 'To Delete',
			'post_status' => 'publish',
			'post_type'   => 'jet-abaf-apartment',
		];

		$result = $this->handler->delete_service( 40 );
		$this->assertTrue( $result );
	}

	// ─── get_service_cpt ──────────────────────────────────

	public function test_get_service_cpt_default(): void {
		$this->assertSame( 'jet-abaf-apartment', $this->handler->get_service_cpt() );
	}

	public function test_get_service_cpt_from_settings(): void {
		$GLOBALS['_wp_options']['jet_abaf_settings'] = [
			'apartment_post_type' => 'custom-rental',
		];

		// Create new handler to reset cached CPT.
		$handler = new Jet_Booking_Handler( new Logger( 'test' ) );
		$this->assertSame( 'custom-rental', $handler->get_service_cpt() );
	}
}
