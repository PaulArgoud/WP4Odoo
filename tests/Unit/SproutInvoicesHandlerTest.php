<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Modules\Sprout_Invoices_Handler;
use WP4Odoo\Logger;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for Sprout_Invoices_Handler.
 *
 * Tests invoice/payment loading, status mapping, Odoo parsing,
 * and save methods.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Sprout_Invoices_Handler::class)]
class SproutInvoicesHandlerTest extends Module_Test_Case {

	private Sprout_Invoices_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new Sprout_Invoices_Handler( new Logger( 'test' ) );
	}

	// ─── Helpers ────────────────────────────────────────────

	private function create_invoice_post( int $id, string $title = 'INV-001' ): void {
		$post              = new \stdClass();
		$post->ID          = $id;
		$post->post_type   = 'sa_invoice';
		$post->post_title  = $title;
		$post->post_status = 'publish';

		$GLOBALS['_wp_posts'][ $id ] = $post;
	}

	private function create_payment_post( int $id, string $title = 'Payment' ): void {
		$post              = new \stdClass();
		$post->ID          = $id;
		$post->post_type   = 'sa_payment';
		$post->post_title  = $title;
		$post->post_status = 'publish';

		$GLOBALS['_wp_posts'][ $id ] = $post;
	}

	private function set_invoice_meta( int $id, float $total = 100.0, string $issue_date = '2026-02-10', string $due_date = '2026-03-10', string $invoice_id = 'INV-001' ): void {
		$GLOBALS['_wp_post_meta'][ $id ] = [
			'_total'              => (string) $total,
			'_invoice_issue_date' => $issue_date,
			'_due_date'           => $due_date,
			'_invoice_id'         => $invoice_id,
			'_doc_line_items'     => [
				[ 'desc' => 'Consulting', 'qty' => 2, 'rate' => 50.0 ],
			],
		];
	}

	private function set_payment_meta( int $id, float $amount = 100.0, string $method = 'Stripe', string $date = '2026-02-15' ): void {
		$GLOBALS['_wp_post_meta'][ $id ] = [
			'_payment_total'  => (string) $amount,
			'_payment_method' => $method,
			'_payment_date'   => $date,
		];
	}

	// ─── load_invoice ───────────────────────────────────────

	public function test_load_invoice_returns_data(): void {
		$this->create_invoice_post( 100, 'INV-001' );
		$this->set_invoice_meta( 100 );

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertSame( 'out_invoice', $data['move_type'] );
		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2026-02-10', $data['invoice_date'] );
		$this->assertSame( '2026-03-10', $data['invoice_date_due'] );
		$this->assertSame( 'INV-001', $data['ref'] );
	}

	public function test_load_invoice_has_invoice_line_ids(): void {
		$this->create_invoice_post( 100 );
		$this->set_invoice_meta( 100 );

		$data  = $this->handler->load_invoice( 100, 42 );
		$lines = $data['invoice_line_ids'];

		$this->assertCount( 1, $lines );
		$this->assertSame( 0, $lines[0][0] );
		$this->assertSame( 0, $lines[0][1] );
		$this->assertSame( 'Consulting', $lines[0][2]['name'] );
		$this->assertSame( 2.0, $lines[0][2]['quantity'] );
		$this->assertSame( 50.0, $lines[0][2]['price_unit'] );
	}

	public function test_load_invoice_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_invoice( 999, 42 ) );
	}

	public function test_load_invoice_empty_for_wrong_post_type(): void {
		$post              = new \stdClass();
		$post->ID          = 100;
		$post->post_type   = 'post';
		$post->post_title  = 'Not an invoice';
		$post->post_status = 'publish';

		$GLOBALS['_wp_posts'][100] = $post;

		$this->assertSame( [], $this->handler->load_invoice( 100, 42 ) );
	}

	public function test_load_invoice_uses_post_id_as_ref_fallback(): void {
		$this->create_invoice_post( 100, 'Invoice' );
		$GLOBALS['_wp_post_meta'][100] = [
			'_total'              => '50',
			'_invoice_issue_date' => '2026-02-10',
			'_due_date'           => '',
			'_invoice_id'         => '',
			'_doc_line_items'     => [],
		];

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertSame( '100', $data['ref'] );
	}

	public function test_load_invoice_uses_today_when_no_issue_date(): void {
		$this->create_invoice_post( 100 );
		$GLOBALS['_wp_post_meta'][100] = [
			'_total'              => '50',
			'_invoice_issue_date' => '',
			'_due_date'           => '',
			'_invoice_id'         => 'INV-001',
			'_doc_line_items'     => [],
		];

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertSame( gmdate( 'Y-m-d' ), $data['invoice_date'] );
	}

	public function test_load_invoice_fallback_line_when_no_items(): void {
		$this->create_invoice_post( 100, 'Invoice #100' );
		$GLOBALS['_wp_post_meta'][100] = [
			'_total'              => '75',
			'_invoice_issue_date' => '2026-02-10',
			'_due_date'           => '',
			'_invoice_id'         => 'INV-100',
			'_doc_line_items'     => [],
		];

		$data  = $this->handler->load_invoice( 100, 42 );
		$lines = $data['invoice_line_ids'];

		$this->assertCount( 1, $lines );
		$this->assertSame( 75.0, $lines[0][2]['price_unit'] );
	}

	// ─── load_payment ───────────────────────────────────────

	public function test_load_payment_returns_data(): void {
		$this->create_payment_post( 200 );
		$this->set_payment_meta( 200 );

		$data = $this->handler->load_payment( 200, 42 );

		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( 100.0, $data['amount'] );
		$this->assertSame( '2026-02-15', $data['date'] );
		$this->assertSame( 'inbound', $data['payment_type'] );
		$this->assertSame( 'Stripe', $data['ref'] );
	}

	public function test_load_payment_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_payment( 999, 42 ) );
	}

	public function test_load_payment_empty_for_wrong_post_type(): void {
		$post              = new \stdClass();
		$post->ID          = 200;
		$post->post_type   = 'post';
		$post->post_title  = 'Not a payment';
		$post->post_status = 'publish';

		$GLOBALS['_wp_posts'][200] = $post;

		$this->assertSame( [], $this->handler->load_payment( 200, 42 ) );
	}

	public function test_load_payment_uses_today_when_no_date(): void {
		$this->create_payment_post( 200 );
		$GLOBALS['_wp_post_meta'][200] = [
			'_payment_total'  => '50',
			'_payment_method' => 'Cash',
			'_payment_date'   => '',
		];

		$data = $this->handler->load_payment( 200, 42 );

		$this->assertSame( gmdate( 'Y-m-d' ), $data['date'] );
	}

	public function test_load_payment_uses_fallback_ref_when_no_method(): void {
		$this->create_payment_post( 200 );
		$GLOBALS['_wp_post_meta'][200] = [
			'_payment_total'  => '50',
			'_payment_method' => '',
			'_payment_date'   => '2026-02-15',
		];

		$data = $this->handler->load_payment( 200, 42 );

		$this->assertSame( 'Payment', $data['ref'] );
	}

	// ─── get_client_id ──────────────────────────────────────

	public function test_get_client_id_returns_value(): void {
		$GLOBALS['_wp_post_meta'][100] = [ '_client_id' => '42' ];

		$this->assertSame( 42, $this->handler->get_client_id( 100 ) );
	}

	public function test_get_client_id_returns_zero_for_missing(): void {
		$this->assertSame( 0, $this->handler->get_client_id( 999 ) );
	}

	// ─── get_invoice_id_for_payment ─────────────────────────

	public function test_get_invoice_id_for_payment_returns_value(): void {
		$GLOBALS['_wp_post_meta'][200] = [ '_invoice_id' => '100' ];

		$this->assertSame( 100, $this->handler->get_invoice_id_for_payment( 200 ) );
	}

	public function test_get_invoice_id_for_payment_returns_zero_for_missing(): void {
		$this->assertSame( 0, $this->handler->get_invoice_id_for_payment( 999 ) );
	}

	// ─── map_status ─────────────────────────────────────────

	public function test_temp_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'temp' ) );
	}

	public function test_publish_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'publish' ) );
	}

	public function test_partial_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'partial' ) );
	}

	public function test_complete_maps_to_posted(): void {
		$this->assertSame( 'posted', $this->handler->map_status( 'complete' ) );
	}

	public function test_write_off_maps_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_status( 'write-off' ) );
	}

	public function test_unknown_status_defaults_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'unknown' ) );
	}

	// ─── map_odoo_status_to_si ──────────────────────────────

	public function test_odoo_draft_maps_to_publish(): void {
		$this->assertSame( 'publish', $this->handler->map_odoo_status_to_si( 'draft' ) );
	}

	public function test_odoo_posted_maps_to_complete(): void {
		$this->assertSame( 'complete', $this->handler->map_odoo_status_to_si( 'posted' ) );
	}

	public function test_odoo_cancel_maps_to_write_off(): void {
		$this->assertSame( 'write-off', $this->handler->map_odoo_status_to_si( 'cancel' ) );
	}

	public function test_odoo_unknown_status_defaults_to_publish(): void {
		$this->assertSame( 'publish', $this->handler->map_odoo_status_to_si( 'unknown' ) );
	}

	// ─── parse_invoice_from_odoo ────────────────────────────

	public function test_parse_invoice_from_odoo_returns_data(): void {
		$odoo_data = [
			'ref'              => 'INV-001',
			'amount_total'     => 100.0,
			'invoice_date'     => '2026-02-10',
			'invoice_date_due' => '2026-03-10',
			'state'            => 'posted',
			'invoice_line_ids' => [
				[ 0, 0, [ 'name' => 'Consulting', 'quantity' => 2, 'price_unit' => 50.0 ] ],
			],
		];

		$data = $this->handler->parse_invoice_from_odoo( $odoo_data );

		$this->assertSame( 'INV-001', $data['title'] );
		$this->assertSame( 100.0, $data['total'] );
		$this->assertSame( '2026-02-10', $data['issue_date'] );
		$this->assertSame( '2026-03-10', $data['due_date'] );
		$this->assertSame( 'complete', $data['status'] );
	}

	public function test_parse_invoice_from_odoo_extracts_line_items_from_tuples(): void {
		$odoo_data = [
			'invoice_line_ids' => [
				[ 0, 0, [ 'name' => 'Service A', 'quantity' => 1, 'price_unit' => 25.0 ] ],
				[ 0, 0, [ 'name' => 'Service B', 'quantity' => 3, 'price_unit' => 15.0 ] ],
			],
		];

		$data = $this->handler->parse_invoice_from_odoo( $odoo_data );

		$this->assertCount( 2, $data['line_items'] );
		$this->assertSame( 'Service A', $data['line_items'][0]['desc'] );
		$this->assertSame( 1.0, $data['line_items'][0]['qty'] );
		$this->assertSame( 25.0, $data['line_items'][0]['rate'] );
	}

	public function test_parse_invoice_from_odoo_extracts_line_items_from_flat_arrays(): void {
		$odoo_data = [
			'invoice_line_ids' => [
				[ 'name' => 'Direct Line', 'quantity' => 2, 'price_unit' => 30.0 ],
			],
		];

		$data = $this->handler->parse_invoice_from_odoo( $odoo_data );

		$this->assertCount( 1, $data['line_items'] );
		$this->assertSame( 'Direct Line', $data['line_items'][0]['desc'] );
	}

	public function test_parse_invoice_from_odoo_skips_integer_line_ids(): void {
		$odoo_data = [
			'invoice_line_ids' => [ 1, 2, 3 ],
		];

		$data = $this->handler->parse_invoice_from_odoo( $odoo_data );

		$this->assertEmpty( $data['line_items'] );
	}

	public function test_parse_invoice_from_odoo_handles_empty_data(): void {
		$data = $this->handler->parse_invoice_from_odoo( [] );

		$this->assertSame( '', $data['title'] );
		$this->assertSame( 0.0, $data['total'] );
		$this->assertSame( '', $data['issue_date'] );
		$this->assertEmpty( $data['line_items'] );
	}

	// ─── parse_payment_from_odoo ────────────────────────────

	public function test_parse_payment_from_odoo_returns_data(): void {
		$odoo_data = [
			'amount' => 75.0,
			'date'   => '2026-02-15',
			'ref'    => 'Bank Transfer',
		];

		$data = $this->handler->parse_payment_from_odoo( $odoo_data );

		$this->assertSame( 75.0, $data['amount'] );
		$this->assertSame( '2026-02-15', $data['date'] );
		$this->assertSame( 'Bank Transfer', $data['method'] );
	}

	public function test_parse_payment_from_odoo_handles_empty_data(): void {
		$data = $this->handler->parse_payment_from_odoo( [] );

		$this->assertSame( 0.0, $data['amount'] );
		$this->assertSame( '', $data['date'] );
		$this->assertSame( '', $data['method'] );
	}

	// ─── save_invoice ───────────────────────────────────────

	public function test_save_invoice_creates_new_post(): void {
		$data = [
			'title'      => 'New Invoice',
			'total'      => 100.0,
			'issue_date' => '2026-02-10',
			'due_date'   => '2026-03-10',
			'ref'        => 'INV-001',
			'status'     => 'publish',
			'line_items' => [ [ 'desc' => 'Item', 'qty' => 1, 'rate' => 100.0 ] ],
		];

		$result = $this->handler->save_invoice( $data );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_invoice_updates_existing(): void {
		$this->create_invoice_post( 100 );

		$data = [
			'title'  => 'Updated Invoice',
			'total'  => 200.0,
			'status' => 'complete',
		];

		$result = $this->handler->save_invoice( $data, 100 );
		$this->assertSame( 100, $result );
	}

	// ─── save_payment ───────────────────────────────────────

	public function test_save_payment_creates_new_post(): void {
		$data = [
			'amount' => 50.0,
			'date'   => '2026-02-15',
			'method' => 'PayPal',
		];

		$result = $this->handler->save_payment( $data );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_payment_updates_existing(): void {
		$this->create_payment_post( 200 );

		$data = [
			'amount' => 75.0,
			'date'   => '2026-02-20',
			'method' => 'Stripe',
		];

		$result = $this->handler->save_payment( $data, 200 );
		$this->assertSame( 200, $result );
	}
}
