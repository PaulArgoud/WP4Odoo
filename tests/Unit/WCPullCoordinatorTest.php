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
			$shipment_handler
		);
	}

	private function make_noop_client(): object {
		return new class {
			/** @return array<int> */
			public function search( string $model, array $domain ): array {
				return [];
			}

			/** @return array */
			public function read( string $model, array $ids, array $fields = [] ): array {
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
			public function read( string $model, array $ids, array $fields = [] ): array {
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
			$this->createMock( \WP4Odoo\Modules\Shipment_Handler::class )
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
			public function read( string $model, array $ids, array $fields = [] ): array {
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
			$shipment_handler
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

		$coordinator = new WC_Pull_Coordinator(
			$logger,
			static fn() => [ 'sync_product_images' => false ],
			static fn() => new class {
				/** @return array<int> */
				public function search( string $model, array $domain ): array { return []; }
			},
			static fn( string $type, int $odoo_id ) => null,
			$this->createMock( \WP4Odoo\Modules\Variant_Handler::class ),
			$image_handler,
			$this->createMock( \WP4Odoo\Modules\Pricelist_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Shipment_Handler::class )
		);

		$coordinator->on_product_pulled( 1, 100 );
	}

	// ─── on_order_pulled ──────────────────────────────────

	public function test_on_order_pulled_skips_shipments_when_disabled(): void {
		$shipment_handler = $this->createMock( \WP4Odoo\Modules\Shipment_Handler::class );
		$shipment_handler->expects( $this->never() )->method( 'pull_shipments' );

		$logger = new Logger( 'woocommerce', wp4odoo_test_settings() );

		$coordinator = new WC_Pull_Coordinator(
			$logger,
			static fn() => [ 'sync_shipments' => false ],
			static fn() => $this->make_noop_client(),
			static fn( string $type, int $odoo_id ) => null,
			$this->createMock( \WP4Odoo\Modules\Variant_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Image_Handler::class ),
			$this->createMock( \WP4Odoo\Modules\Pricelist_Handler::class ),
			$shipment_handler
		);

		$coordinator->on_order_pulled( 50, 200 );
	}
}
