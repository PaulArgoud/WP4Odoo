<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\WC_Addons_Module;

/**
 * Unit tests for WC_Addons_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WC_Addons_Module::class)]
class WCAddonsModuleTest extends TestCase {

	private WC_Addons_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options'] = [];

		$this->module = new WC_Addons_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'wc_addons', $ref->getValue( $this->module ) );
	}

	public function test_module_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'WC Product Add-Ons', $ref->getValue( $this->module ) );
	}

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	public function test_requires_woocommerce_module(): void {
		$this->assertSame( [ 'woocommerce' ], $this->module->get_required_modules() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_addon_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'product.template.attribute.line', $ref->getValue( $this->module )['addon'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 1, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_addons(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_addons'] );
	}

	public function test_default_settings_has_addon_mode(): void {
		$this->assertSame( 'product_attributes', $this->module->get_default_settings()['addon_mode'] );
	}

	public function test_default_settings_has_exactly_two_keys(): void {
		$this->assertCount( 2, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_has_sync_addons(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_addons', $fields );
		$this->assertSame( 'checkbox', $fields['sync_addons']['type'] );
	}

	public function test_settings_fields_has_addon_mode(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'addon_mode', $fields );
		$this->assertSame( 'select', $fields['addon_mode']['type'] );
	}

	public function test_addon_mode_has_two_options(): void {
		$fields  = $this->module->get_settings_fields();
		$options = $fields['addon_mode']['options'];
		$this->assertCount( 2, $options );
		$this->assertArrayHasKey( 'product_attributes', $options );
		$this->assertArrayHasKey( 'bom_components', $options );
	}

	// ─── map_to_odoo ────────────────────────────────────────

	public function test_map_addon_returns_raw_data(): void {
		$input = [
			'product_tmpl_id'    => 10,
			'attribute_line_ids' => [ [ 0, 0, [ 'attribute_id' => [ 'name' => 'Color' ] ] ] ],
		];

		$data = $this->module->map_to_odoo( 'addon', $input );

		$this->assertSame( $input, $data );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_when_class_exists(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}
}
