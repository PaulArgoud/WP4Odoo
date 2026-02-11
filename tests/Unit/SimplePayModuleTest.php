<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\SimplePay_Module;

/**
 * @covers \WP4Odoo\Modules\SimplePay_Module
 */
class SimplePayModuleTest extends TestCase {

	private SimplePay_Module $module;

	protected function setUp(): void {
		$this->module = new SimplePay_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id_is_simplepay(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'simplepay', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_wp_simple_pay(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'WP Simple Pay', $ref->getValue( $this->module ) );
	}

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_form_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'product.product', $ref->getValue( $this->module )['form'] );
	}

	public function test_declares_payment_model_default(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'account.move', $ref->getValue( $this->module )['payment'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 2, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_forms(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_forms'] );
	}

	public function test_default_settings_has_sync_payments(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_payments'] );
	}

	public function test_default_settings_has_auto_validate_payments(): void {
		$this->assertTrue( $this->module->get_default_settings()['auto_validate_payments'] );
	}

	public function test_default_settings_has_exactly_three_keys(): void {
		$this->assertCount( 3, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_exposes_sync_forms(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_forms']['type'] );
	}

	public function test_settings_fields_exposes_sync_payments(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_payments']['type'] );
	}

	public function test_settings_fields_exposes_auto_validate_payments(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['auto_validate_payments']['type'] );
	}

	public function test_settings_fields_count(): void {
		$this->assertCount( 3, $this->module->get_settings_fields() );
	}

	// ─── Field Mappings: Form ──────────────────────────────

	public function test_form_mapping_includes_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'name', $ref->getValue( $this->module )['form']['form_name'] );
	}

	public function test_form_mapping_includes_list_price(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'list_price', $ref->getValue( $this->module )['form']['list_price'] );
	}

	public function test_form_mapping_includes_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'type', $ref->getValue( $this->module )['form']['type'] );
	}

	// ─── Field Mappings: Payment ────────────────────────────

	public function test_payment_mapping_includes_partner_id(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'partner_id', $ref->getValue( $this->module )['payment']['partner_id'] );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_with_simplepay(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices_with_simplepay(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash_with_simplepay(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── map_to_odoo passthrough ────────────────────────────

	public function test_map_to_odoo_payment_returns_data_as_is(): void {
		$data = [
			'move_type'  => 'out_invoice',
			'partner_id' => 42,
			'ref'        => 'spay-pi_test123',
		];

		$this->assertSame( $data, $this->module->map_to_odoo( 'payment', $data ) );
	}
}
