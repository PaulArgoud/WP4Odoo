<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Modules\LearnDash_Handler;
use WP4Odoo\Logger;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for LearnDash_Handler.
 *
 * Tests data loading for courses, groups, transactions, enrollments,
 * invoice/sale order formatting, Odoo parsing, and save methods.
 *
 */
#[CoversClass(\WP4Odoo\Modules\LearnDash_Handler::class)]
class LearnDashHandlerTest extends Module_Test_Case {

	private LearnDash_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_learndash_prices'] = [];
		$this->handler                = new LearnDash_Handler( new Logger( 'test' ) );
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
		$this->create_post( 10, 'sfwd-courses', 'PHP 101', 'Learn PHP basics' );
		$GLOBALS['_learndash_prices'][10] = [ 'type' => 'paynow', 'price' => '49.99' ];

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
		$this->create_post( 10, 'sfwd-courses', 'Free Course' );

		$data = $this->handler->load_course( 10 );

		$this->assertSame( 0.0, $data['list_price'] );
	}

	public function test_load_course_strips_html_from_description(): void {
		$this->create_post( 10, 'sfwd-courses', 'Course', '<p>Some <b>HTML</b></p>' );
		$GLOBALS['_learndash_prices'][10] = [ 'type' => 'paynow', 'price' => '10' ];

		$data = $this->handler->load_course( 10 );

		$this->assertStringNotContainsString( '<p>', $data['description'] );
		$this->assertStringNotContainsString( '<b>', $data['description'] );
	}

	// ─── load_group ─────────────────────────────────────────

	public function test_load_group_returns_data(): void {
		$this->create_post( 20, 'groups', 'Premium Group', 'Group description' );
		$GLOBALS['_learndash_prices'][20] = [ 'type' => 'paynow', 'price' => '199.00' ];

		$data = $this->handler->load_group( 20 );

		$this->assertSame( 'Premium Group', $data['title'] );
		$this->assertSame( 'Group description', $data['description'] );
		$this->assertSame( 199.0, $data['list_price'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_group_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_group( 999 ) );
	}

	public function test_load_group_empty_for_wrong_post_type(): void {
		$this->create_post( 20, 'post', 'Not a group' );

		$this->assertSame( [], $this->handler->load_group( 20 ) );
	}

	// ─── load_transaction ───────────────────────────────────

	public function test_load_transaction_returns_data(): void {
		$this->create_post( 30, 'sfwd-transactions', 'Transaction #30' );
		// get_post_meta() with no key returns raw global — handler accesses $meta['key'][0].
		$GLOBALS['_wp_post_meta'][30] = [
			'course_id'            => [ '10' ],
			'user_id'              => [ '5' ],
			'mc_gross'             => [ '49.99' ],
			'ld_payment_processor' => [ 'stripe' ],
			'mc_currency'          => [ 'usd' ],
		];

		$data = $this->handler->load_transaction( 30 );

		$this->assertSame( 10, $data['course_id'] );
		$this->assertSame( 5, $data['user_id'] );
		$this->assertSame( 49.99, $data['amount'] );
		$this->assertSame( 'stripe', $data['gateway'] );
		$this->assertSame( 'USD', $data['currency'] );
		$this->assertSame( '2026-02-10 12:00:00', $data['created_at'] );
	}

	public function test_load_transaction_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_transaction( 999 ) );
	}

	public function test_load_transaction_empty_for_wrong_post_type(): void {
		$this->create_post( 30, 'post', 'Not a transaction' );

		$this->assertSame( [], $this->handler->load_transaction( 30 ) );
	}

	public function test_load_transaction_uses_post_id_fallback_for_course(): void {
		$this->create_post( 30, 'sfwd-transactions', 'Txn' );
		$GLOBALS['_wp_post_meta'][30] = [
			'post_id'  => [ '15' ],
			'user_id'  => [ '5' ],
			'mc_gross' => [ '10.00' ],
		];

		$data = $this->handler->load_transaction( 30 );

		$this->assertSame( 15, $data['course_id'] );
	}

	public function test_load_transaction_uses_stripe_price_fallback(): void {
		$this->create_post( 30, 'sfwd-transactions', 'Txn' );
		$GLOBALS['_wp_post_meta'][30] = [
			'course_id'       => [ '10' ],
			'user_id'         => [ '5' ],
			'stripe_price'    => [ '99.00' ],
			'stripe_currency' => [ 'eur' ],
		];

		$data = $this->handler->load_transaction( 30 );

		$this->assertSame( 99.0, $data['amount'] );
		$this->assertSame( 'EUR', $data['currency'] );
	}

	public function test_load_transaction_defaults_to_unknown_gateway(): void {
		$this->create_post( 30, 'sfwd-transactions', 'Txn' );
		$GLOBALS['_wp_post_meta'][30] = [
			'course_id' => [ '10' ],
			'user_id'   => [ '5' ],
		];

		$data = $this->handler->load_transaction( 30 );

		$this->assertSame( 'unknown', $data['gateway'] );
	}

	// ─── load_enrollment ────────────────────────────────────

	public function test_load_enrollment_returns_data(): void {
		$this->create_user( 5, 'student@example.com', 'Jane Doe' );
		$this->create_post( 10, 'sfwd-courses', 'PHP 101' );
		$GLOBALS['_wp_user_meta'][5] = [
			'ld_course_10_enrolled' => (string) strtotime( '2026-02-01' ),
		];

		$data = $this->handler->load_enrollment( 5, 10 );

		$this->assertSame( 5, $data['user_id'] );
		$this->assertSame( 10, $data['course_id'] );
		$this->assertSame( 'student@example.com', $data['user_email'] );
		$this->assertSame( 'Jane Doe', $data['user_name'] );
		$this->assertSame( '2026-02-01', $data['date'] );
	}

	public function test_load_enrollment_empty_for_missing_user(): void {
		$this->create_post( 10, 'sfwd-courses', 'PHP 101' );

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
		$this->create_post( 10, 'sfwd-courses', 'PHP 101' );

		$data = $this->handler->load_enrollment( 5, 10 );

		$this->assertSame( gmdate( 'Y-m-d' ), $data['date'] );
	}

	// ─── format_invoice ─────────────────────────────────────

	public function test_format_invoice_returns_account_move(): void {
		$this->create_post( 10, 'sfwd-courses', 'PHP 101' );

		$txn = [
			'course_id'      => 10,
			'amount'         => 49.99,
			'created_at'     => '2026-02-10 12:00:00',
			'transaction_id' => 30,
		];

		$invoice = $this->handler->format_invoice( $txn, 42, 100, false );

		$this->assertSame( 'out_invoice', $invoice['move_type'] );
		$this->assertSame( 100, $invoice['partner_id'] );
		$this->assertSame( '2026-02-10', $invoice['invoice_date'] );
		$this->assertSame( 'LD-TXN-30', $invoice['ref'] );
	}

	public function test_format_invoice_has_invoice_line_ids(): void {
		$this->create_post( 10, 'sfwd-courses', 'PHP 101' );

		$txn = [
			'course_id'      => 10,
			'amount'         => 49.99,
			'created_at'     => '2026-02-10',
			'transaction_id' => 30,
		];

		$invoice = $this->handler->format_invoice( $txn, 42, 100, false );
		$lines   = $invoice['invoice_line_ids'];

		$this->assertCount( 1, $lines );
		$this->assertSame( 0, $lines[0][0] );
		$this->assertSame( 0, $lines[0][1] );
		$this->assertSame( 42, $lines[0][2]['product_id'] );
		$this->assertSame( 49.99, $lines[0][2]['price_unit'] );
		$this->assertSame( 'PHP 101', $lines[0][2]['name'] );
	}

	public function test_format_invoice_with_auto_post(): void {
		$txn = [
			'amount'         => 10.0,
			'created_at'     => '2026-02-10',
			'transaction_id' => 30,
		];

		$invoice = $this->handler->format_invoice( $txn, 42, 100, true );

		$this->assertTrue( $invoice['_auto_validate'] );
	}

	public function test_format_invoice_without_auto_post(): void {
		$txn = [
			'amount'         => 10.0,
			'created_at'     => '2026-02-10',
			'transaction_id' => 30,
		];

		$invoice = $this->handler->format_invoice( $txn, 42, 100, false );

		$this->assertArrayNotHasKey( '_auto_validate', $invoice );
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

		$this->assertSame( 'LearnDash enrollment', $order['order_line'][0][2]['name'] );
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

	// ─── parse_group_from_odoo ──────────────────────────────

	public function test_parse_group_from_odoo(): void {
		$odoo_data = [
			'name'             => 'Odoo Group',
			'description_sale' => 'Group desc',
			'list_price'       => 199.0,
		];

		$data = $this->handler->parse_group_from_odoo( $odoo_data );

		$this->assertSame( 'Odoo Group', $data['title'] );
		$this->assertSame( 199.0, $data['list_price'] );
	}

	// ─── save_course ────────────────────────────────────────

	public function test_save_course_creates_new_post(): void {
		$data   = [ 'title' => 'New Course', 'description' => 'Content' ];
		$result = $this->handler->save_course( $data );

		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_course_updates_existing(): void {
		$this->create_post( 10, 'sfwd-courses', 'Old Title' );

		$data   = [ 'title' => 'Updated Course', 'description' => 'Updated' ];
		$result = $this->handler->save_course( $data, 10 );

		$this->assertSame( 10, $result );
	}

	// ─── save_group ─────────────────────────────────────────

	public function test_save_group_creates_new_post(): void {
		$data   = [ 'title' => 'New Group', 'description' => 'Desc' ];
		$result = $this->handler->save_group( $data );

		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_group_updates_existing(): void {
		$this->create_post( 20, 'groups', 'Old Group' );

		$data   = [ 'title' => 'Updated Group', 'description' => 'Updated' ];
		$result = $this->handler->save_group( $data, 20 );

		$this->assertSame( 20, $result );
	}
}
