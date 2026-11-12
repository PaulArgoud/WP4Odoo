<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\JetEngine_Meta_Module;

/**
 * Unit tests for JetEngine_Meta_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\JetEngine_Meta_Module::class)]
class JetEngineMetaModuleTest extends TestCase {

	private JetEngine_Meta_Module $module;

	protected function setUp(): void {
		$this->module = new JetEngine_Meta_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$GLOBALS['_wp_options'] = [];
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id_is_jetengine_meta(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'jetengine_meta', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_jetengine_meta(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'JetEngine Meta', $ref->getValue( $this->module ) );
	}

	public function test_exclusive_group_is_empty(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_odoo_models_is_empty(): void {
		$this->assertSame( [], $this->module->get_odoo_models() );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_jetengine_mappings(): void {
		$this->assertArrayHasKey( 'jetengine_mappings', $this->module->get_default_settings() );
	}

	public function test_default_jetengine_mappings_is_empty_array(): void {
		$this->assertSame( [], $this->module->get_default_settings()['jetengine_mappings'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 1, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_count(): void {
		$this->assertCount( 1, $this->module->get_settings_fields() );
	}

	public function test_settings_fields_jetengine_mappings_is_mappings_type(): void {
		$this->assertSame( 'mappings', $this->module->get_settings_fields()['jetengine_mappings']['type'] );
	}

	public function test_settings_fields_have_labels(): void {
		$fields = $this->module->get_settings_fields();
		foreach ( $fields as $field ) {
			$this->assertNotEmpty( $field['label'] );
		}
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_with_jetengine(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices_when_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── get_jetengine_mappings ─────────────────────────────

	public function test_get_jetengine_mappings_returns_empty_by_default(): void {
		$this->assertSame( [], $this->module->get_jetengine_mappings() );
	}

	public function test_get_jetengine_mappings_filters_invalid_rules(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_jetengine_meta_settings'] = [
			'jetengine_mappings' => [
				[ 'jet_field' => '', 'odoo_field' => 'x_test', 'target_module' => 'woocommerce', 'entity_type' => 'product', 'type' => 'text' ],
				[ 'jet_field' => 'field', 'odoo_field' => '', 'target_module' => 'woocommerce', 'entity_type' => 'product', 'type' => 'text' ],
				'not_an_array',
			],
		];

		$this->assertSame( [], $this->module->get_jetengine_mappings() );
	}

	public function test_get_jetengine_mappings_returns_valid_rules(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_jetengine_meta_settings'] = [
			'jetengine_mappings' => [
				[
					'jet_field'     => 'custom_weight',
					'odoo_field'    => 'x_custom_weight',
					'target_module' => 'woocommerce',
					'entity_type'   => 'product',
					'type'          => 'number',
				],
			],
		];

		$mappings = $this->module->get_jetengine_mappings();
		$this->assertCount( 1, $mappings );
		$this->assertSame( 'custom_weight', $mappings[0]['jet_field'] );
		$this->assertSame( 'x_custom_weight', $mappings[0]['odoo_field'] );
	}

	// ─── load_wp_data (always empty) ────────────────────────

	public function test_load_wp_data_returns_empty(): void {
		$ref = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$this->assertSame( [], $ref->invoke( $this->module, 'anything', 1 ) );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	public function test_boot_with_empty_mappings_does_not_crash(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_jetengine_meta_settings'] = [
			'jetengine_mappings' => [],
		];
		$this->module->boot();
		$this->assertTrue( true );
	}
}
