<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\WC_Subscriptions_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Subscriptions_Handler.
 *
 * Tests product/subscription loading, formatting, status/billing mapping,
 * reverse mappings (Odoo → WCS), parsing, and save methods for pull sync.
 */
class WCSubscriptionsHandlerTest extends TestCase {

	private WC_Subscriptions_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']        = [];
		$GLOBALS['_wp_posts']          = [];
		$GLOBALS['_wp_post_meta']      = [];
		$GLOBALS['_wc_products']       = [];
		$GLOBALS['_wc_orders']         = [];
		$GLOBALS['_wc_subscriptions']  = [];

		$this->handler = new WC_Subscriptions_Handler( new Logger( 'test' ) );
	}

	// ─── load_product ────────────────────────────────────

	public function test_load_product_returns_data(): void {
		$GLOBALS['_wc_products'][10] = [
			'name'  => 'Premium Monthly',
			'price' => '19.99',
			'type'  => 'subscription',
		];

		$data = $this->handler->load_product( 10 );

		$this->assertSame( 'Premium Monthly', $data['product_name'] );
		$this->assertSame( 19.99, $data['list_price'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_product_empty_for_nonexistent(): void {
		$data = $this->handler->load_product( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_product_empty_for_non_subscription_type(): void {
		$GLOBALS['_wc_products'][10] = [
			'name'  => 'Simple Product',
			'price' => '10.00',
			'type'  => 'simple',
		];

		$data = $this->handler->load_product( 10 );
		$this->assertEmpty( $data );
	}

	public function test_load_product_accepts_variable_subscription(): void {
		$GLOBALS['_wc_products'][10] = [
			'name'  => 'Variable Sub',
			'price' => '29.99',
			'type'  => 'variable-subscription',
		];

		$data = $this->handler->load_product( 10 );
		$this->assertSame( 'Variable Sub', $data['product_name'] );
		$this->assertSame( 29.99, $data['list_price'] );
	}

	// ─── load_subscription ───────────────────────────────

	public function test_load_subscription_returns_data(): void {
		$GLOBALS['_wc_subscriptions'][20] = [
			'user_id'          => 42,
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'start_date'       => '2026-01-15 10:00:00',
			'next_payment'     => '2026-02-15 10:00:00',
			'end'              => '',
			'status'           => 'active',
			'items'            => [
				[ 'product_id' => 10, 'line_total' => 19.99, 'name' => 'Premium Monthly' ],
			],
		];

		$data = $this->handler->load_subscription( 20 );

		$this->assertSame( 42, $data['user_id'] );
		$this->assertSame( 10, $data['product_id'] );
		$this->assertSame( 'Premium Monthly', $data['product_name'] );
		$this->assertSame( 'month', $data['billing_period'] );
		$this->assertSame( 1, $data['billing_interval'] );
		$this->assertSame( 'active', $data['status'] );
	}

	public function test_load_subscription_empty_for_nonexistent(): void {
		$data = $this->handler->load_subscription( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_subscription_includes_dates(): void {
		$GLOBALS['_wc_subscriptions'][20] = [
			'user_id'          => 42,
			'billing_period'   => 'year',
			'billing_interval' => 1,
			'start_date'       => '2026-01-01 00:00:00',
			'next_payment'     => '2027-01-01 00:00:00',
			'end'              => '2028-01-01 00:00:00',
			'status'           => 'active',
			'items'            => [],
		];

		$data = $this->handler->load_subscription( 20 );

		$this->assertSame( '2026-01-01 00:00:00', $data['start_date'] );
		$this->assertSame( '2027-01-01 00:00:00', $data['next_payment'] );
		$this->assertSame( '2028-01-01 00:00:00', $data['end_date'] );
	}

	// ─── format_subscription ─────────────────────────────

	public function test_format_subscription_includes_partner_id(): void {
		$data = [
			'start_date'       => '2026-01-15 10:00:00',
			'next_payment'     => '2026-02-15 10:00:00',
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'line_total'       => 19.99,
			'product_name'     => 'Premium Monthly',
		];

		$result = $this->handler->format_subscription( $data, 100, 200 );

		$this->assertSame( 200, $result['partner_id'] );
	}

	public function test_format_subscription_includes_billing(): void {
		$data = [
			'start_date'       => '2026-01-15 10:00:00',
			'next_payment'     => '2026-02-15 10:00:00',
			'billing_period'   => 'year',
			'billing_interval' => 2,
			'line_total'       => 99.00,
			'product_name'     => 'Annual Plan',
		];

		$result = $this->handler->format_subscription( $data, 100, 200 );

		$this->assertSame( 'yearly', $result['recurring_rule_type'] );
		$this->assertSame( 2, $result['recurring_interval'] );
		$this->assertSame( '2026-01-15', $result['date_start'] );
		$this->assertSame( '2026-02-15', $result['recurring_next_date'] );
	}

	public function test_format_subscription_includes_line_ids(): void {
		$data = [
			'start_date'       => '2026-01-01 00:00:00',
			'next_payment'     => '2026-02-01 00:00:00',
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'line_total'       => 29.99,
			'product_name'     => 'Premium Monthly',
		];

		$result = $this->handler->format_subscription( $data, 100, 200 );
		$lines  = $result['recurring_invoice_line_ids'];

		$this->assertIsArray( $lines );
		$this->assertCount( 1, $lines );
		$this->assertSame( 0, $lines[0][0] );
		$this->assertSame( 0, $lines[0][1] );
		$this->assertSame( 100, $lines[0][2]['product_id'] );
		$this->assertSame( 1, $lines[0][2]['quantity'] );
		$this->assertSame( 29.99, $lines[0][2]['price_unit'] );
		$this->assertSame( 'Premium Monthly', $lines[0][2]['name'] );
	}

	// ─── format_renewal_invoice ──────────────────────────

	public function test_format_renewal_invoice_includes_move_type(): void {
		$data = [
			'total'        => 19.99,
			'date'         => '2026-02-15',
			'ref'          => 'WCS-100',
			'product_name' => 'Premium Monthly',
		];

		$result = $this->handler->format_renewal_invoice( $data, 100, 200 );

		$this->assertSame( 'out_invoice', $result['move_type'] );
		$this->assertSame( 200, $result['partner_id'] );
		$this->assertSame( '2026-02-15', $result['invoice_date'] );
		$this->assertSame( 'WCS-100', $result['ref'] );
	}

	public function test_format_renewal_invoice_includes_line_ids(): void {
		$data = [
			'total'        => 19.99,
			'date'         => '2026-02-15',
			'ref'          => 'WCS-100',
			'product_name' => 'Premium Monthly',
		];

		$result = $this->handler->format_renewal_invoice( $data, 100, 200 );
		$lines  = $result['invoice_line_ids'];

		$this->assertIsArray( $lines );
		$this->assertCount( 1, $lines );
		$this->assertSame( 0, $lines[0][0] );
		$this->assertSame( 0, $lines[0][1] );
		$this->assertSame( 100, $lines[0][2]['product_id'] );
		$this->assertSame( 1, $lines[0][2]['quantity'] );
		$this->assertSame( 19.99, $lines[0][2]['price_unit'] );
		$this->assertSame( 'Premium Monthly', $lines[0][2]['name'] );
	}

	public function test_format_renewal_invoice_default_name(): void {
		$data = [
			'total'        => 9.99,
			'date'         => '2026-02-15',
			'ref'          => 'WCS-100',
			'product_name' => '',
		];

		$result = $this->handler->format_renewal_invoice( $data, 100, 200 );
		$lines  = $result['invoice_line_ids'];

		$this->assertNotEmpty( $lines[0][2]['name'] );
	}

	// ─── Subscription status mapping ─────────────────────

	public function test_status_pending_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status_to_odoo( 'pending' ) );
	}

	public function test_status_active_to_in_progress(): void {
		$this->assertSame( 'in_progress', $this->handler->map_status_to_odoo( 'active' ) );
	}

	public function test_status_on_hold_to_paused(): void {
		$this->assertSame( 'paused', $this->handler->map_status_to_odoo( 'on-hold' ) );
	}

	public function test_status_cancelled_to_close(): void {
		$this->assertSame( 'close', $this->handler->map_status_to_odoo( 'cancelled' ) );
	}

	public function test_status_expired_to_close(): void {
		$this->assertSame( 'close', $this->handler->map_status_to_odoo( 'expired' ) );
	}

	public function test_status_pending_cancel_to_in_progress(): void {
		$this->assertSame( 'in_progress', $this->handler->map_status_to_odoo( 'pending-cancel' ) );
	}

	public function test_status_switched_to_close(): void {
		$this->assertSame( 'close', $this->handler->map_status_to_odoo( 'switched' ) );
	}

	public function test_unknown_status_defaults_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status_to_odoo( 'unknown' ) );
	}

	// ─── Renewal status mapping ──────────────────────────

	public function test_renewal_completed_to_posted(): void {
		$this->assertSame( 'posted', $this->handler->map_renewal_status_to_odoo( 'completed' ) );
	}

	public function test_renewal_processing_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_renewal_status_to_odoo( 'processing' ) );
	}

	public function test_renewal_failed_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_renewal_status_to_odoo( 'failed' ) );
	}

	public function test_unknown_renewal_status_defaults_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_renewal_status_to_odoo( 'unknown' ) );
	}

	// ─── Billing period mapping ──────────────────────────

	public function test_billing_day_to_daily(): void {
		$this->assertSame( 'daily', $this->handler->map_billing_period( 'day' ) );
	}

	public function test_billing_week_to_weekly(): void {
		$this->assertSame( 'weekly', $this->handler->map_billing_period( 'week' ) );
	}

	public function test_billing_month_to_monthly(): void {
		$this->assertSame( 'monthly', $this->handler->map_billing_period( 'month' ) );
	}

	public function test_billing_year_to_yearly(): void {
		$this->assertSame( 'yearly', $this->handler->map_billing_period( 'year' ) );
	}

	public function test_unknown_billing_period_defaults_to_monthly(): void {
		$this->assertSame( 'monthly', $this->handler->map_billing_period( 'unknown' ) );
	}

	// ─── Filterable maps ─────────────────────────────────

	public function test_status_map_is_filterable(): void {
		$result = $this->handler->map_status_to_odoo( 'active' );
		$this->assertSame( 'in_progress', $result );
	}

	public function test_renewal_status_map_is_filterable(): void {
		$result = $this->handler->map_renewal_status_to_odoo( 'completed' );
		$this->assertSame( 'posted', $result );
	}

	// ─── get_product_id_for_renewal ──────────────────────

	public function test_get_product_id_for_renewal_returns_id(): void {
		$GLOBALS['_wc_orders'][100] = [
			'status'    => 'completed',
			'total'     => '19.99',
			'items'     => [
				[ 'product_id' => 10, 'name' => 'Premium Monthly', 'line_total' => '19.99' ],
			],
		];

		$product_id = $this->handler->get_product_id_for_renewal( 100 );
		$this->assertSame( 10, $product_id );
	}

	public function test_get_product_id_for_renewal_returns_zero_for_missing_order(): void {
		$product_id = $this->handler->get_product_id_for_renewal( 999 );
		$this->assertSame( 0, $product_id );
	}

	public function test_get_product_id_for_renewal_returns_zero_for_empty_items(): void {
		$GLOBALS['_wc_orders'][100] = [
			'status' => 'completed',
			'total'  => '0.00',
			'items'  => [],
		];

		$product_id = $this->handler->get_product_id_for_renewal( 100 );
		$this->assertSame( 0, $product_id );
	}

	// ─── Reverse status mapping (Odoo → WCS) ────────────

	public function test_reverse_status_draft_to_pending(): void {
		$this->assertSame( 'pending', $this->handler->map_odoo_status_to_wcs( 'draft' ) );
	}

	public function test_reverse_status_in_progress_to_active(): void {
		$this->assertSame( 'active', $this->handler->map_odoo_status_to_wcs( 'in_progress' ) );
	}

	public function test_reverse_status_paused_to_on_hold(): void {
		$this->assertSame( 'on-hold', $this->handler->map_odoo_status_to_wcs( 'paused' ) );
	}

	public function test_reverse_status_close_to_cancelled(): void {
		$this->assertSame( 'cancelled', $this->handler->map_odoo_status_to_wcs( 'close' ) );
	}

	public function test_reverse_status_unknown_to_pending(): void {
		$this->assertSame( 'pending', $this->handler->map_odoo_status_to_wcs( 'unknown_state' ) );
	}

	// ─── Reverse billing period mapping (Odoo → WCS) ────

	public function test_reverse_billing_daily_to_day(): void {
		$this->assertSame( 'day', $this->handler->map_odoo_billing_period_to_wcs( 'daily' ) );
	}

	public function test_reverse_billing_weekly_to_week(): void {
		$this->assertSame( 'week', $this->handler->map_odoo_billing_period_to_wcs( 'weekly' ) );
	}

	public function test_reverse_billing_monthly_to_month(): void {
		$this->assertSame( 'month', $this->handler->map_odoo_billing_period_to_wcs( 'monthly' ) );
	}

	public function test_reverse_billing_yearly_to_year(): void {
		$this->assertSame( 'year', $this->handler->map_odoo_billing_period_to_wcs( 'yearly' ) );
	}

	public function test_reverse_billing_unknown_to_month(): void {
		$this->assertSame( 'month', $this->handler->map_odoo_billing_period_to_wcs( 'unknown_rule' ) );
	}

	// ─── parse_subscription_from_odoo ───────────────────

	public function test_parse_subscription_from_odoo(): void {
		$odoo_data = [
			'state'                => 'in_progress',
			'date_start'           => '2026-01-15',
			'recurring_next_date'  => '2026-02-15',
			'recurring_rule_type'  => 'monthly',
			'recurring_interval'   => 1,
		];

		$result = $this->handler->parse_subscription_from_odoo( $odoo_data );

		$this->assertSame( 'active', $result['status'] );
		$this->assertSame( '2026-01-15', $result['start_date'] );
		$this->assertSame( '2026-02-15', $result['next_payment'] );
		$this->assertSame( 'month', $result['billing_period'] );
		$this->assertSame( 1, $result['billing_interval'] );
	}

	public function test_parse_subscription_from_odoo_defaults(): void {
		$odoo_data = [];

		$result = $this->handler->parse_subscription_from_odoo( $odoo_data );

		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'month', $result['billing_period'] );
		$this->assertSame( 1, $result['billing_interval'] );
	}

	// ─── save_subscription ──────────────────────────────

	public function test_save_subscription_updates_status(): void {
		$GLOBALS['_wc_subscriptions'][20] = [
			'user_id'          => 42,
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'start_date'       => '2026-01-15',
			'next_payment'     => '2026-02-15',
			'status'           => 'active',
			'items'            => [],
		];

		$data   = [ 'status' => 'on-hold' ];
		$result = $this->handler->save_subscription( $data, 20 );

		$this->assertSame( 20, $result );
		$this->assertSame( 'on-hold', $GLOBALS['_wc_subscriptions'][20]['status'] );
	}

	public function test_save_subscription_returns_zero_for_missing(): void {
		$data   = [ 'status' => 'cancelled' ];
		$result = $this->handler->save_subscription( $data, 999 );

		$this->assertSame( 0, $result );
	}

	public function test_save_subscription_returns_zero_for_wp_id_zero(): void {
		$data   = [ 'status' => 'active' ];
		$result = $this->handler->save_subscription( $data, 0 );

		$this->assertSame( 0, $result );
	}

	public function test_save_subscription_no_change_when_same_status(): void {
		$GLOBALS['_wc_subscriptions'][20] = [
			'user_id'          => 42,
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'start_date'       => '2026-01-15',
			'next_payment'     => '2026-02-15',
			'status'           => 'active',
			'items'            => [],
		];

		$data   = [ 'status' => 'active' ];
		$result = $this->handler->save_subscription( $data, 20 );

		$this->assertSame( 20, $result );
		// Status unchanged.
		$this->assertSame( 'active', $GLOBALS['_wc_subscriptions'][20]['status'] );
	}
}
