<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\Food_Ordering_Handler;
use WP4Odoo\Modules\Food_Order_Extractor;
use WP4Odoo\Logger;

/**
 * Unit tests for Food_Ordering_Handler and Food_Order_Extractor.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Food_Ordering_Handler::class)]
#[CoversClass(\WP4Odoo\Modules\Food_Order_Extractor::class)]
class FoodOrderingHandlerTest extends Module_Test_Case {

	private Food_Ordering_Handler $handler;
	private Food_Order_Extractor $extractor;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->handler   = new Food_Ordering_Handler( new Logger( 'test' ) );
		$this->extractor = new Food_Order_Extractor( new Logger( 'test' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
	}

	// ─── Handler: format_pos_order ──────────────────────────

	public function test_format_pos_order_builds_order_with_lines(): void {
		$data = [
			'lines' => [
				[ 'name' => 'Pizza Margherita', 'qty' => 2, 'price_unit' => 12.50 ],
				[ 'name' => 'Tiramisu', 'qty' => 1, 'price_unit' => 8.00 ],
			],
			'amount_total' => 33.00,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
			'note'         => 'Extra cheese please',
		];

		$result = $this->handler->format_pos_order( $data, 42 );

		$this->assertNotEmpty( $result );
		$this->assertArrayHasKey( 'lines', $result );
		$this->assertCount( 2, $result['lines'] );
	}

	public function test_format_pos_order_lines_are_one2many_tuples(): void {
		$data = [
			'lines' => [
				[ 'name' => 'Pizza', 'qty' => 1, 'price_unit' => 10.00 ],
			],
			'amount_total' => 10.00,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
		];

		$result = $this->handler->format_pos_order( $data, 0 );
		$line   = $result['lines'][0];

		$this->assertSame( 0, $line[0] );
		$this->assertSame( 0, $line[1] );
		$this->assertIsArray( $line[2] );
	}

	public function test_format_pos_order_line_contains_product_data(): void {
		$data = [
			'lines' => [
				[ 'name' => 'Pizza Margherita', 'qty' => 2, 'price_unit' => 12.50 ],
			],
			'amount_total' => 25.00,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
		];

		$result    = $this->handler->format_pos_order( $data, 0 );
		$line_data = $result['lines'][0][2];

		$this->assertSame( 'Pizza Margherita', $line_data['full_product_name'] );
		$this->assertSame( 2.0, $line_data['qty'] );
		$this->assertSame( 12.50, $line_data['price_unit'] );
		$this->assertSame( 25.0, $line_data['price_subtotal'] );
		$this->assertSame( 25.0, $line_data['price_subtotal_incl'] );
	}

	public function test_format_pos_order_includes_partner_id_when_positive(): void {
		$data = [
			'lines' => [
				[ 'name' => 'Pizza', 'qty' => 1, 'price_unit' => 10.00 ],
			],
			'amount_total' => 10.00,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
		];

		$result = $this->handler->format_pos_order( $data, 42 );

		$this->assertSame( 42, $result['partner_id'] );
	}

	public function test_format_pos_order_omits_partner_id_when_zero(): void {
		$data = [
			'lines' => [
				[ 'name' => 'Pizza', 'qty' => 1, 'price_unit' => 10.00 ],
			],
			'amount_total' => 10.00,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
		];

		$result = $this->handler->format_pos_order( $data, 0 );

		$this->assertArrayNotHasKey( 'partner_id', $result );
	}

	public function test_format_pos_order_returns_empty_when_no_lines(): void {
		$data = [
			'lines'        => [],
			'amount_total' => 0.0,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
		];

		$result = $this->handler->format_pos_order( $data, 0 );

		$this->assertEmpty( $result );
	}

	public function test_format_pos_order_returns_empty_when_lines_key_missing(): void {
		$data = [
			'amount_total' => 10.00,
			'source'       => 'gloriafoood',
		];

		$result = $this->handler->format_pos_order( $data, 0 );

		$this->assertEmpty( $result );
	}

	public function test_format_pos_order_includes_note_when_non_empty(): void {
		$data = [
			'lines' => [
				[ 'name' => 'Pizza', 'qty' => 1, 'price_unit' => 10.00 ],
			],
			'amount_total' => 10.00,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
			'note'         => 'Extra cheese please',
		];

		$result = $this->handler->format_pos_order( $data, 0 );

		$this->assertSame( 'Extra cheese please', $result['note'] );
	}

	public function test_format_pos_order_omits_note_when_empty(): void {
		$data = [
			'lines' => [
				[ 'name' => 'Pizza', 'qty' => 1, 'price_unit' => 10.00 ],
			],
			'amount_total' => 10.00,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
			'note'         => '',
		];

		$result = $this->handler->format_pos_order( $data, 0 );

		$this->assertArrayNotHasKey( 'note', $result );
	}

	public function test_format_pos_order_generates_pos_reference_with_source_prefix(): void {
		$data = [
			'lines' => [
				[ 'name' => 'Pizza', 'qty' => 1, 'price_unit' => 10.00 ],
			],
			'amount_total' => 10.00,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
		];

		$result = $this->handler->format_pos_order( $data, 0 );

		$this->assertStringStartsWith( 'WP-GLORIAFOOOD-', $result['pos_reference'] );
	}

	public function test_format_pos_order_pos_reference_for_wppizza(): void {
		$data = [
			'lines' => [
				[ 'name' => 'Pepperoni', 'qty' => 1, 'price_unit' => 15.00 ],
			],
			'amount_total' => 15.00,
			'date_order'   => '2024-01-15 14:30:00',
			'source'       => 'wppizza',
		];

		$result = $this->handler->format_pos_order( $data, 0 );

		$this->assertStringStartsWith( 'WP-WPPIZZA-', $result['pos_reference'] );
	}

	public function test_format_pos_order_preserves_amount_total(): void {
		$data = [
			'lines' => [
				[ 'name' => 'Pizza', 'qty' => 2, 'price_unit' => 12.50 ],
			],
			'amount_total' => 33.00,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
		];

		$result = $this->handler->format_pos_order( $data, 0 );

		$this->assertSame( 33.0, $result['amount_total'] );
	}

	public function test_format_pos_order_preserves_date_order(): void {
		$data = [
			'lines' => [
				[ 'name' => 'Pizza', 'qty' => 1, 'price_unit' => 10.00 ],
			],
			'amount_total' => 10.00,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
		];

		$result = $this->handler->format_pos_order( $data, 0 );

		$this->assertSame( '2024-01-15 12:00:00', $result['date_order'] );
	}

	public function test_format_pos_order_line_defaults_name(): void {
		$data = [
			'lines' => [
				[ 'qty' => 1, 'price_unit' => 5.00 ],
			],
			'amount_total' => 5.00,
			'date_order'   => '2024-01-15 12:00:00',
			'source'       => 'gloriafoood',
		];

		$result    = $this->handler->format_pos_order( $data, 0 );
		$line_data = $result['lines'][0][2];

		$this->assertSame( 'Food item', $line_data['full_product_name'] );
	}

	// ─── Extractor: extract_from_gloriafoood ────────────────

	public function test_extractor_gloriafoood_extracts_order_data(): void {
		$this->setup_gloriafoood_order();

		$data = $this->extractor->extract_from_gloriafoood( 10 );

		$this->assertNotEmpty( $data );
		$this->assertSame( 'gloriafoood', $data['source'] );
	}

	public function test_extractor_gloriafoood_parses_client_name(): void {
		$this->setup_gloriafoood_order();

		$data = $this->extractor->extract_from_gloriafoood( 10 );

		$this->assertSame( 'John Doe', $data['partner_name'] );
	}

	public function test_extractor_gloriafoood_parses_client_email(): void {
		$this->setup_gloriafoood_order();

		$data = $this->extractor->extract_from_gloriafoood( 10 );

		$this->assertSame( 'john@example.com', $data['partner_email'] );
	}

	public function test_extractor_gloriafoood_parses_items(): void {
		$this->setup_gloriafoood_order();

		$data = $this->extractor->extract_from_gloriafoood( 10 );

		$this->assertCount( 2, $data['lines'] );
		$this->assertSame( 'Pizza Margherita', $data['lines'][0]['name'] );
		$this->assertSame( 2, $data['lines'][0]['qty'] );
		$this->assertSame( 12.50, $data['lines'][0]['price_unit'] );
		$this->assertSame( 'Tiramisu', $data['lines'][1]['name'] );
		$this->assertSame( 1, $data['lines'][1]['qty'] );
		$this->assertSame( 8.00, $data['lines'][1]['price_unit'] );
	}

	public function test_extractor_gloriafoood_parses_total(): void {
		$this->setup_gloriafoood_order();

		$data = $this->extractor->extract_from_gloriafoood( 10 );

		$this->assertSame( 33.0, $data['amount_total'] );
	}

	public function test_extractor_gloriafoood_parses_instructions(): void {
		$this->setup_gloriafoood_order();

		$data = $this->extractor->extract_from_gloriafoood( 10 );

		$this->assertSame( 'Extra cheese please', $data['note'] );
	}

	public function test_extractor_gloriafoood_parses_date(): void {
		$this->setup_gloriafoood_order();

		$data = $this->extractor->extract_from_gloriafoood( 10 );

		$this->assertSame( '2024-01-15 12:00:00', $data['date_order'] );
	}

	public function test_extractor_gloriafoood_returns_empty_for_nonexistent_post(): void {
		$data = $this->extractor->extract_from_gloriafoood( 999 );

		$this->assertEmpty( $data );
	}

	public function test_extractor_gloriafoood_returns_empty_for_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][20] = (object) [
			'ID'            => 20,
			'post_type'     => 'post',
			'post_title'    => 'Not an order',
			'post_content'  => '',
			'post_date_gmt' => '2024-01-15 12:00:00',
			'post_author'   => 1,
			'post_status'   => 'publish',
		];

		$data = $this->extractor->extract_from_gloriafoood( 20 );

		$this->assertEmpty( $data );
	}

	public function test_extractor_gloriafoood_handles_missing_meta(): void {
		$GLOBALS['_wp_posts'][30] = (object) [
			'ID'            => 30,
			'post_type'     => 'flavor_order',
			'post_title'    => 'Order',
			'post_content'  => '',
			'post_date_gmt' => '2024-01-15 12:00:00',
			'post_author'   => 1,
			'post_status'   => 'publish',
		];
		$GLOBALS['_wp_post_meta'][30] = [];

		$data = $this->extractor->extract_from_gloriafoood( 30 );

		$this->assertSame( 'gloriafoood', $data['source'] );
		$this->assertEmpty( $data['lines'] );
		$this->assertSame( '', $data['partner_name'] );
		$this->assertSame( '', $data['partner_email'] );
	}

	// ─── Extractor: extract_from_wppizza ────────────────────

	public function test_extractor_wppizza_extracts_order_data(): void {
		$this->setup_wppizza_order();

		$data = $this->extractor->extract_from_wppizza( 5 );

		$this->assertNotEmpty( $data );
		$this->assertSame( 'wppizza', $data['source'] );
	}

	public function test_extractor_wppizza_parses_customer_name(): void {
		$this->setup_wppizza_order();

		$data = $this->extractor->extract_from_wppizza( 5 );

		$this->assertSame( 'Jane Smith', $data['partner_name'] );
	}

	public function test_extractor_wppizza_parses_customer_email(): void {
		$this->setup_wppizza_order();

		$data = $this->extractor->extract_from_wppizza( 5 );

		$this->assertSame( 'jane@example.com', $data['partner_email'] );
	}

	public function test_extractor_wppizza_parses_items(): void {
		$this->setup_wppizza_order();

		$data = $this->extractor->extract_from_wppizza( 5 );

		$this->assertCount( 1, $data['lines'] );
		$this->assertSame( 'Pepperoni Pizza', $data['lines'][0]['name'] );
		$this->assertSame( 1, $data['lines'][0]['qty'] );
		$this->assertSame( 15.0, $data['lines'][0]['price_unit'] );
	}

	public function test_extractor_wppizza_parses_total(): void {
		$this->setup_wppizza_order();

		$data = $this->extractor->extract_from_wppizza( 5 );

		$this->assertSame( 15.0, $data['amount_total'] );
	}

	public function test_extractor_wppizza_parses_date(): void {
		$this->setup_wppizza_order();

		$data = $this->extractor->extract_from_wppizza( 5 );

		$this->assertSame( '2024-01-15 14:30:00', $data['date_order'] );
	}

	public function test_extractor_wppizza_parses_notes(): void {
		$this->setup_wppizza_order();

		$data = $this->extractor->extract_from_wppizza( 5 );

		$this->assertSame( 'Ring doorbell', $data['note'] );
	}

	public function test_extractor_wppizza_returns_empty_for_missing_order(): void {
		$data = $this->extractor->extract_from_wppizza( 999 );

		$this->assertEmpty( $data );
	}

	public function test_extractor_wppizza_handles_empty_customer(): void {
		$GLOBALS['_wp_options']['wppizza_order_7'] = [
			'items' => [
				[ 'name' => 'Garlic Bread', 'quantity' => 1, 'price' => 5.00 ],
			],
			'total' => 5.00,
			'date'  => '2024-01-15 16:00:00',
		];

		$data = $this->extractor->extract_from_wppizza( 7 );

		$this->assertSame( '', $data['partner_name'] );
		$this->assertSame( '', $data['partner_email'] );
		$this->assertCount( 1, $data['lines'] );
	}

	public function test_extractor_wppizza_skips_items_with_empty_name(): void {
		$GLOBALS['_wp_options']['wppizza_order_8'] = [
			'customer' => [ 'name' => 'Test', 'email' => 'test@example.com' ],
			'items'    => [
				[ 'name' => '', 'quantity' => 1, 'price' => 5.00 ],
				[ 'name' => 'Valid Item', 'quantity' => 1, 'price' => 10.00 ],
			],
			'total' => 15.00,
			'date'  => '2024-01-15 16:00:00',
		];

		$data = $this->extractor->extract_from_wppizza( 8 );

		$this->assertCount( 1, $data['lines'] );
		$this->assertSame( 'Valid Item', $data['lines'][0]['name'] );
	}

	public function test_extractor_wppizza_defaults_missing_notes(): void {
		$GLOBALS['_wp_options']['wppizza_order_9'] = [
			'customer' => [ 'name' => 'Test', 'email' => 'test@example.com' ],
			'items'    => [
				[ 'name' => 'Pizza', 'quantity' => 1, 'price' => 10.00 ],
			],
			'total' => 10.00,
			'date'  => '2024-01-15 16:00:00',
		];

		$data = $this->extractor->extract_from_wppizza( 9 );

		$this->assertSame( '', $data['note'] );
	}

	// ─── Helpers ────────────────────────────────────────────

	private function setup_gloriafoood_order(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'            => 10,
			'post_type'     => 'flavor_order',
			'post_title'    => 'Order',
			'post_content'  => '',
			'post_date_gmt' => '2024-01-15 12:00:00',
			'post_author'   => 1,
			'post_status'   => 'publish',
		];
		$GLOBALS['_wp_post_meta'][10] = [
			'_flavor_order_data' => json_encode( [
				'client' => [
					'name'  => 'John Doe',
					'email' => 'john@example.com',
				],
				'items' => [
					[ 'name' => 'Pizza Margherita', 'quantity' => 2, 'price' => 12.50 ],
					[ 'name' => 'Tiramisu', 'quantity' => 1, 'price' => 8.00 ],
				],
				'total_price'  => 33.00,
				'instructions' => 'Extra cheese please',
			] ),
		];
	}

	private function setup_wppizza_order(): void {
		$GLOBALS['_wp_options']['wppizza_order_5'] = [
			'customer' => [
				'name'  => 'Jane Smith',
				'email' => 'jane@example.com',
			],
			'items' => [
				[ 'name' => 'Pepperoni Pizza', 'quantity' => 1, 'price' => 15.00 ],
			],
			'total' => 15.00,
			'date'  => '2024-01-15 14:30:00',
			'notes' => 'Ring doorbell',
		];
	}
}
