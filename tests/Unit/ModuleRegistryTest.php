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

	public function test_exclusive_group_blocks_lower_priority(): void {
		// Enable both WC and Sales.
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_sales_enabled'] = true;

		// Register WC first (priority 30), then Sales (priority 10).
		$this->registry->register( 'woocommerce', $this->make_wc() );
		$this->registry->register( 'sales', $this->make_sales() );

		// WC is active in the group, Sales should not be.
		$this->assertSame( 'woocommerce', $this->registry->get_active_in_group( 'commerce' ) );
	}

	public function test_exclusive_group_allows_higher_priority_only(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_edd_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_sales_enabled'] = true;

		$this->registry->register( 'edd', $this->make_edd() );
		$this->registry->register( 'sales', $this->make_sales() );

		$this->assertSame( 'edd', $this->registry->get_active_in_group( 'commerce' ) );
	}

	public function test_exclusive_group_allows_different_groups(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_memberpress_enabled'] = true;

		$this->registry->register( 'woocommerce', $this->make_wc() );
		$this->registry->register( 'memberpress', $this->make_memberpress() );

		// Both should be active — different groups.
		$this->assertSame( 'woocommerce', $this->registry->get_active_in_group( 'commerce' ) );
		$this->assertSame( 'memberpress', $this->registry->get_active_in_group( 'memberships' ) );
	}

	public function test_no_exclusive_group_module_always_boots(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_crm_enabled'] = true;
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;

		$this->registry->register( 'crm', $this->make_crm() );
		$this->registry->register( 'woocommerce', $this->make_wc() );

		// CRM has no exclusive group, so it coexists with WC (commerce group).
		$this->assertSame( 'woocommerce', $this->registry->get_active_in_group( 'commerce' ) );
		$this->assertNotNull( $this->registry->get( 'crm' ) );
	}

	// ─── get_active_in_group ──────────────────────────────

	public function test_get_active_in_group_returns_null_when_none_booted(): void {
		$this->registry->register( 'sales', $this->make_sales() );
		$this->assertNull( $this->registry->get_active_in_group( 'commerce' ) );
	}

	public function test_get_active_in_group_with_memberships(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_memberships_enabled'] = true;

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
}
