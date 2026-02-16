<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WCFM_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WCFM_Module.
 *
 * Tests module configuration, entity type declarations, default settings,
 * settings fields, dependency status, sync direction, field mappings,
 * exclusive group, required modules, and push overrides.
 */
class WCFMModuleTest extends TestCase {

	private WCFM_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_wp_transients']    = [];
		$GLOBALS['_wp_posts']         = [];
		$GLOBALS['_wp_post_meta']     = [];
		$GLOBALS['_wp_users']         = [];
		$GLOBALS['_wp_user_meta']     = [];
		$GLOBALS['_wcfm_vendors']     = [];
		$GLOBALS['_wcfm_commissions'] = [];
		$GLOBALS['_wcfm_withdrawals'] = [];
		$GLOBALS['_wcfm_orders']      = [];

		$this->module = new WCFM_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ──────────────────────────────────

	public function test_module_id_is_wcfm(): void {
		$this->assertSame( 'wcfm', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'WCFM Marketplace', $this->module->get_name() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	public function test_required_modules_includes_woocommerce(): void {
		$this->assertSame( [ 'woocommerce' ], $this->module->get_required_modules() );
	}

	// ─── Exclusive Group ─────────────────────────────────

	public function test_exclusive_group_is_marketplace(): void {
		$this->assertSame( 'marketplace', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority_is_fifteen(): void {
		$this->assertSame( 15, $this->module->get_exclusive_priority() );
	}

	// ─── Odoo Models ─────────────────────────────────────

	public function test_declares_vendor_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'res.partner', $models['vendor'] );
	}

	public function test_declares_sub_order_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'purchase.order', $models['sub_order'] );
	}

	public function test_declares_commission_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.move', $models['commission'] );
	}

	public function test_declares_payout_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.payment', $models['payout'] );
	}

	public function test_declares_exactly_four_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 4, $models );
	}

	// ─── Default Settings ────────────────────────────────

	public function test_default_settings_has_sync_vendors_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_vendors'] );
	}

	public function test_default_settings_has_sync_sub_orders_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_sub_orders'] );
	}

	public function test_default_settings_has_sync_commissions_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_commissions'] );
	}

	public function test_default_settings_has_sync_payouts_disabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertFalse( $settings['sync_payouts'] );
	}

	public function test_default_settings_has_auto_post_bills_disabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertFalse( $settings['auto_post_bills'] );
	}

	public function test_default_settings_has_pull_vendors_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_vendors'] );
	}

	public function test_default_settings_has_exactly_six_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 6, $settings );
	}

	// ─── Settings Fields ─────────────────────────────────

	public function test_settings_fields_exposes_sync_vendors(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_vendors', $fields );
		$this->assertSame( 'checkbox', $fields['sync_vendors']['type'] );
	}

	public function test_settings_fields_exposes_sync_commissions(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_commissions', $fields );
		$this->assertSame( 'checkbox', $fields['sync_commissions']['type'] );
	}

	public function test_settings_fields_exposes_auto_post_bills(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'auto_post_bills', $fields );
		$this->assertSame( 'checkbox', $fields['auto_post_bills']['type'] );
	}

	public function test_settings_fields_has_exactly_six_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 6, $fields );
	}

	// ─── Mapping (via map_to_odoo) ───────────────────────

	public function test_map_to_odoo_vendor_maps_name(): void {
		$mapped = $this->module->map_to_odoo( 'vendor', [ 'name' => 'WCFM Store' ] );
		$this->assertSame( 'WCFM Store', $mapped['name'] );
	}

	public function test_map_to_odoo_vendor_maps_email(): void {
		$mapped = $this->module->map_to_odoo( 'vendor', [ 'email' => 'vendor@example.com' ] );
		$this->assertSame( 'vendor@example.com', $mapped['email'] );
	}

	public function test_map_to_odoo_vendor_maps_supplier_rank(): void {
		$mapped = $this->module->map_to_odoo( 'vendor', [ 'supplier_rank' => 1 ] );
		$this->assertSame( 1, $mapped['supplier_rank'] );
	}

	public function test_map_to_odoo_commission_maps_move_type(): void {
		$mapped = $this->module->map_to_odoo( 'commission', [ 'move_type' => 'in_invoice' ] );
		$this->assertSame( 'in_invoice', $mapped['move_type'] );
	}

	public function test_map_to_odoo_commission_has_five_fields(): void {
		$data = [
			'move_type'        => 'in_invoice',
			'partner_id'       => 42,
			'invoice_date'     => '2026-02-14',
			'ref'              => 'wcfm-comm-100',
			'invoice_line_ids' => [ [ 0, 0, [] ] ],
		];
		$mapped = $this->module->map_to_odoo( 'commission', $data );
		$this->assertCount( 5, $mapped );
	}

	public function test_map_to_odoo_payout_maps_amount(): void {
		$mapped = $this->module->map_to_odoo( 'payout', [ 'amount' => 200.0 ] );
		$this->assertSame( 200.0, $mapped['amount'] );
	}

	public function test_map_to_odoo_payout_has_six_fields(): void {
		$data = [
			'partner_id'   => 42,
			'amount'       => 200.0,
			'date'         => '2026-02-14',
			'ref'          => 'wcfm-payout-1',
			'payment_type' => 'outbound',
			'partner_type' => 'supplier',
		];
		$mapped = $this->module->map_to_odoo( 'payout', $data );
		$this->assertCount( 6, $mapped );
	}

	// ─── Dependency Status ───────────────────────────────

	public function test_dependency_available_when_wcfm_defined(): void {
		$status = $this->module->get_dependency_status();
		// WCFM_VERSION is defined in test stubs.
		$this->assertTrue( $status['available'] );
	}

	// ─── Push Override ───────────────────────────────────

	public function test_push_returns_success_for_delete_action(): void {
		$result = $this->module->push_to_odoo( 'vendor', 'delete', 42, 0 );
		$this->assertTrue( $result->succeeded() );
	}

	// ─── Handler accessor ────────────────────────────────

	public function test_get_handler_returns_handler_instance(): void {
		$handler = $this->module->get_handler();
		$this->assertInstanceOf( \WP4Odoo\Modules\WCFM_Handler::class, $handler );
	}
}
