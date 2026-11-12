<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Logger;
use WP4Odoo\Modules\Odoo_Accounting_Formatter;
use WP4Odoo\Modules\WC_Returns_Handler;
use WP4Odoo\Partner_Service;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Returns_Handler.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WC_Returns_Handler::class)]
class WCReturnsHandlerTest extends TestCase {

	private WC_Returns_Handler $handler;
	private Entity_Map_Repository $entity_map;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']  = [];
		$GLOBALS['_wc_orders']   = [];
		$GLOBALS['_wc_products'] = [];
		$GLOBALS['_wc_refunds']  = [];

		$this->entity_map = wp4odoo_test_entity_map();
		$partner_service  = new Partner_Service(
			wp4odoo_test_client_provider(),
			$this->entity_map
		);

		$this->handler = new WC_Returns_Handler(
			new Logger( 'test', wp4odoo_test_settings() ),
			$partner_service,
			$this->entity_map
		);
	}

	// ─── format_refund_ref ────────────────────────────────

	public function test_format_refund_ref(): void {
		$ref = $this->handler->format_refund_ref( 42, 100 );
		$this->assertSame( 'WC-REFUND-42 (Order #100)', $ref );
	}

	// ─── load_refund ──────────────────────────────────────

	public function test_load_refund_returns_empty_for_missing_order(): void {
		$data = $this->handler->load_refund( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_refund_returns_empty_for_non_refund_order(): void {
		$order = new \WC_Order();
		$order->set_data(
			[
				'id'   => 50,
				'type' => 'shop_order',
			]
		);
		$GLOBALS['_wc_orders'][50] = $order;

		$data = $this->handler->load_refund( 50 );
		$this->assertEmpty( $data );
	}

	// ─── get_refund_line_items ────────────────────────────

	public function test_get_refund_line_items_extracts_items(): void {
		$refund = new \WC_Order();
		$refund->set_data(
			[
				'id'    => 10,
				'type'  => 'shop_order_refund',
				'items' => [
					new \WC_Order_Item( [
						'name'     => 'Widget',
						'quantity' => -2,
						'total'    => '-40.00',
					] ),
				],
			]
		);

		$lines = $this->handler->get_refund_line_items( $refund );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'Widget', $lines[0]['name'] );
		$this->assertSame( 2.0, $lines[0]['quantity'] );
		$this->assertSame( 20.0, $lines[0]['price_unit'] );
	}

	public function test_get_refund_line_items_skips_zero_quantity(): void {
		$refund = new \WC_Order();
		$refund->set_data(
			[
				'id'    => 11,
				'type'  => 'shop_order_refund',
				'items' => [
					new \WC_Order_Item( [
						'name'     => 'Invisible',
						'quantity' => 0,
						'total'    => '0.00',
					] ),
				],
			]
		);

		$lines = $this->handler->get_refund_line_items( $refund );
		$this->assertEmpty( $lines );
	}

	// ─── parse_refund_from_odoo ───────────────────────────

	public function test_parse_refund_extracts_order_id_from_ref(): void {
		$data = $this->handler->parse_refund_from_odoo(
			[
				'amount_total' => 50.0,
				'ref'          => 'WC-REFUND-42 (Order #100)',
				'state'        => 'posted',
			]
		);

		$this->assertSame( 50.0, $data['amount'] );
		$this->assertSame( 100, $data['order_id'] );
		$this->assertSame( 'completed', $data['status'] );
	}

	public function test_parse_refund_defaults_to_zero_order_id(): void {
		$data = $this->handler->parse_refund_from_odoo(
			[
				'amount_total' => 10.0,
				'ref'          => 'Some random ref',
				'state'        => 'draft',
			]
		);

		$this->assertSame( 0, $data['order_id'] );
		$this->assertSame( 'pending', $data['status'] );
	}

	// ─── map_odoo_state_to_wc ─────────────────────────────

	public function test_draft_maps_to_pending(): void {
		$this->assertSame( 'pending', $this->handler->map_odoo_state_to_wc( 'draft' ) );
	}

	public function test_posted_maps_to_completed(): void {
		$this->assertSame( 'completed', $this->handler->map_odoo_state_to_wc( 'posted' ) );
	}

	public function test_cancel_maps_to_cancelled(): void {
		$this->assertSame( 'cancelled', $this->handler->map_odoo_state_to_wc( 'cancel' ) );
	}

	public function test_unknown_state_defaults_to_pending(): void {
		$this->assertSame( 'pending', $this->handler->map_odoo_state_to_wc( 'unknown' ) );
	}

	// ─── save_refund ──────────────────────────────────────

	public function test_save_refund_returns_zero_without_order_id(): void {
		$result = $this->handler->save_refund( [ 'amount' => 50.0 ], 0 );
		$this->assertSame( 0, $result );
	}

	public function test_save_refund_returns_zero_when_order_not_found(): void {
		$result = $this->handler->save_refund( [ 'order_id' => 999, 'amount' => 50.0 ], 0 );
		$this->assertSame( 0, $result );
	}

	public function test_save_refund_returns_wp_id_for_existing(): void {
		$order = new \WC_Order();
		$order->set_data( [ 'id' => 100 ] );
		$GLOBALS['_wc_orders'][100] = $order;

		$result = $this->handler->save_refund( [ 'order_id' => 100, 'amount' => 50.0 ], 42 );
		$this->assertSame( 42, $result );
	}

	// ─── resolve_original_invoice ─────────────────────────

	public function test_resolve_original_invoice_returns_zero_when_not_mapped(): void {
		$this->assertSame( 0, $this->handler->resolve_original_invoice( 999 ) );
	}

	// ─── load_return_picking ──────────────────────────────

	public function test_load_return_picking_returns_empty_for_missing_refund(): void {
		$data = $this->handler->load_return_picking( 999 );
		$this->assertEmpty( $data );
	}

	// ─── Odoo_Accounting_Formatter::for_credit_note ───────

	public function test_for_credit_note_basic(): void {
		$data = Odoo_Accounting_Formatter::for_credit_note(
			42,
			100.0,
			'2026-01-15',
			'WC-REFUND-1 (Order #100)',
			[],
			'Refund'
		);

		$this->assertSame( 'out_refund', $data['move_type'] );
		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2026-01-15', $data['invoice_date'] );
		$this->assertSame( 'WC-REFUND-1 (Order #100)', $data['ref'] );
		$this->assertArrayNotHasKey( 'reversed_entry_id', $data );
	}

	public function test_for_credit_note_with_reversed_entry(): void {
		$data = Odoo_Accounting_Formatter::for_credit_note(
			42,
			100.0,
			'2026-01-15',
			'ref',
			[],
			'Refund',
			99
		);

		$this->assertSame( 99, $data['reversed_entry_id'] );
	}

	public function test_for_credit_note_with_line_items(): void {
		$items = [
			[ 'name' => 'Widget', 'quantity' => 2.0, 'price_unit' => 25.0 ],
		];

		$data = Odoo_Accounting_Formatter::for_credit_note(
			42,
			50.0,
			'2026-01-15',
			'ref',
			$items,
			'Fallback'
		);

		$this->assertCount( 1, $data['invoice_line_ids'] );
		$this->assertSame( 'Widget', $data['invoice_line_ids'][0][2]['name'] );
	}
}
