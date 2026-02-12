<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\ShopWP_Module;
use WP4Odoo\Modules\ShopWP_Handler;
use WP4Odoo\Logger;

/**
 * @covers \WP4Odoo\Modules\ShopWP_Module
 * @covers \WP4Odoo\Modules\ShopWP_Handler
 * @covers \WP4Odoo\Modules\ShopWP_Hooks
 */
class ShopWPModuleTest extends Module_Test_Case {

	private ShopWP_Module $module;
	private ShopWP_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->module  = new ShopWP_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new ShopWP_Handler( new Logger( 'test', wp4odoo_test_settings() ) );
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
	}

	// ─── Identity ────────────────────────────────────────────

	public function test_module_id_is_shopwp(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'shopwp', $ref->getValue( $this->module ) );
	}

	public function test_module_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'ShopWP', $ref->getValue( $this->module ) );
	}

	public function test_exclusive_group_is_ecommerce(): void {
		$this->assertSame( 'ecommerce', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority_is_five(): void {
		$this->assertSame( 5, $this->module->get_exclusive_priority() );
	}

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_product_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'product.product', $ref->getValue( $this->module )['product'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 1, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_products(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_products'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 1, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_has_sync_products(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_products']['type'] );
	}

	public function test_settings_fields_count(): void {
		$this->assertCount( 1, $this->module->get_settings_fields() );
	}

	// ─── Field Mappings ─────────────────────────────────────

	public function test_product_mapping_includes_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'name', $ref->getValue( $this->module )['product']['product_name'] );
	}

	public function test_product_mapping_includes_list_price(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'list_price', $ref->getValue( $this->module )['product']['list_price'] );
	}

	public function test_product_mapping_includes_default_code(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'default_code', $ref->getValue( $this->module )['product']['default_code'] );
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

	// ─── map_to_odoo ────────────────────────────────────────

	public function test_map_to_odoo_product(): void {
		$data = [
			'product_name' => 'Shopify Widget',
			'list_price'   => 29.99,
			'default_code' => 'SH-001',
			'description'  => 'A shopify product',
			'type'         => 'consu',
		];

		$mapped = $this->module->map_to_odoo( 'product', $data );

		$this->assertSame( 'Shopify Widget', $mapped['name'] );
		$this->assertSame( 29.99, $mapped['list_price'] );
		$this->assertSame( 'SH-001', $mapped['default_code'] );
	}

	// ─── Handler: load_product ──────────────────────────────

	public function test_handler_load_product_returns_data(): void {
		$this->create_product( 100, 'Shopify Widget', 'A great widget' );

		$data = $this->handler->load_product( 100 );

		$this->assertSame( 'Shopify Widget', $data['product_name'] );
		$this->assertSame( 'A great widget', $data['description'] );
		$this->assertSame( 'consu', $data['type'] );
	}

	public function test_handler_load_product_returns_empty_when_not_found(): void {
		$data = $this->handler->load_product( 999 );

		$this->assertEmpty( $data );
	}

	public function test_handler_load_product_returns_empty_when_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'           => 100,
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Not a product',
			'post_content' => '',
		];

		$data = $this->handler->load_product( 100 );

		$this->assertEmpty( $data );
	}

	public function test_handler_load_product_returns_empty_when_no_name(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'           => 100,
			'post_type'    => 'wps_products',
			'post_status'  => 'publish',
			'post_title'   => '',
			'post_content' => 'Description',
		];

		$data = $this->handler->load_product( 100 );

		$this->assertEmpty( $data );
	}

	public function test_handler_load_product_strips_html(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'           => 100,
			'post_type'    => 'wps_products',
			'post_status'  => 'publish',
			'post_title'   => 'Widget',
			'post_content' => '<p>Bold <strong>text</strong></p>',
		];

		$data = $this->handler->load_product( 100 );

		$this->assertStringNotContainsString( '<', $data['description'] );
	}

	// ─── Handler: variant fallback ──────────────────────────

	public function test_handler_load_product_zero_price_when_no_variant(): void {
		$this->create_product( 100, 'No Variant', '' );

		$data = $this->handler->load_product( 100 );

		$this->assertSame( 0.0, $data['list_price'] );
		$this->assertSame( '', $data['default_code'] );
	}

	// ─── Hooks: on_product_save ─────────────────────────────

	public function test_hook_on_product_save_skips_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'          => 100,
			'post_type'   => 'post',
			'post_status' => 'publish',
		];

		$this->module->on_product_save( 100 );

		$this->assertEmpty( $this->wpdb->calls );
	}

	public function test_hook_on_product_save_skips_revision(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'          => 100,
			'post_type'   => 'revision',
			'post_status' => 'inherit',
			'post_parent' => 50,
		];

		$this->module->on_product_save( 100 );

		$this->assertEmpty( $this->wpdb->calls );
	}

	// ─── Helpers ────────────────────────────────────────────

	private function create_product( int $post_id, string $name, string $content ): void {
		$GLOBALS['_wp_posts'][ $post_id ] = (object) [
			'ID'           => $post_id,
			'post_type'    => 'wps_products',
			'post_status'  => 'publish',
			'post_title'   => $name,
			'post_content' => $content,
		];
	}
}
