<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\Crowdfunding_Module;
use WP4Odoo\Modules\Crowdfunding_Handler;
use WP4Odoo\Logger;

/**
 * @covers \WP4Odoo\Modules\Crowdfunding_Module
 * @covers \WP4Odoo\Modules\Crowdfunding_Handler
 * @covers \WP4Odoo\Modules\Crowdfunding_Hooks
 */
class CrowdfundingModuleTest extends Module_Test_Case {

	private Crowdfunding_Module $module;
	private Crowdfunding_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wc_products']  = [];

		$this->module  = new Crowdfunding_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new Crowdfunding_Handler( new Logger( 'test', wp4odoo_test_settings() ) );
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wc_products']  = [];
	}

	// ─── Identity ────────────────────────────────────────────

	public function test_module_id_is_crowdfunding(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'crowdfunding', $ref->getValue( $this->module ) );
	}

	public function test_module_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'WP Crowdfunding', $ref->getValue( $this->module ) );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_campaign_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'product.product', $ref->getValue( $this->module )['campaign'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 1, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_campaigns(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_campaigns'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 1, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_exposes_sync_campaigns(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_campaigns']['type'] );
	}

	// ─── Field Mappings ─────────────────────────────────────

	public function test_campaign_mapping_includes_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'name', $ref->getValue( $this->module )['campaign']['campaign_name'] );
	}

	public function test_campaign_mapping_includes_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'type', $ref->getValue( $this->module )['campaign']['type'] );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── map_to_odoo passthrough ────────────────────────────

	public function test_map_to_odoo_campaign(): void {
		$data = [
			'campaign_name' => 'Save the Trees',
			'list_price'    => 10000.0,
			'description'   => 'A campaign to save trees.',
			'type'          => 'service',
		];

		$mapped = $this->module->map_to_odoo( 'campaign', $data );

		$this->assertSame( 'Save the Trees', $mapped['name'] );
		$this->assertSame( 10000.0, $mapped['list_price'] );
		$this->assertSame( 'service', $mapped['type'] );
	}

	// ─── Handler: load_campaign ─────────────────────────────

	public function test_handler_load_campaign_returns_product(): void {
		$this->create_campaign( 100 );

		$data = $this->handler->load_campaign( 100 );

		$this->assertSame( 'Save the Trees', $data['campaign_name'] );
		$this->assertSame( 10000.0, $data['list_price'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_handler_load_campaign_description_includes_funding_info(): void {
		$this->create_campaign( 100 );

		$data = $this->handler->load_campaign( 100 );

		$this->assertStringContainsString( '10,000.00', $data['description'] );
		$this->assertStringContainsString( '2025-12-31', $data['description'] );
	}

	public function test_handler_load_campaign_returns_empty_when_not_found(): void {
		$data = $this->handler->load_campaign( 999 );

		$this->assertEmpty( $data );
	}

	public function test_handler_load_campaign_uses_product_price_when_no_goal(): void {
		$GLOBALS['_wc_products'][100] = [
			'name'        => 'No Goal',
			'price'       => '50.00',
			'description' => '',
		];
		$GLOBALS['_wp_post_meta'][100] = [
			'_wpneo_funding_goal'           => '',
			'_wpneo_funding_end_date'       => '',
			'_wpneo_funding_minimum_amount' => '',
		];

		$data = $this->handler->load_campaign( 100 );

		$this->assertSame( 50.0, $data['list_price'] );
	}

	// ─── Handler: is_crowdfunding ───────────────────────────

	public function test_handler_is_crowdfunding_true(): void {
		$GLOBALS['_wp_post_meta'][100] = [ '_wpneo_funding_goal' => '5000' ];

		$this->assertTrue( $this->handler->is_crowdfunding( 100 ) );
	}

	public function test_handler_is_crowdfunding_false(): void {
		$this->assertFalse( $this->handler->is_crowdfunding( 999 ) );
	}

	// ─── Hooks: on_campaign_save ─────────────────────────────

	public function test_hook_on_campaign_save_skips_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'          => 100,
			'post_type'   => 'post',
			'post_status' => 'publish',
		];

		$this->module->on_campaign_save( 100 );

		$this->assertEmpty( $this->wpdb->calls );
	}

	public function test_hook_on_campaign_save_skips_non_crowdfunding_product(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'          => 100,
			'post_type'   => 'product',
			'post_status' => 'publish',
		];
		// No crowdfunding meta.

		$this->module->on_campaign_save( 100 );

		$this->assertEmpty( $this->wpdb->calls );
	}

	// ─── Helpers ────────────────────────────────────────────

	private function create_campaign( int $product_id ): void {
		$GLOBALS['_wc_products'][ $product_id ] = [
			'name'        => 'Save the Trees',
			'price'       => '0',
			'description' => 'Help us plant trees.',
		];
		$GLOBALS['_wp_post_meta'][ $product_id ] = [
			'_wpneo_funding_goal'           => '10000',
			'_wpneo_funding_end_date'       => '2025-12-31',
			'_wpneo_funding_minimum_amount' => '10',
		];
	}
}
