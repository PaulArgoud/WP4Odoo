<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\WPERP_Accounting_Handler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WPERP_Accounting_Handler.
 *
 * Tests journal entry, chart of accounts, and journal loading from WP ERP
 * Accounting tables via $wpdb stubs. Also covers status mapping and
 * account type mapping.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WPERP_Accounting_Handler::class)]
class WPERPAccountingHandlerTest extends TestCase {

	private WPERP_Accounting_Handler $handler;

	/** @var \WP_DB_Stub */
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options'] = [];

		$this->handler = new WPERP_Accounting_Handler( new Logger( 'test' ) );
	}

	// ─── load_journal_entry ─────────────────────────────────

	public function test_load_journal_entry_returns_data_when_found(): void {
		$this->wpdb->get_row_return = [
			'id'       => 1,
			'trn_date' => '2026-03-01',
			'status'   => 'draft',
		];

		$data = $this->handler->load_journal_entry( 1 );

		$this->assertNotEmpty( $data );
		$this->assertSame( 'entry', $data['move_type'] );
		$this->assertSame( '2026-03-01', $data['date'] );
		$this->assertSame( 'draft', $data['state'] );
	}

	public function test_load_journal_entry_returns_empty_when_not_found(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_journal_entry( 999 );

		$this->assertEmpty( $data );
	}

	public function test_load_journal_entry_ref_format(): void {
		$this->wpdb->get_row_return = [
			'id'       => 42,
			'trn_date' => '2026-03-01',
			'status'   => 'draft',
		];

		$data = $this->handler->load_journal_entry( 42 );

		$this->assertSame( 'WPERP-JE-42', $data['ref'] );
	}

	public function test_load_journal_entry_includes_ledger_lines(): void {
		$this->wpdb->get_row_return = [
			'id'       => 1,
			'trn_date' => '2026-03-01',
			'status'   => 'draft',
		];

		// get_results returns ledger details.
		$this->wpdb->get_results_return = [
			[
				'particulars' => 'Cash deposit',
				'debit'       => 100.0,
				'credit'      => 0.0,
				'ledger_id'   => 5,
			],
			[
				'particulars' => 'Bank account',
				'debit'       => 0.0,
				'credit'      => 100.0,
				'ledger_id'   => 8,
			],
		];

		$data = $this->handler->load_journal_entry( 1 );

		$this->assertArrayHasKey( 'invoice_line_ids', $data );
		$this->assertCount( 2, $data['invoice_line_ids'] );

		// Verify One2many tuple format [0, 0, {...}].
		$this->assertSame( 0, $data['invoice_line_ids'][0][0] );
		$this->assertSame( 0, $data['invoice_line_ids'][0][1] );
		$this->assertSame( 'Cash deposit', $data['invoice_line_ids'][0][2]['name'] );
		$this->assertSame( 100.0, $data['invoice_line_ids'][0][2]['debit'] );
		$this->assertSame( 0.0, $data['invoice_line_ids'][0][2]['credit'] );
		$this->assertSame( 5, $data['invoice_line_ids'][0][2]['ledger_id'] );
	}

	public function test_load_journal_entry_no_lines_omits_key(): void {
		$this->wpdb->get_row_return = [
			'id'       => 1,
			'trn_date' => '2026-03-01',
			'status'   => 'draft',
		];

		// Empty ledger details.
		$this->wpdb->get_results_return = [];

		$data = $this->handler->load_journal_entry( 1 );

		$this->assertArrayNotHasKey( 'invoice_line_ids', $data );
	}

	public function test_load_journal_entry_truncates_date_to_10_chars(): void {
		$this->wpdb->get_row_return = [
			'id'       => 1,
			'trn_date' => '2026-03-01 14:30:00',
			'status'   => 'draft',
		];

		$data = $this->handler->load_journal_entry( 1 );

		$this->assertSame( '2026-03-01', $data['date'] );
	}

	// ─── parse_journal_entry_from_odoo ──────────────────────

	public function test_parse_journal_entry_from_odoo_maps_state_and_date(): void {
		$data = $this->handler->parse_journal_entry_from_odoo( [
			'ref'   => 'JE-100',
			'date'  => '2026-03-01',
			'state' => 'posted',
		] );

		$this->assertSame( 'JE-100', $data['ref'] );
		$this->assertSame( '2026-03-01', $data['trn_date'] );
		$this->assertSame( 'awaiting_payment', $data['status'] );
	}

	public function test_parse_journal_entry_from_odoo_defaults_to_draft(): void {
		$data = $this->handler->parse_journal_entry_from_odoo( [] );

		$this->assertSame( '', $data['ref'] );
		$this->assertSame( '', $data['trn_date'] );
		$this->assertSame( 'draft', $data['status'] );
	}

	// ─── save_journal_entry ─────────────────────────────────

	public function test_save_journal_entry_creates_new_entry(): void {
		$this->wpdb->insert_id = 42;

		$result = $this->handler->save_journal_entry( [
			'trn_date' => '2026-03-01',
			'status'   => 'draft',
			'ref'      => 'JE-100',
		] );

		$this->assertSame( 42, $result );
	}

	public function test_save_journal_entry_updates_existing_entry(): void {
		$result = $this->handler->save_journal_entry( [
			'trn_date' => '2026-03-02',
			'status'   => 'awaiting_payment',
		], 10 );

		$this->assertSame( 10, $result );
	}

	// ─── delete_journal_entry ───────────────────────────────

	public function test_delete_journal_entry_returns_true(): void {
		$result = $this->handler->delete_journal_entry( 1 );

		$this->assertTrue( $result );
	}

	// ─── load_chart_account ─────────────────────────────────

	public function test_load_chart_account_returns_data_with_mapped_type(): void {
		$this->wpdb->get_row_return = [
			'id'   => 1,
			'name' => 'Cash',
			'code' => '1000',
			'type' => 'asset',
		];

		$data = $this->handler->load_chart_account( 1 );

		$this->assertSame( 'Cash', $data['name'] );
		$this->assertSame( '1000', $data['code'] );
		$this->assertSame( 'asset_current', $data['account_type'] );
	}

	public function test_load_chart_account_returns_empty_when_not_found(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_chart_account( 999 );

		$this->assertEmpty( $data );
	}

	// ─── parse_chart_account_from_odoo ──────────────────────

	public function test_parse_chart_account_from_odoo_maps_fields(): void {
		$data = $this->handler->parse_chart_account_from_odoo( [
			'name'         => 'Accounts Receivable',
			'code'         => '1200',
			'account_type' => 'asset_receivable',
		] );

		$this->assertSame( 'Accounts Receivable', $data['name'] );
		$this->assertSame( '1200', $data['code'] );
		$this->assertSame( 'asset_receivable', $data['type'] );
	}

	public function test_parse_chart_account_from_odoo_defaults(): void {
		$data = $this->handler->parse_chart_account_from_odoo( [] );

		$this->assertSame( '', $data['name'] );
		$this->assertSame( '', $data['code'] );
		$this->assertSame( 'asset_current', $data['type'] );
	}

	// ─── save_chart_account ─────────────────────────────────

	public function test_save_chart_account_creates_new(): void {
		$this->wpdb->insert_id = 15;

		$result = $this->handler->save_chart_account( [
			'name' => 'Bank Account',
			'code' => '1100',
			'type' => 'asset',
		] );

		$this->assertSame( 15, $result );
	}

	public function test_save_chart_account_updates_existing(): void {
		$result = $this->handler->save_chart_account( [
			'name' => 'Updated Account',
			'code' => '1100',
			'type' => 'asset',
		], 5 );

		$this->assertSame( 5, $result );
	}

	// ─── delete_chart_account ───────────────────────────────

	public function test_delete_chart_account_returns_true(): void {
		$result = $this->handler->delete_chart_account( 1 );

		$this->assertTrue( $result );
	}

	// ─── load_journal ───────────────────────────────────────

	public function test_load_journal_returns_data_from_option(): void {
		$GLOBALS['_wp_options']['wp4odoo_erp_journal_1'] = [
			'name' => 'Sales Journal',
			'type' => 'sale',
			'code' => 'SAL',
		];

		$data = $this->handler->load_journal( 1 );

		$this->assertSame( 'Sales Journal', $data['name'] );
		$this->assertSame( 'sale', $data['type'] );
		$this->assertSame( 'SAL', $data['code'] );
	}

	public function test_load_journal_returns_empty_when_not_found(): void {
		$data = $this->handler->load_journal( 999 );

		$this->assertEmpty( $data );
	}

	// ─── parse_journal_from_odoo ────────────────────────────

	public function test_parse_journal_from_odoo_maps_fields(): void {
		$data = $this->handler->parse_journal_from_odoo( [
			'name' => 'Purchase Journal',
			'type' => 'purchase',
			'code' => 'PUR',
		] );

		$this->assertSame( 'Purchase Journal', $data['name'] );
		$this->assertSame( 'purchase', $data['type'] );
		$this->assertSame( 'PUR', $data['code'] );
	}

	public function test_parse_journal_from_odoo_defaults(): void {
		$data = $this->handler->parse_journal_from_odoo( [] );

		$this->assertSame( '', $data['name'] );
		$this->assertSame( 'general', $data['type'] );
		$this->assertSame( '', $data['code'] );
	}

	// ─── save_journal ───────────────────────────────────────

	public function test_save_journal_saves_option_with_ref_id(): void {
		$result = $this->handler->save_journal( [
			'name' => 'General Journal',
			'type' => 'general',
			'code' => 'GEN',
		], 42 );

		$this->assertSame( 42, $result );
		$this->assertSame( 'General Journal', $GLOBALS['_wp_options']['wp4odoo_erp_journal_42']['name'] );
	}

	public function test_save_journal_creates_with_computed_ref_id(): void {
		$result = $this->handler->save_journal( [
			'name' => 'New Journal',
			'type' => 'general',
			'code' => 'NEW',
		] );

		$this->assertGreaterThan( 0, $result );
	}

	// ─── delete_journal ─────────────────────────────────────

	public function test_delete_journal_deletes_option(): void {
		$GLOBALS['_wp_options']['wp4odoo_erp_journal_5'] = [
			'name' => 'Test',
			'type' => 'general',
			'code' => 'TST',
		];

		$result = $this->handler->delete_journal( 5 );

		$this->assertTrue( $result );
		$this->assertArrayNotHasKey( 'wp4odoo_erp_journal_5', $GLOBALS['_wp_options'] );
	}

	// ─── map_invoice_status_to_odoo ─────────────────────────

	public function test_map_invoice_status_draft_to_odoo(): void {
		$this->assertSame( 'draft', $this->handler->map_invoice_status_to_odoo( 'draft' ) );
	}

	public function test_map_invoice_status_awaiting_payment_to_odoo(): void {
		$this->assertSame( 'posted', $this->handler->map_invoice_status_to_odoo( 'awaiting_payment' ) );
	}

	public function test_map_invoice_status_paid_to_odoo(): void {
		$this->assertSame( 'posted', $this->handler->map_invoice_status_to_odoo( 'paid' ) );
	}

	public function test_map_invoice_status_overdue_to_odoo(): void {
		$this->assertSame( 'posted', $this->handler->map_invoice_status_to_odoo( 'overdue' ) );
	}

	public function test_map_invoice_status_void_to_odoo(): void {
		$this->assertSame( 'cancel', $this->handler->map_invoice_status_to_odoo( 'void' ) );
	}

	// ─── map_invoice_status_from_odoo ───────────────────────

	public function test_map_invoice_status_from_odoo_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_invoice_status_from_odoo( 'draft' ) );
	}

	public function test_map_invoice_status_from_odoo_posted(): void {
		$this->assertSame( 'awaiting_payment', $this->handler->map_invoice_status_from_odoo( 'posted' ) );
	}

	public function test_map_invoice_status_from_odoo_cancel(): void {
		$this->assertSame( 'void', $this->handler->map_invoice_status_from_odoo( 'cancel' ) );
	}

	// ─── map_account_type_to_odoo ───────────────────────────

	public function test_map_account_type_asset_to_odoo(): void {
		$this->assertSame( 'asset_current', $this->handler->map_account_type_to_odoo( 'asset' ) );
	}

	public function test_map_account_type_liability_to_odoo(): void {
		$this->assertSame( 'liability_current', $this->handler->map_account_type_to_odoo( 'liability' ) );
	}

	public function test_map_account_type_equity_to_odoo(): void {
		$this->assertSame( 'equity', $this->handler->map_account_type_to_odoo( 'equity' ) );
	}

	public function test_map_account_type_income_to_odoo(): void {
		$this->assertSame( 'income', $this->handler->map_account_type_to_odoo( 'income' ) );
	}

	public function test_map_account_type_expense_to_odoo(): void {
		$this->assertSame( 'expense', $this->handler->map_account_type_to_odoo( 'expense' ) );
	}

	public function test_map_account_type_cost_of_goods_to_odoo(): void {
		$this->assertSame( 'expense_direct_cost', $this->handler->map_account_type_to_odoo( 'cost_of_goods' ) );
	}

	public function test_map_account_type_other_income_to_odoo(): void {
		$this->assertSame( 'income_other', $this->handler->map_account_type_to_odoo( 'other_income' ) );
	}

	public function test_map_account_type_other_expense_to_odoo(): void {
		$this->assertSame( 'expense', $this->handler->map_account_type_to_odoo( 'other_expense' ) );
	}

	public function test_map_account_type_unknown_defaults_to_asset_current(): void {
		$this->assertSame( 'asset_current', $this->handler->map_account_type_to_odoo( 'unknown_type' ) );
	}
}
