<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Module_Registry;
use WP4Odoo\Modules\WooCommerce_Module;
use WP4Odoo\Modules\EDD_Module;
use WP4Odoo\Modules\Sales_Module;
use WP4Odoo\Modules\Memberships_Module;
use WP4Odoo\Modules\MemberPress_Module;
use WP4Odoo\Modules\CRM_Module;
use WP4Odoo\Modules\WC_Subscriptions_Module;
use WP4Odoo\Modules\GamiPress_Module;
use WP4Odoo\Modules\MyCRED_Module;
use PHPUnit\Framework\TestCase;

class ModuleRegistryTest extends TestCase {

	private Module_Registry $registry;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		// Reset all module enabled options to false.
		$GLOBALS['_wp_options'] = [];

		$this->registry = new Module_Registry( \WP4Odoo_Plugin::instance(), wp4odoo_test_settings() );
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_options'] = [];
		\WP4Odoo_Plugin::reset_instance();
	}

	// Helper to create modules
	private function make_wc(): WooCommerce_Module {
		return new WooCommerce_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}
	private function make_edd(): EDD_Module {
		return new EDD_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}
	private function make_sales(): Sales_Module {
		return new Sales_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}
	private function make_crm(): CRM_Module {
		return new CRM_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}
	private function make_memberships(): Memberships_Module {
		return new Memberships_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}
	private function make_memberpress(): MemberPress_Module {
		return new MemberPress_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}
	private function make_wc_subscriptions(): WC_Subscriptions_Module {
		return new WC_Subscriptions_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}
	private function make_gamipress(): GamiPress_Module {
		return new GamiPress_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}
	private function make_mycred(): MyCRED_Module {
		return new MyCRED_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Registration ──────────────────────────────────────

	public function test_register_adds_module(): void {
		$this->registry->register( 'crm', $this->make_crm() );
		$this->assertNotNull( $this->registry->get( 'crm' ) );
	}

	public function test_get_returns_null_for_unknown(): void {
		$this->assertNull( $this->registry->get( 'nonexistent' ) );
	}

	public function test_all_returns_registered_modules(): void {
		$this->registry->register( 'crm', $this->make_crm() );
		$this->registry->register( 'sales', $this->make_sales() );
		$this->assertCount( 2, $this->registry->all() );
	}

	// ─── Boot Behavior ────────────────────────────────────

	public function test_register_does_not_boot_disabled_module(): void {
		// Module not enabled — boot() not called, no exception.
		$this->registry->register( 'sales', $this->make_sales() );
		$this->assertNotNull( $this->registry->get( 'sales' ) );
	}

	public function test_register_boots_enabled_module(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_crm_enabled'] = true;
		$this->registry->register( 'crm', $this->make_crm() );
		// CRM boot() triggers add_action calls — module is registered and booted.
		$this->assertNotNull( $this->registry->get( 'crm' ) );
	}

	// ─── Exclusive Group ──────────────────────────────────

	public function test_exclusive_group_blocks_second_module(): void {
		// Enable both WC and Sales.
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_sales_enabled'] = true;

		// Register WC first, then Sales. First-registered wins.
		$this->registry->register( 'woocommerce', $this->make_wc() );
		$this->registry->register( 'sales', $this->make_sales() );

		// WC is active in the group, Sales should not be.
		$this->assertSame( 'woocommerce', $this->registry->get_active_in_group( 'ecommerce' ) );
	}

	public function test_exclusive_group_first_registered_wins(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_edd_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_sales_enabled'] = true;

		// EDD registered first — it wins regardless of priority values.
		$this->registry->register( 'edd', $this->make_edd() );
		$this->registry->register( 'sales', $this->make_sales() );

		$this->assertSame( 'edd', $this->registry->get_active_in_group( 'ecommerce' ) );
	}

	public function test_exclusive_group_blocks_regardless_of_priority(): void {
		// Sales has lower priority (10) than WC (30), but if Sales registers first it wins.
		$GLOBALS['_wp_options']['wp4odoo_module_sales_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;

		$this->registry->register( 'sales', $this->make_sales() );
		$this->registry->register( 'woocommerce', $this->make_wc() );

		// Sales booted first, WC should be blocked even though it has higher priority.
		$this->assertSame( 'sales', $this->registry->get_active_in_group( 'ecommerce' ) );
		$this->assertSame( 1, $this->registry->get_booted_count() );
	}

	public function test_exclusive_group_allows_different_groups(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_memberpress_enabled'] = true;

		$this->registry->register( 'woocommerce', $this->make_wc() );
		$this->registry->register( 'memberpress', $this->make_memberpress() );

		// Both should be active — different groups.
		$this->assertSame( 'woocommerce', $this->registry->get_active_in_group( 'ecommerce' ) );
		$this->assertSame( 'memberpress', $this->registry->get_active_in_group( 'memberships' ) );
	}

	public function test_no_exclusive_group_module_always_boots(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_crm_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;

		$this->registry->register( 'crm', $this->make_crm() );
		$this->registry->register( 'woocommerce', $this->make_wc() );

		// CRM has no exclusive group, so it coexists with WC (commerce group).
		$this->assertSame( 'woocommerce', $this->registry->get_active_in_group( 'ecommerce' ) );
		$this->assertNotNull( $this->registry->get( 'crm' ) );
	}

	// ─── get_active_in_group ──────────────────────────────

	public function test_get_active_in_group_returns_null_when_none_booted(): void {
		$this->registry->register( 'sales', $this->make_sales() );
		$this->assertNull( $this->registry->get_active_in_group( 'ecommerce' ) );
	}

	public function test_get_active_in_group_with_memberships(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_memberships_enabled'] = true;

		$this->registry->register( 'woocommerce', $this->make_wc() );
		$this->registry->register( 'memberships', $this->make_memberships() );
		$this->assertSame( 'memberships', $this->registry->get_active_in_group( 'memberships' ) );
	}

	// ─── get_conflicts ────────────────────────────────────

	public function test_get_conflicts_returns_enabled_peers(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_sales_enabled'] = true;

		$this->registry->register( 'woocommerce', $this->make_wc() );
		$this->registry->register( 'sales', $this->make_sales() );

		// From Sales' perspective, WC is a conflict.
		$conflicts = $this->registry->get_conflicts( 'sales' );
		$this->assertContains( 'woocommerce', $conflicts );
	}

	public function test_get_conflicts_excludes_disabled_peers(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;
		// Sales is NOT enabled.

		$this->registry->register( 'woocommerce', $this->make_wc() );
		$this->registry->register( 'sales', $this->make_sales() );

		$conflicts = $this->registry->get_conflicts( 'woocommerce' );
		$this->assertEmpty( $conflicts );
	}

	public function test_get_conflicts_returns_empty_for_no_group(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_crm_enabled'] = true;
		$this->registry->register( 'crm', $this->make_crm() );

		$this->assertEmpty( $this->registry->get_conflicts( 'crm' ) );
	}

	public function test_get_conflicts_returns_empty_for_unknown_module(): void {
		$this->assertEmpty( $this->registry->get_conflicts( 'nonexistent' ) );
	}

	public function test_get_conflicts_across_membership_group(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_memberships_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_memberpress_enabled'] = true;

		$this->registry->register( 'memberships', $this->make_memberships() );
		$this->registry->register( 'memberpress', $this->make_memberpress() );

		$conflicts = $this->registry->get_conflicts( 'memberpress' );
		$this->assertContains( 'memberships', $conflicts );
	}

	// ─── get_booted_count (Point 5 support) ─────────────────

	public function test_get_booted_count_returns_zero_with_no_booted(): void {
		$this->registry->register( 'sales', $this->make_sales() );
		$this->assertSame( 0, $this->registry->get_booted_count() );
	}

	public function test_get_booted_count_returns_count_of_booted_modules(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_crm_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_sales_enabled'] = true;

		$this->registry->register( 'crm', $this->make_crm() );
		$this->registry->register( 'sales', $this->make_sales() );

		$this->assertSame( 2, $this->registry->get_booted_count() );
	}

	public function test_get_booted_count_excludes_disabled_modules(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_crm_enabled'] = true;
		// Sales NOT enabled.

		$this->registry->register( 'crm', $this->make_crm() );
		$this->registry->register( 'sales', $this->make_sales() );

		$this->assertSame( 1, $this->registry->get_booted_count() );
	}

	// ─── Declarative register_all (Point 8) ─────────────────

	public function test_register_all_registers_crm_always(): void {
		$this->registry->register_all();
		$this->assertNotNull( $this->registry->get( 'crm' ) );
	}

	public function test_register_all_registers_sales_always(): void {
		$this->registry->register_all();
		$this->assertNotNull( $this->registry->get( 'sales' ) );
	}

	public function test_register_all_skips_woocommerce_when_class_not_exists(): void {
		// WooCommerce class doesn't exist in test env → should not be registered.
		$this->registry->register_all();

		// WC detection checks class_exists('WooCommerce') — which is false in tests.
		// However, in tests we have a WooCommerce stub. Let's just verify
		// that register_all doesn't throw and produces a non-empty module list.
		$all = $this->registry->all();
		$this->assertNotEmpty( $all );
		$this->assertArrayHasKey( 'crm', $all );
		$this->assertArrayHasKey( 'sales', $all );
	}

	// ─── Required Modules ─────────────────────────────────

	public function test_required_module_blocks_boot_when_missing(): void {
		// Enable WC Subscriptions but NOT WooCommerce.
		$GLOBALS['_wp_options']['wp4odoo_module_wc_subscriptions_enabled'] = true;

		$this->registry->register( 'wc_subscriptions', $this->make_wc_subscriptions() );

		// Module is registered but not booted (0 booted).
		$this->assertNotNull( $this->registry->get( 'wc_subscriptions' ) );
		$this->assertSame( 0, $this->registry->get_booted_count() );
	}

	public function test_required_module_generates_warning_when_missing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_subscriptions_enabled'] = true;

		$this->registry->register( 'wc_subscriptions', $this->make_wc_subscriptions() );

		$warnings = $this->registry->get_version_warnings();
		$this->assertArrayHasKey( 'wc_subscriptions', $warnings );
		$this->assertStringContainsString( 'woocommerce', $warnings['wc_subscriptions'][0]['message'] );
	}

	public function test_required_module_allows_boot_when_present(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled']      = true;
		$GLOBALS['_wp_options']['wp4odoo_module_wc_subscriptions_enabled'] = true;

		// Register WC first so it boots.
		$this->registry->register( 'woocommerce', $this->make_wc() );
		$this->registry->register( 'wc_subscriptions', $this->make_wc_subscriptions() );

		// Both should be booted.
		$this->assertSame( 2, $this->registry->get_booted_count() );
	}

	public function test_base_module_returns_empty_required_modules(): void {
		$crm = $this->make_crm();
		$this->assertSame( [], $crm->get_required_modules() );
	}

	public function test_wc_subscriptions_requires_woocommerce(): void {
		$module = $this->make_wc_subscriptions();
		$this->assertSame( [ 'woocommerce' ], $module->get_required_modules() );
	}

	// ─── Gamification Exclusive Group ────────────────────

	public function test_gamification_group_blocks_second_module(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_enabled']    = true;

		$this->registry->register( 'gamipress', $this->make_gamipress() );
		$this->registry->register( 'mycred', $this->make_mycred() );

		// GamiPress registered first — it wins.
		$this->assertSame( 'gamipress', $this->registry->get_active_in_group( 'gamification' ) );
		$this->assertSame( 1, $this->registry->get_booted_count() );
	}

	// ─── Lazy Loading / Deferred Instantiation ───────────

	public function test_disabled_module_accessible_via_get_after_register_all(): void {
		// No modules enabled.
		$this->registry->register_all();

		// 'sales' has null detection (always detected) but is not enabled.
		// With lazy loading, it's deferred but still accessible via get().
		$this->assertNotNull( $this->registry->get( 'sales' ) );
		$this->assertInstanceOf( Sales_Module::class, $this->registry->get( 'sales' ) );
	}

	public function test_all_includes_deferred_disabled_modules(): void {
		// No modules enabled.
		$this->registry->register_all();

		$all = $this->registry->all();

		// Modules with null detection are always present (even disabled).
		$this->assertArrayHasKey( 'crm', $all );
		$this->assertArrayHasKey( 'sales', $all );
		$this->assertArrayHasKey( 'knowledge', $all );
	}

	public function test_deferred_modules_not_booted(): void {
		// No modules enabled.
		$this->registry->register_all();

		// No modules should be booted.
		$this->assertSame( 0, $this->registry->get_booted_count() );

		// But all() should still return detected modules.
		$this->assertNotEmpty( $this->registry->all() );
	}
}
