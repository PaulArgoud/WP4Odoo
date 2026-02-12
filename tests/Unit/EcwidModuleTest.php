<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\Ecwid_Module;
use WP4Odoo\Modules\Ecwid_Handler;
use WP4Odoo\Logger;

/**
 * @covers \WP4Odoo\Modules\Ecwid_Module
 * @covers \WP4Odoo\Modules\Ecwid_Handler
 * @covers \WP4Odoo\Modules\Ecwid_Cron_Hooks
 */
class EcwidModuleTest extends Module_Test_Case {

	private Ecwid_Module $module;
	private Ecwid_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']         = [];
		$GLOBALS['_wp_remote_response'] = null;

		$this->module  = new Ecwid_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new Ecwid_Handler( new Logger( 'test', wp4odoo_test_settings() ) );
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_options']         = [];
		$GLOBALS['_wp_remote_response'] = null;
	}

	// ─── Identity ────────────────────────────────────────────

	public function test_module_id_is_ecwid(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'ecwid', $ref->getValue( $this->module ) );
	}

	public function test_module_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'Ecwid', $ref->getValue( $this->module ) );
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

	public function test_declares_order_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'sale.order', $ref->getValue( $this->module )['order'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 2, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_store_id(): void {
		$this->assertSame( '', $this->module->get_default_settings()['ecwid_store_id'] );
	}

	public function test_default_settings_has_api_token(): void {
		$this->assertSame( '', $this->module->get_default_settings()['ecwid_api_token'] );
	}

	public function test_default_settings_has_sync_products(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_products'] );
	}

	public function test_default_settings_has_sync_orders(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_orders'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 4, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_has_store_id(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'text', $fields['ecwid_store_id']['type'] );
	}

	public function test_settings_fields_has_api_token(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'text', $fields['ecwid_api_token']['type'] );
	}

	public function test_settings_fields_has_sync_products(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_products']['type'] );
	}

	public function test_settings_fields_has_sync_orders(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_orders']['type'] );
	}

	public function test_settings_fields_count(): void {
		$this->assertCount( 4, $this->module->get_settings_fields() );
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

	public function test_order_mapping_includes_partner_id(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'partner_id', $ref->getValue( $this->module )['order']['partner_id'] );
	}

	public function test_order_mapping_includes_order_line(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'order_line', $ref->getValue( $this->module )['order']['order_line'] );
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

	// ─── Poll ───────────────────────────────────────────────

	public function test_poll_does_not_crash(): void {
		$this->module->poll();
		$this->assertTrue( true );
	}

	public function test_poll_skips_when_no_credentials(): void {
		$this->module->poll();
		$this->assertEmpty( $this->wpdb->calls );
	}

	// ─── map_to_odoo ────────────────────────────────────────

	public function test_map_to_odoo_product(): void {
		$data = [
			'product_name' => 'Widget',
			'list_price'   => 29.99,
			'default_code' => 'WDG-001',
			'description'  => 'A widget',
			'type'         => 'consu',
		];

		$mapped = $this->module->map_to_odoo( 'product', $data );

		$this->assertSame( 'Widget', $mapped['name'] );
		$this->assertSame( 29.99, $mapped['list_price'] );
		$this->assertSame( 'WDG-001', $mapped['default_code'] );
	}

	public function test_map_to_odoo_order(): void {
		$data = [
			'partner_id'       => 42,
			'date_order'       => '2025-06-15',
			'client_order_ref' => '10001',
			'order_line'       => [ [ 0, 0, [ 'name' => 'Item', 'product_uom_qty' => 1.0, 'price_unit' => 10.0 ] ] ],
		];

		$mapped = $this->module->map_to_odoo( 'order', $data );

		$this->assertSame( 42, $mapped['partner_id'] );
		$this->assertSame( '10001', $mapped['client_order_ref'] );
	}

	// ─── Handler: load_product ──────────────────────────────

	public function test_handler_load_product_returns_data(): void {
		$api_data = [
			'id'          => 123,
			'name'        => 'Widget',
			'price'       => 29.99,
			'sku'         => 'WDG-001',
			'description' => '<p>A great widget</p>',
		];

		$data = $this->handler->load_product( $api_data );

		$this->assertSame( 'Widget', $data['product_name'] );
		$this->assertSame( 29.99, $data['list_price'] );
		$this->assertSame( 'WDG-001', $data['default_code'] );
		$this->assertSame( 'consu', $data['type'] );
	}

	public function test_handler_load_product_strips_html_description(): void {
		$api_data = [
			'name'        => 'Widget',
			'price'       => 10.0,
			'description' => '<p>Bold <strong>text</strong></p>',
		];

		$data = $this->handler->load_product( $api_data );

		$this->assertStringNotContainsString( '<', $data['description'] );
	}

	public function test_handler_load_product_returns_empty_when_no_name(): void {
		$data = $this->handler->load_product( [ 'price' => 10.0 ] );

		$this->assertEmpty( $data );
	}

	// ─── Handler: load_order ────────────────────────────────

	public function test_handler_load_order_returns_data(): void {
		$api_data = [
			'orderNumber' => 10001,
			'total'       => 59.98,
			'createDate'  => '2025-06-15T10:30:00+0000',
			'items'       => [
				[ 'name' => 'Widget', 'quantity' => 2, 'price' => 29.99 ],
			],
		];

		$data = $this->handler->load_order( $api_data, 42 );

		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2025-06-15', $data['date_order'] );
		$this->assertSame( '10001', $data['client_order_ref'] );
		$this->assertCount( 1, $data['order_line'] );
	}

	public function test_handler_load_order_line_format(): void {
		$api_data = [
			'orderNumber' => 10001,
			'total'       => 29.99,
			'createDate'  => '2025-06-15T10:30:00+0000',
			'items'       => [
				[ 'name' => 'Widget', 'quantity' => 3, 'price' => 9.99 ],
			],
		];

		$data  = $this->handler->load_order( $api_data, 42 );
		$line  = $data['order_line'][0];

		$this->assertSame( 0, $line[0] );
		$this->assertSame( 0, $line[1] );
		$this->assertSame( 'Widget', $line[2]['name'] );
		$this->assertSame( 3.0, $line[2]['product_uom_qty'] );
		$this->assertSame( 9.99, $line[2]['price_unit'] );
	}

	public function test_handler_load_order_fallback_single_line(): void {
		$api_data = [
			'orderNumber' => 10001,
			'total'       => 50.0,
			'createDate'  => '2025-06-15T10:30:00+0000',
			'items'       => [],
		];

		$data = $this->handler->load_order( $api_data, 42 );

		$this->assertCount( 1, $data['order_line'] );
		$this->assertSame( 50.0, $data['order_line'][0][2]['price_unit'] );
	}

	public function test_handler_load_order_skips_empty_item_names(): void {
		$api_data = [
			'orderNumber' => 10001,
			'total'       => 10.0,
			'createDate'  => '2025-06-15T10:30:00+0000',
			'items'       => [
				[ 'name' => '', 'quantity' => 1, 'price' => 5.0 ],
				[ 'name' => 'Valid', 'quantity' => 1, 'price' => 5.0 ],
			],
		];

		$data = $this->handler->load_order( $api_data, 42 );

		$this->assertCount( 1, $data['order_line'] );
		$this->assertSame( 'Valid', $data['order_line'][0][2]['name'] );
	}

	public function test_handler_load_order_uses_today_when_no_date(): void {
		$api_data = [
			'orderNumber' => 10001,
			'total'       => 10.0,
			'items'       => [ [ 'name' => 'Item', 'quantity' => 1, 'price' => 10.0 ] ],
		];

		$data = $this->handler->load_order( $api_data, 42 );

		$this->assertSame( gmdate( 'Y-m-d' ), $data['date_order'] );
	}

	// ─── Handler: fetch_products (API) ──────────────────────

	public function test_handler_fetch_products_returns_items(): void {
		$GLOBALS['_wp_remote_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ 'items' => [ [ 'id' => 1, 'name' => 'Widget' ] ] ] ),
		];

		$products = $this->handler->fetch_products( '12345', 'secret_token' );

		$this->assertCount( 1, $products );
		$this->assertSame( 'Widget', $products[0]['name'] );
	}

	public function test_handler_fetch_products_returns_empty_on_error(): void {
		$GLOBALS['_wp_remote_response'] = new \WP_Error( 'http_request_failed', 'Connection timed out' );

		$products = $this->handler->fetch_products( '12345', 'secret_token' );

		$this->assertEmpty( $products );
	}

	public function test_handler_fetch_products_returns_empty_when_no_credentials(): void {
		$products = $this->handler->fetch_products( '', '' );

		$this->assertEmpty( $products );
	}

	public function test_handler_fetch_products_returns_empty_on_non_200(): void {
		$GLOBALS['_wp_remote_response'] = [
			'response' => [ 'code' => 403 ],
			'body'     => '',
		];

		$products = $this->handler->fetch_products( '12345', 'secret_token' );

		$this->assertEmpty( $products );
	}

	// ─── Handler: fetch_orders (API) ────────────────────────

	public function test_handler_fetch_orders_returns_items(): void {
		$GLOBALS['_wp_remote_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [ 'items' => [ [ 'orderNumber' => 1001, 'total' => 50.0 ] ] ] ),
		];

		$orders = $this->handler->fetch_orders( '12345', 'secret_token' );

		$this->assertCount( 1, $orders );
		$this->assertSame( 1001, $orders[0]['orderNumber'] );
	}

	public function test_handler_fetch_orders_returns_empty_on_error(): void {
		$GLOBALS['_wp_remote_response'] = new \WP_Error( 'http_request_failed', 'Connection timed out' );

		$orders = $this->handler->fetch_orders( '12345', 'secret_token' );

		$this->assertEmpty( $orders );
	}
}
