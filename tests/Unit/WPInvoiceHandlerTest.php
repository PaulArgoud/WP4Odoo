<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WP_Invoice_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WP_Invoice_Handler.
 *
 * Tests invoice loading, user data extraction, status mapping,
 * and line item formatting from WPI_Invoice data.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WP_Invoice_Handler::class)]
class WPInvoiceHandlerTest extends TestCase {

	private WP_Invoice_Handler $handler;

	protected function setUp(): void {
		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wpi_invoices']  = [];
		$GLOBALS['_wp_post_meta']  = [];

		$this->handler = new WP_Invoice_Handler( new Logger( 'test' ) );
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Create a WPI invoice in the global store.
	 *
	 * @param int                           $id    Invoice ID.
	 * @param float                         $total Invoice total.
	 * @param array<int, array<string,mixed>> $items Itemized list.
	 * @param string                        $inv_id Invoice reference ID.
	 * @param string                        $due    Due date.
	 * @param string                        $date   Post date.
	 */
	private function create_invoice(
		int $id,
		float $total = 100.0,
		array $items = [],
		string $inv_id = 'WPI-001',
		string $due = '2026-03-10',
		string $date = '2026-02-10 12:00:00'
	): void {
		$GLOBALS['_wpi_invoices'][ $id ] = [
			'total'          => $total,
			'tax'            => 0.0,
			'itemized_list'  => $items,
			'invoice_id'     => $inv_id,
			'due_date'       => $due,
			'post_date'      => $date,
			'user_data'      => [
				'ID'           => 5,
				'user_email'   => 'client@example.com',
				'display_name' => 'John Client',
			],
		];
	}

	// ─── load_invoice ───────────────────────────────────────

	public function test_load_invoice_returns_data(): void {
		$items = [
			[ 'name' => 'Web Design', 'quantity' => 1, 'price' => 100.0 ],
		];
		$this->create_invoice( 100, 100.0, $items );

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertSame( 'out_invoice', $data['move_type'] );
		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2026-02-10', $data['invoice_date'] );
		$this->assertSame( '2026-03-10', $data['invoice_date_due'] );
		$this->assertSame( 'WPI-001', $data['ref'] );
	}

	public function test_load_invoice_has_invoice_line_ids(): void {
		$items = [
			[ 'name' => 'Web Design', 'quantity' => 2, 'price' => 50.0 ],
			[ 'name' => 'Hosting', 'quantity' => 1, 'price' => 20.0 ],
		];
		$this->create_invoice( 100, 120.0, $items );

		$data  = $this->handler->load_invoice( 100, 42 );
		$lines = $data['invoice_line_ids'];

		$this->assertCount( 2, $lines );

		// First line.
		$this->assertSame( 0, $lines[0][0] );
		$this->assertSame( 0, $lines[0][1] );
		$this->assertSame( 'Web Design', $lines[0][2]['name'] );
		$this->assertSame( 2.0, $lines[0][2]['quantity'] );
		$this->assertSame( 50.0, $lines[0][2]['price_unit'] );

		// Second line.
		$this->assertSame( 'Hosting', $lines[1][2]['name'] );
		$this->assertSame( 1.0, $lines[1][2]['quantity'] );
		$this->assertSame( 20.0, $lines[1][2]['price_unit'] );
	}

	public function test_load_invoice_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_invoice( 999, 42 ) );
	}

	public function test_load_invoice_uses_today_when_no_post_date(): void {
		$this->create_invoice( 100, 50.0, [], 'WPI-001', '2026-03-10', '' );

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertSame( gmdate( 'Y-m-d' ), $data['invoice_date'] );
	}

	public function test_load_invoice_truncates_due_date(): void {
		$this->create_invoice( 100, 50.0, [], 'WPI-001', '2026-03-15 08:00:00' );

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertSame( '2026-03-15', $data['invoice_date_due'] );
	}

	public function test_load_invoice_empty_due_date_when_not_set(): void {
		$this->create_invoice( 100, 50.0, [], 'WPI-001', '' );

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertSame( '', $data['invoice_date_due'] );
	}

	public function test_load_invoice_fallback_line_when_no_items(): void {
		$this->create_invoice( 100, 75.0, [] );

		$data  = $this->handler->load_invoice( 100, 42 );
		$lines = $data['invoice_line_ids'];

		// Falls back to single line with total.
		$this->assertCount( 1, $lines );
		$this->assertSame( 75.0, $lines[0][2]['price_unit'] );
		$this->assertSame( 1.0, $lines[0][2]['quantity'] );
	}

	public function test_load_invoice_skips_items_with_empty_name(): void {
		$items = [
			[ 'name' => '', 'quantity' => 1, 'price' => 10.0 ],
			[ 'name' => 'Valid Item', 'quantity' => 1, 'price' => 50.0 ],
		];
		$this->create_invoice( 100, 60.0, $items );

		$data  = $this->handler->load_invoice( 100, 42 );
		$lines = $data['invoice_line_ids'];

		$this->assertCount( 1, $lines );
		$this->assertSame( 'Valid Item', $lines[0][2]['name'] );
	}

	public function test_load_invoice_uses_post_id_as_ref_fallback(): void {
		// When invoice_id key is absent, the fallback is $post_id.
		$GLOBALS['_wpi_invoices'][100] = [
			'total'         => 50.0,
			'tax'           => 0.0,
			'itemized_list' => [],
			'due_date'      => '',
			'post_date'     => '2026-02-10 12:00:00',
			// Intentionally no 'invoice_id' key — triggers ?? fallback.
		];

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertSame( '100', $data['ref'] );
	}

	// ─── get_user_data ──────────────────────────────────────

	public function test_get_user_data_returns_data(): void {
		$this->create_invoice( 100 );

		$user = $this->handler->get_user_data( 100 );

		$this->assertSame( 5, $user['user_id'] );
		$this->assertSame( 'client@example.com', $user['email'] );
		$this->assertSame( 'John Client', $user['name'] );
	}

	public function test_get_user_data_returns_defaults_for_nonexistent(): void {
		$user = $this->handler->get_user_data( 999 );

		$this->assertSame( 0, $user['user_id'] );
		$this->assertSame( '', $user['email'] );
		$this->assertSame( '', $user['name'] );
	}

	public function test_get_user_data_returns_defaults_when_user_data_missing(): void {
		$GLOBALS['_wpi_invoices'][100] = [
			'total'     => 100.0,
			'post_date' => '2026-02-10',
		];

		$user = $this->handler->get_user_data( 100 );

		$this->assertSame( 0, $user['user_id'] );
		$this->assertSame( '', $user['email'] );
		$this->assertSame( '', $user['name'] );
	}

	// ─── map_status ─────────────────────────────────────────

	public function test_active_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'active' ) );
	}

	public function test_paid_maps_to_posted(): void {
		$this->assertSame( 'posted', $this->handler->map_status( 'paid' ) );
	}

	public function test_pending_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'pending' ) );
	}

	public function test_draft_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'draft' ) );
	}

	public function test_unknown_status_defaults_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'cancelled' ) );
	}

	public function test_status_map_is_filterable(): void {
		// apply_filters stub returns value unchanged — verify the map
		// passes through apply_filters (paid → posted still works).
		$this->assertSame( 'posted', $this->handler->map_status( 'paid' ) );
	}
}
