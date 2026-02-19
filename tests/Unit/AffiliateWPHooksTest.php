<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\AffiliateWP_Module;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for AffiliateWP_Hooks trait.
 *
 * Tests hook callbacks: anti-loop guard, status filtering,
 * settings guard, and queue enqueue behavior.
 */
class AffiliateWPHooksTest extends Module_Test_Case {

	private AffiliateWP_Module $module;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_affwp_affiliates'] = [];
		$GLOBALS['_affwp_referrals']  = [];

		$this->module = new AffiliateWP_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── on_affiliate_status_change ─────────────────────

	public function test_on_affiliate_status_change_enqueues_when_active(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_affiliatewp_settings'] = [
			'sync_affiliates' => true,
			'sync_referrals'  => true,
			'auto_post_bills' => false,
		];

		$this->module->on_affiliate_status_change( 42, 'active', 'pending' );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_affiliate_status_change_skips_pending(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_affiliatewp_settings'] = [
			'sync_affiliates' => true,
			'sync_referrals'  => true,
			'auto_post_bills' => false,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_affiliate_status_change( 42, 'pending', 'active' );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_affiliate_status_change_skips_inactive(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_affiliatewp_settings'] = [
			'sync_affiliates' => true,
			'sync_referrals'  => true,
			'auto_post_bills' => false,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_affiliate_status_change( 42, 'inactive', 'active' );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_affiliate_status_change_skips_rejected(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_affiliatewp_settings'] = [
			'sync_affiliates' => true,
			'sync_referrals'  => true,
			'auto_post_bills' => false,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_affiliate_status_change( 42, 'rejected', 'active' );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_affiliate_status_change_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_affiliatewp_settings'] = [
			'sync_affiliates' => true,
			'sync_referrals'  => true,
			'auto_post_bills' => false,
		];

		$prop = ( new \ReflectionClass( \WP4Odoo\Module_Base::class ) )->getProperty( 'importing_request_local' );
		$prop->setAccessible( true );
		$prop->setValue( null, [ 'affiliatewp' => true ] );

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_affiliate_status_change( 42, 'active', 'pending' );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );

		$prop->setValue( null, [] );
	}

	// ─── on_referral_status_change ──────────────────────

	public function test_on_referral_status_change_enqueues_when_unpaid(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_affiliatewp_settings'] = [
			'sync_affiliates' => true,
			'sync_referrals'  => true,
			'auto_post_bills' => false,
		];

		$this->module->on_referral_status_change( 99, 'unpaid', 'pending' );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_referral_status_change_enqueues_when_paid(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_affiliatewp_settings'] = [
			'sync_affiliates' => true,
			'sync_referrals'  => true,
			'auto_post_bills' => false,
		];

		$this->module->on_referral_status_change( 99, 'paid', 'unpaid' );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_referral_status_change_skips_pending(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_affiliatewp_settings'] = [
			'sync_affiliates' => true,
			'sync_referrals'  => true,
			'auto_post_bills' => false,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_referral_status_change( 99, 'pending', 'unpaid' );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_referral_status_change_skips_rejected(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_affiliatewp_settings'] = [
			'sync_affiliates' => true,
			'sync_referrals'  => true,
			'auto_post_bills' => false,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_referral_status_change( 99, 'rejected', 'unpaid' );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_referral_status_change_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_affiliatewp_settings'] = [
			'sync_affiliates' => true,
			'sync_referrals'  => true,
			'auto_post_bills' => false,
		];

		$prop = ( new \ReflectionClass( \WP4Odoo\Module_Base::class ) )->getProperty( 'importing_request_local' );
		$prop->setAccessible( true );
		$prop->setValue( null, [ 'affiliatewp' => true ] );

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_referral_status_change( 99, 'unpaid', 'pending' );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );

		$prop->setValue( null, [] );
	}
}
