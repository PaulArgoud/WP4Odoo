<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP4Odoo\Logger;
use WP4Odoo\Modules\Stock_Handler;

/**
 * Unit tests for Stock_Handler.
 */
class StockHandlerTest extends TestCase {

	private Stock_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$this->handler = new Stock_Handler( new Logger( 'test' ) );

		// Default: Odoo 17 (quant-based API).
		$GLOBALS['_wp_options']['wp4odoo_odoo_version'] = '17.0';
	}

	// ─── Push stock: invalid product ID ────────────────────

	public function test_push_stock_fails_for_zero_product_id(): void {
		$client = $this->create_mock_client();
		$result = $this->handler->push_stock( $client, 0, 10.0 );

		$this->assertFalse( $result->succeeded() );
		$this->assertStringContainsString( 'no Odoo product ID', $result->get_message() );
	}

	public function test_push_stock_fails_for_negative_product_id(): void {
		$client = $this->create_mock_client();
		$result = $this->handler->push_stock( $client, -1, 10.0 );

		$this->assertFalse( $result->succeeded() );
	}

	// ─── Storable products (Odoo 18+) ──────────────────────

	public function test_push_stock_enables_inventory_tracking_on_odoo_18(): void {
		$GLOBALS['_wp_options']['wp4odoo_odoo_version'] = '18.0';

		$call_log = [];
		$client   = $this->create_mock_client( function ( string $model, string $method, array $args ) use ( &$call_log ) {
			$call_log[] = [ $model, $method, $args ];

			if ( 'product.product' === $model && 'read' === $method ) {
				return [ [ 'id' => 100, 'is_storable' => false, 'product_tmpl_id' => [ 55, 'Widget' ] ] ];
			}
			if ( 'stock.quant' === $model && 'search_read' === $method ) {
				return [ [ 'id' => 42, 'location_id' => [ 8, 'Stock' ] ] ];
			}
			return true;
		} );

		$result = $this->handler->push_stock( $client, 100, 25.0 );

		$this->assertTrue( $result->succeeded() );
		$this->assertSame( [ 'product.product', 'read' ], array_slice( $call_log[0], 0, 2 ) );
		$this->assertSame( [ 'product.template', 'write' ], array_slice( $call_log[1], 0, 2 ) );
		$this->assertSame( [ [ 55 ], [ 'is_storable' => true ] ], $call_log[1][2] );
	}

	public function test_push_stock_skips_write_when_product_already_storable(): void {
		$GLOBALS['_wp_options']['wp4odoo_odoo_version'] = '19.0';

		$call_log = [];
		$client   = $this->create_mock_client( function ( string $model, string $method ) use ( &$call_log ) {
			if ( 'product.product' === $model && 'read' === $method ) {
				return [ [ 'detailed_type' => 'product', 'type' => 'product', 'is_storable' => true, 'product_tmpl_id' => [ 55, 'W' ] ] ];
			}
			$call_log[] = [ $model, $method ];

			if ( 'product.product' === $model && 'read' === $method ) {
				return [ [ 'id' => 100, 'is_storable' => true, 'product_tmpl_id' => [ 55, 'Widget' ] ] ];
			}
			if ( 'stock.quant' === $model && 'search_read' === $method ) {
				return [ [ 'id' => 42, 'location_id' => [ 8, 'Stock' ] ] ];
			}
			return true;
		} );

		$this->handler->push_stock( $client, 100, 25.0 );

		$this->assertNotContains( [ 'product.template', 'write' ], $call_log );
	}

	/**
	 * Run a push with a product read returning $row and report the field probed and any template write.
	 *
	 * @param array<string, mixed> $row Row returned for the product.product read.
	 * @return array{read: array<int, mixed>, write: array<int, mixed>|null}
	 */
	private function storable_probe( string $version, array $row ): array {
		$GLOBALS['_wp_options']['wp4odoo_odoo_version'] = $version;

		$seen   = [ 'read' => [], 'write' => null ];
		$client = $this->create_mock_client( function ( string $model, string $method, array $args, array $kwargs ) use ( &$seen, $row ) {
			if ( 'product.product' === $model && 'read' === $method ) {
				$seen['read'] = $kwargs['fields'] ?? [];
				return [ $row ];
			}
			if ( 'product.template' === $model && 'write' === $method ) {
				$seen['write'] = $args;
				return true;
			}
			if ( 'stock.quant' === $model && 'search_read' === $method ) {
				return [ [ 'id' => 42, 'location_id' => [ 8, 'Stock' ] ] ];
			}
			return true;
		} );

		$this->handler->push_stock( $client, 100, 25.0 );

		return $seen;
	}

	public function test_odoo_17_products_are_made_storable_through_detailed_type(): void {
		$seen = $this->storable_probe( '17.0', [ 'detailed_type' => 'consu', 'product_tmpl_id' => [ 55, 'W' ] ] );

		$this->assertContains( 'detailed_type', $seen['read'] );
		$this->assertSame( [ [ 55 ], [ 'detailed_type' => 'product' ] ], $seen['write'] );
	}

	public function test_odoo_14_and_15_products_are_made_storable_through_type(): void {
		$seen = $this->storable_probe( '15.0', [ 'type' => 'consu', 'product_tmpl_id' => [ 55, 'W' ] ] );

		$this->assertContains( 'type', $seen['read'] );
		$this->assertSame( [ [ 55 ], [ 'type' => 'product' ] ], $seen['write'] );
	}

	public function test_storable_products_are_left_alone_on_every_version(): void {
		$this->assertNull( $this->storable_probe( '17.0', [ 'detailed_type' => 'product', 'product_tmpl_id' => [ 55, 'W' ] ] )['write'] );
		$this->assertNull( $this->storable_probe( '15.0', [ 'type' => 'product', 'product_tmpl_id' => [ 55, 'W' ] ] )['write'] );
	}

	public function test_nothing_is_probed_when_the_odoo_version_is_unknown(): void {
		unset( $GLOBALS['_wp_options']['wp4odoo_odoo_version'] );

		$call_log = [];
		$client   = $this->create_mock_client( function ( string $model, string $method ) use ( &$call_log ) {
			if ( 'product.product' === $model && 'read' === $method ) {
				return [ [ 'detailed_type' => 'product', 'type' => 'product', 'is_storable' => true, 'product_tmpl_id' => [ 55, 'W' ] ] ];
			}
			$call_log[] = [ $model, $method ];
			return 'search_read' === $method ? [ [ 'id' => 42, 'location_id' => [ 8, 'Stock' ] ] ] : true;
		} );

		$this->handler->push_stock( $client, 100, 25.0 );

		$this->assertNotContains( [ 'product.product', 'read' ], $call_log );
	}

	// ─── Push stock: quant API (v16+) ──────────────────────

	public function test_push_stock_quant_updates_existing_quant(): void {
		$GLOBALS['_wp_options']['wp4odoo_odoo_version'] = '17.0';

		$call_log = [];
		$client   = $this->create_mock_client( function ( string $model, string $method, array $args ) use ( &$call_log ) {
			if ( 'product.product' === $model && 'read' === $method ) {
				return [ [ 'detailed_type' => 'product', 'type' => 'product', 'is_storable' => true, 'product_tmpl_id' => [ 55, 'W' ] ] ];
			}
			$call_log[] = [ $model, $method ];

			// search_read for existing quant → return one.
			if ( 'stock.quant' === $model && 'search_read' === $method ) {
				return [ [ 'id' => 42, 'location_id' => [ 8, 'Stock' ] ] ];
			}

			// write → success.
			if ( 'stock.quant' === $model && 'write' === $method ) {
				return true;
			}

			// action_apply_inventory → success.
			if ( 'stock.quant' === $model && 'action_apply_inventory' === $method ) {
				return true;
			}

			return null;
		} );

		$result = $this->handler->push_stock( $client, 100, 25.0 );

		$this->assertTrue( $result->succeeded() );
		$this->assertSame( 100, $result->get_entity_id() );

		// Verify API calls: search_read, write, action_apply_inventory.
		$this->assertCount( 3, $call_log );
		$this->assertSame( [ 'stock.quant', 'search_read' ], $call_log[0] );
		$this->assertSame( [ 'stock.quant', 'write' ], $call_log[1] );
		$this->assertSame( [ 'stock.quant', 'action_apply_inventory' ], $call_log[2] );
	}

	public function test_push_stock_quant_creates_new_quant(): void {
		$GLOBALS['_wp_options']['wp4odoo_odoo_version'] = '16.0';

		$call_log = [];
		$client   = $this->create_mock_client( function ( string $model, string $method, array $args ) use ( &$call_log ) {
			if ( 'product.product' === $model && 'read' === $method ) {
				return [ [ 'detailed_type' => 'product', 'type' => 'product', 'is_storable' => true, 'product_tmpl_id' => [ 55, 'W' ] ] ];
			}
			$call_log[] = [ $model, $method ];

			// search_read for existing quant → empty.
			if ( 'stock.quant' === $model && 'search_read' === $method ) {
				return [];
			}

			// search_read for warehouse → return one.
			if ( 'stock.warehouse' === $model && 'search_read' === $method ) {
				return [ [ 'lot_stock_id' => [ 8, 'WH/Stock' ] ] ];
			}

			// create quant.
			if ( 'stock.quant' === $model && 'create' === $method ) {
				return 99;
			}

			// action_apply_inventory.
			if ( 'stock.quant' === $model && 'action_apply_inventory' === $method ) {
				return true;
			}

			return null;
		} );

		$result = $this->handler->push_stock( $client, 200, 50.0 );

		$this->assertTrue( $result->succeeded() );
		$this->assertSame( 200, $result->get_entity_id() );
		$this->assertCount( 4, $call_log );
	}

	// ─── Push stock: wizard API (v14-15) ───────────────────

	public function test_push_stock_wizard_for_v14(): void {
		$GLOBALS['_wp_options']['wp4odoo_odoo_version'] = '14.0';

		$call_log = [];
		$client   = $this->create_mock_client( function ( string $model, string $method, array $args ) use ( &$call_log ) {
			if ( 'product.product' === $model && 'read' === $method ) {
				return [ [ 'detailed_type' => 'product', 'type' => 'product', 'is_storable' => true, 'product_tmpl_id' => [ 55, 'W' ] ] ];
			}
			$call_log[] = [ $model, $method ];

			// create wizard.
			if ( 'stock.change.product.qty' === $model && 'create' === $method ) {
				return 77;
			}

			// change_product_qty.
			if ( 'stock.change.product.qty' === $model && 'change_product_qty' === $method ) {
				return true;
			}

			return null;
		} );

		$result = $this->handler->push_stock( $client, 300, 15.0 );

		$this->assertTrue( $result->succeeded() );
		$this->assertCount( 2, $call_log );
		$this->assertSame( [ 'stock.change.product.qty', 'create' ], $call_log[0] );
		$this->assertSame( [ 'stock.change.product.qty', 'change_product_qty' ], $call_log[1] );
	}

	public function test_push_stock_wizard_for_v15(): void {
		$GLOBALS['_wp_options']['wp4odoo_odoo_version'] = '15.0';

		$call_log = [];
		$client   = $this->create_mock_client( function ( string $model, string $method ) use ( &$call_log ) {
			if ( 'product.product' === $model && 'read' === $method ) {
				return [ [ 'detailed_type' => 'product', 'type' => 'product', 'is_storable' => true, 'product_tmpl_id' => [ 55, 'W' ] ] ];
			}
			$call_log[] = [ $model, $method ];

			if ( 'create' === $method ) {
				return 1;
			}

			return true;
		} );

		$result = $this->handler->push_stock( $client, 400, 20.0 );

		$this->assertTrue( $result->succeeded() );
		// Wizard API used for v15.
		$this->assertSame( 'stock.change.product.qty', $call_log[0][0] );
	}

	// ─── Error handling ────────────────────────────────────

	public function test_push_stock_returns_failure_on_api_error(): void {
		$client = $this->create_mock_client( function () {
			throw new \RuntimeException( 'Connection refused' );
		} );

		$result = $this->handler->push_stock( $client, 500, 10.0 );

		$this->assertFalse( $result->succeeded() );
		$this->assertStringContainsString( 'Connection refused', $result->get_message() );
	}

	public function test_push_stock_defaults_to_quant_when_version_unknown(): void {
		unset( $GLOBALS['_wp_options']['wp4odoo_odoo_version'] );

		$call_log = [];
		$client   = $this->create_mock_client( function ( string $model, string $method ) use ( &$call_log ) {
			if ( 'product.product' === $model && 'read' === $method ) {
				return [ [ 'detailed_type' => 'product', 'type' => 'product', 'is_storable' => true, 'product_tmpl_id' => [ 55, 'W' ] ] ];
			}
			$call_log[] = [ $model, $method ];

			if ( 'search_read' === $method ) {
				return [ [ 'id' => 1, 'location_id' => [ 1, 'Stock' ] ] ];
			}

			return true;
		} );

		$result = $this->handler->push_stock( $client, 600, 5.0 );

		$this->assertTrue( $result->succeeded() );
		// Should use quant API (v16+ default).
		$this->assertSame( 'stock.quant', $call_log[0][0] );
	}

	// ─── Helper ────────────────────────────────────────────

	/**
	 * Create a mock Odoo_Client using reflection to inject a mock transport.
	 *
	 * @param callable|null $handler Custom call handler (model, method, args) → mixed.
	 * @return \WP4Odoo\API\Odoo_Client
	 */
	private function create_mock_client( ?callable $handler = null ): \WP4Odoo\API\Odoo_Client {
		$transport = new class( $handler ) implements \WP4Odoo\API\Transport {
			/** @var callable|null */
			private $handler;

			public function __construct( ?callable $handler ) {
				$this->handler = $handler;
			}

			public function authenticate( string $username ): int {
				return 1;
			}

			public function execute_kw( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
				if ( $this->handler ) {
					return ( $this->handler )( $model, $method, $args, $kwargs );
				}
				return null;
			}

			public function get_uid(): ?int {
				return 1;
			}

			public function get_server_version(): ?string {
				return '17.0';
			}
		};

		$client = new \WP4Odoo\API\Odoo_Client();

		// Inject mock transport via reflection.
		$ref = new \ReflectionClass( $client );

		$transport_prop = $ref->getProperty( 'transport' );
		$transport_prop->setAccessible( true );
		$transport_prop->setValue( $client, $transport );

		$connected_prop = $ref->getProperty( 'connected' );
		$connected_prop->setAccessible( true );
		$connected_prop->setValue( $client, true );

		return $client;
	}
}
