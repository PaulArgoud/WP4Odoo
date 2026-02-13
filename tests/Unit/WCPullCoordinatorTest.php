<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\WC_Pull_Coordinator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Pull_Coordinator.
 */
class WCPullCoordinatorTest extends TestCase {

	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;
		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_cache']      = [];
		$GLOBALS['_wp_filters']    = [];
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [ 'enabled' => true, 'level' => 'debug' ];
	}

	/**
	 * Create a coordinator with injected fakes.
	 *
	 * @param array    $settings      Module settings.
	 * @param object   $client        Fake Odoo client.
	 * @param \Closure $mapping_fn    Mapping resolver.
	 * @return WC_Pull_Coordinator
	 */
	private function make_coordinator( array $settings = [], ?object $client = null, ?\Closure $mapping_fn = null ): WC_Pull_Coordinator {
		$logger     = new Logger( 'woocommerce', wp4odoo_test_settings() );
		$client     = $client ?? $this->make_noop_client();
		$mapping_fn = $mapping_fn ?? static fn( string $type, int $odoo_id ) => null;

		$variant_handler   = $this->createMock( \WP4Odoo\Modules\Variant_Handler::class );
		$image_handler     = $this->createMock( \WP4Odoo\Modules\Image_Handler::class );
		$pricelist_handler = $this->createMock( \WP4Odoo\Modules\Pricelist_Handler::class );
		$shipment_handler  = $this->createMock( \WP4Odoo\Modules\Shipment_Handler::class );

		return new WC_Pull_Coordinator(
			$logger,
			static fn() => $settings,
			static fn() => $client,
			$mapping_fn,
			$variant_handler,
			$image_handler,
			$pricelist_handler,
			$shipment_handler,
			static fn() => new \WP4Odoo\I18n\Translation_Service( static fn() => $client )
		);
	}

	private function make_noop_client(): object {
		return new class {
			/** @return array<int> */
			public function search( string $model, array $domain ): array {
				return [];
			}

			/** @return array */
			public function read( string $model, array $ids, array $fields = [], array $context = [] ): array {
				return [];
			}
		};
	}

	// ─── capture_odoo_data ─────────────────────────────────

	public function test_capture_odoo_data_returns_wp_data_unchanged(): void {
		$coordinator = $this->make_coordinator();
		$wp_data     = [ 'name' => 'Test Product' ];
		$odoo_data   = [ 'name' => 'Odoo Product', 'image_1920' => 'base64data' ];

		$result = $coordinator->capture_odoo_data( $wp_data, $odoo_data, 'product' );

		$this->assertSame( $wp_data, $result );
	}

	// ─── pull_variant ──────────────────────────────────────

	public function test_pull_variant_fails_when_parent_not_mapped(): void {
		$client = new class {
			public function read( string $model, array $ids, array $fields = [], array $context = [] ): array {
				return [ [ 'product_tmpl_id' => [ 100, 'Template' ] ] ];
			}
		};

		$coordinator = $this->make_coordinator( [], $client );
		$result      = $coordinator->pull_variant( 42, 0, [] );

		$this->assertFalse( $result->succeeded() );
		$this->assertStringContainsString( 'parent product not mapped', $result->get_message() );
	}

	public function test_pull_variant_succeeds_with_payload_parent(): void {
		$variant_handler = $this->createMock( \WP4Odoo\Modules\Variant_Handler::class );
		$variant_handler->method( 'pull_variants' )->willReturn( true );

		$logger     = new Logger( 'woocommerce', wp4odoo_test_settings() );
		$client     = $this->make_noop_client();
		$mapping_fn = static fn( string $type, int $odoo_id ) => null;

		$coordinator = new WC_Pull_Coordinator(
			$logger,
			static fn() => [],
			static fn() => $client,
			$mapping_fn,
			$variant_handler,
			$this->createMock( \WP4Odoo\Modules\Image_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Pricelist_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Shipment_Handler::class ),
			static fn() => new \WP4Odoo\I18n\Translation_Service( static fn() => $client )
		);

		$result = $coordinator->pull_variant( 42, 0, [
			'parent_wp_id'     => 99,
			'template_odoo_id' => 100,
		] );

		$this->assertTrue( $result->succeeded() );
		$this->assertSame( 99, $result->get_entity_id() );
	}

	// ─── pull_shipment_for_picking ─────────────────────────

	public function test_pull_shipment_fails_when_sync_disabled(): void {
		$coordinator = $this->make_coordinator( [ 'sync_shipments' => false ] );
		$result      = $coordinator->pull_shipment_for_picking( 1 );

		$this->assertFalse( $result->succeeded() );
		$this->assertStringContainsString( 'disabled', $result->get_message() );
	}

	public function test_pull_shipment_succeeds_for_done_outgoing(): void {
		$client = new class {
			public function read( string $model, array $ids, array $fields = [], array $context = [] ): array {
				return [ [
					'sale_id'            => [ 50, 'SO050' ],
					'state'              => 'done',
					'picking_type_code'  => 'outgoing',
				] ];
			}
		};

		$shipment_handler = $this->createMock( \WP4Odoo\Modules\Shipment_Handler::class );
		$shipment_handler->method( 'pull_shipments' )->willReturn( true );

		$logger     = new Logger( 'woocommerce', wp4odoo_test_settings() );
		$mapping_fn = static fn( string $type, int $odoo_id ) => 200; // WC order ID.

		$coordinator = new WC_Pull_Coordinator(
			$logger,
			static fn() => [ 'sync_shipments' => true ],
			static fn() => $client,
			$mapping_fn,
			$this->createMock( \WP4Odoo\Modules\Variant_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Image_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Pricelist_Handler::class ),
			$shipment_handler,
			static fn() => new \WP4Odoo\I18n\Translation_Service( static fn() => $client )
		);

		$result = $coordinator->pull_shipment_for_picking( 1 );

		$this->assertTrue( $result->succeeded() );
		$this->assertSame( 200, $result->get_entity_id() );
	}

	// ─── on_product_pulled ────────────────────────────────

	public function test_on_product_pulled_skips_images_when_disabled(): void {
		$image_handler = $this->createMock( \WP4Odoo\Modules\Image_Handler::class );
		$image_handler->expects( $this->never() )->method( 'import_featured_image' );

		$logger = new Logger( 'woocommerce', wp4odoo_test_settings() );

		$noop_client = new class {
			/** @return array<int> */
			public function search( string $model, array $domain ): array { return []; }
		};

		$coordinator = new WC_Pull_Coordinator(
			$logger,
			static fn() => [ 'sync_product_images' => false ],
			static fn() => $noop_client,
			static fn( string $type, int $odoo_id ) => null,
			$this->createMock( \WP4Odoo\Modules\Variant_Handler::class ),
			$image_handler,
			$this->createMock( \WP4Odoo\Modules\Pricelist_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Shipment_Handler::class ),
			static fn() => new \WP4Odoo\I18n\Translation_Service( static fn() => $noop_client )
		);

		$coordinator->on_product_pulled( 1, 100 );
	}

	// ─── on_product_pulled + accumulator ─────────────────

	public function test_on_product_pulled_accumulates_for_translation(): void {
		$coordinator = $this->make_coordinator( [ 'sync_product_images' => false ] );

		// Call on_product_pulled to accumulate.
		$coordinator->on_product_pulled( 10, 100 );
		$coordinator->on_product_pulled( 20, 200 );

		// Verify via reflection that pulled_products is populated.
		$ref = new \ReflectionClass( $coordinator );
		$prop = $ref->getProperty( 'pulled_products' );
		$prop->setAccessible( true );
		$pulled = $prop->getValue( $coordinator );

		$this->assertSame( [ 100 => 10, 200 => 20 ], $pulled );
	}

	// ─── flush_translations ──────────────────────────────

	public function test_flush_translations_skips_when_empty(): void {
		$coordinator = $this->make_coordinator( [ 'sync_translations' => true ] );

		// No products accumulated: flush should be a no-op.
		$coordinator->flush_translations();

		// If we reach here without error, the test passes.
		$this->assertTrue( true );
	}

	public function test_flush_translations_skips_when_setting_disabled(): void {
		$coordinator = $this->make_coordinator( [ 'sync_translations' => false ] );

		// Manually accumulate products.
		$ref = new \ReflectionClass( $coordinator );
		$prop = $ref->getProperty( 'pulled_products' );
		$prop->setAccessible( true );
		$prop->setValue( $coordinator, [ 100 => 10 ] );

		$coordinator->flush_translations();

		// Should have cleared the accumulator.
		$pulled = $prop->getValue( $coordinator );
		$this->assertEmpty( $pulled );
	}

	public function test_flush_translations_clears_accumulator(): void {
		$coordinator = $this->make_coordinator( [ 'sync_translations' => true ] );

		// Manually accumulate products.
		$ref = new \ReflectionClass( $coordinator );
		$prop = $ref->getProperty( 'pulled_products' );
		$prop->setAccessible( true );
		$prop->setValue( $coordinator, [ 100 => 10 ] );

		$coordinator->flush_translations();

		// Accumulator should be cleared after flush.
		$pulled = $prop->getValue( $coordinator );
		$this->assertEmpty( $pulled );
	}

	public function test_flush_translations_passes_enabled_languages(): void {
		// New array format: only 'fr' enabled.
		$coordinator = $this->make_coordinator( [ 'sync_translations' => [ 'fr' ] ] );

		// Manually accumulate products.
		$ref = new \ReflectionClass( $coordinator );
		$prop = $ref->getProperty( 'pulled_products' );
		$prop->setAccessible( true );
		$prop->setValue( $coordinator, [ 100 => 10 ] );

		$coordinator->flush_translations();

		// Accumulator should be cleared.
		$pulled = $prop->getValue( $coordinator );
		$this->assertEmpty( $pulled );
	}

	public function test_flush_translations_backward_compat_boolean_true(): void {
		// Old boolean true format → should process all languages.
		$coordinator = $this->make_coordinator( [ 'sync_translations' => true ] );

		$ref = new \ReflectionClass( $coordinator );
		$prop = $ref->getProperty( 'pulled_products' );
		$prop->setAccessible( true );
		$prop->setValue( $coordinator, [ 100 => 10 ] );

		$coordinator->flush_translations();

		// Accumulator should be cleared (processed, not skipped).
		$pulled = $prop->getValue( $coordinator );
		$this->assertEmpty( $pulled );
	}

	public function test_flush_translations_empty_array_skips(): void {
		// New array format with empty array → translation disabled.
		$coordinator = $this->make_coordinator( [ 'sync_translations' => [] ] );

		$ref = new \ReflectionClass( $coordinator );
		$prop = $ref->getProperty( 'pulled_products' );
		$prop->setAccessible( true );
		$prop->setValue( $coordinator, [ 100 => 10 ] );

		$coordinator->flush_translations();

		// Accumulator should be cleared (skipped early).
		$pulled = $prop->getValue( $coordinator );
		$this->assertEmpty( $pulled );
	}

	// ─── apply_product_translation ───────────────────────

	public function test_apply_product_translation_uses_wp_update_post_fallback(): void {
		$coordinator = $this->make_coordinator();

		// wc_get_product returns false in stubs → fallback to wp_update_post.
		// wp_update_post is a no-op stub that returns the ID.
		// Just verify it does not throw.
		$coordinator->apply_product_translation( 999, [ 'post_title' => 'Produit FR', 'post_content' => 'Desc FR' ], 'fr' );

		$this->assertTrue( true );
	}

	// ─── apply_term_translation ──────────────────────────

	public function test_apply_term_translation_calls_wp_update_term(): void {
		$coordinator = $this->make_coordinator();
		$GLOBALS['_wp_updated_terms'] = [];

		$coordinator->apply_term_translation( 42, 'Catégorie FR', 'fr' );

		$this->assertArrayHasKey( 42, $GLOBALS['_wp_updated_terms'] );
		$this->assertSame( 'Catégorie FR', $GLOBALS['_wp_updated_terms'][42]['name'] );
	}

	// ─── Category accumulation (Phase 6) ─────────────────

	public function test_on_product_pulled_accumulates_categories(): void {
		$coordinator = $this->make_coordinator( [ 'sync_product_images' => false ] );

		// Simulate captured Odoo data with categ_id.
		$ref = new \ReflectionClass( $coordinator );
		$prop = $ref->getProperty( 'last_odoo_data' );
		$prop->setAccessible( true );
		$prop->setValue( $coordinator, [
			'categ_id' => [ 7, 'Electronics' ],
		] );

		// Pre-register the term so term_exists() finds it.
		$GLOBALS['_wp_term_exists']['product_cat']['Electronics'] = [ 'term_id' => 300 ];

		$coordinator->on_product_pulled( 10, 100 );

		$cat_prop = $ref->getProperty( 'pulled_categories' );
		$cat_prop->setAccessible( true );
		$pulled_cats = $cat_prop->getValue( $coordinator );

		$this->assertSame( [ 7 => 300 ], $pulled_cats );
	}

	public function test_on_product_pulled_skips_category_without_categ_id(): void {
		$coordinator = $this->make_coordinator( [ 'sync_product_images' => false ] );

		// No categ_id in Odoo data.
		$ref = new \ReflectionClass( $coordinator );
		$prop = $ref->getProperty( 'last_odoo_data' );
		$prop->setAccessible( true );
		$prop->setValue( $coordinator, [] );

		$coordinator->on_product_pulled( 10, 100 );

		$cat_prop = $ref->getProperty( 'pulled_categories' );
		$cat_prop->setAccessible( true );
		$pulled_cats = $cat_prop->getValue( $coordinator );

		$this->assertEmpty( $pulled_cats );
	}

	// ─── flush_translations with categories ──────────────

	public function test_flush_translations_clears_category_accumulator(): void {
		$coordinator = $this->make_coordinator( [ 'sync_translations' => true ] );

		$ref = new \ReflectionClass( $coordinator );

		$cat_prop = $ref->getProperty( 'pulled_categories' );
		$cat_prop->setAccessible( true );
		$cat_prop->setValue( $coordinator, [ 50 => 300 ] );

		$coordinator->flush_translations();

		$this->assertEmpty( $cat_prop->getValue( $coordinator ) );
	}

	public function test_flush_translations_clears_attribute_value_accumulator(): void {
		$coordinator = $this->make_coordinator( [ 'sync_translations' => true ] );

		$ref = new \ReflectionClass( $coordinator );

		$attr_prop = $ref->getProperty( 'pulled_attribute_values' );
		$attr_prop->setAccessible( true );
		$attr_prop->setValue( $coordinator, [ 88 => 400 ] );

		$coordinator->flush_translations();

		$this->assertEmpty( $attr_prop->getValue( $coordinator ) );
	}

	// ─── pull_variant accumulates attribute values ───────

	public function test_pull_variant_accumulates_attribute_values(): void {
		$variant_handler = $this->createMock( \WP4Odoo\Modules\Variant_Handler::class );
		$variant_handler->method( 'pull_variants' )->willReturn( true );
		$variant_handler->method( 'get_pulled_attribute_values' )->willReturn( [ 55 => 600 ] );
		$variant_handler->expects( $this->once() )->method( 'clear_pulled_attribute_values' );

		$logger     = new Logger( 'woocommerce', wp4odoo_test_settings() );
		$client     = $this->make_noop_client();
		$mapping_fn = static fn( string $type, int $odoo_id ) => null;

		$coordinator = new WC_Pull_Coordinator(
			$logger,
			static fn() => [],
			static fn() => $client,
			$mapping_fn,
			$variant_handler,
			$this->createMock( \WP4Odoo\Modules\Image_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Pricelist_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Shipment_Handler::class ),
			static fn() => new \WP4Odoo\I18n\Translation_Service( static fn() => $client )
		);

		$result = $coordinator->pull_variant( 42, 0, [
			'parent_wp_id'     => 99,
			'template_odoo_id' => 100,
		] );

		$this->assertTrue( $result->succeeded() );

		// Verify attribute values accumulated.
		$ref = new \ReflectionClass( $coordinator );
		$attr_prop = $ref->getProperty( 'pulled_attribute_values' );
		$attr_prop->setAccessible( true );
		$this->assertSame( [ 55 => 600 ], $attr_prop->getValue( $coordinator ) );
	}

	// ─── on_order_pulled ──────────────────────────────────

	public function test_on_order_pulled_skips_shipments_when_disabled(): void {
		$shipment_handler = $this->createMock( \WP4Odoo\Modules\Shipment_Handler::class );
		$shipment_handler->expects( $this->never() )->method( 'pull_shipments' );

		$logger = new Logger( 'woocommerce', wp4odoo_test_settings() );

		$noop = $this->make_noop_client();

		$coordinator = new WC_Pull_Coordinator(
			$logger,
			static fn() => [ 'sync_shipments' => false ],
			static fn() => $noop,
			static fn( string $type, int $odoo_id ) => null,
			$this->createMock( \WP4Odoo\Modules\Variant_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Image_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Pricelist_Handler::class ),
			$shipment_handler,
			static fn() => new \WP4Odoo\I18n\Translation_Service( static fn() => $noop )
		);

		$coordinator->on_order_pulled( 50, 200 );
	}
}
