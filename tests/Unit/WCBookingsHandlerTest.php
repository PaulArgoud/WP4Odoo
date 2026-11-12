<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\WC_Bookings_Handler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Bookings_Handler.
 *
 * Tests product/booking loading, field extraction, and status checks
 * using WC CRUD class stubs.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WC_Bookings_Handler::class)]
class WCBookingsHandlerTest extends TestCase {

	private WC_Bookings_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']  = [];
		$GLOBALS['_wc_products'] = [];
		$GLOBALS['_wc_orders']   = [];
		$GLOBALS['_wc_bookings'] = [];
		$GLOBALS['_wp_users']    = [];
		$GLOBALS['_wp_posts']    = [];

		$this->handler = new WC_Bookings_Handler( new Logger( 'test' ) );
	}

	// ─── load_product ───────────────────────────────────────

	public function test_load_product_returns_data(): void {
		$GLOBALS['_wc_products'][10] = [
			'name'          => 'Guided Tour',
			'description'   => 'A scenic tour',
			'regular_price' => '49.99',
			'type'          => 'booking',
		];

		$data = $this->handler->load_product( 10 );

		$this->assertSame( 'Guided Tour', $data['name'] );
		$this->assertSame( 'A scenic tour', $data['description'] );
		$this->assertSame( 49.99, $data['price'] );
	}

	public function test_load_product_empty_for_nonexistent(): void {
		$data = $this->handler->load_product( 999 );
		$this->assertEmpty( $data );
	}

	// ─── extract_booking_fields ─────────────────────────────

	public function test_extract_booking_fields_returns_seven_fields(): void {
		$GLOBALS['_wc_products'][5] = [
			'name' => 'Guided Tour',
			'type' => 'booking',
		];
		$GLOBALS['_wc_bookings'][100] = [
			'product_id'  => 5,
			'customer_id' => 42,
			'order_id'    => 0,
			'start_date'  => '2025-06-15 10:00:00',
			'end_date'    => '2025-06-15 12:00:00',
			'status'      => 'confirmed',
			'all_day'     => false,
			'persons'     => [],
			'cost'        => 49.99,
		];
		$user               = new \WP_User( 42 );
		$user->user_email   = 'jane@example.com';
		$user->first_name   = 'Jane';
		$user->last_name    = 'Doe';
		$user->display_name = 'Jane Doe';
		$GLOBALS['_wp_users'][42] = $user;

		$fields = $this->handler->extract_booking_fields( 100 );

		$this->assertCount( 7, $fields );
		$this->assertSame( 5, $fields['service_id'] );
		$this->assertSame( 'jane@example.com', $fields['customer_email'] );
		$this->assertSame( 'Jane Doe', $fields['customer_name'] );
		$this->assertSame( 'Guided Tour', $fields['service_name'] );
		$this->assertSame( '2025-06-15 10:00:00', $fields['start'] );
		$this->assertSame( '2025-06-15 12:00:00', $fields['stop'] );
	}

	public function test_extract_booking_fields_empty_for_nonexistent(): void {
		$fields = $this->handler->extract_booking_fields( 999 );
		$this->assertEmpty( $fields );
	}

	public function test_extract_booking_fields_resolves_customer_from_order(): void {
		$GLOBALS['_wc_products'][5] = [
			'name' => 'Spa Day',
			'type' => 'booking',
		];
		$GLOBALS['_wc_bookings'][200] = [
			'product_id'  => 5,
			'customer_id' => 0,
			'order_id'    => 300,
			'start_date'  => '2025-07-01 14:00:00',
			'end_date'    => '2025-07-01 16:00:00',
			'status'      => 'paid',
			'all_day'     => false,
			'persons'     => [],
		];
		$GLOBALS['_wc_orders'][300] = [
			'billing_email' => 'guest@example.com',
			'billing_name'  => 'Guest User',
		];

		$fields = $this->handler->extract_booking_fields( 200 );

		$this->assertSame( 'guest@example.com', $fields['customer_email'] );
		$this->assertSame( 'Guest User', $fields['customer_name'] );
	}

	public function test_extract_booking_fields_includes_persons_in_description(): void {
		$GLOBALS['_wc_products'][5] = [
			'name' => 'Group Tour',
			'type' => 'booking',
		];
		$GLOBALS['_wc_bookings'][300] = [
			'product_id'  => 5,
			'customer_id' => 0,
			'order_id'    => 0,
			'start_date'  => '2025-08-01 09:00:00',
			'end_date'    => '2025-08-01 11:00:00',
			'status'      => 'confirmed',
			'all_day'     => false,
			'persons'     => [ 3, 2 ],
		];

		$fields = $this->handler->extract_booking_fields( 300 );

		$this->assertStringContainsString( '5', $fields['description'] );
	}

	// ─── get_product_id_for_booking ─────────────────────────

	public function test_get_product_id_for_booking(): void {
		$GLOBALS['_wc_bookings'][100] = [
			'product_id' => 42,
		];

		$this->assertSame( 42, $this->handler->get_product_id_for_booking( 100 ) );
	}

	public function test_get_product_id_for_booking_returns_zero_for_missing(): void {
		$this->assertSame( 0, $this->handler->get_product_id_for_booking( 999 ) );
	}

	// ─── is_booking_syncable ────────────────────────────────

	public function test_is_booking_syncable_confirmed(): void {
		$this->assertTrue( $this->handler->is_booking_syncable( 'confirmed' ) );
	}

	public function test_is_booking_syncable_paid(): void {
		$this->assertTrue( $this->handler->is_booking_syncable( 'paid' ) );
	}

	public function test_is_booking_syncable_complete(): void {
		$this->assertTrue( $this->handler->is_booking_syncable( 'complete' ) );
	}

	public function test_is_booking_syncable_false_for_in_cart(): void {
		$this->assertFalse( $this->handler->is_booking_syncable( 'in-cart' ) );
	}

	public function test_is_booking_syncable_false_for_pending(): void {
		$this->assertFalse( $this->handler->is_booking_syncable( 'pending-confirmation' ) );
	}

	public function test_is_booking_syncable_false_for_cancelled(): void {
		$this->assertFalse( $this->handler->is_booking_syncable( 'cancelled' ) );
	}

	public function test_is_booking_syncable_false_for_unpaid(): void {
		$this->assertFalse( $this->handler->is_booking_syncable( 'unpaid' ) );
	}

	// ─── is_all_day ─────────────────────────────────────────

	public function test_is_all_day_true(): void {
		$GLOBALS['_wc_bookings'][100] = [
			'all_day' => true,
		];

		$this->assertTrue( $this->handler->is_all_day( 100 ) );
	}

	public function test_is_all_day_false(): void {
		$GLOBALS['_wc_bookings'][100] = [
			'all_day' => false,
		];

		$this->assertFalse( $this->handler->is_all_day( 100 ) );
	}

	// ─── Pull: parse_product_from_odoo ──────────────────────

	public function test_parse_product_from_odoo(): void {
		$data = $this->handler->parse_product_from_odoo( [
			'name'             => 'Spa Treatment',
			'description_sale' => 'Luxury treatment',
			'list_price'       => 120.0,
		] );

		$this->assertSame( 'Spa Treatment', $data['title'] );
		$this->assertSame( 'Luxury treatment', $data['description'] );
		$this->assertSame( 120.0, $data['list_price'] );
	}

	// ─── Pull: save_product ─────────────────────────────────

	public function test_save_product_creates_new(): void {
		$product_id = $this->handler->save_product( [
			'title'      => 'New Booking Product',
			'list_price' => 80.0,
		] );

		$this->assertGreaterThan( 0, $product_id );
	}

	public function test_save_product_updates_existing(): void {
		// Create a product first.
		$GLOBALS['_wp_posts'][50] = [
			'post_title'  => 'Existing Product',
			'post_type'   => 'product',
			'post_status' => 'publish',
		];

		$product_id = $this->handler->save_product( [
			'title'      => 'Updated Product',
			'list_price' => 99.0,
		], 50 );

		$this->assertSame( 50, $product_id );
	}

	// ─── Pull: delete_product ───────────────────────────────

	public function test_delete_product(): void {
		$GLOBALS['_wp_posts'][50] = [
			'post_title'  => 'To Delete',
			'post_type'   => 'product',
			'post_status' => 'publish',
		];

		$this->assertTrue( $this->handler->delete_product( 50 ) );
	}
}
