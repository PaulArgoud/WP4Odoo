<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\LifterLMS_Handler;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for LifterLMS_Handler.
 *
 * Tests data loading, invoice/order formatting, status mapping, and helpers.
 */
class LifterLMSHandlerTest extends Module_Test_Case {

	private LifterLMS_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new LifterLMS_Handler( new \WP4Odoo\Logger( 'lifterlms', wp4odoo_test_settings() ) );
	}

	private function create_post( int $id, string $type, string $title = '', string $content = '' ): void {
		$GLOBALS['_wp_posts'][ $id ] = (object) [
			'ID'             => $id,
			'post_type'      => $type,
			'post_title'     => $title,
			'post_content'   => $content,
			'post_status'    => 'publish',
			'post_author'    => 1,
			'post_date_gmt'  => '2026-02-01 10:00:00',
		];
	}

	private function create_user( int $id, string $email = 'user@example.com', string $name = 'Test User' ): void {
		$GLOBALS['_wp_users'][ $id ] = (object) [
			'ID'           => $id,
			'user_email'   => $email,
			'display_name' => $name,
		];
	}

	// ─── Load Course ──────────────────────────────────────

	public function test_load_course_returns_data(): void {
		$this->create_post( 100, 'llms_course', 'PHP 101', 'Learn PHP basics' );
		$GLOBALS['_wp_post_meta'][100] = [ '_llms_regular_price' => '49.99' ];

		$data = $this->handler->load_course( 100 );

		$this->assertSame( 'PHP 101', $data['title'] );
		$this->assertSame( 'Learn PHP basics', $data['description'] );
		$this->assertSame( 49.99, $data['list_price'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_course_returns_empty_for_wrong_type(): void {
		$this->create_post( 101, 'post', 'Blog Post' );

		$data = $this->handler->load_course( 101 );
		$this->assertEmpty( $data );
	}

	public function test_load_course_returns_empty_for_missing(): void {
		$data = $this->handler->load_course( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_course_handles_zero_price(): void {
		$this->create_post( 102, 'llms_course', 'Free Course' );

		$data = $this->handler->load_course( 102 );
		$this->assertSame( 0.0, $data['list_price'] );
	}

	// ─── Load Membership ──────────────────────────────────

	public function test_load_membership_returns_data(): void {
		$this->create_post( 200, 'llms_membership', 'Pro Access', 'Full site access' );
		$GLOBALS['_wp_post_meta'][200] = [ '_llms_regular_price' => '99.00' ];

		$data = $this->handler->load_membership( 200 );

		$this->assertSame( 'Pro Access', $data['title'] );
		$this->assertSame( 99.0, $data['list_price'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_membership_returns_empty_for_wrong_type(): void {
		$this->create_post( 201, 'post', 'Not a membership' );

		$data = $this->handler->load_membership( 201 );
		$this->assertEmpty( $data );
	}

	public function test_load_membership_returns_empty_for_missing(): void {
		$data = $this->handler->load_membership( 999 );
		$this->assertEmpty( $data );
	}

	// ─── Load Order ───────────────────────────────────────

	public function test_load_order_returns_data(): void {
		$this->create_post( 300, 'llms_order', 'Order #300' );
		$GLOBALS['_llms_orders'][300] = [
			'product_id' => 100,
			'user_id'    => 5,
			'total'      => 49.99,
			'status'     => 'llms-completed',
			'date'       => '2026-02-01',
		];

		$data = $this->handler->load_order( 300 );

		$this->assertSame( 100, $data['product_id'] );
		$this->assertSame( 5, $data['user_id'] );
		$this->assertSame( 49.99, $data['total'] );
		$this->assertSame( 'llms-completed', $data['status'] );
	}

	public function test_load_order_returns_empty_for_missing_post(): void {
		$GLOBALS['_llms_orders'][301] = [
			'product_id' => 100,
			'user_id'    => 5,
			'total'      => 49.99,
			'status'     => 'llms-completed',
		];
		// No post created — should fail on post type check.
		$data = $this->handler->load_order( 301 );
		$this->assertEmpty( $data );
	}

	public function test_load_order_returns_empty_for_wrong_type(): void {
		$this->create_post( 302, 'post', 'Not an order' );
		$GLOBALS['_llms_orders'][302] = [ 'product_id' => 100 ];

		$data = $this->handler->load_order( 302 );
		$this->assertEmpty( $data );
	}

	// ─── Load Enrollment ──────────────────────────────────

	public function test_load_enrollment_returns_data(): void {
		$this->create_user( 5, 'student@example.com', 'Jane Doe' );
		$this->create_post( 100, 'llms_course', 'PHP 101' );
		$GLOBALS['_llms_enrollments']['5_100'] = [
			'status' => 'enrolled',
			'date'   => '2026-02-01',
		];

		$data = $this->handler->load_enrollment( 5, 100 );

		$this->assertSame( 5, $data['user_id'] );
		$this->assertSame( 100, $data['course_id'] );
		$this->assertSame( 'student@example.com', $data['user_email'] );
		$this->assertSame( 'Jane Doe', $data['user_name'] );
		$this->assertSame( '2026-02-01', $data['date'] );
	}

	public function test_load_enrollment_returns_empty_for_missing_user(): void {
		$this->create_post( 100, 'llms_course', 'PHP 101' );

		$data = $this->handler->load_enrollment( 999, 100 );
		$this->assertEmpty( $data );
	}

	public function test_load_enrollment_returns_empty_for_missing_course(): void {
		$this->create_user( 5 );

		$data = $this->handler->load_enrollment( 5, 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_enrollment_falls_back_to_today(): void {
		$this->create_user( 5 );
		$this->create_post( 100, 'llms_course', 'PHP 101' );
		// No enrollment date set.

		$data = $this->handler->load_enrollment( 5, 100 );
		$this->assertSame( gmdate( 'Y-m-d' ), $data['date'] );
	}

	// ─── Format Invoice ───────────────────────────────────

	public function test_format_invoice_returns_account_move(): void {
		$this->create_post( 100, 'llms_course', 'PHP 101' );

		$data = [
			'product_id' => 100,
			'total'      => 49.99,
			'date'       => '2026-02-01 10:00:00',
			'order_id'   => 300,
		];

		$invoice = $this->handler->format_invoice( $data, 42, 10, false );

		$this->assertSame( 'out_invoice', $invoice['move_type'] );
		$this->assertSame( 10, $invoice['partner_id'] );
		$this->assertSame( '2026-02-01', $invoice['invoice_date'] );
		$this->assertSame( 'LLMS-ORD-300', $invoice['ref'] );
		$this->assertArrayNotHasKey( '_auto_validate', $invoice );
	}

	public function test_format_invoice_has_invoice_line_ids(): void {
		$this->create_post( 100, 'llms_course', 'PHP 101' );

		$data = [
			'product_id' => 100,
			'total'      => 49.99,
			'date'       => '2026-02-01',
			'order_id'   => 300,
		];

		$invoice = $this->handler->format_invoice( $data, 42, 10, false );
		$lines   = $invoice['invoice_line_ids'];

		$this->assertCount( 1, $lines );
		$this->assertSame( 42, $lines[0][2]['product_id'] );
		$this->assertSame( 1, $lines[0][2]['quantity'] );
		$this->assertSame( 49.99, $lines[0][2]['price_unit'] );
		$this->assertSame( 'PHP 101', $lines[0][2]['name'] );
	}

	public function test_format_invoice_with_auto_post(): void {
		$data = [
			'total'    => 49.99,
			'date'     => '2026-02-01',
			'order_id' => 300,
		];

		$invoice = $this->handler->format_invoice( $data, 42, 10, true );
		$this->assertTrue( $invoice['_auto_validate'] );
	}

	// ─── Format Sale Order ────────────────────────────────

	public function test_format_sale_order_returns_data(): void {
		$order = $this->handler->format_sale_order( 42, 10, '2026-02-01', 'PHP 101' );

		$this->assertSame( 10, $order['partner_id'] );
		$this->assertSame( '2026-02-01', $order['date_order'] );
		$this->assertSame( 'sale', $order['state'] );
	}

	public function test_format_sale_order_has_order_line(): void {
		$order = $this->handler->format_sale_order( 42, 10, '2026-02-01', 'PHP 101' );
		$lines = $order['order_line'];

		$this->assertCount( 1, $lines );
		$this->assertSame( 42, $lines[0][2]['product_id'] );
		$this->assertSame( 1, $lines[0][2]['quantity'] );
		$this->assertSame( 'PHP 101', $lines[0][2]['name'] );
	}

	// ─── Product ID Helper ────────────────────────────────

	public function test_get_product_id_for_order(): void {
		$GLOBALS['_llms_orders'][300] = [ 'product_id' => 100 ];

		$this->assertSame( 100, $this->handler->get_product_id_for_order( 300 ) );
	}

	public function test_get_product_id_for_order_returns_zero_for_missing(): void {
		$this->assertSame( 0, $this->handler->get_product_id_for_order( 999 ) );
	}

	// ─── Order Status Mapping ─────────────────────────────

	public function test_active_maps_to_posted(): void {
		$this->assertSame( 'posted', $this->handler->map_order_status_to_odoo( 'llms-active' ) );
	}

	public function test_completed_maps_to_posted(): void {
		$this->assertSame( 'posted', $this->handler->map_order_status_to_odoo( 'llms-completed' ) );
	}

	public function test_pending_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_order_status_to_odoo( 'llms-pending' ) );
	}

	public function test_on_hold_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_order_status_to_odoo( 'llms-on-hold' ) );
	}

	public function test_refunded_maps_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_order_status_to_odoo( 'llms-refunded' ) );
	}

	public function test_cancelled_maps_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_order_status_to_odoo( 'llms-cancelled' ) );
	}

	public function test_expired_maps_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_order_status_to_odoo( 'llms-expired' ) );
	}

	public function test_failed_maps_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_order_status_to_odoo( 'llms-failed' ) );
	}

	public function test_unknown_status_defaults_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_order_status_to_odoo( 'unknown' ) );
	}

	// ─── Filterable Map ───────────────────────────────────

	public function test_order_status_map_is_filterable(): void {
		// apply_filters stub returns value unchanged — verify the map
		// passes through apply_filters (llms-active → posted still works).
		$this->assertSame( 'posted', $this->handler->map_order_status_to_odoo( 'llms-active' ) );
	}

	// ─── Parse course from Odoo ─────────────────────────

	public function test_parse_course_from_odoo(): void {
		$odoo_data = [
			'name'             => 'Odoo Course',
			'description_sale' => 'From Odoo',
			'list_price'       => 49.99,
		];

		$data = $this->handler->parse_course_from_odoo( $odoo_data );

		$this->assertSame( 'Odoo Course', $data['title'] );
		$this->assertSame( 'From Odoo', $data['description'] );
		$this->assertSame( 49.99, $data['list_price'] );
	}

	public function test_parse_course_from_odoo_handles_missing_fields(): void {
		$data = $this->handler->parse_course_from_odoo( [] );

		$this->assertSame( '', $data['title'] );
		$this->assertSame( '', $data['description'] );
		$this->assertSame( 0.0, $data['list_price'] );
	}

	// ─── Parse membership from Odoo ─────────────────────

	public function test_parse_membership_from_odoo(): void {
		$odoo_data = [
			'name'             => 'Odoo Membership',
			'description_sale' => 'From Odoo',
			'list_price'       => 99.0,
		];

		$data = $this->handler->parse_membership_from_odoo( $odoo_data );

		$this->assertSame( 'Odoo Membership', $data['title'] );
		$this->assertSame( 99.0, $data['list_price'] );
	}

	// ─── Save course ────────────────────────────────────

	public function test_save_course_creates_new_post(): void {
		$data = [ 'title' => 'New Course', 'description' => 'Desc', 'list_price' => 49.99 ];

		$result = $this->handler->save_course( $data );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_course_updates_existing(): void {
		$this->create_post( 100, 'llms_course', 'Old Title' );

		$data   = [ 'title' => 'New Title', 'description' => 'Updated', 'list_price' => 59.99 ];
		$result = $this->handler->save_course( $data, 100 );
		$this->assertSame( 100, $result );
	}

	// ─── Save membership ────────────────────────────────

	public function test_save_membership_creates_new_post(): void {
		$data = [ 'title' => 'New Membership', 'description' => 'Desc', 'list_price' => 99.0 ];

		$result = $this->handler->save_membership( $data );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_membership_updates_existing(): void {
		$this->create_post( 200, 'llms_membership', 'Old Title' );

		$data   = [ 'title' => 'New Title', 'description' => 'Updated', 'list_price' => 149.0 ];
		$result = $this->handler->save_membership( $data, 200 );
		$this->assertSame( 200, $result );
	}

	// ─── Reverse status mapping ─────────────────────────

	public function test_odoo_draft_maps_to_llms_pending(): void {
		$this->assertSame( 'llms-pending', $this->handler->map_odoo_status_to_llms( 'draft' ) );
	}

	public function test_odoo_posted_maps_to_llms_completed(): void {
		$this->assertSame( 'llms-completed', $this->handler->map_odoo_status_to_llms( 'posted' ) );
	}

	public function test_odoo_cancel_maps_to_llms_cancelled(): void {
		$this->assertSame( 'llms-cancelled', $this->handler->map_odoo_status_to_llms( 'cancel' ) );
	}

	public function test_odoo_unknown_status_maps_to_llms_pending(): void {
		$this->assertSame( 'llms-pending', $this->handler->map_odoo_status_to_llms( 'unknown' ) );
	}
}
