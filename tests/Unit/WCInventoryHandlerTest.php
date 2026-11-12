<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Logger;
use WP4Odoo\Modules\WC_Inventory_Handler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Inventory_Handler.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WC_Inventory_Handler::class)]
class WCInventoryHandlerTest extends TestCase {

	private WC_Inventory_Handler $handler;
	private Entity_Map_Repository $entity_map;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']  = [];
		$GLOBALS['_wc_orders']   = [];
		$GLOBALS['_wc_products'] = [];

		$this->entity_map = wp4odoo_test_entity_map();

		$this->handler = new WC_Inventory_Handler(
			new Logger( 'test', wp4odoo_test_settings() ),
			$this->entity_map
		);
	}

	// ─── parse_warehouse_from_odoo ────────────────────────

	public function test_parse_warehouse_basic(): void {
		$data = $this->handler->parse_warehouse_from_odoo( [
			'name'         => 'Main Warehouse',
			'code'         => 'WH',
			'lot_stock_id' => [ 8, 'WH/Stock' ],
		] );

		$this->assertSame( 'Main Warehouse', $data['name'] );
		$this->assertSame( 'WH', $data['code'] );
		$this->assertSame( 8, $data['lot_stock_id'] );
	}

	public function test_parse_warehouse_empty(): void {
		$data = $this->handler->parse_warehouse_from_odoo( [] );

		$this->assertSame( '', $data['name'] );
		$this->assertSame( '', $data['code'] );
	}

	// ─── save_warehouse ───────────────────────────────────

	public function test_save_warehouse_returns_ref_id(): void {
		$ref_id = $this->handler->save_warehouse(
			[ 'name' => 'Main', 'code' => 'WH' ],
			0
		);

		$this->assertGreaterThan( 0, $ref_id );
	}

	public function test_save_warehouse_with_existing_id(): void {
		$ref_id = $this->handler->save_warehouse(
			[ 'name' => 'Main', 'code' => 'WH' ],
			42
		);

		$this->assertSame( 42, $ref_id );
	}

	// ─── parse_location_from_odoo ─────────────────────────

	public function test_parse_location_basic(): void {
		$data = $this->handler->parse_location_from_odoo( [
			'complete_name' => 'WH/Stock',
			'usage'         => 'internal',
			'location_id'   => [ 1, 'WH' ],
		] );

		$this->assertSame( 'WH/Stock', $data['name'] );
		$this->assertSame( 'internal', $data['location_type'] );
		$this->assertSame( 1, $data['parent_id'] );
	}

	public function test_parse_location_falls_back_to_name(): void {
		$data = $this->handler->parse_location_from_odoo( [
			'name'  => 'Stock',
			'usage' => 'internal',
		] );

		$this->assertSame( 'Stock', $data['name'] );
	}

	// ─── save_location ────────────────────────────────────

	public function test_save_location_returns_ref_id(): void {
		$ref_id = $this->handler->save_location(
			[ 'name' => 'WH/Stock', 'location_type' => 'internal' ],
			0
		);

		$this->assertGreaterThan( 0, $ref_id );
	}

	// ─── parse_movement_from_odoo ─────────────────────────

	public function test_parse_movement_basic(): void {
		$data = $this->handler->parse_movement_from_odoo( [
			'product_id'      => [ 42, 'Widget' ],
			'product_uom_qty' => 10.0,
			'state'           => 'done',
			'reference'       => 'WH/OUT/00001',
			'date'            => '2026-01-15 10:00:00',
			'name'            => 'Delivery',
			'location_id'     => [ 8, 'WH/Stock' ],
			'location_dest_id' => [ 5, 'Partner Locations/Customers' ],
		] );

		$this->assertSame( 42, $data['odoo_product_id'] );
		$this->assertSame( 10.0, $data['quantity'] );
		$this->assertSame( 'done', $data['state'] );
		$this->assertSame( 'WH/OUT/00001', $data['reference'] );
		$this->assertSame( 8, $data['source_location'] );
		$this->assertSame( 5, $data['dest_location'] );
	}

	public function test_parse_movement_empty(): void {
		$data = $this->handler->parse_movement_from_odoo( [] );

		$this->assertSame( 0.0, $data['quantity'] );
		$this->assertSame( 'draft', $data['state'] );
	}

	// ─── map_movement_state ───────────────────────────────

	public function test_draft_maps_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_movement_state( 'draft' ) );
	}

	public function test_done_maps_to_done(): void {
		$this->assertSame( 'done', $this->handler->map_movement_state( 'done' ) );
	}

	public function test_cancel_maps_to_cancel(): void {
		$this->assertSame( 'cancel', $this->handler->map_movement_state( 'cancel' ) );
	}

	public function test_confirmed_maps_to_confirmed(): void {
		$this->assertSame( 'confirmed', $this->handler->map_movement_state( 'confirmed' ) );
	}

	public function test_assigned_maps_to_assigned(): void {
		$this->assertSame( 'assigned', $this->handler->map_movement_state( 'assigned' ) );
	}

	public function test_unknown_state_defaults_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_movement_state( 'unknown' ) );
	}

	// ─── load_movement ────────────────────────────────────

	public function test_load_movement_returns_empty_for_missing_product(): void {
		$data = $this->handler->load_movement( 999 );
		$this->assertEmpty( $data );
	}

	// ─── save_movement ────────────────────────────────────

	public function test_save_movement_returns_zero_without_product(): void {
		$result = $this->handler->save_movement( [ 'state' => 'done' ], 0 );
		$this->assertSame( 0, $result );
	}

	public function test_save_movement_skips_non_done_state(): void {
		$product = new \WC_Product();
		$product->set_data( [ 'id' => 100, 'stock_quantity' => 50 ] );
		$GLOBALS['_wc_products'][100] = $product;

		$result = $this->handler->save_movement(
			[
				'product_id' => 100,
				'quantity'   => 10.0,
				'state'      => 'draft',
				'reference'  => 'WH/IN/00001',
			],
			0
		);

		$this->assertSame( 100, $result );
		// Stock should NOT change.
		$this->assertSame( 50.0, (float) $product->get_stock_quantity() );
	}

	public function test_save_movement_applies_done_stock_change(): void {
		$product = new \WC_Product();
		$product->set_data( [ 'id' => 101, 'stock_quantity' => 50 ] );
		$GLOBALS['_wc_products'][101] = $product;

		$result = $this->handler->save_movement(
			[
				'product_id' => 101,
				'quantity'   => 10.0,
				'state'      => 'done',
				'reference'  => 'WH/IN/00002',
			],
			0
		);

		$this->assertSame( 101, $result );
		$this->assertSame( 60.0, (float) $product->get_stock_quantity() );
	}

	// ─── resolve_product_odoo_id ──────────────────────────

	public function test_resolve_product_returns_zero_when_not_mapped(): void {
		$this->assertSame( 0, $this->handler->resolve_product_odoo_id( 999 ) );
	}

	// ─── get_default_location_id ──────────────────────────

	public function test_default_location_id_returns_zero(): void {
		$this->assertSame( 0, $this->handler->get_default_location_id() );
	}

	// ─── has_atum ─────────────────────────────────────────

	public function test_has_atum_false_by_default(): void {
		$this->assertFalse( $this->handler->has_atum() );
	}
}
