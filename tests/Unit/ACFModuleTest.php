<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\ACF_Module;

#[CoversClass(\WP4Odoo\Modules\ACF_Module::class)]
class ACFModuleTest extends TestCase {

	private ACF_Module $module;

	protected function setUp(): void {
		$this->module = new ACF_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$GLOBALS['_wp_options'] = [];
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id_is_acf(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'acf', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_advanced_custom_fields(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'Advanced Custom Fields', $ref->getValue( $this->module ) );
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

	public function test_default_settings_has_acf_mappings(): void {
		$this->assertArrayHasKey( 'acf_mappings', $this->module->get_default_settings() );
	}

	public function test_default_acf_mappings_is_empty_array(): void {
		$this->assertSame( [], $this->module->get_default_settings()['acf_mappings'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 1, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_count(): void {
		$this->assertCount( 1, $this->module->get_settings_fields() );
	}

	public function test_settings_fields_acf_mappings_is_mappings_type(): void {
		$this->assertSame( 'mappings', $this->module->get_settings_fields()['acf_mappings']['type'] );
	}

	public function test_settings_fields_have_labels(): void {
		$fields = $this->module->get_settings_fields();
		foreach ( $fields as $field ) {
			$this->assertNotEmpty( $field['label'] );
		}
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_with_acf(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices_when_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── get_acf_mappings ───────────────────────────────────

	public function test_get_acf_mappings_returns_empty_by_default(): void {
		$this->assertSame( [], $this->module->get_acf_mappings() );
	}

	public function test_get_acf_mappings_filters_invalid_rules(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_acf_settings'] = [
			'acf_mappings' => [
				[ 'acf_field' => '', 'odoo_field' => 'x_test', 'target_module' => 'crm', 'entity_type' => 'contact', 'type' => 'text' ],
				[ 'acf_field' => 'field', 'odoo_field' => '', 'target_module' => 'crm', 'entity_type' => 'contact', 'type' => 'text' ],
				'not_an_array',
			],
		];

		$this->assertSame( [], $this->module->get_acf_mappings() );
	}

	public function test_get_acf_mappings_returns_valid_rules(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_acf_settings'] = [
			'acf_mappings' => [
				[
					'acf_field'     => 'company_size',
					'odoo_field'    => 'x_company_size',
					'target_module' => 'crm',
					'entity_type'   => 'contact',
					'type'          => 'integer',
				],
			],
		];

		$mappings = $this->module->get_acf_mappings();
		$this->assertCount( 1, $mappings );
		$this->assertSame( 'company_size', $mappings[0]['acf_field'] );
		$this->assertSame( 'x_company_size', $mappings[0]['odoo_field'] );
		$this->assertSame( 'user', $mappings[0]['context'] );
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
		$GLOBALS['_wp_options']['wp4odoo_module_acf_settings'] = [
			'acf_mappings' => [],
		];
		$this->module->boot();
		$this->assertTrue( true );
	}
}
