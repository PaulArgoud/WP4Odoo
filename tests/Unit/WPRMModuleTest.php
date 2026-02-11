<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\WPRM_Module;

/**
 * @covers \WP4Odoo\Modules\WPRM_Module
 */
class WPRMModuleTest extends TestCase {

	private WPRM_Module $module;

	protected function setUp(): void {
		$this->module = new WPRM_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id_is_wprm(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'wprm', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_wp_recipe_maker(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'WP Recipe Maker', $ref->getValue( $this->module ) );
	}

	public function test_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority(): void {
		$this->assertSame( 0, $this->module->get_exclusive_priority() );
	}

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_recipe_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'product.product', $ref->getValue( $this->module )['recipe'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 1, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_recipes(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_recipes'] );
	}

	public function test_default_settings_has_exactly_one_key(): void {
		$this->assertCount( 1, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_exposes_sync_recipes(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_recipes']['type'] );
	}

	public function test_settings_fields_count(): void {
		$this->assertCount( 1, $this->module->get_settings_fields() );
	}

	// ─── Field Mappings: Recipe ─────────────────────────────

	public function test_recipe_mapping_includes_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'name', $ref->getValue( $this->module )['recipe']['recipe_name'] );
	}

	public function test_recipe_mapping_includes_description_sale(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'description_sale', $ref->getValue( $this->module )['recipe']['description'] );
	}

	public function test_recipe_mapping_includes_list_price(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'list_price', $ref->getValue( $this->module )['recipe']['list_price'] );
	}

	public function test_recipe_mapping_includes_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'type', $ref->getValue( $this->module )['recipe']['type'] );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_with_wprm(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices_with_wprm(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash_with_wprm(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── map_to_odoo passthrough ────────────────────────────

	public function test_map_to_odoo_recipe_uses_field_mapping(): void {
		$data = [
			'recipe_name' => 'Chocolate Cake',
			'description' => 'A delicious chocolate cake.',
			'list_price'  => 12.5,
			'type'        => 'service',
		];

		$mapped = $this->module->map_to_odoo( 'recipe', $data );

		$this->assertSame( 'Chocolate Cake', $mapped['name'] );
		$this->assertSame( 'A delicious chocolate cake.', $mapped['description_sale'] );
		$this->assertSame( 12.5, $mapped['list_price'] );
		$this->assertSame( 'service', $mapped['type'] );
	}
}
