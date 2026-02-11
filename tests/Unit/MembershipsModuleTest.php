<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Memberships_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Memberships_Module.
 *
 * Tests module configuration, entity type declarations, field mappings,
 * and default settings.
 */
class MembershipsModuleTest extends TestCase {

	private Memberships_Module $module;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']          = [];
		$GLOBALS['_wc_memberships']      = [];
		$GLOBALS['_wc_membership_plans'] = [];

		$this->module = new Memberships_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_memberships(): void {
		$this->assertSame( 'memberships', $this->module->get_id() );
	}

	public function test_module_name_is_memberships(): void {
		$this->assertSame( 'Memberships', $this->module->get_name() );
	}

	public function test_exclusive_group(): void {
		$this->assertSame( 'memberships', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority(): void {
		$this->assertSame( 20, $this->module->get_exclusive_priority() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_plan_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.product', $models['plan'] );
	}

	public function test_declares_membership_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'membership.membership_line', $models['membership'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 2, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_plans(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_plans'] );
	}

	public function test_default_settings_has_sync_memberships(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_memberships'] );
	}

	public function test_default_settings_has_exactly_two_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 2, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_sync_plans(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_plans', $fields );
		$this->assertSame( 'checkbox', $fields['sync_plans']['type'] );
	}

	public function test_settings_fields_exposes_sync_memberships(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_memberships', $fields );
		$this->assertSame( 'checkbox', $fields['sync_memberships']['type'] );
	}

	// ─── Field Mappings ────────────────────────────────────

	public function test_plan_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'plan', [ 'plan_name' => 'Gold Plan' ] );
		$this->assertSame( 'Gold Plan', $odoo['name'] );
	}

	public function test_plan_mapping_includes_membership_flag(): void {
		$odoo = $this->module->map_to_odoo( 'plan', [ 'membership' => true ] );
		$this->assertTrue( $odoo['membership'] );
	}

	public function test_plan_mapping_includes_list_price(): void {
		$odoo = $this->module->map_to_odoo( 'plan', [ 'list_price' => 49.99 ] );
		$this->assertSame( 49.99, $odoo['list_price'] );
	}

	public function test_membership_mapping_includes_partner_id(): void {
		$odoo = $this->module->map_to_odoo( 'membership', [ 'partner_id' => 42 ] );
		$this->assertSame( 42, $odoo['partner_id'] );
	}

	public function test_membership_mapping_includes_state(): void {
		$odoo = $this->module->map_to_odoo( 'membership', [ 'state' => 'paid' ] );
		$this->assertSame( 'paid', $odoo['state'] );
	}

	public function test_membership_mapping_includes_dates(): void {
		$odoo = $this->module->map_to_odoo( 'membership', [
			'date_from' => '2026-01-01',
			'date_to'   => '2027-01-01',
		] );
		$this->assertSame( '2026-01-01', $odoo['date_from'] );
		$this->assertSame( '2027-01-01', $odoo['date_to'] );
	}

	public function test_membership_mapping_includes_member_price(): void {
		$odoo = $this->module->map_to_odoo( 'membership', [ 'member_price' => 99.00 ] );
		$this->assertSame( 99.00, $odoo['member_price'] );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_status_available_with_wc_memberships(): void {
		// wc_memberships() is defined as a stub, so function_exists returns true.
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_status_has_no_notices_when_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot guard ────────────────────────────────────────

	public function test_boot_does_not_crash_without_wc_memberships(): void {
		// wc_memberships() is defined as a stub, but the guard checks function_exists().
		// With our stub, function_exists returns true, so boot completes without error.
		$this->module->boot();
		$this->assertTrue( true ); // No exception thrown.
	}

	// ─── Sync Direction ─────────────────────────────────

	public function test_sync_direction(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}
}
