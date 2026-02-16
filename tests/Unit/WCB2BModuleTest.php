<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WC_B2B_Module;
use WP4Odoo\Modules\WC_B2B_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_B2B_Module.
 *
 * Tests module configuration, entity type declarations, field mappings,
 * default settings, company push is_company enforcement, pricelist rule
 * formatting, and dedup domains.
 */
class WCB2BModuleTest extends TestCase {

	private WC_B2B_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']          = [];
		$GLOBALS['_wp_user_meta']        = [];
		$GLOBALS['_wp_users']            = [];
		$GLOBALS['_wc_products']         = [];
		$GLOBALS['_wwp_wholesale_roles'] = [];
		$GLOBALS['_wwp_wholesale_prices'] = [];

		$this->module = new WC_B2B_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ──────────────────────────────────

	public function test_module_id_is_wc_b2b(): void {
		$this->assertSame( 'wc_b2b', $this->module->get_id() );
	}

	public function test_module_name_is_woocommerce_b2b(): void {
		$this->assertSame( 'WooCommerce B2B', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Required Modules ────────────────────────────────

	public function test_requires_woocommerce_module(): void {
		$required = $this->module->get_required_modules();
		$this->assertSame( [ 'woocommerce' ], $required );
	}

	// ─── Odoo Models ─────────────────────────────────────

	public function test_declares_company_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'res.partner', $models['company'] );
	}

	public function test_declares_pricelist_rule_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.pricelist.item', $models['pricelist_rule'] );
	}

	public function test_declares_payment_term_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.payment.term', $models['payment_term'] );
	}

	// ─── Default Settings ─────────────────────────────────

	public function test_default_settings_has_sync_companies(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_companies'] );
	}

	public function test_default_settings_has_sync_pricelist_rules(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_pricelist_rules'] );
	}

	public function test_default_settings_has_pull_payment_terms_disabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertFalse( $settings['pull_payment_terms'] );
	}

	public function test_default_settings_has_wholesale_pricelist_id_zero(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 0, $settings['wholesale_pricelist_id'] );
	}

	// ─── Field Mappings: Company ──────────────────────────

	public function test_company_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'company', [ 'billing_company' => 'Acme Corp' ] );
		$this->assertSame( 'Acme Corp', $odoo['name'] );
	}

	public function test_company_mapping_includes_email(): void {
		$odoo = $this->module->map_to_odoo( 'company', [ 'billing_email' => 'info@acme.com' ] );
		$this->assertSame( 'info@acme.com', $odoo['email'] );
	}

	public function test_company_mapping_includes_is_company(): void {
		$odoo = $this->module->map_to_odoo( 'company', [ 'is_company' => true ] );
		$this->assertTrue( $odoo['is_company'] );
	}

	// ─── Field Mappings: Pricelist Rule ──────────────────

	public function test_pricelist_rule_mapping_includes_pricelist_id(): void {
		$odoo = $this->module->map_to_odoo( 'pricelist_rule', [ 'pricelist_id' => 5 ] );
		$this->assertSame( 5, $odoo['pricelist_id'] );
	}

	public function test_pricelist_rule_mapping_includes_fixed_price(): void {
		$odoo = $this->module->map_to_odoo( 'pricelist_rule', [ 'fixed_price' => 29.99 ] );
		$this->assertSame( 29.99, $odoo['fixed_price'] );
	}

	public function test_pricelist_rule_mapping_includes_compute_price(): void {
		$odoo = $this->module->map_to_odoo( 'pricelist_rule', [ 'compute_price' => 'fixed' ] );
		$this->assertSame( 'fixed', $odoo['compute_price'] );
	}

	public function test_pricelist_rule_mapping_includes_applied_on(): void {
		$odoo = $this->module->map_to_odoo( 'pricelist_rule', [ 'applied_on' => '1_product' ] );
		$this->assertSame( '1_product', $odoo['applied_on'] );
	}

	// ─── Field Mappings: Payment Term ────────────────────

	public function test_payment_term_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'payment_term', [ 'name' => 'Net 30' ] );
		$this->assertSame( 'Net 30', $odoo['name'] );
	}

	// ─── Handler: Company Format ─────────────────────────

	public function test_format_company_for_odoo_sets_is_company(): void {
		$handler = new WC_B2B_Handler( new \WP4Odoo\Logger( 'test', wp4odoo_test_settings() ) );
		$data    = [
			'billing_company' => 'Acme Corp',
			'billing_email'   => 'info@acme.com',
			'billing_vat'     => 'FR12345',
			'billing_phone'   => '+33123456789',
		];

		$result = $handler->format_company_for_odoo( $data );

		$this->assertTrue( $result['is_company'] );
		$this->assertSame( 0, $result['supplier_rank'] );
		$this->assertSame( 1, $result['customer_rank'] );
	}

	public function test_format_company_for_odoo_with_category(): void {
		$handler = new WC_B2B_Handler( new \WP4Odoo\Logger( 'test', wp4odoo_test_settings() ) );
		$data    = [
			'billing_company' => 'Acme Corp',
			'billing_email'   => 'info@acme.com',
			'billing_vat'     => '',
			'billing_phone'   => '',
		];

		$result = $handler->format_company_for_odoo( $data, 42 );

		$this->assertSame( [ [ 6, 0, [ 42 ] ] ], $result['category_id'] );
	}

	public function test_format_company_for_odoo_without_category(): void {
		$handler = new WC_B2B_Handler( new \WP4Odoo\Logger( 'test', wp4odoo_test_settings() ) );
		$data    = [
			'billing_company' => 'Acme Corp',
			'billing_email'   => 'info@acme.com',
			'billing_vat'     => '',
			'billing_phone'   => '',
		];

		$result = $handler->format_company_for_odoo( $data, 0 );

		$this->assertArrayNotHasKey( 'category_id', $result );
	}

	// ─── Handler: Pricelist Rule Format ──────────────────

	public function test_format_pricelist_rule_for_odoo(): void {
		$handler = new WC_B2B_Handler( new \WP4Odoo\Logger( 'test', wp4odoo_test_settings() ) );

		$result = $handler->format_pricelist_rule_for_odoo( 29.99, 5, 100 );

		$this->assertSame( 5, $result['pricelist_id'] );
		$this->assertSame( 100, $result['product_tmpl_id'] );
		$this->assertSame( 29.99, $result['fixed_price'] );
		$this->assertSame( 'fixed', $result['compute_price'] );
		$this->assertSame( '1_product', $result['applied_on'] );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_available_with_wwp(): void {
		// WWP_PLUGIN_VERSION is defined in our test stubs.
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_no_warnings(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Pull: pricelist_rule skipped ────────────────────

	public function test_pull_pricelist_rule_skipped(): void {
		$result = $this->module->pull_from_odoo( 'pricelist_rule', 'update', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Push: payment_term skipped ──────────────────────

	public function test_push_payment_term_skipped(): void {
		$result = $this->module->push_to_odoo( 'payment_term', 'create', 1, 0 );
		$this->assertTrue( $result->succeeded() );
	}

	// ─── Boot Guard ──────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true ); // No exception thrown.
	}
}
