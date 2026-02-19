<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WC_Points_Rewards_Module;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for WC_Points_Rewards_Hooks trait.
 *
 * Tests hook callbacks: anti-loop guard, settings guard,
 * queue enqueue behavior, and action determination.
 */
class WCPointsRewardsHooksTest extends Module_Test_Case {

	private WC_Points_Rewards_Module $module;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_wc_points_rewards'] = [];

		$this->module = new WC_Points_Rewards_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── on_points_change ───────────────────────────────

	public function test_on_points_change_enqueues_job(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_points_rewards_settings'] = [
			'sync_balances'   => true,
			'pull_balances'   => true,
			'odoo_program_id' => 5,
		];
		$GLOBALS['_wc_points_rewards'][42] = 100;

		$this->module->on_points_change( 42 );

		// The queue insert is done via $wpdb — check that calls were made.
		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_points_change_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_points_rewards_settings'] = [
			'sync_balances'   => true,
			'pull_balances'   => true,
			'odoo_program_id' => 5,
		];

		// Simulate importing state via reflection.
		$prop = ( new \ReflectionClass( \WP4Odoo\Module_Base::class ) )->getProperty( 'importing_request_local' );
		$prop->setAccessible( true );
		$prop->setValue( null, [ 'wc_points_rewards' => true ] );

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_points_change( 42 );

		// Should not enqueue — no additional calls.
		$this->assertCount( $initial_call_count, $this->wpdb->calls );

		// Clean up static state.
		$prop->setValue( null, [] );
	}

	public function test_on_points_change_skips_when_sync_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_points_rewards_settings'] = [
			'sync_balances'   => false,
			'pull_balances'   => true,
			'odoo_program_id' => 5,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_points_change( 42 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_points_change_skips_zero_user_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_points_rewards_settings'] = [
			'sync_balances'   => true,
			'pull_balances'   => true,
			'odoo_program_id' => 5,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_points_change( 0 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_points_change_makes_db_calls_for_valid_user(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_points_rewards_settings'] = [
			'sync_balances'   => true,
			'pull_balances'   => true,
			'odoo_program_id' => 5,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_points_change( 42 );

		// Should make at least one DB call (the enqueue).
		$this->assertGreaterThan( $initial_call_count, count( $this->wpdb->calls ) );
	}
}
