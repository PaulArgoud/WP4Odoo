<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\WP_Invoice_Module;
use WP4Odoo\Modules\WP_Invoice_Handler;
use WP4Odoo\Logger;

/**
 * @covers \WP4Odoo\Modules\WP_Invoice_Module
 * @covers \WP4Odoo\Modules\WP_Invoice_Handler
 * @covers \WP4Odoo\Modules\WP_Invoice_Hooks
 */
class WPInvoiceModuleTest extends Module_Test_Case {

	private WP_Invoice_Module $module;
	private WP_Invoice_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wp_users']     = [];
		$GLOBALS['_wpi_invoices'] = [];

		$this->module  = new WP_Invoice_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new WP_Invoice_Handler( new Logger( 'test', wp4odoo_test_settings() ) );
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wp_users']     = [];
		$GLOBALS['_wpi_invoices'] = [];
	}

	// ─── Identity ────────────────────────────────────────────

	public function test_module_id_is_wp_invoice(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'wp_invoice', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_wp_invoice(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'WP-Invoice', $ref->getValue( $this->module ) );
	}

	public function test_exclusive_group_is_invoicing(): void {
		$this->assertSame( 'invoicing', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority(): void {
		$this->assertSame( 5, $this->module->get_exclusive_priority() );
	}

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_invoice_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'account.move', $ref->getValue( $this->module )['invoice'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 1, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_invoices(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_invoices'] );
	}

	public function test_default_settings_has_auto_post_invoices(): void {
		$this->assertTrue( $this->module->get_default_settings()['auto_post_invoices'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 2, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_exposes_sync_invoices(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_invoices']['type'] );
	}

	public function test_settings_fields_exposes_auto_post(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['auto_post_invoices']['type'] );
	}

	public function test_settings_fields_count(): void {
		$this->assertCount( 2, $this->module->get_settings_fields() );
	}

	// ─── Field Mappings ─────────────────────────────────────

	public function test_invoice_mapping_has_move_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'move_type', $ref->getValue( $this->module )['invoice']['move_type'] );
	}

	public function test_invoice_mapping_has_invoice_line_ids(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'invoice_line_ids', $ref->getValue( $this->module )['invoice']['invoice_line_ids'] );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_with_wpi(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices(): void {
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
			'ref'              => 'WPI-100',
			'invoice_line_ids' => [ [ 0, 0, [ 'name' => 'Item', 'quantity' => 1, 'price_unit' => 100.0 ] ] ],
		];

		$mapped = $this->module->map_to_odoo( 'invoice', $data );

		$this->assertSame( 'out_invoice', $mapped['move_type'] );
		$this->assertSame( 42, $mapped['partner_id'] );
		$this->assertSame( 'WPI-100', $mapped['ref'] );
	}

	// ─── Handler: load_invoice ──────────────────────────────

	public function test_handler_load_invoice_returns_account_move(): void {
		$this->create_wpi_invoice( 100 );

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertSame( 'out_invoice', $data['move_type'] );
		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2025-01-15', $data['invoice_date'] );
		$this->assertSame( '2025-02-15', $data['invoice_date_due'] );
		$this->assertSame( 'WPI-100', $data['ref'] );
	}

	public function test_handler_load_invoice_builds_line_items(): void {
		$this->create_wpi_invoice( 100 );

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertCount( 2, $data['invoice_line_ids'] );
		$this->assertSame( 'Web Design', $data['invoice_line_ids'][0][2]['name'] );
		$this->assertSame( 2.0, $data['invoice_line_ids'][0][2]['quantity'] );
		$this->assertSame( 100.0, $data['invoice_line_ids'][0][2]['price_unit'] );
		$this->assertSame( 'Hosting', $data['invoice_line_ids'][1][2]['name'] );
	}

	public function test_handler_load_invoice_skips_empty_name_items(): void {
		$GLOBALS['_wpi_invoices'][100] = [
			'total'         => 50.0,
			'tax'           => 0,
			'itemized_list' => [
				[ 'name' => '', 'price' => 10.0, 'quantity' => 1 ],
				[ 'name' => 'Valid', 'price' => 50.0, 'quantity' => 1 ],
			],
			'invoice_id'    => 'WPI-100',
			'post_date'     => '2025-01-15 10:00:00',
			'due_date'      => '',
		];

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertCount( 1, $data['invoice_line_ids'] );
		$this->assertSame( 'Valid', $data['invoice_line_ids'][0][2]['name'] );
	}

	public function test_handler_load_invoice_fallback_single_line(): void {
		$GLOBALS['_wpi_invoices'][100] = [
			'total'         => 250.0,
			'tax'           => 0,
			'itemized_list' => [],
			'invoice_id'    => 'WPI-100',
			'post_date'     => '2025-01-15 10:00:00',
			'due_date'      => '',
		];

		$data = $this->handler->load_invoice( 100, 42 );

		$this->assertCount( 1, $data['invoice_line_ids'] );
		$this->assertSame( 250.0, $data['invoice_line_ids'][0][2]['price_unit'] );
	}

	public function test_handler_load_invoice_returns_empty_when_not_found(): void {
		$data = $this->handler->load_invoice( 999, 42 );

		$this->assertEmpty( $data );
	}

	// ─── Handler: get_user_data ─────────────────────────────

	public function test_handler_get_user_data_returns_user_info(): void {
		$GLOBALS['_wpi_invoices'][100] = [
			'user_data' => [
				'ID'           => 5,
				'user_email'   => 'john@example.com',
				'display_name' => 'John Doe',
			],
		];

		$user_data = $this->handler->get_user_data( 100 );

		$this->assertSame( 5, $user_data['user_id'] );
		$this->assertSame( 'john@example.com', $user_data['email'] );
		$this->assertSame( 'John Doe', $user_data['name'] );
	}

	public function test_handler_get_user_data_returns_defaults_when_missing(): void {
		$user_data = $this->handler->get_user_data( 999 );

		$this->assertSame( 0, $user_data['user_id'] );
		$this->assertSame( '', $user_data['email'] );
		$this->assertSame( '', $user_data['name'] );
	}

	// ─── Handler: status mapping ────────────────────────────

	public function test_status_active_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'active' ) );
	}

	public function test_status_paid_maps_to_posted(): void {
		$this->assertSame( 'posted', $this->handler->map_status( 'paid' ) );
	}

	public function test_status_pending_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_status( 'pending' ) );
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
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'          => 100,
			'post_type'   => 'post',
			'post_status' => 'publish',
		];

		$this->module->on_payment( 100 );

		$this->assertEmpty( $this->wpdb->calls );
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Create a test WP-Invoice invoice in the global store.
	 *
	 * @param int $post_id Invoice post ID.
	 */
	private function create_wpi_invoice( int $post_id ): void {
		$GLOBALS['_wpi_invoices'][ $post_id ] = [
			'total'         => 300.0,
			'tax'           => 15.0,
			'itemized_list' => [
				[ 'name' => 'Web Design', 'price' => 100.0, 'quantity' => 2 ],
				[ 'name' => 'Hosting', 'price' => 50.0, 'quantity' => 2 ],
			],
			'invoice_id'    => 'WPI-100',
			'post_date'     => '2025-01-15 10:00:00',
			'due_date'      => '2025-02-15 10:00:00',
			'user_data'     => [
				'ID'           => 5,
				'user_email'   => 'client@example.com',
				'display_name' => 'Client Name',
			],
		];
	}
}
