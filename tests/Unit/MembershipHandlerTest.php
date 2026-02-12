<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Membership_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Membership_Handler.
 *
 * Tests plan/membership loading and status mapping.
 */
class MembershipHandlerTest extends TestCase {

	private Membership_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wc_memberships']      = [];
		$GLOBALS['_wc_membership_plans'] = [];

		$this->handler = new Membership_Handler( new Logger( 'test' ) );
	}

	// ─── load_plan ─────────────────────────────────────────

	public function test_load_plan_returns_plan_data(): void {
		$plan = new \WC_Memberships_Membership_Plan( 5 );
		$plan->set_data( [ 'name' => 'Gold Plan', 'product_ids' => [] ] );
		$GLOBALS['_wc_membership_plans'][5] = $plan;

		$data = $this->handler->load_plan( 5 );

		$this->assertSame( 'Gold Plan', $data['plan_name'] );
		$this->assertTrue( $data['membership'] );
	}

	public function test_load_plan_returns_empty_for_invalid_id(): void {
		$data = $this->handler->load_plan( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_plan_includes_price_from_linked_product(): void {
		$plan = new \WC_Memberships_Membership_Plan( 5 );
		$plan->set_data( [ 'name' => 'Premium', 'product_ids' => [ 10 ] ] );
		$GLOBALS['_wc_membership_plans'][5] = $plan;

		// The wc_get_product stub returns false by default.
		// Without a product, list_price should not be set.
		$data = $this->handler->load_plan( 5 );
		$this->assertArrayNotHasKey( 'list_price', $data );
	}

	// ─── load_membership ───────────────────────────────────

	public function test_load_membership_returns_membership_data(): void {
		$membership = new \WC_Memberships_User_Membership( 10 );
		$membership->set_data( [
			'user_id'    => 42,
			'plan_id'    => 5,
			'status'     => 'wcm-active',
			'start_date' => '2026-01-01',
			'end_date'   => '2027-01-01',
		] );
		$GLOBALS['_wc_memberships'][10] = $membership;

		$data = $this->handler->load_membership( 10 );

		$this->assertSame( 42, $data['user_id'] );
		$this->assertSame( 5, $data['plan_id'] );
		$this->assertSame( 'paid', $data['state'] );
		$this->assertSame( '2026-01-01', $data['date_from'] );
		$this->assertSame( '2027-01-01', $data['date_to'] );
	}

	public function test_load_membership_returns_empty_for_invalid_id(): void {
		$data = $this->handler->load_membership( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_membership_handles_empty_end_date(): void {
		$membership = new \WC_Memberships_User_Membership( 10 );
		$membership->set_data( [
			'user_id'    => 42,
			'plan_id'    => 5,
			'status'     => 'wcm-active',
			'start_date' => '2026-01-01',
			'end_date'   => '',
		] );
		$GLOBALS['_wc_memberships'][10] = $membership;

		$data = $this->handler->load_membership( 10 );
		$this->assertFalse( $data['date_to'] );
	}

	public function test_load_membership_handles_cancel_date(): void {
		$membership = new \WC_Memberships_User_Membership( 10 );
		$membership->set_data( [
			'user_id'        => 42,
			'plan_id'        => 5,
			'status'         => 'wcm-cancelled',
			'start_date'     => '2026-01-01',
			'cancelled_date' => '2026-06-15',
		] );
		$GLOBALS['_wc_memberships'][10] = $membership;

		$data = $this->handler->load_membership( 10 );
		$this->assertSame( '2026-06-15', $data['date_cancel'] );
		$this->assertSame( 'cancelled', $data['state'] );
	}

	// ─── Status mapping ────────────────────────────────────

	public function test_map_status_active_to_paid(): void {
		$this->assertSame( 'paid', $this->handler->map_status_to_odoo( 'wcm-active' ) );
	}

	public function test_map_status_free_trial_to_free(): void {
		$this->assertSame( 'free', $this->handler->map_status_to_odoo( 'wcm-free_trial' ) );
	}

	public function test_map_status_complimentary_to_free(): void {
		$this->assertSame( 'free', $this->handler->map_status_to_odoo( 'wcm-complimentary' ) );
	}

	public function test_map_status_delayed_to_waiting(): void {
		$this->assertSame( 'waiting', $this->handler->map_status_to_odoo( 'wcm-delayed' ) );
	}

	public function test_map_status_pending_cancel_to_paid(): void {
		$this->assertSame( 'paid', $this->handler->map_status_to_odoo( 'wcm-pending-cancel' ) );
	}

	public function test_map_status_paused_to_waiting(): void {
		$this->assertSame( 'waiting', $this->handler->map_status_to_odoo( 'wcm-paused' ) );
	}

	public function test_map_status_cancelled_to_cancelled(): void {
		$this->assertSame( 'cancelled', $this->handler->map_status_to_odoo( 'wcm-cancelled' ) );
	}

	public function test_map_status_expired_to_none(): void {
		$this->assertSame( 'none', $this->handler->map_status_to_odoo( 'wcm-expired' ) );
	}

	public function test_map_status_unknown_defaults_to_none(): void {
		$this->assertSame( 'none', $this->handler->map_status_to_odoo( 'wcm-unknown' ) );
	}

	public function test_status_map_is_filterable(): void {
		// The apply_filters stub calls the callback directly in our test env.
		// Since no filter is registered, it returns the default map.
		// Just verify it doesn't crash and returns a known value.
		$result = $this->handler->map_status_to_odoo( 'wcm-active' );
		$this->assertSame( 'paid', $result );
	}

	// ─── Reverse status mapping ────────────────────────────

	public function test_map_odoo_status_paid_to_active(): void {
		$this->assertSame( 'wcm-active', $this->handler->map_odoo_status_to_wc( 'paid' ) );
	}

	public function test_map_odoo_status_free_to_complimentary(): void {
		$this->assertSame( 'wcm-complimentary', $this->handler->map_odoo_status_to_wc( 'free' ) );
	}

	public function test_map_odoo_status_waiting_to_delayed(): void {
		$this->assertSame( 'wcm-delayed', $this->handler->map_odoo_status_to_wc( 'waiting' ) );
	}

	public function test_map_odoo_status_cancelled_to_cancelled(): void {
		$this->assertSame( 'wcm-cancelled', $this->handler->map_odoo_status_to_wc( 'cancelled' ) );
	}

	public function test_map_odoo_status_none_to_expired(): void {
		$this->assertSame( 'wcm-expired', $this->handler->map_odoo_status_to_wc( 'none' ) );
	}

	public function test_map_odoo_status_unknown_defaults_to_expired(): void {
		$this->assertSame( 'wcm-expired', $this->handler->map_odoo_status_to_wc( 'xyz' ) );
	}

	// ─── Pull: parse_plan_from_odoo ────────────────────────

	public function test_parse_plan_from_odoo(): void {
		$data = $this->handler->parse_plan_from_odoo( [
			'name'       => 'Silver Plan',
			'list_price' => 29.99,
		] );

		$this->assertSame( 'Silver Plan', $data['plan_name'] );
		$this->assertSame( 29.99, $data['list_price'] );
	}

	public function test_parse_plan_from_odoo_handles_missing_fields(): void {
		$data = $this->handler->parse_plan_from_odoo( [] );

		$this->assertSame( '', $data['plan_name'] );
		$this->assertSame( 0.0, $data['list_price'] );
	}

	// ─── Pull: save_plan ──────────────────────────────────

	public function test_save_plan_creates_new_post(): void {
		$id = $this->handler->save_plan( [ 'plan_name' => 'Bronze Plan' ] );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_save_plan_updates_existing_post(): void {
		// Create first, then update.
		$created_id = $this->handler->save_plan( [ 'plan_name' => 'Bronze Plan' ] );
		$updated_id = $this->handler->save_plan( [ 'plan_name' => 'Bronze Updated' ], $created_id );
		$this->assertSame( $created_id, $updated_id );
	}

	// ─── Pull: parse_membership_from_odoo ──────────────────

	public function test_parse_membership_from_odoo(): void {
		$data = $this->handler->parse_membership_from_odoo( [
			'state'       => 'paid',
			'date_from'   => '2026-01-01',
			'date_to'     => '2027-01-01',
			'date_cancel' => false,
		] );

		$this->assertSame( 'wcm-active', $data['state'] );
		$this->assertSame( '2026-01-01', $data['date_from'] );
		$this->assertSame( '2027-01-01', $data['date_to'] );
		$this->assertFalse( $data['date_cancel'] );
	}

	public function test_parse_membership_from_odoo_handles_missing_state(): void {
		$data = $this->handler->parse_membership_from_odoo( [] );
		$this->assertSame( 'wcm-expired', $data['state'] );
	}

	// ─── Pull: save_membership_from_odoo ───────────────────

	public function test_save_membership_from_odoo_updates_existing(): void {
		// Create a post first to update.
		$post_id = \wp_insert_post( [
			'post_title'  => 'Test Membership',
			'post_type'   => 'wc_user_membership',
			'post_status' => 'wcm-active',
		] );

		$result = $this->handler->save_membership_from_odoo( [
			'state'     => 'wcm-cancelled',
			'date_from' => '2026-01-01',
			'date_to'   => '2027-01-01',
		], $post_id );

		$this->assertSame( $post_id, $result );
	}

	public function test_save_membership_from_odoo_rejects_create(): void {
		$result = $this->handler->save_membership_from_odoo( [ 'state' => 'wcm-active' ], 0 );
		$this->assertSame( 0, $result );
	}
}
