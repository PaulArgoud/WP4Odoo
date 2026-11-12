<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Ecwid_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Ecwid_Handler.
 *
 * Tests product and order data transformation from Ecwid REST API format
 * to Odoo-compatible format.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Ecwid_Handler::class)]
class EcwidHandlerTest extends TestCase {

	private Ecwid_Handler $handler;

	protected function setUp(): void {
		$this->handler = new Ecwid_Handler( new Logger( 'test' ) );
	}

	// ─── load_product ───────────────────────────────────────

	public function test_load_product_returns_data(): void {
		$api_data = [
			'name'        => 'Widget Pro',
			'price'       => 29.99,
			'sku'         => 'WGT-001',
			'description' => 'A great widget',
		];

		$data = $this->handler->load_product( $api_data );

		$this->assertSame( 'Widget Pro', $data['product_name'] );
		$this->assertSame( 29.99, $data['list_price'] );
		$this->assertSame( 'WGT-001', $data['default_code'] );
		$this->assertSame( 'A great widget', $data['description'] );
		$this->assertSame( 'consu', $data['type'] );
	}

	public function test_load_product_returns_empty_when_no_name(): void {
		$api_data = [
			'price' => 10.0,
			'sku'   => 'SKU-001',
		];

		$this->assertSame( [], $this->handler->load_product( $api_data ) );
	}

	public function test_load_product_returns_empty_when_name_empty_string(): void {
		$api_data = [
			'name'  => '',
			'price' => 10.0,
		];

		$this->assertSame( [], $this->handler->load_product( $api_data ) );
	}

	public function test_load_product_handles_missing_optional_fields(): void {
		$api_data = [ 'name' => 'Basic Widget' ];

		$data = $this->handler->load_product( $api_data );

		$this->assertSame( 'Basic Widget', $data['product_name'] );
		$this->assertSame( 0.0, $data['list_price'] );
		$this->assertSame( '', $data['default_code'] );
		$this->assertSame( '', $data['description'] );
	}

	public function test_load_product_strips_html_from_description(): void {
		$api_data = [
			'name'        => 'Product',
			'description' => '<p>Bold <b>text</b></p>',
		];

		$data = $this->handler->load_product( $api_data );

		$this->assertStringNotContainsString( '<p>', $data['description'] );
		$this->assertStringNotContainsString( '<b>', $data['description'] );
		$this->assertStringContainsString( 'Bold', $data['description'] );
	}

	// ─── load_order ─────────────────────────────────────────

	public function test_load_order_returns_data(): void {
		$api_data = [
			'orderNumber' => 'EC-12345',
			'total'       => 59.98,
			'createDate'  => '2026-02-10T14:30:00Z',
			'items'       => [
				[
					'name'     => 'Widget A',
					'quantity' => 2,
					'price'    => 29.99,
				],
			],
		];

		$data = $this->handler->load_order( $api_data, 42 );

		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2026-02-10', $data['date_order'] );
		$this->assertSame( 'EC-12345', $data['client_order_ref'] );
	}

	public function test_load_order_builds_order_lines_from_items(): void {
		$api_data = [
			'orderNumber' => 'EC-100',
			'total'       => 50.0,
			'createDate'  => '2026-03-01T00:00:00Z',
			'items'       => [
				[
					'name'     => 'Item A',
					'quantity' => 3,
					'price'    => 10.0,
				],
				[
					'name'     => 'Item B',
					'quantity' => 1,
					'price'    => 20.0,
				],
			],
		];

		$data  = $this->handler->load_order( $api_data, 42 );
		$lines = $data['order_line'];

		$this->assertCount( 2, $lines );

		// First line.
		$this->assertSame( 0, $lines[0][0] );
		$this->assertSame( 0, $lines[0][1] );
		$this->assertSame( 'Item A', $lines[0][2]['name'] );
		$this->assertSame( 3.0, $lines[0][2]['product_uom_qty'] );
		$this->assertSame( 10.0, $lines[0][2]['price_unit'] );

		// Second line.
		$this->assertSame( 'Item B', $lines[1][2]['name'] );
		$this->assertSame( 1.0, $lines[1][2]['product_uom_qty'] );
		$this->assertSame( 20.0, $lines[1][2]['price_unit'] );
	}

	public function test_load_order_skips_items_with_no_name(): void {
		$api_data = [
			'orderNumber' => 'EC-101',
			'total'       => 30.0,
			'createDate'  => '2026-03-01T00:00:00Z',
			'items'       => [
				[
					'name'     => '',
					'quantity' => 1,
					'price'    => 10.0,
				],
				[
					'name'     => 'Valid Item',
					'quantity' => 1,
					'price'    => 20.0,
				],
			],
		];

		$data  = $this->handler->load_order( $api_data, 42 );
		$lines = $data['order_line'];

		$this->assertCount( 1, $lines );
		$this->assertSame( 'Valid Item', $lines[0][2]['name'] );
	}

	public function test_load_order_creates_fallback_line_when_no_valid_items(): void {
		$api_data = [
			'orderNumber' => 'EC-102',
			'total'       => 99.0,
			'createDate'  => '2026-03-01T00:00:00Z',
			'items'       => [
				[ 'name' => '', 'quantity' => 1, 'price' => 99.0 ],
			],
		];

		$data  = $this->handler->load_order( $api_data, 42 );
		$lines = $data['order_line'];

		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'EC-102', $lines[0][2]['name'] );
		$this->assertSame( 99.0, $lines[0][2]['price_unit'] );
	}

	public function test_load_order_no_fallback_line_when_total_zero(): void {
		$api_data = [
			'orderNumber' => 'EC-103',
			'total'       => 0,
			'createDate'  => '2026-03-01T00:00:00Z',
			'items'       => [],
		];

		$data  = $this->handler->load_order( $api_data, 42 );
		$lines = $data['order_line'];

		$this->assertEmpty( $lines );
	}

	public function test_load_order_uses_today_when_no_date(): void {
		$api_data = [
			'orderNumber' => 'EC-104',
			'total'       => 10.0,
			'items'       => [
				[ 'name' => 'Widget', 'quantity' => 1, 'price' => 10.0 ],
			],
		];

		$data = $this->handler->load_order( $api_data, 42 );

		$this->assertSame( gmdate( 'Y-m-d' ), $data['date_order'] );
	}

	public function test_load_order_handles_empty_items_list(): void {
		$api_data = [
			'orderNumber' => 'EC-105',
			'total'       => 50.0,
			'createDate'  => '2026-04-01T00:00:00Z',
			'items'       => [],
		];

		$data  = $this->handler->load_order( $api_data, 42 );
		$lines = $data['order_line'];

		// Fallback line because total > 0.
		$this->assertCount( 1, $lines );
		$this->assertSame( 50.0, $lines[0][2]['price_unit'] );
	}

	public function test_load_order_one2many_tuple_format(): void {
		$api_data = [
			'orderNumber' => 'EC-106',
			'total'       => 10.0,
			'createDate'  => '2026-01-01T00:00:00Z',
			'items'       => [
				[ 'name' => 'Widget', 'quantity' => 1, 'price' => 10.0 ],
			],
		];

		$data  = $this->handler->load_order( $api_data, 42 );
		$tuple = $data['order_line'][0];

		$this->assertSame( 0, $tuple[0] );
		$this->assertSame( 0, $tuple[1] );
		$this->assertIsArray( $tuple[2] );
	}

	public function test_load_order_item_defaults_quantity_to_one(): void {
		$api_data = [
			'orderNumber' => 'EC-107',
			'total'       => 10.0,
			'createDate'  => '2026-01-01T00:00:00Z',
			'items'       => [
				[ 'name' => 'Widget' ],
			],
		];

		$data = $this->handler->load_order( $api_data, 42 );
		$line = $data['order_line'][0][2];

		$this->assertSame( 1.0, $line['product_uom_qty'] );
		$this->assertSame( 0.0, $line['price_unit'] );
	}
}
