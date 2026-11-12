<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WC_Inventory_Module;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Inventory_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WC_Inventory_Module::class)]
#[CoversClass(\WP4Odoo\Modules\WC_Inventory_Hooks::class)]
class WCInventoryModuleTest extends TestCase {

	private WC_Inventory_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']  = [];
		$GLOBALS['_wc_orders']   = [];
		$GLOBALS['_wc_products'] = [];

		$this->module = new WC_Inventory_Module(
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);
	}

	// ─── Identity ─────────────────────────────────────────

	public function test_module_id(): void {
		$this->assertSame( 'wc_inventory', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'WC Inventory', $this->module->get_name() );
	}

	public function test_sync_direction(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	public function test_requires_woocommerce_module(): void {
		$this->assertSame( [ 'woocommerce' ], $this->module->get_required_modules() );
	}

	// ─── Odoo models ──────────────────────────────────────

	public function test_declares_warehouse_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'stock.warehouse', $models['warehouse'] );
	}

	public function test_declares_location_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'stock.location', $models['location'] );
	}

	public function test_declares_movement_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'stock.move', $models['movement'] );
	}

	public function test_model_count(): void {
		$this->assertCount( 3, $this->module->get_odoo_models() );
	}

	// ─── Default settings ─────────────────────────────────

	public function test_default_sync_movements_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_movements'] );
	}

	public function test_default_sync_warehouses_disabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertFalse( $settings['sync_warehouses'] );
	}

	public function test_default_sync_locations_disabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertFalse( $settings['sync_locations'] );
	}

	public function test_default_push_adjustments_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['push_adjustments'] );
	}

	public function test_default_warehouse_id_zero(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 0, $settings['default_warehouse_id'] );
	}

	public function test_settings_count(): void {
		$this->assertCount( 5, $this->module->get_default_settings() );
	}

	// ─── Settings fields ──────────────────────────────────

	public function test_settings_fields_count(): void {
		$this->assertCount( 5, $this->module->get_settings_fields() );
	}

	public function test_settings_fields_have_labels(): void {
		$fields = $this->module->get_settings_fields();
		foreach ( $fields as $key => $field ) {
			$this->assertNotEmpty( $field['label'], "Field $key should have a label." );
		}
	}

	// ─── Dependency status ────────────────────────────────

	public function test_dependency_available_when_woocommerce_active(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Boot ─────────────────────────────────────────────

	public function test_boot_does_not_throw(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Field mappings ───────────────────────────────────

	public function test_warehouse_mapping(): void {
		$data = [
			'name' => 'Main Warehouse',
			'code' => 'WH',
		];

		$mapped = $this->module->map_to_odoo( 'warehouse', $data );

		$this->assertSame( 'Main Warehouse', $mapped['name'] );
		$this->assertSame( 'WH', $mapped['code'] );
	}

	public function test_movement_mapping_is_identity(): void {
		$data = [
			'product_id'       => 42,
			'product_uom_qty'  => 10.0,
			'state'            => 'draft',
			'location_id'      => 5,
			'location_dest_id' => 8,
			'reference'        => 'WC-ADJ-100',
			'date'             => '2026-01-15',
			'name'             => 'Stock adjustment',
		];

		$mapped = $this->module->map_to_odoo( 'movement', $data );

		$this->assertSame( 42, $mapped['product_id'] );
		$this->assertSame( 10.0, $mapped['product_uom_qty'] );
		$this->assertSame( 'draft', $mapped['state'] );
	}

	// ─── Deduplication ────────────────────────────────────

	public function test_warehouse_dedup_uses_code(): void {
		$method = new \ReflectionMethod( WC_Inventory_Module::class, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'warehouse', [ 'code' => 'WH' ] );

		$this->assertCount( 1, $domain );
		$this->assertSame( [ 'code', '=', 'WH' ], $domain[0] );
	}

	public function test_location_dedup_uses_complete_name(): void {
		$method = new \ReflectionMethod( WC_Inventory_Module::class, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'location', [ 'complete_name' => 'WH/Stock' ] );

		$this->assertCount( 1, $domain );
		$this->assertSame( [ 'complete_name', '=', 'WH/Stock' ], $domain[0] );
	}

	public function test_movement_dedup_uses_reference(): void {
		$method = new \ReflectionMethod( WC_Inventory_Module::class, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'movement', [ 'reference' => 'WC-ADJ-100' ] );

		$this->assertCount( 1, $domain );
		$this->assertSame( [ 'reference', '=', 'WC-ADJ-100' ], $domain[0] );
	}
}
