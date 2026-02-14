<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Odoo_Accounting_Formatter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Odoo_Accounting_Formatter.
 *
 * Tests static formatting methods for donation model,
 * account.move (customer invoice), and vendor bill.
 */
class OdooAccountingFormatterTest extends TestCase {

	// ─── for_donation_model ─────────────────────────────

	public function test_for_donation_model_has_correct_structure(): void {
		$result = Odoo_Accounting_Formatter::for_donation_model( 10, 20, 50.0, '2026-01-15', 'DON-001' );

		$this->assertSame( 10, $result['partner_id'] );
		$this->assertSame( '2026-01-15', $result['donation_date'] );
		$this->assertSame( 'DON-001', $result['payment_ref'] );
		$this->assertArrayHasKey( 'line_ids', $result );
	}

	public function test_for_donation_model_line_has_product_id(): void {
		$result = Odoo_Accounting_Formatter::for_donation_model( 10, 20, 50.0, '2026-01-15', 'DON-001' );

		$line = $result['line_ids'][0][2];
		$this->assertSame( 20, $line['product_id'] );
		$this->assertSame( 1, $line['quantity'] );
		$this->assertSame( 50.0, $line['unit_price'] );
	}

	public function test_for_donation_model_uses_one2many_tuple(): void {
		$result = Odoo_Accounting_Formatter::for_donation_model( 10, 20, 50.0, '2026-01-15', 'DON-001' );

		$tuple = $result['line_ids'][0];
		$this->assertSame( 0, $tuple[0] );
		$this->assertSame( 0, $tuple[1] );
		$this->assertIsArray( $tuple[2] );
	}

	// ─── for_account_move ───────────────────────────────

	public function test_for_account_move_has_out_invoice_type(): void {
		$result = Odoo_Accounting_Formatter::for_account_move( 10, 20, 100.0, '2026-02-01', 'INV-001', 'Test line' );

		$this->assertSame( 'out_invoice', $result['move_type'] );
	}

	public function test_for_account_move_has_correct_structure(): void {
		$result = Odoo_Accounting_Formatter::for_account_move( 10, 20, 100.0, '2026-02-01', 'INV-001', 'Test line' );

		$this->assertSame( 10, $result['partner_id'] );
		$this->assertSame( '2026-02-01', $result['invoice_date'] );
		$this->assertSame( 'INV-001', $result['ref'] );
		$this->assertArrayHasKey( 'invoice_line_ids', $result );
	}

	public function test_for_account_move_line_has_product_id(): void {
		$result = Odoo_Accounting_Formatter::for_account_move( 10, 20, 100.0, '2026-02-01', 'INV-001', 'Test line' );

		$line = $result['invoice_line_ids'][0][2];
		$this->assertSame( 20, $line['product_id'] );
		$this->assertSame( 100.0, $line['price_unit'] );
		$this->assertSame( 'Test line', $line['name'] );
	}

	public function test_for_account_move_uses_fallback_name(): void {
		$result = Odoo_Accounting_Formatter::for_account_move( 10, 20, 100.0, '2026-02-01', 'INV-001', '', 'Fallback' );

		$line = $result['invoice_line_ids'][0][2];
		$this->assertSame( 'Fallback', $line['name'] );
	}

	// ─── for_vendor_bill ────────────────────────────────

	public function test_for_vendor_bill_has_in_invoice_type(): void {
		$result = Odoo_Accounting_Formatter::for_vendor_bill( 10, 25.50, '2026-02-14', 'affwp-ref-42', 'Commission #42' );

		$this->assertSame( 'in_invoice', $result['move_type'] );
	}

	public function test_for_vendor_bill_has_correct_structure(): void {
		$result = Odoo_Accounting_Formatter::for_vendor_bill( 10, 25.50, '2026-02-14', 'affwp-ref-42', 'Commission #42' );

		$this->assertSame( 10, $result['partner_id'] );
		$this->assertSame( '2026-02-14', $result['invoice_date'] );
		$this->assertSame( 'affwp-ref-42', $result['ref'] );
		$this->assertArrayHasKey( 'invoice_line_ids', $result );
	}

	public function test_for_vendor_bill_line_has_no_product_id(): void {
		$result = Odoo_Accounting_Formatter::for_vendor_bill( 10, 25.50, '2026-02-14', 'affwp-ref-42', 'Commission #42' );

		$line = $result['invoice_line_ids'][0][2];
		$this->assertArrayNotHasKey( 'product_id', $line );
	}

	public function test_for_vendor_bill_line_has_correct_amount(): void {
		$result = Odoo_Accounting_Formatter::for_vendor_bill( 10, 99.99, '2026-03-01', 'affwp-ref-7', 'Commission #7' );

		$line = $result['invoice_line_ids'][0][2];
		$this->assertSame( 99.99, $line['price_unit'] );
		$this->assertSame( 1, $line['quantity'] );
		$this->assertSame( 'Commission #7', $line['name'] );
	}

	public function test_for_vendor_bill_uses_one2many_tuple(): void {
		$result = Odoo_Accounting_Formatter::for_vendor_bill( 10, 25.50, '2026-02-14', 'affwp-ref-42', 'Commission #42' );

		$tuple = $result['invoice_line_ids'][0];
		$this->assertSame( 0, $tuple[0] );
		$this->assertSame( 0, $tuple[1] );
		$this->assertIsArray( $tuple[2] );
	}

	public function test_for_vendor_bill_has_exactly_one_line(): void {
		$result = Odoo_Accounting_Formatter::for_vendor_bill( 10, 25.50, '2026-02-14', 'affwp-ref-42', 'Commission #42' );

		$this->assertCount( 1, $result['invoice_line_ids'] );
	}

	// ─── build_invoice_lines ───────────────────────────

	public function test_build_invoice_lines_converts_items_to_tuples(): void {
		$items = [
			[ 'name' => 'Item A', 'quantity' => 2.0, 'price_unit' => 10.0 ],
			[ 'name' => 'Item B', 'quantity' => 1.0, 'price_unit' => 25.0 ],
		];

		$lines = Odoo_Accounting_Formatter::build_invoice_lines( $items, 'Fallback', 0.0 );

		$this->assertCount( 2, $lines );
		$this->assertSame( 0, $lines[0][0] );
		$this->assertSame( 0, $lines[0][1] );
		$this->assertSame( 'Item A', $lines[0][2]['name'] );
		$this->assertSame( 2.0, $lines[0][2]['quantity'] );
		$this->assertSame( 10.0, $lines[0][2]['price_unit'] );
		$this->assertSame( 'Item B', $lines[1][2]['name'] );
	}

	public function test_build_invoice_lines_skips_empty_names(): void {
		$items = [
			[ 'name' => '', 'quantity' => 1.0, 'price_unit' => 10.0 ],
			[ 'name' => 'Valid', 'quantity' => 1.0, 'price_unit' => 20.0 ],
		];

		$lines = Odoo_Accounting_Formatter::build_invoice_lines( $items, 'Fallback', 0.0 );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'Valid', $lines[0][2]['name'] );
	}

	public function test_build_invoice_lines_falls_back_when_no_items(): void {
		$lines = Odoo_Accounting_Formatter::build_invoice_lines( [], 'Invoice #123', 99.0 );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'Invoice #123', $lines[0][2]['name'] );
		$this->assertSame( 1.0, $lines[0][2]['quantity'] );
		$this->assertSame( 99.0, $lines[0][2]['price_unit'] );
	}

	public function test_build_invoice_lines_no_fallback_when_total_is_zero(): void {
		$lines = Odoo_Accounting_Formatter::build_invoice_lines( [], 'Fallback', 0.0 );

		$this->assertEmpty( $lines );
	}

	public function test_build_invoice_lines_falls_back_when_all_names_empty(): void {
		$items = [
			[ 'name' => '', 'quantity' => 1.0, 'price_unit' => 10.0 ],
		];

		$lines = Odoo_Accounting_Formatter::build_invoice_lines( $items, 'Fallback', 50.0 );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'Fallback', $lines[0][2]['name'] );
		$this->assertSame( 50.0, $lines[0][2]['price_unit'] );
	}

}
