<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WC_Bundle_BOM_Module;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for WC_Bundle_BOM_Hooks trait.
 *
 * Tests hook callbacks: anti-loop guard, settings guard,
 * type guard, queue enqueue behavior, and create/update detection.
 */
class WCBundleBomHooksTest extends Module_Test_Case {

	private WC_Bundle_BOM_Module $module;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_wc_bundles']    = [];
		$GLOBALS['_wc_composites'] = [];

		$this->module = new WC_Bundle_BOM_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── on_bundle_save ─────────────────────────────────

	public function test_on_bundle_save_enqueues_job_for_bundle(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_bundle_bom_settings'] = [
			'sync_bundles' => true,
			'bom_type'     => 'phantom',
		];

		// Create a bundle product.
		$bundle = new \WC_Product_Bundle( 42 );
		$GLOBALS['_wc_products'][42] = $bundle;
		$GLOBALS['_wp_posts'][42]    = (object) [
			'ID'        => 42,
			'post_type' => 'product',
		];
		$GLOBALS['_wc_bundles'][42]  = [
			new \WC_Bundled_Item( 10, 1, false ),
		];

		$this->module->on_bundle_save( 42 );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_bundle_save_enqueues_job_for_composite(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_bundle_bom_settings'] = [
			'sync_bundles' => true,
			'bom_type'     => 'phantom',
		];

		$composite = new \WC_Product_Composite( 42 );
		$GLOBALS['_wc_products'][42]   = $composite;
		$GLOBALS['_wp_posts'][42]      = (object) [
			'ID'        => 42,
			'post_type' => 'product',
		];
		$GLOBALS['_wc_composites'][42] = [
			[
				'query_ids'    => [ 10 ],
				'quantity_min' => 1,
				'optional'     => false,
			],
		];

		$this->module->on_bundle_save( 42 );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_bundle_save_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_bundle_bom_settings'] = [
			'sync_bundles' => true,
			'bom_type'     => 'phantom',
		];

		$bundle = new \WC_Product_Bundle( 42 );
		$GLOBALS['_wc_products'][42] = $bundle;
		$GLOBALS['_wp_posts'][42]    = (object) [
			'ID'        => 42,
			'post_type' => 'product',
		];

		$prop = ( new \ReflectionClass( \WP4Odoo\Module_Base::class ) )->getProperty( 'importing_request_local' );
		$prop->setAccessible( true );
		$prop->setValue( null, [ 'wc_bundle_bom' => true ] );

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_bundle_save( 42 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );

		$prop->setValue( null, [] );
	}

	public function test_on_bundle_save_skips_when_sync_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_bundle_bom_settings'] = [
			'sync_bundles' => false,
			'bom_type'     => 'phantom',
		];

		$bundle = new \WC_Product_Bundle( 42 );
		$GLOBALS['_wc_products'][42] = $bundle;
		$GLOBALS['_wp_posts'][42]    = (object) [
			'ID'        => 42,
			'post_type' => 'product',
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_bundle_save( 42 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_bundle_save_skips_non_product_post_type(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_bundle_bom_settings'] = [
			'sync_bundles' => true,
			'bom_type'     => 'phantom',
		];

		$GLOBALS['_wp_posts'][42] = (object) [
			'ID'        => 42,
			'post_type' => 'post',
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_bundle_save( 42 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_bundle_save_skips_simple_product(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_bundle_bom_settings'] = [
			'sync_bundles' => true,
			'bom_type'     => 'phantom',
		];

		$GLOBALS['_wc_products'][42] = [ 'type' => 'simple', 'name' => 'Widget' ];
		$GLOBALS['_wp_posts'][42]    = (object) [
			'ID'        => 42,
			'post_type' => 'product',
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_bundle_save( 42 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_bundle_save_skips_revision(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_bundle_bom_settings'] = [
			'sync_bundles' => true,
			'bom_type'     => 'phantom',
		];

		$GLOBALS['_wp_posts'][42] = (object) [
			'ID'          => 42,
			'post_type'   => 'product',
			'post_parent' => 10,
			'post_status' => 'inherit',
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_bundle_save( 42 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}
}
