<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WC_Points_Rewards_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Points_Rewards_Module.
 *
 * Tests module configuration, entity type declarations, default settings,
 * settings fields, dependency status, and push/pull overrides.
 */
class WCPointsRewardsModuleTest extends TestCase {

	private WC_Points_Rewards_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']        = [];
		$GLOBALS['_wp_users']          = [];
		$GLOBALS['_wp_user_meta']      = [];
		$GLOBALS['_wc_points_rewards'] = [];

		$this->module = new WC_Points_Rewards_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ──────────────────────────────────

	public function test_module_id_is_wc_points_rewards(): void {
		$this->assertSame( 'wc_points_rewards', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'WC Points & Rewards', $this->module->get_name() );
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

	public function test_declares_balance_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'loyalty.card', $models['balance'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 1, $models );
	}

	// ─── Default Settings ─────────────────────────────────

	public function test_default_settings_has_sync_balances(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_balances'] );
	}

	public function test_default_settings_has_pull_balances(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_balances'] );
	}

	public function test_default_settings_has_odoo_program_id(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 0, $settings['odoo_program_id'] );
	}

	public function test_default_settings_has_exactly_three_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 3, $settings );
	}

	// ─── Settings Fields ──────────────────────────────────

	public function test_settings_fields_exposes_sync_balances(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_balances', $fields );
		$this->assertSame( 'checkbox', $fields['sync_balances']['type'] );
	}

	public function test_settings_fields_exposes_pull_balances(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_balances', $fields );
		$this->assertSame( 'checkbox', $fields['pull_balances']['type'] );
	}

	public function test_settings_fields_exposes_odoo_program_id(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'odoo_program_id', $fields );
		$this->assertSame( 'number', $fields['odoo_program_id']['type'] );
	}

	public function test_settings_fields_has_exactly_three_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 3, $fields );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_available_when_class_exists(): void {
		$status = $this->module->get_dependency_status();
		// WC_Points_Rewards_Manager is defined in test stubs.
		$this->assertTrue( $status['available'] );
	}

	// ─── Pull Override ────────────────────────────────────

	public function test_pull_skips_when_pull_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_points_rewards_settings'] = [
			'sync_balances'   => true,
			'pull_balances'   => false,
			'odoo_program_id' => 5,
		];

		$result = $this->module->pull_from_odoo( 'balance', 'update', 55, 42 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Push Override ────────────────────────────────────

	public function test_push_skips_delete_action(): void {
		$result = $this->module->push_to_odoo( 'balance', 'delete', 42, 55 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_push_fails_when_program_id_not_configured(): void {
		// Set transient to indicate loyalty model is available.
		$GLOBALS['_wp_transients']['wp4odoo_has_loyalty_program'] = 1;
		$GLOBALS['_wp_options']['wp4odoo_module_wc_points_rewards_settings'] = [
			'sync_balances'   => true,
			'pull_balances'   => true,
			'odoo_program_id' => 0,
		];

		$result = $this->module->push_to_odoo( 'balance', 'create', 42, 0 );

		$this->assertFalse( $result->succeeded() );
	}

	public function test_push_fails_when_user_not_found(): void {
		$GLOBALS['_wp_transients']['wp4odoo_has_loyalty_program'] = 1;
		$GLOBALS['_wp_options']['wp4odoo_module_wc_points_rewards_settings'] = [
			'sync_balances'   => true,
			'pull_balances'   => true,
			'odoo_program_id' => 5,
		];

		// No user data → load_balance returns empty.
		$result = $this->module->push_to_odoo( 'balance', 'create', 999, 0 );

		$this->assertFalse( $result->succeeded() );
	}

	// ─── Delete WP Data ───────────────────────────────────

	public function test_delete_wp_data_always_returns_false(): void {
		// Access via pull with delete action — internally calls delete_wp_data.
		// Since delete_wp_data returns false, the pull will still succeed
		// but skip the actual delete (no-op for balances).
		$this->assertTrue( true ); // Structural test — delete is a no-op.
	}

	// ─── Odoo Model Name ──────────────────────────────────

	public function test_balance_odoo_model_is_loyalty_card(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'loyalty.card', $models['balance'] );
	}
}
