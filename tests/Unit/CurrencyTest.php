<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Field_Mapper;
use WP4Odoo\Modules\WooCommerce_Module;
use WP4Odoo\Modules\Sales_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for multi-currency support.
 *
 * Tests currency extraction from Many2one fields and currency mapping
 * in WooCommerce and Sales modules.
 */
class CurrencyTest extends TestCase {

	// ─── Field_Mapper currency extraction ────────────────────

	public function test_many2one_to_name_extracts_currency_code(): void {
		$this->assertSame( 'EUR', Field_Mapper::many2one_to_name( [ 2, 'EUR' ] ) );
	}

	public function test_many2one_to_name_extracts_usd(): void {
		$this->assertSame( 'USD', Field_Mapper::many2one_to_name( [ 1, 'USD' ] ) );
	}

	public function test_many2one_to_name_returns_null_for_false(): void {
		$this->assertNull( Field_Mapper::many2one_to_name( false ) );
	}

	public function test_many2one_to_name_returns_null_for_empty_array(): void {
		$this->assertNull( Field_Mapper::many2one_to_name( [] ) );
	}

	// ─── WooCommerce module currency mappings ────────────────

	public function test_wc_product_mapping_includes_currency(): void {
		$module  = $this->create_wc_module();
		$mapping = $module->map_from_odoo( 'product', [
			'name'           => 'Test',
			'currency_id'    => [ 2, 'EUR' ],
		] );

		$this->assertArrayHasKey( '_wp4odoo_currency', $mapping );
		$this->assertSame( [ 2, 'EUR' ], $mapping['_wp4odoo_currency'] );
	}

	public function test_wc_variant_mapping_includes_currency(): void {
		$module  = $this->create_wc_module();
		$mapping = $module->map_from_odoo( 'variant', [
			'currency_id' => [ 1, 'USD' ],
		] );

		$this->assertArrayHasKey( '_wp4odoo_currency', $mapping );
	}

	public function test_wc_invoice_mapping_includes_currency(): void {
		$module  = $this->create_wc_module();
		$mapping = $module->map_from_odoo( 'invoice', [
			'name'        => 'INV/2024/001',
			'currency_id' => [ 2, 'EUR' ],
		] );

		$this->assertArrayHasKey( '_invoice_currency', $mapping );
		$this->assertSame( [ 2, 'EUR' ], $mapping['_invoice_currency'] );
	}

	// ─── Sales module currency mappings ──────────────────────

	public function test_sales_order_mapping_includes_currency(): void {
		$module  = $this->create_sales_module();
		$mapping = $module->map_from_odoo( 'order', [
			'name'        => 'SO001',
			'currency_id' => [ 2, 'EUR' ],
		] );

		$this->assertArrayHasKey( '_order_currency', $mapping );
		$this->assertSame( [ 2, 'EUR' ], $mapping['_order_currency'] );
	}

	public function test_sales_invoice_mapping_includes_currency(): void {
		$module  = $this->create_sales_module();
		$mapping = $module->map_from_odoo( 'invoice', [
			'name'        => 'INV/2024/001',
			'currency_id' => [ 2, 'EUR' ],
		] );

		$this->assertArrayHasKey( '_invoice_currency', $mapping );
		$this->assertSame( [ 2, 'EUR' ], $mapping['_invoice_currency'] );
	}

	// ─── Helpers ─────────────────────────────────────────────

	private function create_wc_module(): WooCommerce_Module {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();
		return new WooCommerce_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	private function create_sales_module(): Sales_Module {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();
		return new Sales_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}
}
