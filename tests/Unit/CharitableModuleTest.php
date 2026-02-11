<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\Charitable_Module;

/**
 * @covers \WP4Odoo\Modules\Charitable_Module
 */
class CharitableModuleTest extends TestCase {

	private Charitable_Module $module;

	protected function setUp(): void {
		$this->module = new Charitable_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id_is_charitable(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'charitable', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_wp_charitable(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'WP Charitable', $ref->getValue( $this->module ) );
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

	public function test_declares_campaign_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'product.product', $ref->getValue( $this->module )['campaign'] );
	}

	public function test_declares_donation_model_default(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'account.move', $ref->getValue( $this->module )['donation'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 2, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_campaigns(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_campaigns'] );
	}

	public function test_default_settings_has_sync_donations(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_donations'] );
	}

	public function test_default_settings_has_auto_validate_donations(): void {
		$this->assertTrue( $this->module->get_default_settings()['auto_validate_donations'] );
	}

	public function test_default_settings_has_exactly_three_keys(): void {
		$this->assertCount( 3, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_exposes_sync_campaigns(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_campaigns']['type'] );
	}

	public function test_settings_fields_exposes_sync_donations(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_donations']['type'] );
	}

	public function test_settings_fields_exposes_auto_validate_donations(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['auto_validate_donations']['type'] );
	}

	public function test_settings_fields_count(): void {
		$this->assertCount( 3, $this->module->get_settings_fields() );
	}

	// ─── Field Mappings: Campaign ───────────────────────────

	public function test_campaign_mapping_includes_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'name', $ref->getValue( $this->module )['campaign']['form_name'] );
	}

	public function test_campaign_mapping_includes_list_price(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'list_price', $ref->getValue( $this->module )['campaign']['list_price'] );
	}

	public function test_campaign_mapping_includes_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'type', $ref->getValue( $this->module )['campaign']['type'] );
	}

	// ─── Field Mappings: Donation ───────────────────────────

	public function test_donation_mapping_includes_partner_id(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'partner_id', $ref->getValue( $this->module )['donation']['partner_id'] );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_with_charitable(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices_with_charitable(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash_with_charitable(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── map_to_odoo passthrough ────────────────────────────

	public function test_map_to_odoo_donation_returns_data_as_is(): void {
		$data = [
			'move_type'  => 'out_invoice',
			'partner_id' => 42,
			'ref'        => 'charitable-donation-123',
		];

		$this->assertSame( $data, $this->module->map_to_odoo( 'donation', $data ) );
	}
}
