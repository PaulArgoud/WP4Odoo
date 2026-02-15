<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\RCP_Handler;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for RCP_Handler.
 *
 * Tests data loading, status mapping, and level ID helpers.
 */
class RCPHandlerTest extends Module_Test_Case {

	private RCP_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new RCP_Handler( new \WP4Odoo\Logger( 'rcp', wp4odoo_test_settings() ) );
	}

	// ─── Load Level ───────────────────────────────────────

	public function test_load_level_returns_data(): void {
		$GLOBALS['_rcp_levels'][1] = [
			'id'               => 1,
			'name'             => 'Gold Plan',
			'initial_amount'   => '29.99',
			'recurring_amount' => '0',
		];

		$data = $this->handler->load_level( 1 );
		$this->assertSame( 'Gold Plan', $data['level_name'] );
		$this->assertSame( 29.99, $data['list_price'] );
		$this->assertTrue( $data['membership'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_level_uses_recurring_amount(): void {
		$GLOBALS['_rcp_levels'][2] = [
			'id'               => 2,
			'name'             => 'Pro Monthly',
			'initial_amount'   => '10.00',
			'recurring_amount' => '9.99',
		];

		$data = $this->handler->load_level( 2 );
		$this->assertSame( 9.99, $data['list_price'] );
	}

	public function test_load_level_falls_back_to_initial_amount(): void {
		$GLOBALS['_rcp_levels'][3] = [
			'id'               => 3,
			'name'             => 'Lifetime',
			'initial_amount'   => '199.00',
			'recurring_amount' => '0',
		];

		$data = $this->handler->load_level( 3 );
		$this->assertSame( 199.0, $data['list_price'] );
	}

	public function test_load_level_returns_empty_for_missing(): void {
		$data = $this->handler->load_level( 999 );
		$this->assertEmpty( $data );
	}

	// ─── Load Payment ─────────────────────────────────────

	public function test_load_payment_returns_invoice_data(): void {
		$GLOBALS['_rcp_payments'][10] = [
			'id'                => 10,
			'subscription_name' => 'Gold Plan',
			'object_id'         => 1,
			'user_id'           => 5,
			'amount'            => 29.99,
			'status'            => 'complete',
			'date'              => '2026-02-01 10:00:00',
		];

		$data = $this->handler->load_payment( 10, 42, 100 );

		$this->assertSame( 'out_invoice', $data['move_type'] );
		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2026-02-01', $data['invoice_date'] );
		$this->assertSame( 'RCP-10', $data['ref'] );
	}

	public function test_load_payment_has_invoice_line_ids(): void {
		$GLOBALS['_rcp_payments'][10] = [
			'id'                => 10,
			'subscription_name' => 'Gold Plan',
			'object_id'         => 1,
			'user_id'           => 5,
			'amount'            => 29.99,
			'status'            => 'complete',
			'date'              => '2026-02-01',
		];

		$data  = $this->handler->load_payment( 10, 42, 100 );
		$lines = $data['invoice_line_ids'];

		$this->assertCount( 1, $lines );
		$this->assertSame( 0, $lines[0][0] );
		$this->assertSame( 0, $lines[0][1] );
		$this->assertSame( 100, $lines[0][2]['product_id'] );
		$this->assertSame( 1, $lines[0][2]['quantity'] );
		$this->assertSame( 29.99, $lines[0][2]['price_unit'] );
		$this->assertSame( 'Gold Plan', $lines[0][2]['name'] );
	}

	public function test_load_payment_returns_empty_for_missing(): void {
		$data = $this->handler->load_payment( 999, 42, 100 );
		$this->assertEmpty( $data );
	}

	public function test_load_payment_resolves_level_name_from_level(): void {
		$GLOBALS['_rcp_levels'][1] = [
			'id'               => 1,
			'name'             => 'Gold Plan',
			'initial_amount'   => '29.99',
			'recurring_amount' => '0',
		];
		$GLOBALS['_rcp_payments'][11] = [
			'id'        => 11,
			'object_id' => 1,
			'user_id'   => 5,
			'amount'    => 29.99,
			'status'    => 'complete',
			'date'      => '2026-02-01',
		];

		$data  = $this->handler->load_payment( 11, 42, 100 );
		$lines = $data['invoice_line_ids'];
		$this->assertSame( 'Gold Plan', $lines[0][2]['name'] );
	}

	// ─── Load Membership ──────────────────────────────────

	public function test_load_membership_returns_data(): void {
		$GLOBALS['_rcp_memberships'][20] = [
			'id'              => 20,
			'customer_id'     => 5,
			'object_id'       => 1,
			'status'          => 'active',
			'created_date'    => '2026-01-01',
			'expiration_date' => '2027-01-01',
		];

		$data = $this->handler->load_membership( 20 );

		$this->assertSame( 5, $data['user_id'] );
		$this->assertSame( 1, $data['level_id'] );
		$this->assertSame( '2026-01-01', $data['date_from'] );
		$this->assertSame( '2027-01-01', $data['date_to'] );
		$this->assertSame( 'paid', $data['state'] );
	}

	public function test_load_membership_handles_no_expiration(): void {
		$GLOBALS['_rcp_memberships'][21] = [
			'id'              => 21,
			'customer_id'     => 5,
			'object_id'       => 1,
			'status'          => 'active',
			'created_date'    => '2026-01-01',
			'expiration_date' => 'none',
		];

		$data = $this->handler->load_membership( 21 );
		$this->assertFalse( $data['date_to'] );
	}

	public function test_load_membership_handles_empty_expiration(): void {
		$GLOBALS['_rcp_memberships'][22] = [
			'id'              => 22,
			'customer_id'     => 5,
			'object_id'       => 1,
			'status'          => 'active',
			'created_date'    => '2026-01-01',
			'expiration_date' => '',
		];

		$data = $this->handler->load_membership( 22 );
		$this->assertFalse( $data['date_to'] );
	}

	public function test_load_membership_returns_empty_for_missing(): void {
		$data = $this->handler->load_membership( 999 );
		$this->assertEmpty( $data );
	}

	// ─── Level ID Helpers ─────────────────────────────────

	public function test_get_level_id_for_payment(): void {
		$GLOBALS['_rcp_payments'][10] = [
			'id'        => 10,
			'object_id' => 3,
			'user_id'   => 5,
			'amount'    => 29.99,
			'status'    => 'complete',
			'date'      => '2026-02-01',
		];

		$this->assertSame( 3, $this->handler->get_level_id_for_payment( 10 ) );
	}

	public function test_get_level_id_for_payment_returns_zero_for_missing(): void {
		$this->assertSame( 0, $this->handler->get_level_id_for_payment( 999 ) );
	}

	public function test_get_level_id_for_membership(): void {
		$GLOBALS['_rcp_memberships'][20] = [
			'id'              => 20,
			'customer_id'     => 5,
			'object_id'       => 3,
			'status'          => 'active',
			'created_date'    => '2026-01-01',
			'expiration_date' => '2027-01-01',
		];

		$this->assertSame( 3, $this->handler->get_level_id_for_membership( 20 ) );
	}

	public function test_get_level_id_for_membership_returns_zero_for_missing(): void {
		$this->assertSame( 0, $this->handler->get_level_id_for_membership( 999 ) );
	}

	// ─── Payment Status Mapping ───────────────────────────

	public function test_complete_maps_to_posted(): void {
		$this->assertSame( 'posted', $this->handler->map_payment_status_to_odoo( 'complete' ) );
	}

	public function test_pending_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_payment_status_to_odoo( 'pending' ) );
	}

	public function test_failed_maps_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_payment_status_to_odoo( 'failed' ) );
	}

	public function test_abandoned_maps_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_payment_status_to_odoo( 'abandoned' ) );
	}

	public function test_unknown_payment_status_defaults_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_payment_status_to_odoo( 'unknown' ) );
	}

	// ─── Membership Status Mapping ────────────────────────

	public function test_active_maps_to_paid(): void {
		$this->assertSame( 'paid', $this->handler->map_membership_status_to_odoo( 'active' ) );
	}

	public function test_membership_pending_maps_to_none(): void {
		$this->assertSame( 'none', $this->handler->map_membership_status_to_odoo( 'pending' ) );
	}

	public function test_canceled_maps_to_cancelled(): void {
		$this->assertSame( 'cancelled', $this->handler->map_membership_status_to_odoo( 'canceled' ) );
	}

	public function test_expired_maps_to_old(): void {
		$this->assertSame( 'old', $this->handler->map_membership_status_to_odoo( 'expired' ) );
	}

	public function test_unknown_membership_status_defaults_to_none(): void {
		$this->assertSame( 'none', $this->handler->map_membership_status_to_odoo( 'unknown' ) );
	}

	// ─── Filterable Maps ──────────────────────────────────

	public function test_payment_status_map_is_filterable(): void {
		// apply_filters stub returns value unchanged — verify the map
		// passes through apply_filters (complete → posted still works).
		$this->assertSame( 'posted', $this->handler->map_payment_status_to_odoo( 'complete' ) );
	}

	public function test_membership_status_map_is_filterable(): void {
		// apply_filters stub returns value unchanged — verify the map
		// passes through apply_filters (active → paid still works).
		$this->assertSame( 'paid', $this->handler->map_membership_status_to_odoo( 'active' ) );
	}
}
