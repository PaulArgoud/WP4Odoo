<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WC_Returns_Module;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Returns_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WC_Returns_Module::class)]
#[CoversClass(\WP4Odoo\Modules\WC_Returns_Hooks::class)]
class WCReturnsModuleTest extends TestCase {

	private WC_Returns_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']  = [];
		$GLOBALS['_wc_orders']   = [];
		$GLOBALS['_wc_products'] = [];
		$GLOBALS['_wc_refunds']  = [];

		$this->module = new WC_Returns_Module(
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);
	}

	// ─── Identity ─────────────────────────────────────────

	public function test_module_id(): void {
		$this->assertSame( 'wc_returns', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'WooCommerce Returns', $this->module->get_name() );
	}

	public function test_sync_direction(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	public function test_requires_woocommerce_module(): void {
		$this->assertSame( [ 'woocommerce' ], $this->module->get_required_modules() );
	}

	// ─── Odoo models ──────────────────────────────────────

	public function test_declares_refund_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.move', $models['refund'] );
	}

	public function test_declares_return_picking_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'stock.picking', $models['return_picking'] );
	}

	public function test_model_count(): void {
		$this->assertCount( 2, $this->module->get_odoo_models() );
	}

	// ─── Default settings ─────────────────────────────────

	public function test_default_sync_refunds_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_refunds'] );
	}

	public function test_default_pull_refunds_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_refunds'] );
	}

	public function test_default_return_pickings_disabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertFalse( $settings['sync_return_pickings'] );
	}

	public function test_default_auto_post_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['auto_post_refund'] );
	}

	public function test_default_yith_hooks_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['yith_hooks'] );
	}

	public function test_default_returngo_hooks_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['returngo_hooks'] );
	}

	public function test_settings_count(): void {
		$this->assertCount( 6, $this->module->get_default_settings() );
	}

	// ─── Settings fields ──────────────────────────────────

	public function test_settings_fields_all_checkboxes(): void {
		$fields = $this->module->get_settings_fields();
		foreach ( $fields as $key => $field ) {
			$this->assertSame( 'checkbox', $field['type'], "Field $key should be a checkbox." );
		}
	}

	public function test_settings_fields_count(): void {
		$this->assertCount( 6, $this->module->get_settings_fields() );
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

	public function test_refund_mapping_is_identity(): void {
		$data = [
			'move_type'        => 'out_refund',
			'partner_id'       => 42,
			'invoice_date'     => '2026-01-15',
			'ref'              => 'WC-REFUND-1 (Order #100)',
			'invoice_line_ids' => [ [ 0, 0, [ 'name' => 'Item', 'quantity' => 1, 'price_unit' => 50.0 ] ] ],
		];

		$mapped = $this->module->map_to_odoo( 'refund', $data );

		$this->assertSame( 'out_refund', $mapped['move_type'] );
		$this->assertSame( 42, $mapped['partner_id'] );
		$this->assertSame( '2026-01-15', $mapped['invoice_date'] );
	}

	// ─── Deduplication ────────────────────────────────────

	public function test_refund_dedup_uses_ref_and_move_type(): void {
		$method = new \ReflectionMethod( WC_Returns_Module::class, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'refund', [ 'ref' => 'WC-REFUND-1 (Order #100)' ] );

		$this->assertCount( 2, $domain );
		$this->assertSame( [ 'ref', '=', 'WC-REFUND-1 (Order #100)' ], $domain[0] );
		$this->assertSame( [ 'move_type', '=', 'out_refund' ], $domain[1] );
	}

	public function test_return_picking_dedup_returns_empty(): void {
		$method = new \ReflectionMethod( WC_Returns_Module::class, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'return_picking', [ 'origin' => 'test' ] );

		$this->assertEmpty( $domain );
	}

	// ─── Pull override ────────────────────────────────────

	public function test_pull_return_picking_returns_success(): void {
		$result = $this->module->pull_from_odoo( 'return_picking', 'create', 999 );
		$this->assertTrue( $result->succeeded() );
	}
}
