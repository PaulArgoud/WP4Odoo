<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\SureCart_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SureCart_Module.
 *
 * Tests module configuration, entity type declarations, field mappings,
 * default settings, deduplication, subscription Enterprise guard,
 * and bidirectional pull support.
 */
class SureCartModuleTest extends TestCase {

	private SureCart_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']             = [];
		$GLOBALS['_surecart_products']      = [];
		$GLOBALS['_surecart_orders']        = [];
		$GLOBALS['_surecart_subscriptions'] = [];

		$this->module = new SureCart_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ──────────────────────────────────

	public function test_module_id_is_surecart(): void {
		$this->assertSame( 'surecart', $this->module->get_id() );
	}

	public function test_module_name_is_surecart(): void {
		$this->assertSame( 'SureCart', $this->module->get_name() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	public function test_exclusive_group_is_ecommerce(): void {
		$this->assertSame( 'ecommerce', $this->module->get_exclusive_group() );
	}

	// ─── Exclusive Group ──────────────────────────────────

	public function test_exclusive_priority_is_eight(): void {
		$this->assertSame( 8, $this->module->get_exclusive_priority() );
	}

	public function test_exclusive_group_not_empty(): void {
		$this->assertNotEmpty( $this->module->get_exclusive_group() );
	}

	// ─── Odoo Models ──────────────────────────────────────

	public function test_declares_product_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.template', $models['product'] );
	}

	public function test_declares_order_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'sale.order', $models['order'] );
	}

	public function test_declares_subscription_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'sale.subscription', $models['subscription'] );
	}

	public function test_declares_exactly_three_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 3, $models );
	}

	// ─── Default Settings ─────────────────────────────────

	public function test_default_settings_has_sync_products(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_products'] );
	}

	public function test_default_settings_has_sync_orders(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_orders'] );
	}

	public function test_default_settings_has_sync_subscriptions(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_subscriptions'] );
	}

	public function test_default_settings_has_pull_subscriptions(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_subscriptions'] );
	}

	public function test_default_settings_has_exactly_four_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 4, $settings );
	}

	// ─── Settings Fields ──────────────────────────────────

	public function test_settings_fields_exposes_four_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 4, $fields );
	}

	public function test_settings_fields_sync_products_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_products']['type'] );
	}

	public function test_settings_fields_sync_orders_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_orders']['type'] );
	}

	public function test_settings_fields_sync_subscriptions_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_subscriptions']['type'] );
	}

	// ─── Field Mappings: Product ──────────────────────────

	public function test_product_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'product', [ 'name' => 'Pro Plan' ] );
		$this->assertSame( 'Pro Plan', $odoo['name'] );
	}

	public function test_product_mapping_includes_slug_as_default_code(): void {
		$odoo = $this->module->map_to_odoo( 'product', [ 'slug' => 'pro-plan' ] );
		$this->assertSame( 'pro-plan', $odoo['default_code'] );
	}

	public function test_product_mapping_includes_description(): void {
		$odoo = $this->module->map_to_odoo( 'product', [ 'description' => 'Best plan ever.' ] );
		$this->assertSame( 'Best plan ever.', $odoo['description_sale'] );
	}

	public function test_product_mapping_includes_price(): void {
		$odoo = $this->module->map_to_odoo( 'product', [ 'price' => 29.99 ] );
		$this->assertSame( 29.99, $odoo['list_price'] );
	}

	public function test_product_mapping_sets_type_consu(): void {
		$odoo = $this->module->map_to_odoo( 'product', [ 'name' => 'Widget' ] );
		$this->assertSame( 'consu', $odoo['type'] );
	}

	// ─── Field Mappings: Order ────────────────────────────

	public function test_order_mapping_includes_partner_id(): void {
		$odoo = $this->module->map_to_odoo( 'order', [ 'partner_id' => 42 ] );
		$this->assertSame( 42, $odoo['partner_id'] );
	}

	public function test_order_mapping_includes_date_order(): void {
		$odoo = $this->module->map_to_odoo( 'order', [ 'date_order' => '2026-01-15' ] );
		$this->assertSame( '2026-01-15', $odoo['date_order'] );
	}

	public function test_order_mapping_includes_ref(): void {
		$odoo = $this->module->map_to_odoo( 'order', [ 'ref' => 'sc-123' ] );
		$this->assertSame( 'sc-123', $odoo['client_order_ref'] );
	}

	public function test_order_mapping_includes_order_lines(): void {
		$lines = [ [ 0, 0, [ 'product_id' => 10, 'product_uom_qty' => 1, 'price_unit' => 29.99, 'name' => 'Item' ] ] ];
		$odoo  = $this->module->map_to_odoo( 'order', [ 'order_line' => $lines ] );
		$this->assertSame( $lines, $odoo['order_line'] );
	}

	// ─── Field Mappings: Subscription ─────────────────────

	public function test_subscription_mapping_includes_partner_id(): void {
		$odoo = $this->module->map_to_odoo( 'subscription', [ 'partner_id' => 42 ] );
		$this->assertSame( 42, $odoo['partner_id'] );
	}

	public function test_subscription_mapping_includes_date_start(): void {
		$odoo = $this->module->map_to_odoo( 'subscription', [ 'date_start' => '2026-01-15' ] );
		$this->assertSame( '2026-01-15', $odoo['date_start'] );
	}

	public function test_subscription_mapping_includes_recurring_fields(): void {
		$odoo = $this->module->map_to_odoo( 'subscription', [
			'recurring_rule_type' => 'monthly',
			'recurring_interval'  => 1,
		] );
		$this->assertSame( 'monthly', $odoo['recurring_rule_type'] );
		$this->assertSame( 1, $odoo['recurring_interval'] );
	}

	public function test_subscription_mapping_includes_line_ids(): void {
		$lines = [ [ 0, 0, [ 'product_id' => 100, 'quantity' => 1, 'price_unit' => 19.99, 'name' => 'Sub' ] ] ];
		$odoo  = $this->module->map_to_odoo( 'subscription', [ 'recurring_invoice_line_ids' => $lines ] );
		$this->assertSame( $lines, $odoo['recurring_invoice_line_ids'] );
	}

	// ─── Pull: product and order skipped ──────────────────

	public function test_pull_product_skipped(): void {
		$result = $this->module->pull_from_odoo( 'product', 'update', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	public function test_pull_order_skipped(): void {
		$result = $this->module->pull_from_odoo( 'order', 'create', 200, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	public function test_pull_subscription_skipped_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_surecart_settings'] = [ 'pull_subscriptions' => false ];
		$module = new SureCart_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$result = $module->pull_from_odoo( 'subscription', 'update', 300, 10 );
		$this->assertTrue( $result->succeeded() );
	}

	// ─── Deduplication ────────────────────────────────────

	public function test_dedup_product_by_default_code(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'product', [ 'default_code' => 'pro-plan' ] );
		$this->assertSame( [ [ 'default_code', '=', 'pro-plan' ] ], $domain );
	}

	public function test_dedup_order_by_ref(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'order', [ 'client_order_ref' => 'sc-123' ] );
		$this->assertSame( [ [ 'client_order_ref', '=', 'sc-123' ] ], $domain );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_available_with_surecart(): void {
		// SURECART_VERSION is defined in our test stubs.
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Enterprise Guard ─────────────────────────────────

	public function test_subscription_push_skipped_without_enterprise(): void {
		// has_subscription_model() will return false (no real Odoo connection).
		$result = $this->module->push_to_odoo( 'subscription', 'create', 1, 0 );
		$this->assertTrue( $result->succeeded() );
	}

	public function test_subscription_delete_not_guarded_by_enterprise(): void {
		// Delete action should not check for subscription model.
		$result = $this->module->push_to_odoo( 'subscription', 'delete', 1, 99 );
		// Will fail because no real Odoo connection, but shouldn't be skipped.
		$this->assertFalse( $result->succeeded() );
	}

	// ─── Boot Guard ───────────────────────────────────────

	public function test_boot_does_not_crash_with_surecart(): void {
		$this->module->boot();
		$this->assertTrue( true ); // No exception thrown.
	}
}
