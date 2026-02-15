<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\MemberPress_Handler;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for MemberPress_Handler.
 *
 * Tests plan/transaction/subscription loading and status mapping.
 */
class MemberPressHandlerTest extends Module_Test_Case {

	private MemberPress_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new MemberPress_Handler( new Logger( 'test' ) );
	}

	// ─── load_plan ────────────────────────────────────────

	public function test_load_plan_returns_data(): void {
		$post              = new \stdClass();
		$post->ID          = 10;
		$post->post_title  = 'Monthly Premium';
		$post->post_type   = 'memberpressproduct';
		$post->post_status = 'publish';

		$GLOBALS['_wp_posts'][10]     = $post;
		$GLOBALS['_wp_post_meta'][10] = [ '_mepr_product_price' => '29.99' ];

		$data = $this->handler->load_plan( 10 );

		$this->assertSame( 'Monthly Premium', $data['plan_name'] );
		$this->assertTrue( $data['membership'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_plan_empty_for_nonexistent(): void {
		$data = $this->handler->load_plan( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_plan_empty_for_wrong_post_type(): void {
		$post              = new \stdClass();
		$post->ID          = 10;
		$post->post_title  = 'Regular Post';
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		$GLOBALS['_wp_posts'][10] = $post;

		$data = $this->handler->load_plan( 10 );
		$this->assertEmpty( $data );
	}

	public function test_load_plan_includes_price(): void {
		$post              = new \stdClass();
		$post->ID          = 10;
		$post->post_title  = 'Annual Plan';
		$post->post_type   = 'memberpressproduct';
		$post->post_status = 'publish';

		$GLOBALS['_wp_posts'][10]     = $post;
		$GLOBALS['_wp_post_meta'][10] = [ '_mepr_product_price' => '99.00' ];

		$data = $this->handler->load_plan( 10 );
		$this->assertSame( 99.0, $data['list_price'] );
	}

	// ─── load_transaction ─────────────────────────────────

	public function test_load_transaction_returns_invoice_data(): void {
		$GLOBALS['_mepr_transactions'][50] = [
			'id'         => 50,
			'user_id'    => 42,
			'product_id' => 10,
			'amount'     => 29.99,
			'trans_num'  => 'mp-txn-50',
			'created_at' => '2026-02-10 14:30:00',
			'status'     => 'complete',
		];

		// Create plan post for name resolution.
		$post              = new \stdClass();
		$post->ID          = 10;
		$post->post_title  = 'Premium Plan';
		$post->post_type   = 'memberpressproduct';
		$post->post_status = 'publish';
		$GLOBALS['_wp_posts'][10] = $post;

		$data = $this->handler->load_transaction( 50, 100, 200 );

		$this->assertSame( 'out_invoice', $data['move_type'] );
		$this->assertSame( 100, $data['partner_id'] );
		$this->assertSame( '2026-02-10', $data['invoice_date'] );
		$this->assertSame( 'mp-txn-50', $data['ref'] );
	}

	public function test_load_transaction_includes_invoice_line_ids(): void {
		$GLOBALS['_mepr_transactions'][50] = [
			'id'         => 50,
			'user_id'    => 42,
			'product_id' => 10,
			'amount'     => 29.99,
			'trans_num'  => 'mp-txn-50',
			'created_at' => '2026-02-10 14:30:00',
			'status'     => 'complete',
		];

		$post              = new \stdClass();
		$post->ID          = 10;
		$post->post_title  = 'Premium Plan';
		$post->post_type   = 'memberpressproduct';
		$post->post_status = 'publish';
		$GLOBALS['_wp_posts'][10] = $post;

		$data  = $this->handler->load_transaction( 50, 100, 200 );
		$lines = $data['invoice_line_ids'];

		$this->assertIsArray( $lines );
		$this->assertCount( 1, $lines );
		$this->assertSame( 0, $lines[0][0] );
		$this->assertSame( 0, $lines[0][1] );
		$this->assertSame( 200, $lines[0][2]['product_id'] );
		$this->assertSame( 1, $lines[0][2]['quantity'] );
		$this->assertSame( 29.99, $lines[0][2]['price_unit'] );
		$this->assertSame( 'Premium Plan', $lines[0][2]['name'] );
	}

	public function test_load_transaction_empty_for_nonexistent(): void {
		$data = $this->handler->load_transaction( 999, 100, 200 );
		$this->assertEmpty( $data );
	}

	public function test_load_transaction_includes_ref(): void {
		$GLOBALS['_mepr_transactions'][50] = [
			'id'         => 50,
			'user_id'    => 42,
			'product_id' => 10,
			'amount'     => 29.99,
			'trans_num'  => 'REF-ABC-123',
			'created_at' => '2026-02-10 14:30:00',
			'status'     => 'complete',
		];

		$data = $this->handler->load_transaction( 50, 100, 200 );
		$this->assertSame( 'REF-ABC-123', $data['ref'] );
	}

	// ─── load_subscription ────────────────────────────────

	public function test_load_subscription_returns_data(): void {
		$GLOBALS['_mepr_subscriptions'][30] = [
			'id'         => 30,
			'user_id'    => 42,
			'product_id' => 10,
			'price'      => '29.99',
			'status'     => 'active',
			'created_at' => '2026-01-01 00:00:00',
		];

		$data = $this->handler->load_subscription( 30 );

		$this->assertSame( 42, $data['user_id'] );
		$this->assertSame( 10, $data['plan_id'] );
		$this->assertSame( '2026-01-01', $data['date_from'] );
		$this->assertFalse( $data['date_to'] );
		$this->assertSame( 'paid', $data['state'] );
	}

	public function test_load_subscription_empty_for_nonexistent(): void {
		$data = $this->handler->load_subscription( 999 );
		$this->assertEmpty( $data );
	}

	// ─── Transaction status mapping ───────────────────────

	public function test_txn_status_complete_to_posted(): void {
		$this->assertSame( 'posted', $this->handler->map_txn_status_to_odoo( 'complete' ) );
	}

	public function test_txn_status_pending_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_txn_status_to_odoo( 'pending' ) );
	}

	public function test_txn_status_failed_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_txn_status_to_odoo( 'failed' ) );
	}

	public function test_txn_status_refunded_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_txn_status_to_odoo( 'refunded' ) );
	}

	public function test_unknown_txn_status_defaults_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_txn_status_to_odoo( 'unknown' ) );
	}

	public function test_txn_status_map_is_filterable(): void {
		$result = $this->handler->map_txn_status_to_odoo( 'complete' );
		$this->assertSame( 'posted', $result );
	}

	// ─── Subscription status mapping ──────────────────────

	public function test_sub_status_active_to_paid(): void {
		$this->assertSame( 'paid', $this->handler->map_sub_status_to_odoo( 'active' ) );
	}

	public function test_sub_status_suspended_to_waiting(): void {
		$this->assertSame( 'waiting', $this->handler->map_sub_status_to_odoo( 'suspended' ) );
	}

	public function test_sub_status_cancelled_to_cancelled(): void {
		$this->assertSame( 'cancelled', $this->handler->map_sub_status_to_odoo( 'cancelled' ) );
	}

	public function test_sub_status_expired_to_old(): void {
		$this->assertSame( 'old', $this->handler->map_sub_status_to_odoo( 'expired' ) );
	}

	public function test_sub_status_paused_to_waiting(): void {
		$this->assertSame( 'waiting', $this->handler->map_sub_status_to_odoo( 'paused' ) );
	}

	public function test_sub_status_stopped_to_cancelled(): void {
		$this->assertSame( 'cancelled', $this->handler->map_sub_status_to_odoo( 'stopped' ) );
	}

	public function test_unknown_sub_status_defaults_to_none(): void {
		$this->assertSame( 'none', $this->handler->map_sub_status_to_odoo( 'unknown' ) );
	}

	public function test_sub_status_map_is_filterable(): void {
		$result = $this->handler->map_sub_status_to_odoo( 'active' );
		$this->assertSame( 'paid', $result );
	}
}
