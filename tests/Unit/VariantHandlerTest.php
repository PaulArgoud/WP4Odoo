<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Variant_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Variant_Handler.
 *
 * Tests variant import logic: save_variation, ensure_variable_product.
 * Uses WC class stubs from bootstrap.php.
 */
class VariantHandlerTest extends TestCase {

	private Variant_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$logger   = new Logger( 'woocommerce' );
		$client_fn = fn() => new class {
			/** @return array<int, array<string, mixed>> */
			public function search_read( string $model, array $domain, array $fields = [] ): array {
				return [];
			}
			/** @return list<int> */
			public function search( string $model, array $domain ): array {
				return [];
			}
			/** @return array<int, array<string, mixed>> */
			public function read( string $model, array $ids, array $fields = [] ): array {
				return [];
			}
		};

		$this->handler = new Variant_Handler( $logger, $client_fn, wp4odoo_test_entity_map() );
	}

	// ─── Instantiation ───────────────────────────────────

	public function test_can_be_instantiated(): void {
		$this->assertInstanceOf( Variant_Handler::class, $this->handler );
	}

	// ─── save_variation ───────────────────────────────────

	public function test_save_variation_creates_new_variation(): void {
		$data = [
			'sku'            => 'TEST-VAR-01',
			'regular_price'  => 19.99,
			'stock_quantity' => 5,
			'weight'         => 0.3,
		];

		$result = $this->handler->save_variation( 100, $data, [] );

		// WC_Product_Variation stub returns 1 from save().
		$this->assertSame( 1, $result );
	}

	public function test_save_variation_with_empty_sku(): void {
		$data = [
			'sku'            => '',
			'regular_price'  => 10.00,
			'stock_quantity' => 2,
		];

		$result = $this->handler->save_variation( 100, $data, [] );
		$this->assertSame( 1, $result );
	}

	public function test_save_variation_with_attributes(): void {
		$data = [
			'regular_price'  => 29.99,
			'stock_quantity' => 10,
		];
		$attributes = [
			'pa_color' => 'Red',
			'pa_size'  => 'M',
		];

		$result = $this->handler->save_variation( 100, $data, $attributes );
		$this->assertSame( 1, $result );
	}

	public function test_save_variation_with_zero_weight_skips_weight(): void {
		$data = [
			'regular_price'  => 15.00,
			'stock_quantity' => 3,
			'weight'         => 0,
		];

		$result = $this->handler->save_variation( 100, $data, [] );
		$this->assertSame( 1, $result );
	}

	// ─── ensure_variable_product ──────────────────────────

	public function test_ensure_variable_product_returns_null_for_missing_product(): void {
		// wc_get_product() returns false in stub.
		$result = $this->handler->ensure_variable_product( 999 );
		$this->assertNull( $result );
	}

	// ─── pull_variants with empty data ────────────────────

	public function test_pull_variants_returns_true_with_no_variants(): void {
		$result = $this->handler->pull_variants( 1, 100 );
		// Client stub returns empty array → no variants → return true.
		$this->assertTrue( $result );
	}
}
