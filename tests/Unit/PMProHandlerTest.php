<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\PMPro_Handler;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for PMPro_Handler.
 *
 * Tests level/order/membership loading and status mapping.
 */
class PMProHandlerTest extends Module_Test_Case {

	private PMPro_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new PMPro_Handler( new Logger( 'test' ) );
	}

	// ─── load_level ──────────────────────────────────────

	public function test_load_level_returns_data(): void {
		$level       = new \PMPro_Membership_Level();
		$level->id   = 1;
		$level->name = 'Gold Membership';
		$level->initial_payment = '49.99';
		$level->billing_amount  = '29.99';

		$GLOBALS['_pmpro_levels'][1] = $level;

		$data = $this->handler->load_level( 1 );

		$this->assertSame( 'Gold Membership', $data['level_name'] );
		$this->assertTrue( $data['membership'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_level_empty_for_nonexistent(): void {
		$data = $this->handler->load_level( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_level_uses_billing_amount_when_recurring(): void {
		$level       = new \PMPro_Membership_Level();
		$level->id   = 1;
		$level->name = 'Monthly';
		$level->initial_payment = '99.00';
		$level->billing_amount  = '29.99';

		$GLOBALS['_pmpro_levels'][1] = $level;

		$data = $this->handler->load_level( 1 );
		$this->assertSame( 29.99, $data['list_price'] );
	}

	public function test_load_level_uses_initial_payment_when_not_recurring(): void {
		$level       = new \PMPro_Membership_Level();
		$level->id   = 2;
		$level->name = 'Lifetime';
		$level->initial_payment = '199.00';
		$level->billing_amount  = '0.00';

		$GLOBALS['_pmpro_levels'][2] = $level;

		$data = $this->handler->load_level( 2 );
		$this->assertSame( 199.0, $data['list_price'] );
	}

	// ─── load_order ──────────────────────────────────────

	public function test_load_order_returns_invoice_data(): void {
		$GLOBALS['_pmpro_orders'][50] = [
			'id'            => 50,
			'user_id'       => 42,
			'membership_id' => 1,
			'total'         => '49.99',
			'code'          => 'PMPRO-ORDER-50',
			'timestamp'     => '2026-02-10 14:30:00',
			'status'        => 'success',
		];

		// Create level for name resolution.
		$level       = new \PMPro_Membership_Level();
		$level->id   = 1;
		$level->name = 'Premium Plan';
		$GLOBALS['_pmpro_levels'][1] = $level;

		$data = $this->handler->load_order( 50, 100, 200 );

		$this->assertSame( 'out_invoice', $data['move_type'] );
		$this->assertSame( 100, $data['partner_id'] );
		$this->assertSame( '2026-02-10', $data['invoice_date'] );
		$this->assertSame( 'PMPRO-ORDER-50', $data['ref'] );
	}

	public function test_load_order_includes_invoice_line_ids(): void {
		$GLOBALS['_pmpro_orders'][50] = [
			'id'            => 50,
			'user_id'       => 42,
			'membership_id' => 1,
			'total'         => '49.99',
			'code'          => 'PMPRO-ORDER-50',
			'timestamp'     => '2026-02-10 14:30:00',
			'status'        => 'success',
		];

		$level       = new \PMPro_Membership_Level();
		$level->id   = 1;
		$level->name = 'Premium Plan';
		$GLOBALS['_pmpro_levels'][1] = $level;

		$data  = $this->handler->load_order( 50, 100, 200 );
		$lines = $data['invoice_line_ids'];

		$this->assertIsArray( $lines );
		$this->assertCount( 1, $lines );
		$this->assertSame( 0, $lines[0][0] );
		$this->assertSame( 0, $lines[0][1] );
		$this->assertSame( 200, $lines[0][2]['product_id'] );
		$this->assertSame( 1, $lines[0][2]['quantity'] );
		$this->assertSame( 49.99, $lines[0][2]['price_unit'] );
		$this->assertSame( 'Premium Plan', $lines[0][2]['name'] );
	}

	public function test_load_order_empty_for_nonexistent(): void {
		$data = $this->handler->load_order( 999, 100, 200 );
		$this->assertEmpty( $data );
	}

	public function test_load_order_includes_ref_from_code(): void {
		$GLOBALS['_pmpro_orders'][50] = [
			'id'            => 50,
			'user_id'       => 42,
			'membership_id' => 1,
			'total'         => '49.99',
			'code'          => 'REF-ABC-123',
			'timestamp'     => '2026-02-10 14:30:00',
			'status'        => 'success',
		];

		$data = $this->handler->load_order( 50, 100, 200 );
		$this->assertSame( 'REF-ABC-123', $data['ref'] );
	}

	public function test_load_order_uses_total_for_price(): void {
		$GLOBALS['_pmpro_orders'][50] = [
			'id'            => 50,
			'user_id'       => 42,
			'membership_id' => 1,
			'total'         => '79.50',
			'code'          => 'PMPRO-50',
			'timestamp'     => '2026-02-10 14:30:00',
			'status'        => 'success',
		];

		$data  = $this->handler->load_order( 50, 100, 200 );
		$lines = $data['invoice_line_ids'];

		$this->assertSame( 79.50, $lines[0][2]['price_unit'] );
	}

	// ─── load_membership ─────────────────────────────────

	public function test_load_membership_returns_data(): void {
		$row              = new \stdClass();
		$row->user_id     = 42;
		$row->membership_id = 1;
		$row->status      = 'active';
		$row->startdate   = '2026-01-01 00:00:00';
		$row->enddate     = '2027-01-01 00:00:00';

		$this->wpdb->get_row_return = $row;

		$data = $this->handler->load_membership( 100 );

		$this->assertSame( 42, $data['user_id'] );
		$this->assertSame( 1, $data['level_id'] );
		$this->assertSame( '2026-01-01', $data['date_from'] );
		$this->assertSame( 'paid', $data['state'] );
	}

	public function test_load_membership_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_membership( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_membership_date_to_false_when_zero_date(): void {
		$row              = new \stdClass();
		$row->user_id     = 42;
		$row->membership_id = 1;
		$row->status      = 'active';
		$row->startdate   = '2026-01-01 00:00:00';
		$row->enddate     = '0000-00-00 00:00:00';

		$this->wpdb->get_row_return = $row;

		$data = $this->handler->load_membership( 100 );
		$this->assertFalse( $data['date_to'] );
	}

	public function test_load_membership_date_to_present_when_set(): void {
		$row              = new \stdClass();
		$row->user_id     = 42;
		$row->membership_id = 1;
		$row->status      = 'active';
		$row->startdate   = '2026-01-01 00:00:00';
		$row->enddate     = '2027-06-15 00:00:00';

		$this->wpdb->get_row_return = $row;

		$data = $this->handler->load_membership( 100 );
		$this->assertSame( '2027-06-15', $data['date_to'] );
	}

	// ─── get_level_id_for_order ──────────────────────────

	public function test_get_level_id_for_order(): void {
		$GLOBALS['_pmpro_orders'][50] = [
			'id'            => 50,
			'membership_id' => 3,
		];

		$this->assertSame( 3, $this->handler->get_level_id_for_order( 50 ) );
	}

	// ─── get_level_id_for_membership ─────────────────────

	public function test_get_level_id_for_membership(): void {
		$this->wpdb->get_var_return = 5;

		$result = $this->handler->get_level_id_for_membership( 100 );
		$this->assertSame( 5, $result );
	}

	// ─── Order status mapping ────────────────────────────

	public function test_order_status_success_to_posted(): void {
		$this->assertSame( 'posted', $this->handler->map_order_status_to_odoo( 'success' ) );
	}

	public function test_order_status_pending_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_order_status_to_odoo( 'pending' ) );
	}

	public function test_order_status_refunded_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_order_status_to_odoo( 'refunded' ) );
	}

	public function test_order_status_error_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_order_status_to_odoo( 'error' ) );
	}

	public function test_order_status_review_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_order_status_to_odoo( 'review' ) );
	}

	public function test_order_status_token_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_order_status_to_odoo( 'token' ) );
	}

	public function test_unknown_order_status_defaults_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_order_status_to_odoo( 'unknown' ) );
	}

	public function test_order_status_map_is_filterable(): void {
		$result = $this->handler->map_order_status_to_odoo( 'success' );
		$this->assertSame( 'posted', $result );
	}

	// ─── Membership status mapping ───────────────────────

	public function test_membership_status_active_to_paid(): void {
		$this->assertSame( 'paid', $this->handler->map_membership_status_to_odoo( 'active' ) );
	}

	public function test_membership_status_admin_cancelled_to_cancelled(): void {
		$this->assertSame( 'cancelled', $this->handler->map_membership_status_to_odoo( 'admin_cancelled' ) );
	}

	public function test_membership_status_admin_changed_to_old(): void {
		$this->assertSame( 'old', $this->handler->map_membership_status_to_odoo( 'admin_changed' ) );
	}

	public function test_membership_status_cancelled_to_cancelled(): void {
		$this->assertSame( 'cancelled', $this->handler->map_membership_status_to_odoo( 'cancelled' ) );
	}

	public function test_membership_status_changed_to_old(): void {
		$this->assertSame( 'old', $this->handler->map_membership_status_to_odoo( 'changed' ) );
	}

	public function test_membership_status_expired_to_old(): void {
		$this->assertSame( 'old', $this->handler->map_membership_status_to_odoo( 'expired' ) );
	}

	public function test_membership_status_inactive_to_none(): void {
		$this->assertSame( 'none', $this->handler->map_membership_status_to_odoo( 'inactive' ) );
	}

	public function test_unknown_membership_status_defaults_to_none(): void {
		$this->assertSame( 'none', $this->handler->map_membership_status_to_odoo( 'unknown' ) );
	}

	public function test_membership_status_map_is_filterable(): void {
		$result = $this->handler->map_membership_status_to_odoo( 'active' );
		$this->assertSame( 'paid', $result );
	}
}
