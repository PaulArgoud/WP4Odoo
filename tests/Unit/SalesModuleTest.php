<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Sales_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Sales_Module.
 *
 * Tests module configuration, entity type declarations, field mappings,
 * and default settings.
 */
class SalesModuleTest extends TestCase {

	private Sales_Module $module;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options'] = [];
		$GLOBALS['_wp_posts']   = [];

		$this->module = new Sales_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_sales(): void {
		$this->assertSame( 'sales', $this->module->get_id() );
	}

	public function test_module_name_is_sales(): void {
		$this->assertSame( 'Sales', $this->module->get_name() );
	}

	public function test_exclusive_group(): void {
		$this->assertSame( 'commerce', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority(): void {
		$this->assertSame( 10, $this->module->get_exclusive_priority() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_product_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.template', $models['product'] );
	}

	public function test_declares_order_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'sale.order', $models['order'] );
	}

	public function test_declares_invoice_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.move', $models['invoice'] );
	}

	public function test_declares_exactly_three_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 3, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_import_products(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['import_products'] );
	}

	public function test_default_settings_has_portal_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertFalse( $settings['portal_enabled'] );
	}

	public function test_default_settings_has_orders_per_page(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 10, $settings['orders_per_page'] );
	}

	public function test_default_settings_has_exactly_three_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 3, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_import_products(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'import_products', $fields );
		$this->assertSame( 'checkbox', $fields['import_products']['type'] );
	}

	public function test_settings_fields_exposes_portal_enabled(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'portal_enabled', $fields );
		$this->assertSame( 'checkbox', $fields['portal_enabled']['type'] );
	}

	public function test_settings_fields_exposes_orders_per_page(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'orders_per_page', $fields );
		$this->assertSame( 'number', $fields['orders_per_page']['type'] );
	}

	// ─── Field Mappings ────────────────────────────────────

	public function test_order_mapping_includes_total(): void {
		$mapping = $this->module->map_to_odoo( 'order', [ '_order_total' => '150.00' ] );
		$this->assertSame( '150.00', $mapping['amount_total'] );
	}

	public function test_order_mapping_includes_state(): void {
		$mapping = $this->module->map_to_odoo( 'order', [ '_order_state' => 'sale' ] );
		$this->assertSame( 'sale', $mapping['state'] );
	}

	public function test_invoice_mapping_includes_total(): void {
		$mapping = $this->module->map_to_odoo( 'invoice', [ '_invoice_total' => '200.00' ] );
		$this->assertSame( '200.00', $mapping['amount_total'] );
	}

	public function test_invoice_mapping_includes_state(): void {
		$mapping = $this->module->map_to_odoo( 'invoice', [ '_invoice_state' => 'posted' ] );
		$this->assertSame( 'posted', $mapping['state'] );
	}

	public function test_product_mapping_includes_name(): void {
		$mapping = $this->module->map_to_odoo( 'product', [ 'post_title' => 'Widget' ] );
		$this->assertSame( 'Widget', $mapping['name'] );
	}

	// ─── Reverse Mapping ───────────────────────────────────

	public function test_map_from_odoo_order(): void {
		$odoo_data = [ 'amount_total' => 250.0, 'state' => 'sale', 'name' => 'SO001' ];
		$wp_data   = $this->module->map_from_odoo( 'order', $odoo_data );

		$this->assertSame( 250.0, $wp_data['_order_total'] );
		$this->assertSame( 'sale', $wp_data['_order_state'] );
		$this->assertSame( 'SO001', $wp_data['post_title'] );
	}

	public function test_map_from_odoo_invoice(): void {
		$odoo_data = [ 'amount_total' => 100.0, 'state' => 'posted', 'name' => 'INV001' ];
		$wp_data   = $this->module->map_from_odoo( 'invoice', $odoo_data );

		$this->assertSame( 100.0, $wp_data['_invoice_total'] );
		$this->assertSame( 'posted', $wp_data['_invoice_state'] );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_status_is_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_status_has_no_notices(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot without crash ──────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true ); // No exception thrown.
	}

	// ─── Sync Direction ─────────────────────────────────

	public function test_sync_direction(): void {
		$this->assertSame( 'odoo_to_wp', $this->module->get_sync_direction() );
	}
}
