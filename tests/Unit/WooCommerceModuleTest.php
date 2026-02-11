<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WooCommerce_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WooCommerce_Module.
 *
 * Tests module configuration, entity type declarations, and default settings.
 * WC-specific hook callbacks require full WC stubs and are tested in integration.
 */
class WooCommerceModuleTest extends TestCase {

	private WooCommerce_Module $module;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$this->module = new WooCommerce_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map() );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_woocommerce(): void {
		$this->assertSame( 'woocommerce', $this->module->get_id() );
	}

	public function test_module_name_is_woocommerce(): void {
		$this->assertSame( 'WooCommerce', $this->module->get_name() );
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

	public function test_declares_stock_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'stock.quant', $models['stock'] );
	}

	public function test_declares_variant_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.product', $models['variant'] );
	}

	public function test_declares_invoice_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.move', $models['invoice'] );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_products(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_products'] );
	}

	public function test_default_settings_has_sync_orders(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_orders'] );
	}

	public function test_default_settings_has_sync_stock(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_stock'] );
	}

	public function test_default_settings_has_auto_confirm_orders(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['auto_confirm_orders'] );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_six_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 6, $fields );
		$this->assertArrayHasKey( 'sync_products', $fields );
		$this->assertArrayHasKey( 'sync_orders', $fields );
		$this->assertArrayHasKey( 'sync_stock', $fields );
		$this->assertArrayHasKey( 'sync_product_images', $fields );
		$this->assertArrayHasKey( 'auto_confirm_orders', $fields );
		$this->assertArrayHasKey( 'convert_currency', $fields );
	}

	// ─── Field Mappings ────────────────────────────────────

	public function test_product_mapping_includes_sku(): void {
		$mapping = $this->module->map_to_odoo( 'product', [ 'sku' => 'ABC123' ] );
		$this->assertSame( 'ABC123', $mapping['default_code'] );
	}

	public function test_product_mapping_includes_regular_price(): void {
		$mapping = $this->module->map_to_odoo( 'product', [ 'regular_price' => '29.99' ] );
		$this->assertSame( '29.99', $mapping['list_price'] );
	}

	public function test_order_mapping_includes_total(): void {
		$mapping = $this->module->map_to_odoo( 'order', [ 'total' => '150.00' ] );
		$this->assertSame( '150.00', $mapping['amount_total'] );
	}

	public function test_invoice_mapping_includes_state(): void {
		$mapping = $this->module->map_to_odoo( 'invoice', [ '_invoice_state' => 'posted' ] );
		$this->assertSame( 'posted', $mapping['state'] );
	}

	public function test_variant_mapping_includes_sku(): void {
		$mapping = $this->module->map_to_odoo( 'variant', [ 'sku' => 'VAR-01' ] );
		$this->assertSame( 'VAR-01', $mapping['default_code'] );
	}

	public function test_variant_mapping_includes_price(): void {
		$mapping = $this->module->map_to_odoo( 'variant', [ 'regular_price' => '39.99' ] );
		$this->assertSame( '39.99', $mapping['lst_price'] );
	}

	public function test_map_from_odoo_variant(): void {
		$odoo_data = [
			'default_code'  => 'VAR-02',
			'lst_price'     => 25.0,
			'qty_available' => 10,
			'weight'        => 0.5,
			'display_name'  => 'Widget [Red, M]',
		];
		$wp_data = $this->module->map_from_odoo( 'variant', $odoo_data );

		$this->assertSame( 'VAR-02', $wp_data['sku'] );
		$this->assertSame( 25.0, $wp_data['regular_price'] );
		$this->assertSame( 10, $wp_data['stock_quantity'] );
		$this->assertSame( 0.5, $wp_data['weight'] );
		$this->assertSame( 'Widget [Red, M]', $wp_data['display_name'] );
	}

	// ─── Reverse Mapping ───────────────────────────────────

	public function test_map_from_odoo_product(): void {
		$odoo_data = [ 'name' => 'Widget', 'default_code' => 'WDG-01', 'list_price' => 19.99 ];
		$wp_data   = $this->module->map_from_odoo( 'product', $odoo_data );

		$this->assertSame( 'Widget', $wp_data['name'] );
		$this->assertSame( 'WDG-01', $wp_data['sku'] );
		$this->assertSame( 19.99, $wp_data['regular_price'] );
	}

	public function test_map_from_odoo_order(): void {
		$odoo_data = [ 'amount_total' => 250.0, 'state' => 'sale' ];
		$wp_data   = $this->module->map_from_odoo( 'order', $odoo_data );

		$this->assertSame( 250.0, $wp_data['total'] );
		$this->assertSame( 'sale', $wp_data['status'] );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_status_unavailable_without_woocommerce(): void {
		// WooCommerce class does not exist in test env.
		$status = $this->module->get_dependency_status();
		$this->assertFalse( $status['available'] );
	}

	public function test_dependency_status_has_warning_without_woocommerce(): void {
		$status = $this->module->get_dependency_status();
		$this->assertNotEmpty( $status['notices'] );
		$this->assertSame( 'warning', $status['notices'][0]['type'] );
	}

	// ─── Boot without WooCommerce ──────────────────────────

	public function test_boot_does_not_crash_without_woocommerce(): void {
		// WooCommerce class does not exist in test env.
		// boot() should return without error.
		$this->module->boot();
		$this->assertTrue( true ); // No exception thrown.
	}

	// ─── Sync Direction ─────────────────────────────────

	public function test_sync_direction(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}
}
