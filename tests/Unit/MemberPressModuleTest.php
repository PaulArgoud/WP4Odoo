<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\MemberPress_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MemberPress_Module.
 *
 * Tests module configuration, entity type declarations, field mappings,
 * and default settings.
 */
class MemberPressModuleTest extends TestCase {

	private MemberPress_Module $module;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']          = [];
		$GLOBALS['_mepr_transactions']   = [];
		$GLOBALS['_mepr_subscriptions']  = [];

		$this->module = new MemberPress_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map() );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_memberpress(): void {
		$this->assertSame( 'memberpress', $this->module->get_id() );
	}

	public function test_module_name_is_memberpress(): void {
		$this->assertSame( 'MemberPress', $this->module->get_name() );
	}

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_plan_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.product', $models['plan'] );
	}

	public function test_declares_transaction_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.move', $models['transaction'] );
	}

	public function test_declares_subscription_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'membership.membership_line', $models['subscription'] );
	}

	public function test_declares_exactly_three_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 3, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_plans(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_plans'] );
	}

	public function test_default_settings_has_sync_transactions(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_transactions'] );
	}

	public function test_default_settings_has_sync_subscriptions(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_subscriptions'] );
	}

	public function test_default_settings_has_auto_post_invoices(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['auto_post_invoices'] );
	}

	public function test_default_settings_has_exactly_four_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 4, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_sync_plans(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_plans', $fields );
		$this->assertSame( 'checkbox', $fields['sync_plans']['type'] );
	}

	public function test_settings_fields_exposes_sync_transactions(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_transactions', $fields );
		$this->assertSame( 'checkbox', $fields['sync_transactions']['type'] );
	}

	public function test_settings_fields_exposes_sync_subscriptions(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_subscriptions', $fields );
		$this->assertSame( 'checkbox', $fields['sync_subscriptions']['type'] );
	}

	public function test_settings_fields_exposes_auto_post_invoices(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'auto_post_invoices', $fields );
		$this->assertSame( 'checkbox', $fields['auto_post_invoices']['type'] );
	}

	// ─── Field Mappings: Plan ──────────────────────────────

	public function test_plan_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'plan', [ 'plan_name' => 'Premium' ] );
		$this->assertSame( 'Premium', $odoo['name'] );
	}

	public function test_plan_mapping_includes_membership_flag(): void {
		$odoo = $this->module->map_to_odoo( 'plan', [ 'membership' => true ] );
		$this->assertTrue( $odoo['membership'] );
	}

	public function test_plan_mapping_includes_list_price(): void {
		$odoo = $this->module->map_to_odoo( 'plan', [ 'list_price' => 29.99 ] );
		$this->assertSame( 29.99, $odoo['list_price'] );
	}

	public function test_plan_mapping_includes_type(): void {
		$odoo = $this->module->map_to_odoo( 'plan', [ 'type' => 'service' ] );
		$this->assertSame( 'service', $odoo['type'] );
	}

	// ─── Field Mappings: Transaction ───────────────────────

	public function test_transaction_mapping_includes_move_type(): void {
		$odoo = $this->module->map_to_odoo( 'transaction', [ 'move_type' => 'out_invoice' ] );
		$this->assertSame( 'out_invoice', $odoo['move_type'] );
	}

	public function test_transaction_mapping_includes_partner_id(): void {
		$odoo = $this->module->map_to_odoo( 'transaction', [ 'partner_id' => 42 ] );
		$this->assertSame( 42, $odoo['partner_id'] );
	}

	public function test_transaction_mapping_includes_invoice_line_ids(): void {
		$lines = [ [ 0, 0, [ 'product_id' => 5, 'quantity' => 1, 'price_unit' => 29.99 ] ] ];
		$odoo  = $this->module->map_to_odoo( 'transaction', [ 'invoice_line_ids' => $lines ] );
		$this->assertSame( $lines, $odoo['invoice_line_ids'] );
	}

	public function test_transaction_mapping_includes_ref(): void {
		$odoo = $this->module->map_to_odoo( 'transaction', [ 'ref' => 'mp-txn-123' ] );
		$this->assertSame( 'mp-txn-123', $odoo['ref'] );
	}

	// ─── Field Mappings: Subscription ──────────────────────

	public function test_subscription_mapping_includes_partner_id(): void {
		$odoo = $this->module->map_to_odoo( 'subscription', [ 'partner_id' => 42 ] );
		$this->assertSame( 42, $odoo['partner_id'] );
	}

	public function test_subscription_mapping_includes_membership_id(): void {
		$odoo = $this->module->map_to_odoo( 'subscription', [ 'membership_id' => 5 ] );
		$this->assertSame( 5, $odoo['membership_id'] );
	}

	public function test_subscription_mapping_includes_state(): void {
		$odoo = $this->module->map_to_odoo( 'subscription', [ 'state' => 'paid' ] );
		$this->assertSame( 'paid', $odoo['state'] );
	}

	public function test_subscription_mapping_includes_dates(): void {
		$odoo = $this->module->map_to_odoo( 'subscription', [
			'date_from' => '2026-01-01',
			'date_to'   => '2027-01-01',
		] );
		$this->assertSame( '2026-01-01', $odoo['date_from'] );
		$this->assertSame( '2027-01-01', $odoo['date_to'] );
	}

	public function test_subscription_mapping_includes_member_price(): void {
		$odoo = $this->module->map_to_odoo( 'subscription', [ 'member_price' => 29.99 ] );
		$this->assertSame( 29.99, $odoo['member_price'] );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_unavailable_without_memberpress(): void {
		// MEPR_VERSION is not defined in our test bootstrap.
		$status = $this->module->get_dependency_status();
		$this->assertFalse( $status['available'] );
	}

	public function test_dependency_has_warning_without_memberpress(): void {
		$status = $this->module->get_dependency_status();
		$this->assertNotEmpty( $status['notices'] );
		$this->assertSame( 'warning', $status['notices'][0]['type'] );
	}

	// ─── Boot Guard ───────────────────────────────────────

	public function test_boot_does_not_crash_without_memberpress(): void {
		$this->module->boot();
		$this->assertTrue( true ); // No exception thrown.
	}
}
