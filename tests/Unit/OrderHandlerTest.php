<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Order_Handler;
use WP4Odoo\Logger;
use WP4Odoo\Partner_Service;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Order_Handler.
 *
 * Tests load/save operations and Odoo → WooCommerce status mapping.
 */
class OrderHandlerTest extends TestCase {

	private Order_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']                         = [];
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [ 'enabled' => true, 'level' => 'debug' ];
		$GLOBALS['_wc_orders']                          = [];

		$logger          = new Logger( 'test' );
		$partner_service = $this->createMock( Partner_Service::class );

		$this->handler = new Order_Handler( $logger, $partner_service );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wc_orders'] );
	}

	// ─── Load ────────────────────────────────────────────────

	public function test_load_returns_empty_when_order_not_found(): void {
		// wc_get_order returns false by default (no order registered).
		$result = $this->handler->load( 999 );

		$this->assertSame( [], $result );
	}

	// ─── Save ────────────────────────────────────────────────

	public function test_save_returns_zero_when_wp_id_zero(): void {
		$result = $this->handler->save( [ 'status' => 'sale' ], 0 );

		$this->assertSame( 0, $result );
	}

	public function test_save_returns_zero_when_order_not_found(): void {
		// No order registered for ID 42.
		$result = $this->handler->save( [ 'status' => 'sale' ], 42 );

		$this->assertSame( 0, $result );
	}

	// ─── Status Mapping ─────────────────────────────────────

	public function test_map_odoo_status_draft_to_pending(): void {
		$this->assertSame( 'pending', $this->handler->map_odoo_status_to_wc( 'draft' ) );
	}

	public function test_map_odoo_status_sent_to_on_hold(): void {
		$this->assertSame( 'on-hold', $this->handler->map_odoo_status_to_wc( 'sent' ) );
	}

	public function test_map_odoo_status_sale_to_processing(): void {
		$this->assertSame( 'processing', $this->handler->map_odoo_status_to_wc( 'sale' ) );
	}

	public function test_map_odoo_status_done_to_completed(): void {
		$this->assertSame( 'completed', $this->handler->map_odoo_status_to_wc( 'done' ) );
	}

	public function test_map_odoo_status_cancel_to_cancelled(): void {
		$this->assertSame( 'cancelled', $this->handler->map_odoo_status_to_wc( 'cancel' ) );
	}

	public function test_map_odoo_status_unknown_to_on_hold(): void {
		$this->assertSame( 'on-hold', $this->handler->map_odoo_status_to_wc( 'nonexistent_state' ) );
	}
}
