<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Logger;
use WP4Odoo\Modules\WC_Shipping_Handler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Shipping_Handler.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WC_Shipping_Handler::class)]
class WCShippingHandlerTest extends TestCase {

	private WC_Shipping_Handler $handler;
	private Entity_Map_Repository $entity_map;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']        = [];
		$GLOBALS['_wc_orders']         = [];
		$GLOBALS['_wc_products']       = [];
		$GLOBALS['_wc_shipping_zones'] = [];

		$this->entity_map = wp4odoo_test_entity_map();

		$this->handler = new WC_Shipping_Handler(
			new Logger( 'test', wp4odoo_test_settings() ),
			$this->entity_map
		);
	}

	// ─── build_tracking_item ─────────────────────────────

	public function test_build_tracking_item_basic(): void {
		$item = $this->handler->build_tracking_item( 'DHL', 'TRACK-123', '2026-01-15' );

		$this->assertSame( 'DHL', $item['tracking_provider'] );
		$this->assertSame( 'TRACK-123', $item['tracking_number'] );
		$this->assertSame( '', $item['custom_tracking_provider'] );
		$this->assertSame( '', $item['custom_tracking_link'] );
		$this->assertSame( 1, $item['status_shipped'] );
	}

	public function test_build_tracking_item_with_datetime(): void {
		$item = $this->handler->build_tracking_item( 'UPS', 'UPS-456', '2026-01-15 14:30:00' );

		$this->assertSame( 'UPS', $item['tracking_provider'] );
		$this->assertSame( 'UPS-456', $item['tracking_number'] );
		$this->assertNotSame( '0', $item['date_shipped'] );
	}

	public function test_build_tracking_item_empty_date(): void {
		$item = $this->handler->build_tracking_item( 'FedEx', 'FDX-789', '' );

		$this->assertSame( '0', $item['date_shipped'] );
	}

	public function test_build_tracking_item_false_date(): void {
		$item = $this->handler->build_tracking_item( 'FedEx', 'FDX-789', 'false' );

		$this->assertSame( '0', $item['date_shipped'] );
	}

	// ─── map_picking_state ───────────────────────────────

	public function test_draft_maps_to_pending(): void {
		$this->assertSame( 'pending', $this->handler->map_picking_state( 'draft' ) );
	}

	public function test_waiting_maps_to_on_hold(): void {
		$this->assertSame( 'on-hold', $this->handler->map_picking_state( 'waiting' ) );
	}

	public function test_confirmed_maps_to_processing(): void {
		$this->assertSame( 'processing', $this->handler->map_picking_state( 'confirmed' ) );
	}

	public function test_assigned_maps_to_processing(): void {
		$this->assertSame( 'processing', $this->handler->map_picking_state( 'assigned' ) );
	}

	public function test_done_maps_to_completed(): void {
		$this->assertSame( 'completed', $this->handler->map_picking_state( 'done' ) );
	}

	public function test_cancel_maps_to_cancelled(): void {
		$this->assertSame( 'cancelled', $this->handler->map_picking_state( 'cancel' ) );
	}

	public function test_unknown_state_defaults_to_pending(): void {
		$this->assertSame( 'pending', $this->handler->map_picking_state( 'unknown' ) );
	}

	// ─── extract_from_shipstation ─────────────────────────

	public function test_extract_from_shipstation(): void {
		$data = $this->handler->extract_from_shipstation( [
			'tracking_number' => 'SS-TRACK-1',
			'carrier_code'    => 'fedex',
			'ship_date'       => '2026-01-15',
		] );

		$this->assertSame( 'SS-TRACK-1', $data['tracking_number'] );
		$this->assertSame( 'fedex', $data['carrier_name'] );
		$this->assertSame( '2026-01-15', $data['date_shipped'] );
	}

	public function test_extract_from_shipstation_empty(): void {
		$data = $this->handler->extract_from_shipstation( [] );

		$this->assertSame( '', $data['tracking_number'] );
		$this->assertSame( '', $data['carrier_name'] );
	}

	// ─── extract_from_sendcloud ───────────────────────────

	public function test_extract_from_sendcloud(): void {
		$data = $this->handler->extract_from_sendcloud( [
			'tracking_number' => 'SC-TRACK-2',
			'carrier'         => [ 'code' => 'dhl' ],
			'date_created'    => '2026-01-16',
		] );

		$this->assertSame( 'SC-TRACK-2', $data['tracking_number'] );
		$this->assertSame( 'dhl', $data['carrier_name'] );
		$this->assertSame( '2026-01-16', $data['date_shipped'] );
	}

	public function test_extract_from_sendcloud_empty(): void {
		$data = $this->handler->extract_from_sendcloud( [] );

		$this->assertSame( '', $data['tracking_number'] );
		$this->assertSame( '', $data['carrier_name'] );
	}

	// ─── extract_from_packlink ────────────────────────────

	public function test_extract_from_packlink(): void {
		$data = $this->handler->extract_from_packlink( [
			'tracking_number' => 'PL-TRACK-3',
			'carrier'         => 'gls',
			'shipped_date'    => '2026-01-17',
		] );

		$this->assertSame( 'PL-TRACK-3', $data['tracking_number'] );
		$this->assertSame( 'gls', $data['carrier_name'] );
		$this->assertSame( '2026-01-17', $data['date_shipped'] );
	}

	public function test_extract_from_packlink_with_tracking_code(): void {
		$data = $this->handler->extract_from_packlink( [
			'tracking_code' => 'PL-CODE-4',
			'carrier'       => 'ups',
		] );

		$this->assertSame( 'PL-CODE-4', $data['tracking_number'] );
	}

	// ─── parse_shipment_from_odoo ─────────────────────────

	public function test_parse_shipment_extracts_tracking(): void {
		$data = $this->handler->parse_shipment_from_odoo( [
			'carrier_tracking_ref' => 'ODOO-TRACK-1',
			'state'                => 'done',
			'date_done'            => '2026-01-15 10:00:00',
			'origin'               => 'WC Order #200',
			'carrier_id'           => [ 5, 'DHL Express' ],
		] );

		$this->assertSame( 'ODOO-TRACK-1', $data['tracking_number'] );
		$this->assertSame( 'DHL Express', $data['carrier_name'] );
		$this->assertSame( 'completed', $data['status'] );
		$this->assertSame( 200, $data['order_id'] );
	}

	public function test_parse_shipment_no_origin_match(): void {
		$data = $this->handler->parse_shipment_from_odoo( [
			'carrier_tracking_ref' => 'TRACK-X',
			'state'                => 'draft',
			'origin'               => 'SO00123',
		] );

		$this->assertSame( 0, $data['order_id'] );
		$this->assertSame( 'pending', $data['status'] );
	}

	// ─── extract_tracking_from_meta ───────────────────────

	public function test_extract_tracking_from_meta_empty(): void {
		$order = new \WC_Order();
		$order->set_data( [ 'id' => 300 ] );
		$GLOBALS['_wc_orders'][300] = $order;

		$tracking = $this->handler->extract_tracking_from_meta( 300 );
		$this->assertEmpty( $tracking );
	}

	public function test_extract_tracking_from_meta_with_ast(): void {
		$order = new \WC_Order();
		$order->set_data( [
			'id'   => 301,
			'meta' => [
				'_wc_shipment_tracking_items' => [
					[
						'tracking_provider' => 'DHL',
						'tracking_number'   => 'DHL-100',
					],
				],
			],
		] );
		$GLOBALS['_wc_orders'][301] = $order;

		$tracking = $this->handler->extract_tracking_from_meta( 301 );
		$this->assertCount( 1, $tracking );
		$this->assertSame( 'DHL-100', $tracking[0]['tracking_number'] );
	}

	public function test_extract_tracking_missing_order(): void {
		$tracking = $this->handler->extract_tracking_from_meta( 999 );
		$this->assertEmpty( $tracking );
	}

	// ─── load_shipment_from_order ─────────────────────────

	public function test_load_shipment_returns_empty_without_tracking(): void {
		$order = new \WC_Order();
		$order->set_data( [ 'id' => 400 ] );
		$GLOBALS['_wc_orders'][400] = $order;

		$data = $this->handler->load_shipment_from_order( 400 );
		$this->assertEmpty( $data );
	}

	// ─── save_shipment ────────────────────────────────────

	public function test_save_shipment_returns_zero_without_order(): void {
		$result = $this->handler->save_shipment( [ 'tracking_number' => 'X' ], 0 );
		$this->assertSame( 0, $result );
	}

	public function test_save_shipment_returns_order_id_for_empty_tracking(): void {
		$order = new \WC_Order();
		$order->set_data( [ 'id' => 500 ] );
		$GLOBALS['_wc_orders'][500] = $order;

		$result = $this->handler->save_shipment( [ 'tracking_number' => '' ], 500 );
		$this->assertSame( 500, $result );
	}

	public function test_save_shipment_adds_tracking_to_order(): void {
		$order = new \WC_Order();
		$order->set_data( [ 'id' => 501 ] );
		$GLOBALS['_wc_orders'][501] = $order;

		$result = $this->handler->save_shipment(
			[
				'tracking_number' => 'NEW-TRACK-1',
				'carrier_name'    => 'FedEx',
				'date_done'       => '2026-01-15',
			],
			501
		);

		$this->assertSame( 501, $result );
	}

	public function test_save_shipment_skips_duplicate_tracking(): void {
		$order = new \WC_Order();
		$order->set_data( [
			'id'   => 502,
			'meta' => [
				'_wc_shipment_tracking_items' => [
					[
						'tracking_provider' => 'DHL',
						'tracking_number'   => 'EXISTING-TRACK',
					],
				],
			],
		] );
		$GLOBALS['_wc_orders'][502] = $order;

		$result = $this->handler->save_shipment(
			[
				'tracking_number' => 'EXISTING-TRACK',
				'carrier_name'    => 'DHL',
			],
			502
		);

		$this->assertSame( 502, $result );
	}

	// ─── load_carrier ─────────────────────────────────────

	public function test_load_carrier_returns_empty_for_missing(): void {
		$data = $this->handler->load_carrier( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_carrier_from_shipping_zone(): void {
		$method = new \WC_Shipping_Method();
		$method->set_data( [
			'instance_id' => 5,
			'title'       => 'Express Shipping',
		] );

		$GLOBALS['_wc_shipping_zones'] = [
			1 => [
				'shipping_methods' => [ $method ],
			],
		];

		$data = $this->handler->load_carrier( 5 );
		$this->assertSame( 'Express Shipping', $data['name'] );
		$this->assertSame( 'fixed', $data['delivery_type'] );
	}

	// ─── parse_carrier_from_odoo ──────────────────────────

	public function test_parse_carrier_from_odoo(): void {
		$data = $this->handler->parse_carrier_from_odoo( [
			'name'          => 'DHL Express',
			'delivery_type' => 'fixed',
			'tracking_url'  => 'https://track.dhl.com/',
		] );

		$this->assertSame( 'DHL Express', $data['name'] );
		$this->assertSame( 'fixed', $data['delivery_type'] );
		$this->assertSame( 'https://track.dhl.com/', $data['tracking_url'] );
	}

	// ─── Provider detection ───────────────────────────────

	public function test_has_sendcloud_false_by_default(): void {
		$this->assertFalse( $this->handler->has_sendcloud() );
	}

	public function test_has_packlink_false_by_default(): void {
		$this->assertFalse( $this->handler->has_packlink() );
	}

	// ─── resolve_odoo_picking ─────────────────────────────

	public function test_resolve_odoo_picking_returns_zero_when_not_mapped(): void {
		$this->assertSame( 0, $this->handler->resolve_odoo_picking( 999 ) );
	}
}
