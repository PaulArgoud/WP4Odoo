<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WC_Subscriptions_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Subscriptions_Module.
 *
 * Tests module configuration, entity type declarations, field mappings,
 * default settings, and bidirectional pull support.
 */
class WCSubscriptionsModuleTest extends TestCase {

	private WC_Subscriptions_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']        = [];
		$GLOBALS['_wc_products']       = [];
		$GLOBALS['_wc_orders']         = [];
		$GLOBALS['_wc_subscriptions']  = [];

		$this->module = new WC_Subscriptions_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ──────────────────────────────────

	public function test_module_id_is_wc_subscriptions(): void {
		$this->assertSame( 'wc_subscriptions', $this->module->get_id() );
	}

	public function test_module_name_is_woocommerce_subscriptions(): void {
		$this->assertSame( 'WooCommerce Subscriptions', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority_is_zero(): void {
		$this->assertSame( 0, $this->module->get_exclusive_priority() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ─────────────────────────────────────

	public function test_declares_product_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.product', $models['product'] );
	}

	public function test_declares_subscription_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'sale.subscription', $models['subscription'] );
	}

	public function test_declares_renewal_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.move', $models['renewal'] );
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

	public function test_default_settings_has_sync_subscriptions(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_subscriptions'] );
	}

	public function test_default_settings_has_sync_renewals(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_renewals'] );
	}

	public function test_default_settings_has_auto_post_invoices(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['auto_post_invoices'] );
	}

	public function test_default_settings_has_pull_subscriptions(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_subscriptions'] );
	}

	public function test_default_settings_has_exactly_five_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 5, $settings );
	}

	// ─── Settings Fields ──────────────────────────────────

	public function test_settings_fields_exposes_sync_products(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_products', $fields );
		$this->assertSame( 'checkbox', $fields['sync_products']['type'] );
	}

	public function test_settings_fields_exposes_sync_subscriptions(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_subscriptions', $fields );
		$this->assertSame( 'checkbox', $fields['sync_subscriptions']['type'] );
	}

	public function test_settings_fields_exposes_sync_renewals(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_renewals', $fields );
		$this->assertSame( 'checkbox', $fields['sync_renewals']['type'] );
	}

	public function test_settings_fields_exposes_auto_post_invoices(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'auto_post_invoices', $fields );
		$this->assertSame( 'checkbox', $fields['auto_post_invoices']['type'] );
	}

	// ─── Field Mappings: Product ──────────────────────────

	public function test_product_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'product', [ 'product_name' => 'Premium Monthly' ] );
		$this->assertSame( 'Premium Monthly', $odoo['name'] );
	}

	public function test_product_mapping_includes_list_price(): void {
		$odoo = $this->module->map_to_odoo( 'product', [ 'list_price' => 19.99 ] );
		$this->assertSame( 19.99, $odoo['list_price'] );
	}

	public function test_product_mapping_includes_type(): void {
		$odoo = $this->module->map_to_odoo( 'product', [ 'type' => 'service' ] );
		$this->assertSame( 'service', $odoo['type'] );
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
			'recurring_next_date' => '2026-02-15',
		] );
		$this->assertSame( 'monthly', $odoo['recurring_rule_type'] );
		$this->assertSame( 1, $odoo['recurring_interval'] );
		$this->assertSame( '2026-02-15', $odoo['recurring_next_date'] );
	}

	public function test_subscription_mapping_includes_line_ids(): void {
		$lines = [ [ 0, 0, [ 'product_id' => 100, 'quantity' => 1, 'price_unit' => 19.99, 'name' => 'Sub' ] ] ];
		$odoo  = $this->module->map_to_odoo( 'subscription', [ 'recurring_invoice_line_ids' => $lines ] );
		$this->assertSame( $lines, $odoo['recurring_invoice_line_ids'] );
	}

	// ─── Field Mappings: Renewal ──────────────────────────

	public function test_renewal_mapping_includes_move_type(): void {
		$odoo = $this->module->map_to_odoo( 'renewal', [ 'move_type' => 'out_invoice' ] );
		$this->assertSame( 'out_invoice', $odoo['move_type'] );
	}

	public function test_renewal_mapping_includes_partner_id(): void {
		$odoo = $this->module->map_to_odoo( 'renewal', [ 'partner_id' => 42 ] );
		$this->assertSame( 42, $odoo['partner_id'] );
	}

	public function test_renewal_mapping_includes_invoice_line_ids(): void {
		$lines = [ [ 0, 0, [ 'product_id' => 100, 'quantity' => 1, 'price_unit' => 19.99 ] ] ];
		$odoo  = $this->module->map_to_odoo( 'renewal', [ 'invoice_line_ids' => $lines ] );
		$this->assertSame( $lines, $odoo['invoice_line_ids'] );
	}

	public function test_renewal_mapping_includes_ref(): void {
		$odoo = $this->module->map_to_odoo( 'renewal', [ 'ref' => 'WCS-100' ] );
		$this->assertSame( 'WCS-100', $odoo['ref'] );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_available_with_wc_subscriptions(): void {
		// WC_Subscriptions is defined in our test stubs.
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_no_warnings_with_wc_subscriptions(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Settings Fields: Pull ────────────────────────────

	public function test_settings_fields_exposes_pull_subscriptions(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_subscriptions', $fields );
		$this->assertSame( 'checkbox', $fields['pull_subscriptions']['type'] );
	}

	// ─── Pull: product skipped ──────────────────────────

	public function test_pull_product_skipped(): void {
		$result = $this->module->pull_from_odoo( 'product', 'update', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertSame( 0, $result->get_entity_id() );
	}

	public function test_pull_renewal_skipped(): void {
		$result = $this->module->pull_from_odoo( 'renewal', 'create', 200, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertSame( 0, $result->get_entity_id() );
	}

	// ─── Pull: delete not supported ─────────────────────

	public function test_pull_delete_subscription_succeeds(): void {
		$result = $this->module->pull_from_odoo( 'subscription', 'delete', 300, 20 );
		$this->assertTrue( $result->succeeded() );
	}

	// ─── map_from_odoo ──────────────────────────────────

	public function test_map_from_odoo_subscription_parses_correctly(): void {
		$odoo_data = [
			'state'                => 'in_progress',
			'date_start'           => '2026-01-15',
			'recurring_next_date'  => '2026-02-15',
			'recurring_rule_type'  => 'monthly',
			'recurring_interval'   => 1,
		];

		$wp_data = $this->module->map_from_odoo( 'subscription', $odoo_data );

		$this->assertSame( 'active', $wp_data['status'] );
		$this->assertSame( '2026-01-15', $wp_data['start_date'] );
		$this->assertSame( 'month', $wp_data['billing_period'] );
	}

	// ─── Boot Guard ───────────────────────────────────────

	public function test_boot_does_not_crash_with_wc_subscriptions(): void {
		$this->module->boot();
		$this->assertTrue( true ); // No exception thrown.
	}
}
