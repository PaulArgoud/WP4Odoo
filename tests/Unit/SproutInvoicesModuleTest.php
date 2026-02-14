<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\Sprout_Invoices_Module;
use WP4Odoo\Modules\Sprout_Invoices_Handler;
use WP4Odoo\Logger;

/**
 * @covers \WP4Odoo\Modules\Sprout_Invoices_Module
 * @covers \WP4Odoo\Modules\Sprout_Invoices_Handler
 * @covers \WP4Odoo\Modules\Sprout_Invoices_Hooks
 */
class SproutInvoicesModuleTest extends Module_Test_Case {

	private Sprout_Invoices_Module $module;
	private Sprout_Invoices_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wp_users']     = [];

		$this->module  = new Sprout_Invoices_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new Sprout_Invoices_Handler( new Logger( 'test', wp4odoo_test_settings() ) );
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wp_users']     = [];
	}

	// ─── Identity ────────────────────────────────────────────

	public function test_module_id_is_sprout_invoices(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'sprout_invoices', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_sprout_invoices(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'Sprout Invoices', $ref->getValue( $this->module ) );
	}

	public function test_exclusive_group_is_invoicing(): void {
		$this->assertSame( 'invoicing', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority(): void {
		$this->assertSame( 10, $this->module->get_exclusive_priority() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_invoice_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'account.move', $ref->getValue( $this->module )['invoice'] );
	}

	public function test_declares_payment_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'account.payment', $ref->getValue( $this->module )['payment'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 2, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_invoices(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_invoices'] );
	}

	public function test_default_settings_has_sync_payments(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_payments'] );
	}

	public function test_default_settings_has_auto_post_invoices(): void {
		$this->assertTrue( $this->module->get_default_settings()['auto_post_invoices'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 5, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_exposes_sync_invoices(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_invoices']['type'] );
	}

	public function test_settings_fields_exposes_sync_payments(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_payments']['type'] );
	}

	public function test_settings_fields_exposes_auto_post_invoices(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['auto_post_invoices']['type'] );
	}

	public function test_settings_fields_count(): void {
		$this->assertCount( 5, $this->module->get_settings_fields() );
	}

	// ─── Field Mappings ─────────────────────────────────────

	public function test_invoice_mapping_has_move_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'move_type', $ref->getValue( $this->module )['invoice']['move_type'] );
	}

	public function test_invoice_mapping_has_partner_id(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'partner_id', $ref->getValue( $this->module )['invoice']['partner_id'] );
	}

	public function test_invoice_mapping_has_invoice_line_ids(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'invoice_line_ids', $ref->getValue( $this->module )['invoice']['invoice_line_ids'] );
	}

	public function test_payment_mapping_has_amount(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'amount', $ref->getValue( $this->module )['payment']['amount'] );
	}

	public function test_payment_mapping_has_payment_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'payment_type', $ref->getValue( $this->module )['payment']['payment_type'] );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_with_si(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices_with_si(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── map_to_odoo passthrough ────────────────────────────

	public function test_map_to_odoo_invoice_passes_data_through(): void {
		$data = [
			'move_type'        => 'out_invoice',
			'partner_id'       => 42,
			'invoice_date'     => '2025-01-15',
			'invoice_date_due' => '2025-02-15',
			'ref'              => 'INV-001',
			'invoice_line_ids' => [ [ 0, 0, [ 'name' => 'Item', 'quantity' => 1, 'price_unit' => 100.0 ] ] ],
		];

		$mapped = $this->module->map_to_odoo( 'invoice', $data );

		$this->assertSame( 'out_invoice', $mapped['move_type'] );
		$this->assertSame( 42, $mapped['partner_id'] );
		$this->assertSame( 'INV-001', $mapped['ref'] );
		$this->assertCount( 1, $mapped['invoice_line_ids'] );
	}

	public function test_map_to_odoo_payment_passes_data_through(): void {
		$data = [
			'partner_id'   => 42,
			'amount'       => 150.0,
			'date'         => '2025-01-20',
			'payment_type' => 'inbound',
			'ref'          => 'Check',
		];

		$mapped = $this->module->map_to_odoo( 'payment', $data );

		$this->assertSame( 150.0, $mapped['amount'] );
		$this->assertSame( 'inbound', $mapped['payment_type'] );
	}

	// ─── Handler: load_invoice ──────────────────────────────

	public function test_handler_load_invoice_returns_account_move(): void {
		$this->create_invoice_post( 100, 'publish' );

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertSame( 'out_invoice', $data['move_type'] );
		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2025-01-15', $data['invoice_date'] );
		$this->assertSame( '2025-02-15', $data['invoice_date_due'] );
		$this->assertSame( 'INV-001', $data['ref'] );
	}

	public function test_handler_load_invoice_builds_line_items(): void {
		$this->create_invoice_post( 100, 'publish' );

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertCount( 2, $data['invoice_line_ids'] );
		$this->assertSame( 0, $data['invoice_line_ids'][0][0] );
		$this->assertSame( 0, $data['invoice_line_ids'][0][1] );
		$this->assertSame( 'Consulting', $data['invoice_line_ids'][0][2]['name'] );
		$this->assertSame( 2.0, $data['invoice_line_ids'][0][2]['quantity'] );
		$this->assertSame( 100.0, $data['invoice_line_ids'][0][2]['price_unit'] );
	}

	public function test_handler_load_invoice_skips_empty_desc_lines(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'          => 100,
			'post_type'   => 'sa_invoice',
			'post_status' => 'publish',
			'post_title'  => 'Test Invoice',
		];
		$GLOBALS['_wp_post_meta'][100] = [
			'_total'              => 50.0,
			'_doc_line_items'     => [
				[ 'desc' => '', 'rate' => 10.0, 'qty' => 1 ],
				[ 'desc' => 'Valid', 'rate' => 50.0, 'qty' => 1 ],
			],
			'_invoice_issue_date' => '2025-01-15',
			'_due_date'           => '',
			'_invoice_id'         => '',
		];

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertCount( 1, $data['invoice_line_ids'] );
		$this->assertSame( 'Valid', $data['invoice_line_ids'][0][2]['name'] );
	}

	public function test_handler_load_invoice_fallback_single_line(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'          => 100,
			'post_type'   => 'sa_invoice',
			'post_status' => 'publish',
			'post_title'  => 'Flat Invoice',
		];
		$GLOBALS['_wp_post_meta'][100] = [
			'_total'              => 250.0,
			'_doc_line_items'     => [],
			'_invoice_issue_date' => '2025-01-15',
			'_due_date'           => '',
			'_invoice_id'         => '',
		];

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertCount( 1, $data['invoice_line_ids'] );
		$this->assertSame( 'Flat Invoice', $data['invoice_line_ids'][0][2]['name'] );
		$this->assertSame( 1.0, $data['invoice_line_ids'][0][2]['quantity'] );
		$this->assertSame( 250.0, $data['invoice_line_ids'][0][2]['price_unit'] );
	}

	public function test_handler_load_invoice_returns_empty_when_not_found(): void {
		$data = $this->handler->load_invoice( 999, 42 );

		$this->assertEmpty( $data );
	}

	public function test_handler_load_invoice_returns_empty_when_wrong_type(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'          => 100,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Not an invoice',
		];

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertEmpty( $data );
	}

	// ─── Handler: load_payment ──────────────────────────────

	public function test_handler_load_payment_returns_account_payment(): void {
		$this->create_payment_post( 200 );

		$data = $this->handler->load_payment( 200, 42 );

		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( 150.0, $data['amount'] );
		$this->assertSame( '2025-01-20', $data['date'] );
		$this->assertSame( 'inbound', $data['payment_type'] );
		$this->assertSame( 'Check', $data['ref'] );
	}

	public function test_handler_load_payment_returns_empty_when_not_found(): void {
		$data = $this->handler->load_payment( 999, 42 );

		$this->assertEmpty( $data );
	}

	public function test_handler_load_payment_returns_empty_when_wrong_type(): void {
		$GLOBALS['_wp_posts'][200] = (object) [
			'ID'          => 200,
			'post_type'   => 'post',
			'post_status' => 'publish',
		];

		$data = $this->handler->load_payment( 200, 42 );

		$this->assertEmpty( $data );
	}

	// ─── Handler: helpers ───────────────────────────────────

	public function test_handler_get_client_id(): void {
		$GLOBALS['_wp_post_meta'][100] = [ '_client_id' => 50 ];

		$this->assertSame( 50, $this->handler->get_client_id( 100 ) );
	}

	public function test_handler_get_client_id_returns_zero_when_missing(): void {
		$this->assertSame( 0, $this->handler->get_client_id( 999 ) );
	}

	public function test_handler_get_invoice_id_for_payment(): void {
		$GLOBALS['_wp_post_meta'][200] = [ '_invoice_id' => 100 ];

		$this->assertSame( 100, $this->handler->get_invoice_id_for_payment( 200 ) );
	}

	// ─── Handler: status mapping ────────────────────────────

	public function test_status_temp_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'temp' ) );
	}

	public function test_status_publish_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'publish' ) );
	}

	public function test_status_partial_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'partial' ) );
	}

	public function test_status_complete_maps_to_posted(): void {
		$this->assertSame( 'posted', $this->handler->map_status( 'complete' ) );
	}

	public function test_status_write_off_maps_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_status( 'write-off' ) );
	}

	public function test_status_unknown_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'custom_status' ) );
	}

	// ─── Hooks: on_invoice_save ─────────────────────────────

	public function test_hook_on_invoice_save_skips_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'          => 100,
			'post_type'   => 'post',
			'post_status' => 'publish',
		];

		$this->module->on_invoice_save( 100 );

		$this->assertEmpty( $this->wpdb->calls );
	}

	// ─── Hooks: on_payment ──────────────────────────────────

	public function test_hook_on_payment_skips_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][200] = (object) [
			'ID'          => 200,
			'post_type'   => 'post',
			'post_status' => 'publish',
		];

		$this->module->on_payment( 200 );

		$this->assertEmpty( $this->wpdb->calls );
	}

	// ─── Pull settings ──────────────────────────────────

	public function test_default_settings_has_pull_invoices(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_invoices'] );
	}

	public function test_default_settings_has_pull_payments(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_payments'] );
	}

	public function test_settings_fields_exposes_pull_invoices(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['pull_invoices']['type'] );
	}

	public function test_settings_fields_exposes_pull_payments(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['pull_payments']['type'] );
	}

	// ─── Pull: delete ───────────────────────────────────

	public function test_pull_invoice_delete_removes_post(): void {
		$this->create_invoice_post( 50, 'publish' );

		$result = $this->module->pull_from_odoo( 'invoice', 'delete', 100, 50 );
		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_payment_delete_removes_post(): void {
		$this->create_payment_post( 60 );

		$result = $this->module->pull_from_odoo( 'payment', 'delete', 200, 60 );
		$this->assertTrue( $result->succeeded() );
	}

	// ─── map_from_odoo ──────────────────────────────────

	public function test_map_from_odoo_invoice(): void {
		$odoo_data = [
			'ref'              => 'INV-100',
			'invoice_date'     => '2025-03-01',
			'invoice_date_due' => '2025-04-01',
			'amount_total'     => 500.0,
			'state'            => 'posted',
			'invoice_line_ids' => [
				[ 0, 0, [ 'name' => 'Consulting', 'quantity' => 2.0, 'price_unit' => 250.0 ] ],
			],
		];

		$wp_data = $this->module->map_from_odoo( 'invoice', $odoo_data );

		$this->assertSame( 'INV-100', $wp_data['title'] );
		$this->assertSame( 500.0, $wp_data['total'] );
		$this->assertSame( 'complete', $wp_data['status'] );
		$this->assertCount( 1, $wp_data['line_items'] );
		$this->assertSame( 'Consulting', $wp_data['line_items'][0]['desc'] );
	}

	public function test_map_from_odoo_payment(): void {
		$odoo_data = [
			'amount' => 150.0,
			'date'   => '2025-03-15',
			'ref'    => 'Bank Transfer',
		];

		$wp_data = $this->module->map_from_odoo( 'payment', $odoo_data );

		$this->assertSame( 150.0, $wp_data['amount'] );
		$this->assertSame( '2025-03-15', $wp_data['date'] );
		$this->assertSame( 'Bank Transfer', $wp_data['method'] );
	}

	// ─── Handler: reverse status mapping ────────────────

	public function test_handler_odoo_draft_maps_to_publish(): void {
		$this->assertSame( 'publish', $this->handler->map_odoo_status_to_si( 'draft' ) );
	}

	public function test_handler_odoo_posted_maps_to_complete(): void {
		$this->assertSame( 'complete', $this->handler->map_odoo_status_to_si( 'posted' ) );
	}

	public function test_handler_odoo_cancel_maps_to_write_off(): void {
		$this->assertSame( 'write-off', $this->handler->map_odoo_status_to_si( 'cancel' ) );
	}

	public function test_handler_odoo_unknown_maps_to_publish(): void {
		$this->assertSame( 'publish', $this->handler->map_odoo_status_to_si( 'unknown' ) );
	}

	// ─── Handler: parse_invoice_from_odoo ───────────────

	public function test_handler_parse_invoice_from_odoo(): void {
		$odoo_data = [
			'ref'              => 'INV-200',
			'invoice_date'     => '2025-05-01',
			'invoice_date_due' => '2025-06-01',
			'amount_total'     => 300.0,
			'state'            => 'draft',
			'invoice_line_ids' => [
				[ 0, 0, [ 'name' => 'Service A', 'quantity' => 1.0, 'price_unit' => 300.0 ] ],
			],
		];

		$data = $this->handler->parse_invoice_from_odoo( $odoo_data );

		$this->assertSame( 'INV-200', $data['title'] );
		$this->assertSame( 300.0, $data['total'] );
		$this->assertSame( 'publish', $data['status'] );
		$this->assertCount( 1, $data['line_items'] );
	}

	public function test_handler_parse_invoice_handles_flat_line_items(): void {
		$odoo_data = [
			'ref'              => 'INV-300',
			'state'            => 'posted',
			'invoice_line_ids' => [
				[ 'name' => 'Flat Line', 'quantity' => 2.0, 'price_unit' => 100.0 ],
			],
		];

		$data = $this->handler->parse_invoice_from_odoo( $odoo_data );

		$this->assertCount( 1, $data['line_items'] );
		$this->assertSame( 'Flat Line', $data['line_items'][0]['desc'] );
	}

	// ─── Handler: parse_payment_from_odoo ───────────────

	public function test_handler_parse_payment_from_odoo(): void {
		$odoo_data = [
			'amount' => 200.0,
			'date'   => '2025-05-15',
			'ref'    => 'Wire Transfer',
		];

		$data = $this->handler->parse_payment_from_odoo( $odoo_data );

		$this->assertSame( 200.0, $data['amount'] );
		$this->assertSame( '2025-05-15', $data['date'] );
		$this->assertSame( 'Wire Transfer', $data['method'] );
	}

	// ─── Handler: save_invoice ──────────────────────────

	public function test_handler_save_invoice_creates_new_post(): void {
		$data = [
			'title'      => 'New Invoice',
			'total'      => 500.0,
			'issue_date' => '2025-03-01',
			'due_date'   => '2025-04-01',
			'ref'        => 'INV-NEW',
			'status'     => 'publish',
			'line_items' => [
				[ 'desc' => 'Service', 'qty' => 1, 'rate' => 500.0 ],
			],
		];

		$result = $this->handler->save_invoice( $data );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_handler_save_invoice_updates_existing(): void {
		$this->create_invoice_post( 100, 'publish' );

		$data = [
			'title'      => 'Updated Invoice',
			'total'      => 600.0,
			'issue_date' => '2025-03-01',
			'due_date'   => '2025-04-01',
			'ref'        => 'INV-UPD',
			'status'     => 'complete',
		];

		$result = $this->handler->save_invoice( $data, 100 );
		$this->assertSame( 100, $result );
	}

	// ─── Handler: save_payment ──────────────────────────

	public function test_handler_save_payment_creates_new_post(): void {
		$data = [
			'amount' => 150.0,
			'date'   => '2025-03-15',
			'method' => 'Check',
		];

		$result = $this->handler->save_payment( $data );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_handler_save_payment_updates_existing(): void {
		$this->create_payment_post( 200 );

		$data = [
			'amount' => 200.0,
			'date'   => '2025-03-20',
			'method' => 'Bank Transfer',
		];

		$result = $this->handler->save_payment( $data, 200 );
		$this->assertSame( 200, $result );
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Create a test invoice post with standard meta.
	 *
	 * @param int    $post_id Invoice post ID.
	 * @param string $status  Post status.
	 */
	private function create_invoice_post( int $post_id, string $status ): void {
		$GLOBALS['_wp_posts'][ $post_id ] = (object) [
			'ID'          => $post_id,
			'post_type'   => 'sa_invoice',
			'post_status' => $status,
			'post_title'  => 'Test Invoice',
		];
		$GLOBALS['_wp_post_meta'][ $post_id ] = [
			'_total'              => 300.0,
			'_doc_line_items'     => [
				[ 'desc' => 'Consulting', 'rate' => 100.0, 'qty' => 2 ],
				[ 'desc' => 'Design', 'rate' => 50.0, 'qty' => 2 ],
			],
			'_client_id'          => 50,
			'_due_date'           => '2025-02-15',
			'_invoice_issue_date' => '2025-01-15',
			'_invoice_id'         => 'INV-001',
		];
	}

	/**
	 * Create a test payment post with standard meta.
	 *
	 * @param int $post_id Payment post ID.
	 */
	private function create_payment_post( int $post_id ): void {
		$GLOBALS['_wp_posts'][ $post_id ] = (object) [
			'ID'          => $post_id,
			'post_type'   => 'sa_payment',
			'post_status' => 'publish',
		];
		$GLOBALS['_wp_post_meta'][ $post_id ] = [
			'_payment_total'  => 150.0,
			'_payment_method' => 'Check',
			'_payment_date'   => '2025-01-20',
			'_invoice_id'     => 100,
		];
	}
}
