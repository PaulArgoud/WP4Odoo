<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Modules\LearnPress_Handler;
use WP4Odoo\Logger;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for LearnPress_Handler.
 *
 * Tests data loading for courses, orders, and enrollments,
 * invoice/sale order formatting, Odoo parsing, and save methods.
 *
 */
#[CoversClass(\WP4Odoo\Modules\LearnPress_Handler::class)]
class LearnPressHandlerTest extends Module_Test_Case {

	private LearnPress_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new LearnPress_Handler( new Logger( 'learnpress', wp4odoo_test_settings() ) );
	}

	// ─── Helpers ────────────────────────────────────────────

	private function create_post( int $id, string $type, string $title = '', string $content = '' ): void {
		$post                = new \stdClass();
		$post->ID            = $id;
		$post->post_type     = $type;
		$post->post_title    = $title;
		$post->post_content  = $content;
		$post->post_status   = 'publish';
		$post->post_author   = 1;
		$post->post_date_gmt = '2026-02-10 12:00:00';

		$GLOBALS['_wp_posts'][ $id ] = $post;
	}

	private function create_user( int $id, string $email = 'student@example.com', string $name = 'Test Student' ): void {
		$user                = new \stdClass();
		$user->ID            = $id;
		$user->user_email    = $email;
		$user->display_name  = $name;

		$GLOBALS['_wp_users'][ $id ] = $user;
	}

	// ─── load_course ────────────────────────────────────────

	public function test_load_course_returns_data(): void {
		$this->create_post( 10, 'lp_course', 'PHP 101', 'Learn PHP basics' );
		$GLOBALS['_wp_post_meta'][10] = [ '_lp_price' => '49.99' ];

		$data = $this->handler->load_course( 10 );

		$this->assertSame( 'PHP 101', $data['title'] );
		$this->assertSame( 'Learn PHP basics', $data['description'] );
		$this->assertSame( 49.99, $data['list_price'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_course_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_course( 999 ) );
	}

	public function test_load_course_empty_for_wrong_post_type(): void {
		$this->create_post( 10, 'post', 'Blog Post' );

		$this->assertSame( [], $this->handler->load_course( 10 ) );
	}

	public function test_load_course_handles_free_course(): void {
		$this->create_post( 10, 'lp_course', 'Free Course' );

		$data = $this->handler->load_course( 10 );

		$this->assertSame( 0.0, $data['list_price'] );
	}

	public function test_load_course_strips_html_from_description(): void {
		$this->create_post( 10, 'lp_course', 'Course', '<p>Some <b>HTML</b></p>' );
		$GLOBALS['_wp_post_meta'][10] = [ '_lp_price' => '10' ];

		$data = $this->handler->load_course( 10 );

		$this->assertStringNotContainsString( '<p>', $data['description'] );
		$this->assertStringNotContainsString( '<b>', $data['description'] );
	}

	public function test_load_course_includes_type_service(): void {
		$this->create_post( 10, 'lp_course', 'Course Title' );
		$GLOBALS['_wp_post_meta'][10] = [ '_lp_price' => '29.99' ];

		$data = $this->handler->load_course( 10 );

		$this->assertSame( 'service', $data['type'] );
	}

	// ─── load_order ─────────────────────────────────────────

	public function test_load_order_returns_data(): void {
		$this->create_post( 30, 'lp_order', 'Order #30' );
		$GLOBALS['_wp_post_meta'][30] = [
			'_lp_course_id'    => [ '100' ],
			'_user_id'         => [ '5' ],
			'_order_total'     => [ '49.99' ],
			'_order_currency'  => [ 'EUR' ],
		];

		$data = $this->handler->load_order( 30 );

		$this->assertSame( 100, $data['course_id'] );
		$this->assertSame( 5, $data['user_id'] );
		$this->assertSame( 49.99, $data['amount'] );
		$this->assertSame( 'EUR', $data['currency'] );
	}

	public function test_load_order_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_order( 999 ) );
	}

	public function test_load_order_empty_for_wrong_post_type(): void {
		$this->create_post( 30, 'post', 'Not an order' );

		$this->assertSame( [], $this->handler->load_order( 30 ) );
	}

	public function test_load_order_includes_order_id(): void {
		$this->create_post( 30, 'lp_order', 'Order' );
		$GLOBALS['_wp_post_meta'][30] = [
			'_lp_course_id' => [ '100' ],
		];

		$data = $this->handler->load_order( 30 );

		$this->assertSame( 30, $data['order_id'] );
	}

	public function test_load_order_includes_created_at(): void {
		$this->create_post( 30, 'lp_order', 'Order' );
		$GLOBALS['_wp_post_meta'][30] = [];

		$data = $this->handler->load_order( 30 );

		$this->assertSame( '2026-02-10 12:00:00', $data['created_at'] );
	}

	public function test_load_order_defaults_currency_to_usd(): void {
		$this->create_post( 30, 'lp_order', 'Order' );
		$GLOBALS['_wp_post_meta'][30] = [
			'_lp_course_id' => [ '100' ],
		];

		$data = $this->handler->load_order( 30 );

		$this->assertSame( 'USD', $data['currency'] );
	}

	public function test_load_order_falls_back_to_post_author(): void {
		$post                = new \stdClass();
		$post->ID            = 30;
		$post->post_type     = 'lp_order';
		$post->post_title    = 'Order';
		$post->post_content  = '';
		$post->post_status   = 'publish';
		$post->post_author   = 7;
		$post->post_date_gmt = '2026-02-10 12:00:00';

		$GLOBALS['_wp_posts'][30]     = $post;
		$GLOBALS['_wp_post_meta'][30] = [
			'_lp_course_id' => [ '100' ],
		];

		$data = $this->handler->load_order( 30 );

		$this->assertSame( 7, $data['user_id'] );
	}

	// ─── load_enrollment ────────────────────────────────────

	public function test_load_enrollment_returns_data(): void {
		$this->create_user( 5, 'student@example.com', 'Jane Doe' );
		$this->create_post( 10, 'lp_course', 'PHP 101' );

		$this->wpdb->get_row_return = [
			'user_item_id' => 1,
			'user_id'      => 5,
			'item_id'      => 10,
			'item_type'    => 'lp_course',
			'start_time'   => '2026-02-01 10:00:00',
		];

		$data = $this->handler->load_enrollment( 5, 10 );

		$this->assertSame( 5, $data['user_id'] );
		$this->assertSame( 10, $data['course_id'] );
		$this->assertSame( 'student@example.com', $data['user_email'] );
		$this->assertSame( 'Jane Doe', $data['user_name'] );
	}

	public function test_load_enrollment_empty_for_missing_user(): void {
		$this->create_post( 10, 'lp_course', 'PHP 101' );

		$this->assertSame( [], $this->handler->load_enrollment( 999, 10 ) );
	}

	public function test_load_enrollment_empty_for_missing_course(): void {
		$this->create_user( 5 );

		$this->assertSame( [], $this->handler->load_enrollment( 5, 999 ) );
	}

	public function test_load_enrollment_empty_for_wrong_post_type(): void {
		$this->create_user( 5 );
		$this->create_post( 10, 'post', 'Not a course' );

		$this->assertSame( [], $this->handler->load_enrollment( 5, 10 ) );
	}

	public function test_load_enrollment_falls_back_to_today(): void {
		$this->create_user( 5 );
		$this->create_post( 10, 'lp_course', 'PHP 101' );

		$data = $this->handler->load_enrollment( 5, 10 );

		$this->assertSame( gmdate( 'Y-m-d' ), $data['date'] );
	}

	public function test_load_enrollment_uses_start_time_from_db(): void {
		$this->create_user( 5 );
		$this->create_post( 10, 'lp_course', 'PHP 101' );

		$this->wpdb->get_row_return = [
			'user_item_id' => 1,
			'user_id'      => 5,
			'item_id'      => 10,
			'item_type'    => 'lp_course',
			'start_time'   => '2026-01-15 08:30:00',
		];

		$data = $this->handler->load_enrollment( 5, 10 );

		$this->assertSame( '2026-01-15', $data['date'] );
	}

	// ─── format_invoice ─────────────────────────────────────

	public function test_format_invoice_returns_account_move(): void {
		$this->create_post( 10, 'lp_course', 'PHP 101' );

		$data = [
			'course_id'  => 10,
			'amount'     => 49.99,
			'created_at' => '2026-02-10 12:00:00',
			'order_id'   => 30,
		];

		$invoice = $this->handler->format_invoice( $data, 42, 100, false );

		$this->assertSame( 'out_invoice', $invoice['move_type'] );
		$this->assertSame( 100, $invoice['partner_id'] );
		$this->assertSame( '2026-02-10', $invoice['invoice_date'] );
		$this->assertSame( 'LP-ORDER-30', $invoice['ref'] );
	}

	public function test_format_invoice_has_invoice_line_ids(): void {
		$this->create_post( 10, 'lp_course', 'PHP 101' );

		$data = [
			'course_id'  => 10,
			'amount'     => 49.99,
			'created_at' => '2026-02-10',
			'order_id'   => 30,
		];

		$invoice = $this->handler->format_invoice( $data, 42, 100, false );
		$lines   = $invoice['invoice_line_ids'];

		$this->assertCount( 1, $lines );
		$this->assertSame( 0, $lines[0][0] );
		$this->assertSame( 0, $lines[0][1] );
		$this->assertSame( 42, $lines[0][2]['product_id'] );
		$this->assertSame( 49.99, $lines[0][2]['price_unit'] );
		$this->assertSame( 'PHP 101', $lines[0][2]['name'] );
	}

	public function test_format_invoice_with_auto_post(): void {
		$data = [
			'amount'     => 10.0,
			'created_at' => '2026-02-10',
			'order_id'   => 30,
		];

		$invoice = $this->handler->format_invoice( $data, 42, 100, true );

		$this->assertTrue( $invoice['_auto_validate'] );
	}

	public function test_format_invoice_without_auto_post(): void {
		$data = [
			'amount'     => 10.0,
			'created_at' => '2026-02-10',
			'order_id'   => 30,
		];

		$invoice = $this->handler->format_invoice( $data, 42, 100, false );

		$this->assertArrayNotHasKey( '_auto_validate', $invoice );
	}

	public function test_format_invoice_line_has_quantity_one(): void {
		$data = [
			'amount'     => 25.0,
			'created_at' => '2026-02-10',
			'order_id'   => 30,
		];

		$invoice = $this->handler->format_invoice( $data, 42, 100, false );

		$this->assertSame( 1, $invoice['invoice_line_ids'][0][2]['quantity'] );
	}

	// ─── format_sale_order ──────────────────────────────────

	public function test_format_sale_order_returns_data(): void {
		$order = $this->handler->format_sale_order( 42, 100, '2026-02-01', 'PHP 101' );

		$this->assertSame( 100, $order['partner_id'] );
		$this->assertSame( '2026-02-01', $order['date_order'] );
		$this->assertSame( 'sale', $order['state'] );
	}

	public function test_format_sale_order_has_order_line(): void {
		$order = $this->handler->format_sale_order( 42, 100, '2026-02-01', 'PHP 101' );
		$lines = $order['order_line'];

		$this->assertCount( 1, $lines );
		$this->assertSame( 42, $lines[0][2]['product_id'] );
		$this->assertSame( 1, $lines[0][2]['quantity'] );
		$this->assertSame( 'PHP 101', $lines[0][2]['name'] );
	}

	public function test_format_sale_order_uses_fallback_name(): void {
		$order = $this->handler->format_sale_order( 42, 100, '2026-02-01' );

		$this->assertSame( 'LearnPress enrollment', $order['order_line'][0][2]['name'] );
	}

	public function test_format_sale_order_one2many_tuple_structure(): void {
		$order = $this->handler->format_sale_order( 42, 100, '2026-02-01', 'Test' );
		$line  = $order['order_line'][0];

		$this->assertSame( 0, $line[0] );
		$this->assertSame( 0, $line[1] );
		$this->assertIsArray( $line[2] );
	}

	// ─── parse_course_from_odoo ─────────────────────────────

	public function test_parse_course_from_odoo(): void {
		$odoo_data = [
			'name'             => 'Odoo Course',
			'description_sale' => 'A description',
			'list_price'       => 79.99,
		];

		$data = $this->handler->parse_course_from_odoo( $odoo_data );

		$this->assertSame( 'Odoo Course', $data['title'] );
		$this->assertSame( 'A description', $data['description'] );
		$this->assertSame( 79.99, $data['list_price'] );
	}

	public function test_parse_course_from_odoo_handles_empty_data(): void {
		$data = $this->handler->parse_course_from_odoo( [] );

		$this->assertSame( '', $data['title'] );
		$this->assertSame( '', $data['description'] );
		$this->assertSame( 0.0, $data['list_price'] );
	}

	// ─── save_course ────────────────────────────────────────

	public function test_save_course_creates_new_post(): void {
		$data   = [ 'title' => 'New Course', 'description' => 'Content', 'list_price' => 49.99 ];
		$result = $this->handler->save_course( $data );

		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_course_updates_existing(): void {
		$this->create_post( 10, 'lp_course', 'Old Title' );

		$data   = [ 'title' => 'Updated Course', 'description' => 'Updated', 'list_price' => 59.99 ];
		$result = $this->handler->save_course( $data, 10 );

		$this->assertSame( 10, $result );
	}

	public function test_save_course_saves_price_meta(): void {
		$data   = [ 'title' => 'Course', 'description' => 'Desc', 'list_price' => 39.99 ];
		$result = $this->handler->save_course( $data );

		$this->assertGreaterThan( 0, $result );
		$this->assertSame( '39.99', $GLOBALS['_wp_post_meta'][ $result ]['_lp_price'] ?? '' );
	}
}
