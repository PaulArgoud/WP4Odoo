<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WC_Addons_Handler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Addons_Handler.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WC_Addons_Handler::class)]
class WCAddonsHandlerTest extends TestCase {

	private WC_Addons_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->handler = new WC_Addons_Handler();
	}

	// ─── get_addon_source ─────────────────────────────────

	public function test_detect_official_source(): void {
		// WC_Product_Addons class exists (loaded from stub).
		$this->assertSame( 'official', $this->handler->get_addon_source() );
	}

	// ─── load_addons (official) ───────────────────────────

	public function test_load_official_addons(): void {
		$GLOBALS['_wp_post_meta'][100] = [
			'_product_addons' => [
				[
					'name'    => 'Engraving',
					'type'    => 'custom_text',
					'options' => [
						[ 'label' => 'Yes', 'price' => 10.0 ],
						[ 'label' => 'No', 'price' => 0.0 ],
					],
				],
			],
		];

		$addons = $this->handler->load_addons( 100 );

		$this->assertCount( 1, $addons );
		$this->assertSame( 'Engraving', $addons[0]['name'] );
		$this->assertSame( 'custom_text', $addons[0]['type'] );
		$this->assertCount( 2, $addons[0]['options'] );
		$this->assertSame( 'Yes', $addons[0]['options'][0]['label'] );
		$this->assertSame( 10.0, $addons[0]['options'][0]['price'] );
	}

	public function test_load_addons_empty_when_no_meta(): void {
		$addons = $this->handler->load_addons( 999 );
		$this->assertEmpty( $addons );
	}

	public function test_load_addons_skips_invalid_groups(): void {
		$GLOBALS['_wp_post_meta'][101] = [
			'_product_addons' => [
				'not_an_array',
				[ 'name' => '' ],
				[
					'name'    => 'Valid',
					'type'    => 'select',
					'options' => [ [ 'label' => 'A', 'price' => 5.0 ] ],
				],
			],
		];

		$addons = $this->handler->load_addons( 101 );

		$this->assertCount( 1, $addons );
		$this->assertSame( 'Valid', $addons[0]['name'] );
	}

	// ─── format_as_attributes ─────────────────────────────

	public function test_format_as_attributes_creates_lines(): void {
		$addons = [
			[
				'name'    => 'Size',
				'type'    => 'select',
				'options' => [
					[ 'label' => 'S', 'price' => 0.0 ],
					[ 'label' => 'M', 'price' => 5.0 ],
					[ 'label' => 'L', 'price' => 10.0 ],
				],
			],
		];

		$data = $this->handler->format_as_attributes( $addons, 42 );

		$this->assertSame( 42, $data['product_tmpl_id'] );
		$this->assertCount( 1, $data['attribute_line_ids'] );

		$line = $data['attribute_line_ids'][0];
		$this->assertSame( 0, $line[0] );
		$this->assertSame( 0, $line[1] );
		$this->assertSame( 'Size', $line[2]['attribute_id']['name'] );
		$this->assertCount( 3, $line[2]['value_ids'] );
	}

	public function test_format_as_attributes_skips_empty_options(): void {
		$addons = [
			[
				'name'    => 'Notes',
				'type'    => 'text',
				'options' => [],
			],
		];

		$data = $this->handler->format_as_attributes( $addons, 42 );
		$this->assertEmpty( $data['attribute_line_ids'] );
	}

	// ─── format_as_bom_lines ──────────────────────────────

	public function test_format_as_bom_lines_creates_structure(): void {
		$addons = [
			[
				'name'    => 'Extra',
				'type'    => 'checkbox',
				'options' => [
					[ 'label' => 'Gift wrap', 'price' => 3.0 ],
					[ 'label' => 'Card', 'price' => 2.0 ],
				],
			],
		];

		$data = $this->handler->format_as_bom_lines( $addons, 42 );

		$this->assertSame( 42, $data['product_tmpl_id'] );
		$this->assertSame( 'phantom', $data['type'] );
		$this->assertSame( 1, $data['product_qty'] );
		$this->assertCount( 2, $data['bom_line_ids'] );
	}

	public function test_format_as_bom_lines_empty_for_no_options(): void {
		$addons = [
			[
				'name'    => 'Notes',
				'type'    => 'text',
				'options' => [],
			],
		];

		$data = $this->handler->format_as_bom_lines( $addons, 42 );
		$this->assertEmpty( $data['bom_line_ids'] );
	}
}
