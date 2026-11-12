<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WC_Shipping_Module;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Shipping_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WC_Shipping_Module::class)]
#[CoversClass(\WP4Odoo\Modules\WC_Shipping_Hooks::class)]
class WCShippingModuleTest extends TestCase {

	private WC_Shipping_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_wc_orders']        = [];
		$GLOBALS['_wc_products']      = [];
		$GLOBALS['_wc_shipping_zones'] = [];

		$this->module = new WC_Shipping_Module(
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);
	}

	// ─── Identity ─────────────────────────────────────────

	public function test_module_id(): void {
		$this->assertSame( 'wc_shipping', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'WC Shipping', $this->module->get_name() );
	}

	public function test_sync_direction(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	public function test_requires_woocommerce_module(): void {
		$this->assertSame( [ 'woocommerce' ], $this->module->get_required_modules() );
	}

	// ─── Odoo models ──────────────────────────────────────

	public function test_declares_carrier_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'delivery.carrier', $models['carrier'] );
	}

	public function test_declares_shipment_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'stock.picking', $models['shipment'] );
	}

	public function test_model_count(): void {
		$this->assertCount( 2, $this->module->get_odoo_models() );
	}

	// ─── Default settings ─────────────────────────────────

	public function test_default_sync_carriers_disabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertFalse( $settings['sync_carriers'] );
	}

	public function test_default_tracking_push_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_tracking_push'] );
	}

	public function test_default_tracking_pull_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_tracking_pull'] );
	}

	public function test_default_auto_validate_disabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertFalse( $settings['auto_validate_picking'] );
	}

	public function test_default_shipstation_hooks_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['shipstation_hooks'] );
	}

	public function test_default_sendcloud_hooks_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sendcloud_hooks'] );
	}

	public function test_default_packlink_hooks_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['packlink_hooks'] );
	}

	public function test_settings_count(): void {
		$this->assertCount( 7, $this->module->get_default_settings() );
	}

	// ─── Settings fields ──────────────────────────────────

	public function test_settings_fields_all_checkboxes(): void {
		$fields = $this->module->get_settings_fields();
		foreach ( $fields as $key => $field ) {
			$this->assertSame( 'checkbox', $field['type'], "Field $key should be a checkbox." );
		}
	}

	public function test_settings_fields_count(): void {
		$this->assertCount( 7, $this->module->get_settings_fields() );
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

	// ─── Field mappings (identity pass-through) ───────────

	public function test_shipment_mapping_is_identity(): void {
		$data = [
			'carrier_tracking_ref' => 'TRACK-123',
			'carrier_id'           => 42,
			'state'                => 'done',
			'date_done'            => '2026-01-15',
			'origin'               => 'WC Order #100',
		];

		$mapped = $this->module->map_to_odoo( 'shipment', $data );

		$this->assertSame( 'TRACK-123', $mapped['carrier_tracking_ref'] );
		$this->assertSame( 42, $mapped['carrier_id'] );
		$this->assertSame( 'done', $mapped['state'] );
	}

	// ─── Deduplication ────────────────────────────────────

	public function test_carrier_dedup_uses_name(): void {
		$method = new \ReflectionMethod( WC_Shipping_Module::class, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'carrier', [ 'name' => 'DHL Express' ] );

		$this->assertCount( 1, $domain );
		$this->assertSame( [ 'name', '=', 'DHL Express' ], $domain[0] );
	}

	public function test_shipment_dedup_returns_empty(): void {
		$method = new \ReflectionMethod( WC_Shipping_Module::class, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'shipment', [ 'origin' => 'WC Order #100' ] );

		$this->assertEmpty( $domain );
	}

	// ─── Pull override ────────────────────────────────────

	public function test_pull_carrier_returns_success(): void {
		$result = $this->module->pull_from_odoo( 'carrier', 'create', 999 );
		$this->assertTrue( $result->succeeded() );
	}
}
