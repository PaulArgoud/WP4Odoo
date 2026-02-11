<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\GiveWP_Module;

/**
 * @covers \WP4Odoo\Modules\GiveWP_Module
 */
class GiveWPModuleTest extends TestCase {

	private GiveWP_Module $module;

	protected function setUp(): void {
		$this->module = new GiveWP_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id_is_givewp(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'givewp', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_givewp(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'GiveWP', $ref->getValue( $this->module ) );
	}

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_form_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$models = $ref->getValue( $this->module );
		$this->assertSame( 'product.product', $models['form'] );
	}

	public function test_declares_donation_model_default(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$models = $ref->getValue( $this->module );
		$this->assertSame( 'account.move', $models['donation'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$models = $ref->getValue( $this->module );
		$this->assertCount( 2, $models );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_forms(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_forms'] );
	}

	public function test_default_settings_has_sync_donations(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_donations'] );
	}

	public function test_default_settings_has_auto_validate_donations(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['auto_validate_donations'] );
	}

	public function test_default_settings_has_exactly_three_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 3, $settings );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_exposes_sync_forms(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_forms', $fields );
		$this->assertSame( 'checkbox', $fields['sync_forms']['type'] );
	}

	public function test_settings_fields_exposes_sync_donations(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_donations', $fields );
		$this->assertSame( 'checkbox', $fields['sync_donations']['type'] );
	}

	public function test_settings_fields_exposes_auto_validate_donations(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'auto_validate_donations', $fields );
		$this->assertSame( 'checkbox', $fields['auto_validate_donations']['type'] );
	}

	public function test_settings_fields_count(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 3, $fields );
	}

	// ─── Field Mappings: Form ───────────────────────────────

	public function test_form_mapping_includes_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'name', $mappings['form']['form_name'] );
	}

	public function test_form_mapping_includes_list_price(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'list_price', $mappings['form']['list_price'] );
	}

	public function test_form_mapping_includes_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'type', $mappings['form']['type'] );
	}

	// ─── Field Mappings: Donation ───────────────────────────

	public function test_donation_mapping_includes_partner_id(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'partner_id', $mappings['donation']['partner_id'] );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_with_givewp(): void {
		$status = $this->module->get_dependency_status();
		// GIVE_VERSION is defined in the test bootstrap.
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices_with_givewp(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash_with_givewp(): void {
		// GIVE_VERSION is defined, so boot should proceed without error.
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── map_to_odoo passthrough for donations ──────────────

	public function test_map_to_odoo_donation_returns_data_as_is(): void {
		$data = [
			'move_type'        => 'out_invoice',
			'partner_id'       => 42,
			'invoice_date'     => '2026-02-10',
			'ref'              => 'give-payment-123',
			'invoice_line_ids' => [ [ 0, 0, [ 'product_id' => 1 ] ] ],
		];
		$result = $this->module->map_to_odoo( 'donation', $data );
		$this->assertSame( $data, $result );
	}
}
