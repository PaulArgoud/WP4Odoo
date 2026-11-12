<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\JetEngine_Module;

/**
 * Unit tests for JetEngine_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\JetEngine_Module::class)]
class JetEngineModuleTest extends TestCase {

	private JetEngine_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options'] = [];

		$this->module = new JetEngine_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	/**
	 * Build a module with pre-configured CPT mappings.
	 *
	 * @param array $mappings Raw cpt_mappings array.
	 * @return JetEngine_Module
	 */
	private function module_with_mappings( array $mappings ): JetEngine_Module {
		$GLOBALS['_wp_options']['wp4odoo_module_jetengine_settings'] = [
			'cpt_mappings' => $mappings,
		];

		return new JetEngine_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'jetengine', $ref->getValue( $this->module ) );
	}

	public function test_module_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'JetEngine', $ref->getValue( $this->module ) );
	}

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── No Mappings by Default ─────────────────────────────

	public function test_odoo_models_empty_without_mappings(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertEmpty( $ref->getValue( $this->module ) );
	}

	public function test_get_cpt_mappings_empty_by_default(): void {
		$this->assertEmpty( $this->module->get_cpt_mappings() );
	}

	// ─── Dynamic Models from Settings ───────────────────────

	public function test_populates_odoo_models_from_settings(): void {
		$module = $this->module_with_mappings( [
			[
				'cpt_slug'    => 'property',
				'entity_type' => 'property',
				'odoo_model'  => 'x_property',
				'dedup_field' => 'name',
				'fields'      => [
					[ 'wp_field' => 'post_title', 'odoo_field' => 'name', 'type' => 'text' ],
				],
			],
		] );

		$ref = new \ReflectionProperty( $module, 'odoo_models' );
		$this->assertSame( 'x_property', $ref->getValue( $module )['property'] );
	}

	public function test_populates_multiple_models(): void {
		$module = $this->module_with_mappings( [
			[
				'cpt_slug'    => 'property',
				'entity_type' => 'property',
				'odoo_model'  => 'x_property',
				'dedup_field' => '',
				'fields'      => [
					[ 'wp_field' => 'post_title', 'odoo_field' => 'name', 'type' => 'text' ],
				],
			],
			[
				'cpt_slug'    => 'vehicle',
				'entity_type' => 'vehicle',
				'odoo_model'  => 'fleet.vehicle',
				'dedup_field' => 'license_plate',
				'fields'      => [
					[ 'wp_field' => 'post_title', 'odoo_field' => 'name', 'type' => 'text' ],
				],
			],
		] );

		$ref = new \ReflectionProperty( $module, 'odoo_models' );
		$models = $ref->getValue( $module );
		$this->assertCount( 2, $models );
		$this->assertSame( 'x_property', $models['property'] );
		$this->assertSame( 'fleet.vehicle', $models['vehicle'] );
	}

	// ─── Mapping Validation ─────────────────────────────────

	public function test_skips_invalid_mappings(): void {
		$module = $this->module_with_mappings( [
			'not_an_array',
			[ 'cpt_slug' => '', 'entity_type' => 'x', 'odoo_model' => 'x.y' ],
			[
				'cpt_slug'    => 'valid',
				'entity_type' => 'valid',
				'odoo_model'  => 'x_valid',
				'dedup_field' => '',
				'fields'      => [],
			],
		] );

		$mappings = $module->get_cpt_mappings();
		$this->assertCount( 1, $mappings );
		$this->assertSame( 'valid', $mappings[0]['entity_type'] );
	}

	public function test_caches_validated_mappings(): void {
		$module = $this->module_with_mappings( [
			[
				'cpt_slug'    => 'test',
				'entity_type' => 'test',
				'odoo_model'  => 'x_test',
				'dedup_field' => '',
				'fields'      => [],
			],
		] );

		$first  = $module->get_cpt_mappings();
		$second = $module->get_cpt_mappings();
		$this->assertSame( $first, $second );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_cpt_mappings(): void {
		$defaults = $this->module->get_default_settings();
		$this->assertArrayHasKey( 'cpt_mappings', $defaults );
		$this->assertIsArray( $defaults['cpt_mappings'] );
		$this->assertEmpty( $defaults['cpt_mappings'] );
	}

	public function test_default_settings_has_exactly_one_key(): void {
		$this->assertCount( 1, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_has_cpt_mappings(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'cpt_mappings', $fields );
		$this->assertSame( 'cpt_mappings', $fields['cpt_mappings']['type'] );
	}

	public function test_settings_fields_has_exactly_one_entry(): void {
		$this->assertCount( 1, $this->module->get_settings_fields() );
	}

	// ─── map_to_odoo ────────────────────────────────────────

	public function test_map_to_odoo_passes_through_data(): void {
		$module = $this->module_with_mappings( [
			[
				'cpt_slug'    => 'property',
				'entity_type' => 'property',
				'odoo_model'  => 'x_property',
				'dedup_field' => '',
				'fields'      => [
					[ 'wp_field' => 'post_title', 'odoo_field' => 'name', 'type' => 'text' ],
				],
			],
		] );

		$input = [
			'_wp_entity_id' => 42,
			'name'          => 'My Property',
		];

		$data = $module->map_to_odoo( 'property', $input );

		$this->assertSame( 'My Property', $data['name'] );
		$this->assertArrayNotHasKey( '_wp_entity_id', $data );
	}

	public function test_map_to_odoo_returns_raw_for_unknown_entity(): void {
		$input = [ 'name' => 'Test' ];
		$data  = $this->module->map_to_odoo( 'unknown', $input );
		$this->assertSame( $input, $data );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_when_constant_defined(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Boot ───────────────────────────────────────────────

	public function test_boot_without_mappings_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	public function test_boot_with_mappings_does_not_crash(): void {
		$module = $this->module_with_mappings( [
			[
				'cpt_slug'    => 'property',
				'entity_type' => 'property',
				'odoo_model'  => 'x_property',
				'dedup_field' => '',
				'fields'      => [
					[ 'wp_field' => 'post_title', 'odoo_field' => 'name', 'type' => 'text' ],
				],
			],
		] );

		$module->boot();
		$this->assertTrue( true );
	}
}
