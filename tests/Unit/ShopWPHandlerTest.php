<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Modules\ShopWP_Handler;
use WP4Odoo\Logger;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for ShopWP_Handler.
 *
 * Tests product loading from the wps_products CPT and variant lookup
 * from the custom shopwp_variants table.
 *
 */
#[CoversClass(\WP4Odoo\Modules\ShopWP_Handler::class)]
class ShopWPHandlerTest extends Module_Test_Case {

	private ShopWP_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new ShopWP_Handler( new Logger( 'test' ) );
	}

	// ─── Helpers ────────────────────────────────────────────

	private function create_product_post( int $id, string $title = 'Shopify Tee', string $content = '' ): void {
		$post               = new \stdClass();
		$post->ID           = $id;
		$post->post_type    = 'wps_products';
		$post->post_title   = $title;
		$post->post_content = $content;
		$post->post_status  = 'publish';

		$GLOBALS['_wp_posts'][ $id ] = $post;
	}

	/**
	 * Queue a variant row for the $wpdb->get_row stub.
	 *
	 * @param float  $price Variant price.
	 * @param string $sku   Variant SKU.
	 */
	private function queue_variant( float $price, string $sku ): void {
		$this->wpdb->get_row_return = [
			'price' => (string) $price,
			'sku'   => $sku,
		];
	}

	// ─── load_product ───────────────────────────────────────

	public function test_load_product_returns_data(): void {
		$this->create_product_post( 10, 'Shopify Tee', 'A cool shirt' );
		$this->queue_variant( 29.99, 'SHP-TEE-001' );

		$data = $this->handler->load_product( 10 );

		$this->assertSame( 'Shopify Tee', $data['product_name'] );
		$this->assertSame( 29.99, $data['list_price'] );
		$this->assertSame( 'SHP-TEE-001', $data['default_code'] );
		$this->assertSame( 'A cool shirt', $data['description'] );
		$this->assertSame( 'consu', $data['type'] );
	}

	public function test_load_product_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_product( 999 ) );
	}

	public function test_load_product_empty_for_wrong_post_type(): void {
		$post             = new \stdClass();
		$post->ID         = 10;
		$post->post_type  = 'post';
		$post->post_title = 'Not a product';
		$post->post_content = '';

		$GLOBALS['_wp_posts'][10] = $post;

		$this->assertSame( [], $this->handler->load_product( 10 ) );
	}

	public function test_load_product_empty_when_title_empty(): void {
		$this->create_product_post( 10, '' );

		$this->assertSame( [], $this->handler->load_product( 10 ) );
	}

	public function test_load_product_handles_no_variant(): void {
		$this->create_product_post( 10, 'No Variant Product' );
		// $wpdb->get_row returns null by default — no variant.

		$data = $this->handler->load_product( 10 );

		$this->assertSame( 'No Variant Product', $data['product_name'] );
		$this->assertSame( 0.0, $data['list_price'] );
		$this->assertSame( '', $data['default_code'] );
	}

	public function test_load_product_strips_html_from_content(): void {
		$this->create_product_post( 10, 'Product', '<p>Bold <strong>text</strong></p>' );

		$data = $this->handler->load_product( 10 );

		$this->assertStringNotContainsString( '<p>', $data['description'] );
		$this->assertStringNotContainsString( '<strong>', $data['description'] );
		$this->assertStringContainsString( 'Bold', $data['description'] );
	}

	public function test_load_product_variant_with_zero_price(): void {
		$this->create_product_post( 10, 'Free Product' );
		$this->queue_variant( 0.0, 'FREE-001' );

		$data = $this->handler->load_product( 10 );

		$this->assertSame( 0.0, $data['list_price'] );
		$this->assertSame( 'FREE-001', $data['default_code'] );
	}

	public function test_load_product_variant_with_empty_sku(): void {
		$this->create_product_post( 10, 'No SKU Product' );
		$this->queue_variant( 15.0, '' );

		$data = $this->handler->load_product( 10 );

		$this->assertSame( 15.0, $data['list_price'] );
		$this->assertSame( '', $data['default_code'] );
	}
}
